<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * السجل الرقابي (§14): استعراض العمليات الحساسة وتصديرها.
 *
 * الشاشة للقراءة فقط عمدًا — سجلٌّ رقابي يُحرَّر من داخل النظام لا يشهد
 * على شيء، ولذلك لا مسارات إنشاء أو تعديل أو حذف هنا.
 */
class AuditLogController extends Controller
{
    public function index(Request $request): Response
    {
        $logs = $this->filtered($request)
            ->with('user:id,name')
            ->latest('id')
            ->paginate(30)
            ->withQueryString()
            ->through(fn (AuditLog $log) => $this->row($log));

        return Inertia::render('admin/system/AuditLog', [
            'logs' => $logs,
            'filters' => $request->only(['event', 'type', 'user_id', 'from', 'to', 'search']),
            'events' => collect(AuditLog::EVENTS)->map(fn ($l, $k) => ['key' => $k, 'label' => $l])->values(),
            'types' => collect(AuditLog::SUBJECTS)->map(fn ($l, $k) => ['key' => $k, 'label' => $l])->values(),
            // المستخدمون الذين لهم أثرٌ فعلًا — قائمة كل المستخدمين تُطيل
            // المرشّح بأسماء لا سجل لها.
            'users' => User::whereIn('id', AuditLog::query()->distinct()->pluck('user_id')->filter())
                ->orderBy('name')->get(['id', 'name']),
            'stats' => [
                'total' => (clone $this->filtered($request))->count(),
                'today' => (clone $this->filtered($request))->whereDate('created_at', now()->toDateString())->count(),
                'deletes' => (clone $this->filtered($request))->where('event', 'deleted')->count(),
            ],
        ]);
    }

    /**
     * تصدير المعروض — بنفس المرشّحات، لا الجدول كاملًا.
     */
    public function export(Request $request): StreamedResponse
    {
        $filename = 'audit-log-'.now()->format('Y-m-d-His').'.csv';

        return response()->streamDownload(function () use ($request) {
            $out = fopen('php://output', 'w');

            // BOM حتى يفتح إكسل العربية بترميزها الصحيح بدل رموز مبهمة.
            fwrite($out, "\xEF\xBB\xBF");

            fputcsv($out, ['التاريخ والوقت', 'المستخدم', 'العملية', 'النوع', 'السجل', 'التغييرات', 'المسار', 'IP']);

            $this->filtered($request)->with('user:id,name')->latest('id')->chunk(500, function ($chunk) use ($out) {
                foreach ($chunk as $log) {
                    fputcsv($out, [
                        $log->created_at?->format('Y-m-d H:i:s'),
                        $log->actor_name ?? $log->user?->name,
                        $log->eventLabel(),
                        $log->subjectLabel(),
                        $log->auditable_label,
                        $this->changesText($log),
                        $log->url,
                        $log->ip_address,
                    ]);
                }
            });

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * @return Builder<AuditLog>
     */
    private function filtered(Request $request)
    {
        return AuditLog::query()
            ->when($request->string('event')->toString(), fn ($q, $e) => $q->where('event', $e))
            ->ofType($request->string('type')->toString() ?: null)
            ->when($request->integer('user_id'), fn ($q, $id) => $q->where('user_id', $id))
            ->when($request->date('from'), fn ($q, $d) => $q->whereDate('created_at', '>=', $d))
            ->when($request->date('to'), fn ($q, $d) => $q->whereDate('created_at', '<=', $d))
            ->when($request->string('search')->toString(), fn ($q, $term) => $q->where(
                fn ($sub) => $sub->where('auditable_label', 'like', "%{$term}%")
                    ->orWhere('actor_name', 'like', "%{$term}%"),
            ));
    }

    /**
     * @return array<string, mixed>
     */
    private function row(AuditLog $log): array
    {
        return [
            'id' => $log->id,
            'created_at' => $log->created_at?->format('Y-m-d H:i:s'),
            'actor_name' => $log->actor_name ?? $log->user?->name ?? 'النظام',
            'user_id' => $log->user_id,
            'event' => $log->event,
            'event_label' => $log->eventLabel(),
            'subject_label' => $log->subjectLabel(),
            'auditable_label' => $log->auditable_label,
            'auditable_id' => $log->auditable_id,
            'ip_address' => $log->ip_address,
            'url' => $log->url,
            'method' => $log->method,
            // التعديل وحده يستحق عرض ما قبل وما بعد؛ الإنشاء والحذف يُعرض
            // فيهما السجل كاملًا وهو ضجيج في جدول يُتصفَّح.
            'changes' => $log->event === 'updated' ? $this->visibleChanges($log) : [],
            'changes_count' => $log->event === 'updated' ? count($this->visibleChanges($log)) : 0,
        ];
    }

    /**
     * التغييرات بعد استبعاد ما لا يعني المراجع.
     *
     * @return list<array<string, mixed>>
     */
    private function visibleChanges(AuditLog $log): array
    {
        return array_values(array_filter(
            $log->changes(),
            fn (array $c) => ! in_array($c['field'], ['updated_at', 'created_at'], true),
        ));
    }

    private function changesText(AuditLog $log): string
    {
        if ($log->event !== 'updated') {
            return '—';
        }

        return collect($this->visibleChanges($log))
            ->map(fn (array $c) => $c['label'].': '.$this->scalar($c['old']).' ← '.$this->scalar($c['new']))
            ->implode(' | ');
    }

    private function scalar(mixed $value): string
    {
        return match (true) {
            is_null($value) => '—',
            is_bool($value) => $value ? 'نعم' : 'لا',
            is_array($value) => json_encode($value, JSON_UNESCAPED_UNICODE),
            default => (string) $value,
        };
    }
}
