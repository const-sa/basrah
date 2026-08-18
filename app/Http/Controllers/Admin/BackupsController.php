<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Backup;
use App\Services\BackupService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * النسخ الاحتياطي (§18): استعراض النسخ، أخذ نسخة الآن، تنزيلها أو حذفها.
 *
 * الشاشة تُظهر أيضًا حال الجدولة، لأن أخطر عطلٍ في النسخ الاحتياطي هو
 * الصامت: خادمٌ بلا سطر cron لا يشتكي، ويُكتشف نقصه يوم يُحتاج إلى النسخة.
 * فإن تأخّرت آخر نسخة عن يومين قالت الشاشة ذلك صراحة.
 */
class BackupsController extends Controller
{
    public function __construct(private readonly BackupService $backups) {}

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

    public function destroy(Backup $backup): RedirectResponse
    {
        $this->backups->delete($backup);

        return back()->with('success', 'تم حذف النسخة الاحتياطية');
    }
}
