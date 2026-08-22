<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Backup;
use App\Services\BackupService;
use App\Services\RestoreService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

/**
 * النسخ الاحتياطي (§18): استعراض النسخ، أخذ نسخة الآن، تنزيلها أو حذفها،
 * ورفع ملف قاعدة من الخارج واستعادته.
 *
 * الشاشة تُظهر أيضًا حال الجدولة، لأن أخطر عطلٍ في النسخ الاحتياطي هو
 * الصامت: خادمٌ بلا سطر cron لا يشتكي، ويُكتشف نقصه يوم يُحتاج إلى النسخة.
 * فإن تأخّرت آخر نسخة عن يومين قالت الشاشة ذلك صراحة.
 *
 * والاستعادة تُفصَل بصلاحية عن التنزيل: من يقرأ النسخة ليس بالضرورة من
 * يُؤذن له أن يكتب بها فوق قاعدةٍ عاملة.
 */
class BackupsController extends Controller
{
    public function __construct(
        private readonly BackupService $backups,
        private readonly RestoreService $restores,
    ) {}

    public function index(): Response
    {
        $latest = Backup::completed()->latest('id')->first();

        return Inertia::render('admin/system/Backups', [
            'backups' => Backup::with('creator:id,name')
                ->latest('id')
                ->paginate(20)
                ->through(fn (Backup $b) => [
                    'id' => $b->id,
                    'filename' => $b->filename,
                    'size' => $b->size,
                    'size_label' => $b->sizeLabel(),
                    'status' => $b->status,
                    'status_label' => $b->statusLabel(),
                    'trigger_label' => $b->triggerLabel(),
                    'method' => $b->method,
                    'driver' => $b->driver,
                    'duration' => round($b->duration_ms / 1000, 1),
                    'error' => $b->error,
                    'created_at' => $b->created_at?->format('Y-m-d H:i'),
                    'creator' => $b->creator?->name,
                    // الصف بلا ملفه لا يُنزَّل — والشاشة تقول ذلك بدل خطأ 404.
                    'exists' => $b->isCompleted() && File::exists($this->backups->path($b->filename)),
                ]),
            'stats' => [
                'total' => Backup::completed()->count(),
                'failed' => Backup::where('status', 'failed')->count(),
                'last_at' => $latest?->created_at?->format('Y-m-d H:i'),
                'last_size' => $latest?->sizeLabel(),
                // «متأخّرة» = مضى على آخر نسخة أكثر من يومين، أو لا نسخة أصلًا.
                'is_stale' => ! $latest || $latest->created_at->lt(now()->subDays(2)),
                'keep' => (int) config('operations.backup.keep', 14),
                'schedule' => '02:00',
                'cron_hint' => '* * * * * cd '.base_path().' && php artisan schedule:run >> /dev/null 2>&1',
                'driver' => DB::connection()->getDriverName(),
                // الحدّ المعروض هو ما يقبله الخادم فعلًا، لا ما نتمنّاه:
                // طلبٌ يتجاوز حدود PHP يُرفض قبل أن يصل إلى لارافيل، فيرى
                // المستخدم صفحة خطأ لا رسالة تحقّق.
                'upload_max_mb' => $this->uploadLimitMb(),
                'extensions' => RestoreService::EXTENSIONS,
            ],
        ]);
    }

    /**
     * أخذ نسخة الآن — تُؤخذ في الطلب نفسه لا في الطابور، ليرى الطالب نتيجتها.
     */
    public function store(Request $request): RedirectResponse
    {
        $backup = $this->backups->run('manual', $request->user()?->id);

        if (! $backup->isCompleted()) {
            return back()->with('error', 'تعذّر أخذ النسخة: '.$backup->error);
        }

        return back()->with('success', "تمت النسخة الاحتياطية ({$backup->sizeLabel()})");
    }

    public function download(Backup $backup): BinaryFileResponse
    {
        abort_unless($backup->isCompleted(), 404);

        $path = $this->backups->path($backup->filename);

        abort_unless(File::exists($path), 404);

        return response()->download($path);
    }

