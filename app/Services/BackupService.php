<?php

namespace App\Services;

use App\Models\Backup;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use PDO;
use RuntimeException;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * أخذ نسخة احتياطية من قاعدة البيانات (§18).
 *
 * الأداة تُختار بحسب ما هو متاح لا بحسب ما هو مثالي:
 *   • MySQL مع mysqldump في المسار → mysqldump، وهو الأسرع والأوثق.
 *   • MySQL بلا mysqldump → مُصدِّر داخلي بالـPHP، أبطأ لكنه لا يحتاج شيئًا
 *     خارج التطبيق. ونسخةٌ أبطأ خيرٌ من لا نسخة على استضافةٍ مشتركة.
 *   • SQLite → نسخُ الملف، فهو القاعدة كلها.
 *
 * والنسخة تُسجَّل قبل أن تبدأ لا بعد أن تنجح: العملية التي تنقطع في منتصفها
 * يجب أن تترك أثرًا يقول إنها وقعت وفشلت، وإلا مضت الليلة بلا نسخة ولا خبر.
 */
class BackupService
{
    /**
     * أخذ نسخة وتسجيلها، ثم حذف ما زاد عن مدة الاحتفاظ.
     */
    public function run(string $trigger = 'schedule', ?int $userId = null): Backup
    {
        $driver = DB::connection()->getDriverName();
        $startedAt = microtime(true);

        $backup = Backup::create([
            'filename' => $this->filename($driver),
            'disk' => 'local',
            'trigger' => $trigger,
            'created_by' => $userId,
            'driver' => $driver,
            'status' => 'running',
        ]);

        try {
            $path = $this->path($backup->filename);
            File::ensureDirectoryExists(dirname($path));

            $method = match ($driver) {
                'sqlite' => $this->copySqlite($path),
                'mysql', 'mariadb' => $this->dumpMysql($path),
                default => throw new RuntimeException("النسخ الاحتياطي لا يدعم قاعدة من نوع {$driver} بعد."),
            };

            $final = $this->compress($path);

            $backup->update([
                'filename' => basename($final),
                'size' => File::size($final),
                'method' => $method,
                'status' => 'completed',
                'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            ]);

            $this->prune();
        } catch (Throwable $e) {
            // الفشل يُسجَّل ولا يُخفى: نسخةٌ فشلت بصمت أسوأ من نسخة لم تُطلب.
            $backup->update([
                'status' => 'failed',
                'error' => mb_substr($e->getMessage(), 0, 1000),
                'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            ]);

            Log::error('فشل أخذ النسخة الاحتياطية', ['backup_id' => $backup->id, 'error' => $e->getMessage()]);
        }

        return $backup->fresh();
    }

    /**
     * تبنّي ملف نسخة مرفوع من الخارج: يُنقل إلى مجلد النسخ ويُسجَّل صفًّا.
     *
     * الملف يُحفظ باسمٍ مولَّد لا باسمه الأصلي: اسمٌ يأتي من متصفّح المستخدم
     * ليس ممّا يُبنى عليه مسارٌ على القرص وإن بدا بريئًا. ولا يُستبقى منه
     * سوى امتداده بعد تنقيته، لأن فارق المضغوط عن النصّ يُقرأ منه.
     *
     * ويُسجَّل «مكتملة» لأن الملف موجود فعلًا — وصلاحيته للاستعادة يحكم عليها
     * RestoreService حين يُقرأ، لا هذه الدالة حين يُنقَل.
     */
    public function adopt(UploadedFile $file, ?int $userId = null): Backup
    {
        $extension = strtolower((string) preg_replace('/[^A-Za-z0-9]/', '', (string) $file->getClientOriginalExtension()));
        $extension = in_array($extension, ['sql', 'gz', 'sqlite', 'sqlite3', 'db'], true) ? $extension : 'sql';

        $filename = 'upload-'.now()->format('Y-m-d-His').'-'.Str::random(6).'.'.$extension;
        $path = $this->path($filename);

        File::ensureDirectoryExists(dirname($path));
        $file->move(dirname($path), basename($path));

        return Backup::create([
            'filename' => $filename,
            'disk' => 'local',
            'size' => File::size($path),
            'trigger' => 'upload',
            'created_by' => $userId,
            'driver' => DB::connection()->getDriverName(),
            'method' => 'upload',
            'status' => 'completed',
        ]);
    }

    /**
     * المسار المطلق لملف نسخة.
     */
    public function path(string $filename): string
    {
        return storage_path('app/'.trim(config('operations.backup.path', 'backups'), '/').'/'.$filename);
    }

    /**
     * حذف نسخة بملفها — الصف بلا ملفه يكذب على من يقرأ الشاشة.
     */
    public function delete(Backup $backup): void
    {
        $path = $this->path($backup->filename);

        if (File::exists($path)) {
            File::delete($path);
        }

        $backup->delete();
    }

    /**
     * حذف ما زاد عن عدد النسخ المحتفظ بها.
     */
    public function prune(): int
    {
        $keep = (int) config('operations.backup.keep', 14);

        // الملفات المرفوعة تُستثنى من التقليم: جاءت بفعلٍ مقصود من مستخدم،
        // وحذفُها تلقائيًا يعني أن نسخةً رُفعت لتُستعاد قد تختفي قبل استعادتها
        // — والتقليم يجري داخل run()، وهي نفسها تُستدعى قبل كل استعادة.
        $stale = Backup::completed()
            ->where('trigger', '!=', 'upload')
            ->orderByDesc('id')
            ->skip($keep)
            ->take(100)
            ->get();

        foreach ($stale as $backup) {
            $this->delete($backup);
        }

        // النسخ الفاشلة لا تشغل قرصًا، لكن صفوفها تُغرق الشاشة — يبقى منها
        // ما يكفي للتشخيص ويُحذف ما قبله.
        Backup::where('status', 'failed')->orderByDesc('id')->skip(20)->take(100)->get()
            ->each(fn (Backup $b) => $b->delete());

        return $stale->count();
    }

    /**
     * SQLite: القاعدة ملفٌ واحد، فنسخه هو النسخة.
     */
    private function copySqlite(string $path): string
    {
        $database = DB::connection()->getDatabaseName();

        // قاعدة الاختبارات في الذاكرة لا ملف لها — تُصدَّر بالمُصدِّر الداخلي.
        if ($database === ':memory:' || ! File::exists($database)) {
            $this->dumpWithPhp($path);

            return 'php';
        }

        File::copy($database, $path);

        return 'copy';
    }

    /**
     * MySQL: mysqldump إن وُجد، وإلا المُصدِّر الداخلي.
     */
    private function dumpMysql(string $path): string
    {
        $config = DB::connection()->getConfig();
        $binary = (string) config('operations.backup.mysqldump_path', 'mysqldump');

        $process = new Process([
            $binary,
            '--host='.($config['host'] ?? '127.0.0.1'),
            '--port='.($config['port'] ?? 3306),
            '--user='.($config['username'] ?? 'root'),
            '--password='.($config['password'] ?? ''),
            '--single-transaction',
            '--skip-lock-tables',
            '--default-character-set=utf8mb4',
            '--result-file='.$path,
            $config['database'],
        ]);

        $process->setTimeout(600);

        try {
            $process->mustRun();

            // الخروج بصفرٍ ليس دليل نجاح كافٍ: mysqldump قد ينتهي بلا شكوى
            // ولا يترك ملفًا (مسارٌ لم يُقبل، أو ثنائيٌّ آخر يحمل الاسم نفسه)،
            // فيقع الخطأ بعدها عند الضغط برسالة «الملف غير موجود» لا تدلّ على
            // سببه. فيُتحقّق من الملف هنا ويُحوَّل إلى المُصدِّر الداخلي.
            if (! File::exists($path) || File::size($path) === 0) {
                throw new RuntimeException('انتهى mysqldump دون أن يكتب ملفًا.');
            }

            return 'mysqldump';
        } catch (ProcessFailedException|RuntimeException $e) {
            Log::warning('تعذّر تشغيل mysqldump — التحويل إلى المُصدِّر الداخلي', ['error' => $e->getMessage()]);

            $this->dumpWithPhp($path);

            return 'php';
        }
    }

    /**
     * مُصدِّر داخلي: يقرأ الجداول صفًّا صفًّا ويكتبها عبارات إدراج.
     *
     * الكتابة على دفعات لا مرةً واحدة: جدول الحجوزات بعد سنة لا يُحمَل في
     * الذاكرة كاملًا لتُكتب منه سطور.
     */
    private function dumpWithPhp(string $path): void
    {
        $handle = fopen($path, 'w');

        if (! $handle) {
            throw new RuntimeException("تعذّر فتح ملف النسخة للكتابة: {$path}");
        }

        $pdo = DB::connection()->getPdo();
        $driver = DB::connection()->getDriverName();

        fwrite($handle, '-- نسخة احتياطية — '.now()->toDateTimeString()."\n");
        fwrite($handle, '-- '.config('app.name')." / {$driver}\n\n");

        if ($driver === 'mysql' || $driver === 'mariadb') {
            fwrite($handle, "SET FOREIGN_KEY_CHECKS=0;\nSET NAMES utf8mb4;\n\n");
        }

        foreach ($this->tables() as $table) {
            // جدولٌ يتعذّر تصديره (منظور مكسور، صلاحية ناقصة) لا يُسقط النسخة
            // كلها: يُذكر في الملف وفي السجل، وتمضي بقية الجداول.
            try {
                $this->dumpTable($handle, $table, $pdo, $driver);
            } catch (Throwable $e) {
                fwrite($handle, "\n-- تعذّر تصدير الجدول {$table}: {$e->getMessage()}\n");
                Log::warning("تعذّر تصدير الجدول {$table} في النسخة الاحتياطية", ['error' => $e->getMessage()]);
            }
        }

        if ($driver === 'mysql' || $driver === 'mariadb') {
            fwrite($handle, "\nSET FOREIGN_KEY_CHECKS=1;\n");
        }

        fclose($handle);
    }

    /**
     * تصدير جدول واحد: بنيته ثم صفوفه.
     *
     * @param  resource  $handle
     */
    private function dumpTable($handle, string $table, PDO $pdo, string $driver): void
    {
        fwrite($handle, "\n-- جدول {$table}\n");

        if ($create = $this->createStatement($table)) {
            fwrite($handle, $create.";\n");
        }

        // القراءة تدفّقًا بـPDO لا صفحاتٍ بـchunk: الأخيرة تحتاج ترتيبًا ثابتًا
        // وتعيد الاستعلام لكل صفحة، وجدولٌ بلا مفتاح مرتَّب يجعلها تمرّ على
        // القاعدة مرارًا. والتدفّق مرورٌ واحد لا يحمل الصفوف كلها في الذاكرة.
        $quoted = $driver === 'sqlite' ? '"'.$table.'"' : '`'.$table.'`';
        $statement = $pdo->query("SELECT * FROM {$quoted}");

        while ($row = $statement->fetch(PDO::FETCH_ASSOC)) {
            $values = collect($row)
                ->map(fn ($value) => match (true) {
                    is_null($value) => 'NULL',
                    is_bool($value) => $value ? '1' : '0',
                    is_int($value), is_float($value) => (string) $value,
                    default => $pdo->quote((string) $value),
                })
                ->implode(', ');

            $columns = collect(array_keys($row))->map(fn ($c) => '`'.$c.'`')->implode(', ');

            fwrite($handle, "INSERT INTO `{$table}` ({$columns}) VALUES ({$values});\n");
        }
    }

    /**
     * جداول القاعدة — ما يخصّ التطبيق دون جداول التشغيل العابرة.
     *
     * @return list<string>
     */
    private function tables(): array
    {
        $skipped = ['cache', 'cache_locks', 'sessions', 'jobs', 'job_batches', 'failed_jobs'];

        // القائمة على MySQL تأتي مؤهَّلة باسم قاعدتها وتشمل قواعد الخادم كلها
        // — عشرة آلاف جدول لا تخصّنا. فيُرشَّح ما ليس من قاعدتنا قبل تجريد
        // البادئة، وإلا صار «قاعدة أخرى.عملاء» جدولَ عملائنا في نظر المُصدِّر.
        $database = DB::connection()->getDatabaseName();

        // وSQLite تُؤهِّل جداولها بـ«main» لا باسم الملف، فتُقبل هي أيضًا.
        $prefixes = [$database.'.', 'main.'];

        return collect(DB::connection()->getSchemaBuilder()->getTableListing())
            ->filter(fn (string $table) => ! str_contains($table, '.') || str_starts_with($table, $prefixes[0]) || str_starts_with($table, $prefixes[1]))
            ->map(fn (string $table) => str_contains($table, '.') ? substr($table, strrpos($table, '.') + 1) : $table)
            ->reject(fn (string $table) => in_array($table, $skipped, true))
            ->unique()
            ->values()
            ->all();
    }

    /**
     * عبارة إنشاء الجدول — تُقرأ من القاعدة نفسها لا تُبنى تخمينًا.
     */
    private function createStatement(string $table): ?string
    {
        $driver = DB::connection()->getDriverName();

        try {
            if ($driver === 'mysql' || $driver === 'mariadb') {
                $row = (array) DB::selectOne("SHOW CREATE TABLE `{$table}`");

                return "DROP TABLE IF EXISTS `{$table}`;\n".($row['Create Table'] ?? null);
            }

            if ($driver === 'sqlite') {
                $row = DB::selectOne('SELECT sql FROM sqlite_master WHERE type = ? AND name = ?', ['table', $table]);

                // الإسقاط قبل الإنشاء كما في MySQL: نسخةٌ بلا DROP لا تصلح
                // للاستعادة على قاعدةٍ عامرة — تفشل عبارات الإنشاء لوجود
                // الجداول، ثم تُضاف الصفوف فوق صفوفٍ قائمة فتتضاعف.
                return $row?->sql ? "DROP TABLE IF EXISTS \"{$table}\";\n".$row->sql : null;
            }
        } catch (Throwable $e) {
            Log::warning("تعذّر قراءة بنية الجدول {$table}", ['error' => $e->getMessage()]);
        }

        return null;
    }

    /**
     * ضغط الملف إن توفّر zlib — نسخة القاعدة نصٌّ يضغط إلى عُشره.
     */
    private function compress(string $path): string
    {
        if (! config('operations.backup.compress', true) || ! function_exists('gzencode')) {
            return $path;
        }

        $contents = File::get($path);
        $compressed = gzencode($contents, 6);

        if ($compressed === false) {
            return $path;
        }

        $target = $path.'.gz';
        File::put($target, $compressed);
        File::delete($path);

        return $target;
    }

    /**
     * اسم ملف النسخة — بالوقت ولاحقةٍ عشوائية.
     *
     * الوقت وحده بدقّة الثانية ليس مميِّزًا: نسختان تُؤخذان في الثانية نفسها
     * تحملان الاسم نفسه، فتكتب الثانية فوق الأولى ويبقى في السجل صفّان
     * يشيران إلى ملفٍ واحد. وهذا يقع فعلًا لا نظريًّا — نسخة الأمان تُؤخذ
     * لحظة الاستعادة، فتمحو الملف الذي جيء لاستعادته.
     */
    private function filename(string $driver): string
    {
        return 'backup-'.now()->format('Y-m-d-His').'-'.Str::random(6).'-'.$driver.'.sql';
    }
}
