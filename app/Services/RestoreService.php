<?php

namespace App\Services;

use App\Models\Backup;
use Generator;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * استعادة قاعدة البيانات من ملف نسخة (§18).
 *
 * الاستعادة عمليةٌ لا تُراجَع: تكتب فوق القاعدة كلها، وما كان فيها قبلها
 * يذهب. ولذلك تُؤخذ نسخةُ أمانٍ قبل أول عبارة تُنفَّذ لا بعد آخرها — من
 * استعاد ملفًا خاطئًا يحتاج ما يعود إليه، ولا ينفعه أن يُقال له بعدها إن
 * الملف الذي رفعه لم يكن الصحيح.
 *
 * والملف يُقرأ تدفّقًا لا يُحمَل في الذاكرة: نسخةُ قاعدةٍ بعد سنتين تُقاس
 * بمئات الميجابايت، وقراءتها دفعةً واحدة تُسقط الطلب قبل أن يبدأ.
 */
class RestoreService
{
    /** امتدادات الملفات المقبولة للرفع — ما عداها لا يُقرأ. */
    public const EXTENSIONS = ['sql', 'gz', 'sqlite', 'sqlite3', 'db'];

    public function __construct(private readonly BackupService $backups) {}

    /**
     * استعادة القاعدة من ملف نسخة، بعد أخذ نسخة أمان.
     *
     * @return array{statements:int, failed:int, safety:int}
     */
    public function restore(Backup $backup, ?int $userId = null): array
    {
        $path = $this->backups->path($backup->filename);

        if (! File::exists($path)) {
            throw new RuntimeException("ملف النسخة غير موجود على القرص: {$backup->filename}");
        }

        // نسخة الأمان أولًا. وإن تعذّر أخذها توقّفت الاستعادة: المضيّ بلا
        // شبكةٍ تحت الحبل ليس شجاعة، وإنما هو الخطأ الذي لا يُصلَح.
        $safety = $this->backups->run('pre_restore', $userId);

        if (! $safety->isCompleted()) {
            throw new RuntimeException('تعذّر أخذ نسخة أمان قبل الاستعادة فأُلغيت: '.$safety->error);
        }

        @set_time_limit((int) config('operations.backup.restore_timeout', 900));

        try {
            $result = $this->apply($path);
        } catch (Throwable $e) {
            Log::error('فشلت استعادة قاعدة البيانات', [
                'backup_id' => $backup->id,
                'safety_id' => $safety->id,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }

        // سجل النسخ نفسه جزءٌ ممّا استُعيد، فصفّ نسخة الأمان مُحي مع ما مُحي
        // — وملفها باقٍ على القرص لكن لا أثر له في الشاشة. ومن استعاد ملفًا
        // خاطئًا يحتاج زرَّ الرجوع ظاهرًا أمامه لا ملفًا يعرف عنه المسؤول
        // وحده. فيُعاد الصفّان — نسخة الأمان والنسخة المستعادة — إلى السجل.
        $safety = $this->relist($safety);
        $this->relist($backup);

        // الذاكرة المؤقتة تحمل صورة القاعدة قبل الاستعادة — إبقاؤها يعني
        // نظامًا يقرأ من ماضٍ لم يعد موجودًا.
        $this->flushCaches();

        Log::warning('استُعيدت قاعدة البيانات من نسخة', [
            'backup_id' => $backup->id,
            'file' => $backup->filename,
            'safety_id' => $safety->id,
            'user_id' => $userId,
        ] + $result);

        return $result + ['safety' => $safety->id];
    }

    /**
     * إعادة صفّ نسخةٍ إلى السجل بعد أن محته الاستعادة، إن لم يعد له أثر.
     *
     * المالك (created_by) لا يُنقل: المستخدم الذي أخذ النسخة قد لا يكون
     * موجودًا في القاعدة المستعادة، والإشارة إلى معرِّفٍ راحل تكسر المفتاح.
     */
    private function relist(Backup $backup): Backup
    {
        try {
            if ($existing = Backup::where('filename', $backup->filename)->first()) {
                return $existing;
            }

            return Backup::create([
                'filename' => $backup->filename,
                'disk' => $backup->disk ?? 'local',
                'size' => $backup->size ?? 0,
                'trigger' => $backup->trigger ?? 'manual',
                'driver' => $backup->driver,
                'method' => $backup->method,
                'duration_ms' => $backup->duration_ms ?? 0,
                'status' => 'completed',
            ]);
        } catch (Throwable $e) {
            Log::warning('تعذّرت إعادة صفّ النسخة إلى السجل بعد الاستعادة', [
                'file' => $backup->filename,
                'error' => $e->getMessage(),
            ]);

            return $backup;
        }
    }

    /**
     * تنفيذ الملف على القاعدة — ملف SQLite يُنسخ، وملف SQL يُنفَّذ عبارةً عبارة.
     *
     * @return array{statements:int, failed:int}
     */
    private function apply(string $path): array
    {
        $driver = DB::connection()->getDriverName();

        return $this->isSqliteFile($path)
            ? $this->copySqlite($path, $driver)
            : $this->runSql($path);
    }

    /**
     * ملف قاعدة SQLite ثنائي: القاعدة كلها فيه، فيحلّ محلّها نسخًا.
     *
     * @return array{statements:int, failed:int}
     */
    private function copySqlite(string $path, string $driver): array
    {
        if ($driver !== 'sqlite') {
            throw new RuntimeException("الملف المرفوع قاعدة SQLite والنظام يعمل على {$driver} — لا تصلح إحداهما مكان الأخرى.");
        }

        $database = DB::connection()->getDatabaseName();

        if ($database === ':memory:' || $database === '') {
            throw new RuntimeException('لا يمكن استعادة ملف على قاعدة في الذاكرة.');
        }

        [$source, $temporary] = $this->readable($path);

        // الاتصال يُغلق قبل الكتابة: ويندوز يمنع استبدال ملف مفتوح، ولينكس
        // يسمح به فيبقى المقبض معلّقًا على ملفٍ حُذف.
        DB::disconnect();

        try {
            File::copy($source, $database);
        } finally {
            if ($temporary) {
                File::delete($source);
            }

            DB::reconnect();
        }

        return ['statements' => 1, 'failed' => 0];
    }

    /**
     * تنفيذ ملف SQL عبارةً عبارة مع تعطيل فحص المفاتيح الأجنبية.
     *
     * الفحص يُعطَّل لأن الملف يكتب الجداول بترتيب سردها لا بترتيب تبعيتها:
     * جدول الحجوزات قد يُملأ قبل جدول العملاء الذي يشير إليه.
     *
     * @return array{statements:int, failed:int}
     */
    private function runSql(string $path): array
    {
        $driver = DB::connection()->getDriverName();
        $pdo = DB::connection()->getPdo();

        $executed = 0;
        $failed = 0;
        $firstError = null;

        $this->toggleForeignKeys($driver, false);

        try {
            foreach ($this->statements($path) as $statement) {
                try {
                    $pdo->exec($statement);
                    $executed++;
                } catch (Throwable $e) {
                    // عبارةٌ واحدة تفشل (منظور، امتياز ناقص، جدولٌ ليس لنا)
                    // لا تُسقط الاستعادة كلها — تُعدّ وتُسجَّل ويمضي الملف.
                    $failed++;
                    $firstError ??= $e->getMessage();

                    Log::warning('تعذّر تنفيذ عبارة أثناء الاستعادة', [
                        'error' => $e->getMessage(),
                        'sql' => mb_substr($statement, 0, 200),
                    ]);
                }
            }
        } finally {
            $this->toggleForeignKeys($driver, true);
        }

        if ($executed === 0) {
            throw new RuntimeException(trim('لم تُنفَّذ أي عبارة من الملف — تأكّد أنه نسخة SQL صحيحة. '.$firstError));
        }

        return ['statements' => $executed, 'failed' => $failed];
    }

    /**
     * قراءة الملف وتقطيعه عبارات عند الفواصل المنقوطة الواقعة خارج النصوص.
     *
     * التقطيع الساذج على «;» يكسر أي قيمة نصّية تحوي فاصلة منقوطة — وعنوانُ
     * قاعةٍ فيه «الرياض; حي النخيل» كافٍ ليقسم عبارة إدراج نصفين لا يُنفَّذ
     * أيٌّ منهما. فتُتتبَّع حال الاقتباس والتعليق حرفًا حرفًا.
     *
     * @return Generator<string>
     */
    private function statements(string $path): Generator
    {
        $handle = $this->open($path);

        $buffer = '';
        $quote = null;      // نوع الاقتباس المفتوح: ' أو " أو `
        $escaped = false;   // شرطةٌ مائلة معلّقة تُبطل مفعول الحرف التالي
        $inComment = false; // تعليق /* ... */ ممتد عبر الأسطر

        try {
            while (($line = fgets($handle)) !== false) {
                $length = strlen($line);
                $i = 0;

                while ($i < $length) {
                    $char = $line[$i];

                    if ($inComment) {
                        if ($char === '*' && ($line[$i + 1] ?? '') === '/') {
                            $inComment = false;
                            $i += 2;

                            continue;
                        }

                        $i++;

                        continue;
                    }

                    if ($quote !== null) {
                        $buffer .= $char;

                        if ($escaped) {
                            $escaped = false;
                        } elseif ($char === '\\' && $quote !== '`') {
                            $escaped = true;
                        } elseif ($char === $quote) {
                            // اقتباسٌ مُضاعف داخل النص لا يُنهيه.
                            if (($line[$i + 1] ?? '') === $quote) {
                                $buffer .= $quote;
                                $i++;
                            } else {
                                $quote = null;
                            }
                        }

                        $i++;

                        continue;
                    }

                    // تعليق سطري: -- أو # في أول عبارةٍ لم تبدأ بعد.
                    if (trim($buffer) === '' && ($char === '#' || ($char === '-' && ($line[$i + 1] ?? '') === '-'))) {
                        break;
                    }

                    if ($char === '/' && ($line[$i + 1] ?? '') === '*') {
                        $inComment = true;
                        $i += 2;

                        continue;
                    }

                    if ($char === "'" || $char === '"' || $char === '`') {
                        $quote = $char;
                        $buffer .= $char;
                        $i++;

                        continue;
                    }

                    if ($char === ';') {
                        if (trim($buffer) !== '') {
                            yield trim($buffer);
                        }

                        $buffer = '';
                        $i++;

                        continue;
                    }

                    $buffer .= $char;
                    $i++;
                }
            }

            if (trim($buffer) !== '') {
                yield trim($buffer);
            }
        } finally {
            fclose($handle);
        }
    }

    /**
     * فتح الملف للقراءة — المضغوط عبر غلاف zlib بلا فكِّ ضغطٍ إلى القرص.
     *
     * @return resource
     */
    private function open(string $path)
    {
        $handle = $this->isGzip($path) ? gzopen($path, 'rb') : fopen($path, 'rb');

        if (! $handle) {
            throw new RuntimeException("تعذّر فتح ملف النسخة للقراءة: {$path}");
        }

        return $handle;
    }

    /**
     * مسارٌ يصلح للنسخ المباشر، مع علامةٍ تقول أهو مؤقّت يُحذف بعد الاستعمال.
     *
     * @return array{0:string, 1:bool}
     */
    private function readable(string $path): array
    {
        if (! $this->isGzip($path)) {
            return [$path, false];
        }

        $target = $path.'.restore.tmp';

        $in = gzopen($path, 'rb');
        $out = fopen($target, 'wb');

        if (! $in || ! $out) {
            throw new RuntimeException("تعذّر فكّ ضغط ملف النسخة: {$path}");
        }

        while (! gzeof($in)) {
            fwrite($out, (string) gzread($in, 1 << 20));
        }

        gzclose($in);
        fclose($out);

        return [$target, true];
    }

    /**
     * أهو ملف مضغوط بـgzip؟ — الرقم السحري أصدق من الامتداد.
     */
    private function isGzip(string $path): bool
    {
        return $this->head($path, 2) === "\x1f\x8b";
    }

    /**
     * أهو ملف قاعدة SQLite ثنائي؟ — رأسه ثابتٌ معروف.
     */
    private function isSqliteFile(string $path): bool
    {
        if (! $this->isGzip($path)) {
            return str_starts_with($this->head($path, 16), "SQLite format 3\0");
        }

        // المضغوط يُقرأ منه رأسه وحده لا محتواه كله.
        $handle = gzopen($path, 'rb');

        if (! $handle) {
            return false;
        }

        $head = (string) gzread($handle, 16);
        gzclose($handle);

        return str_starts_with($head, "SQLite format 3\0");
    }

    private function head(string $path, int $bytes): string
    {
        $handle = fopen($path, 'rb');

        if (! $handle) {
            return '';
        }

        $head = (string) fread($handle, $bytes);
        fclose($handle);

        return $head;
    }

    /**
     * تعطيل فحص المفاتيح الأجنبية أثناء الاستعادة وإعادته بعدها.
     */
    private function toggleForeignKeys(string $driver, bool $on): void
    {
        try {
            match ($driver) {
                'mysql', 'mariadb' => DB::statement('SET FOREIGN_KEY_CHECKS='.($on ? '1' : '0')),
                'sqlite' => DB::statement('PRAGMA foreign_keys = '.($on ? 'ON' : 'OFF')),
                default => null,
            };
        } catch (Throwable $e) {
            Log::warning('تعذّر ضبط فحص المفاتيح الأجنبية أثناء الاستعادة', ['error' => $e->getMessage()]);
        }
    }

    /**
     * تفريغ ما يحمل صورةً قديمة للقاعدة بعد استبدالها.
     */
    private function flushCaches(): void
    {
        foreach (['cache:clear', 'view:clear'] as $command) {
            try {
                Artisan::call($command);
            } catch (Throwable $e) {
                Log::warning("تعذّر تنفيذ {$command} بعد الاستعادة", ['error' => $e->getMessage()]);
            }
        }
    }
}