    /**
     * تنزيل قاعدة البيانات الآن: تُؤخذ نسخة طازجة ويُرسَل ملفها في الرد نفسه.
     *
     * الفرق عن download() أن هذه لا تنتظر نسخةً سابقة — من يريد نقل القاعدة
     * إلى خادمٍ آخر يريدها بحالها هذه اللحظة، لا بحال ليلة أمس. والنسخة
     * تُسجَّل في السجل كغيرها لأنها نسخةٌ حقيقية على القرص.
     */
    public function export(Request $request): BinaryFileResponse|RedirectResponse
    {
        $backup = $this->backups->run('manual', $request->user()?->id);

        if (! $backup->isCompleted()) {
            return back()->with('error', 'تعذّر تجهيز ملف القاعدة: '.$backup->error);
        }

        $path = $this->backups->path($backup->filename);

        abort_unless(File::exists($path), 404);

        return response()->download($path);
    }

    /**
     * رفع ملف قاعدة من الخارج — يُخزَّن نسخةً، ويُستعاد فورًا إن طُلب ذلك.
     *
     * الرفع والاستعادة فعلان منفصلان عمدًا: من ينقل نسخةً إلى الخادم ليحفظها
     * ليس بالضرورة من يريد الكتابة بها فوق القاعدة العاملة الآن. فمن أراد
     * الاثنين معًا أشّر على الخانة صراحةً.
     */
    public function upload(Request $request): RedirectResponse
    {
        $request->validate([
            'file' => [
                'required', 'file',
                'max:'.($this->uploadLimitMb() * 1024),
                'extensions:'.implode(',', RestoreService::EXTENSIONS),
            ],
            'restore' => ['boolean'],
        ], [], [
            'file' => 'ملف النسخة',
        ]);

        $backup = $this->backups->adopt($request->file('file'), $request->user()?->id);

        if (! $request->boolean('restore')) {
            return back()->with('success', "تم رفع الملف ({$backup->sizeLabel()}) وحُفظ ضمن النسخ. استعِده متى شئت من زر «استعادة».");
        }

        // الاستعادة مباشرةً بعد الرفع تحتاج صلاحيتها هي، لا صلاحية الرفع.
        if (! $request->user()?->hasPermission('backups.restore')) {
            return back()->with('error', 'رُفع الملف وحُفظ، لكن ليس لديك صلاحية الاستعادة.');
        }

        return $this->runRestore($backup, $request->user()?->id);
    }

    /**
     * استعادة القاعدة من نسخة محفوظة.
     */
    public function restore(Request $request, Backup $backup): RedirectResponse
    {
        abort_unless($backup->isCompleted(), 404);

        return $this->runRestore($backup, $request->user()?->id);
    }

    /**
     * تشغيل الاستعادة وترجمة نتيجتها إلى رسالةٍ تُقرأ.
     */
    private function runRestore(Backup $backup, ?int $userId): RedirectResponse
    {
        try {
            $result = $this->restores->restore($backup, $userId);
        } catch (Throwable $e) {
            return back()->with('error', 'فشلت الاستعادة: '.$e->getMessage());
        }

        $message = "تمت استعادة القاعدة من {$backup->filename} ({$result['statements']} عبارة).";

        // العبارات المتعثّرة تُذكر لا تُبتلع: قاعدةٌ استُعيدت ناقصةً وقيل عنها
        // «تمت» أسوأ من استعادةٍ فشلت وقيل إنها فشلت.
        if ($result['failed'] > 0) {
            $message .= " تعذّر تنفيذ {$result['failed']} عبارة — راجع سجل الأخطاء.";
        }

        $message .= " وأُخذت نسخة أمان قبلها (#{$result['safety']}).";

        return back()->with($result['failed'] > 0 ? 'warning' : 'success', $message);
    }

    public function destroy(Backup $backup): RedirectResponse
    {
        $this->backups->delete($backup);

        return back()->with('success', 'تم حذف النسخة الاحتياطية');
    }

    /**
     * أقصى حجم رفعٍ مقبول فعلًا: الأصغر من إعداد التطبيق وحدود PHP.
     */
    private function uploadLimitMb(): int
    {
        $limits = [(int) config('operations.backup.upload_max_mb', 256)];

        foreach (['upload_max_filesize', 'post_max_size'] as $directive) {
            $bytes = $this->toBytes((string) ini_get($directive));

            if ($bytes > 0) {
                $limits[] = (int) floor($bytes / 1048576);
            }
        }

        return max(1, min($limits));
    }

    /**
     * تحويل قيمة ini المختصرة (128M) إلى بايتات.
     */
    private function toBytes(string $value): int
    {
        $value = trim($value);

        if ($value === '') {
            return 0;
        }

        $number = (int) $value;

        return match (strtolower(substr($value, -1))) {
            'g' => $number * 1073741824,
            'm' => $number * 1048576,
            'k' => $number * 1024,
            default => $number,
        };
    }
}
