<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Support\Archive;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

/**
 * الأرشيف: كل ما حُذف من شاشات النظام، في مكان واحد.
 *
 * الحذف من الشاشات لا يُتلف السجل بل يرفعه من الاستعمال، فهذه الشاشة هي
 * الوجه الآخر لتلك العملية: تُظهر المرفوع، وتُعيده إلى الخدمة، وتُتلفه
 * إتلافًا نهائيًا حين يُقرَّر ذلك عمدًا — والقرار الأخير محكومٌ بصلاحية
 * مستقلة (archive.delete) لأنه الوحيد الذي لا رجعة فيه.
 *
 * القراءة تمرّ على جداول كثيرة، ولا يجمعها استعلام واحد لأن كل نوع في
 * جدوله. فتُقرأ الأنواع مستقلةً ثم تُدمج وتُرتَّب بتاريخ الحذف. وحدُّ
 * القراءة لكل نوع يمنع صفحةً تقرأ مليون صف لتعرض ثلاثين.
 */
class ArchiveController extends Controller
{
    /** أقصى ما يُقرأ من كل نوع قبل الدمج والترتيب. */
    private const PER_TYPE_CAP = 500;

    private const PER_PAGE = 30;

    public function index(Request $request): Response
    {
        $type = $request->string('type')->toString();
        $types = array_key_exists($type, Archive::TYPES) ? [$type] : array_keys(Archive::TYPES);

        $rows = collect($types)
            ->flatMap(fn (string $t) => $this->rowsOf($t, $request))
            ->sortByDesc('deleted_at')
            ->values();

        $page = max(1, $request->integer('page', 1));
        $paginator = new LengthAwarePaginator(
            $this->withActors($rows->slice(($page - 1) * self::PER_PAGE, self::PER_PAGE)->values()),
            $rows->count(),
            self::PER_PAGE,
            $page,
            ['path' => $request->url(), 'query' => $request->query()],
        );

        return Inertia::render('admin/system/Archive', [
            'records' => $paginator,
            'filters' => $request->only(['type', 'search', 'from', 'to']),
            'types' => $this->typeOptions(),
            'stats' => [
                'total' => $rows->count(),
                'types' => collect($types)->filter(fn ($t) => $rows->contains('type', $t))->count(),
                'today' => $rows->where('deleted_at', '>=', now()->startOfDay()->format('Y-m-d H:i:s'))->count(),
            ],
        ]);
    }

    /**
     * إعادة السجل إلى الخدمة.
     */
    public function restore(Request $request, string $type, int $id): RedirectResponse
    {
        if (! array_key_exists($type, Archive::TYPES)) {
            abort(404);
        }

        $record = Archive::query($type)->findOrFail($id);
        $record->restore();

        return back()->with('success', 'تم استرجاع '.Archive::label($type).' «'.Archive::nameOf($record).'»');
    }

    /**
     * الإتلاف النهائي — لا رجعة فيه.
     *
     * السجل المرتبط بغيره تمنعه قاعدة البيانات، ورسالة الخطأ الخام لا تعني
     * المستخدم شيئًا، فتُترجَم إلى سبب مفهوم: المحذوف مرتبط بسجلات أخرى.
     */
    public function destroy(Request $request, string $type, int $id): RedirectResponse
    {
        if (! array_key_exists($type, Archive::TYPES)) {
            abort(404);
        }

        $record = Archive::query($type)->findOrFail($id);
        $name = Archive::nameOf($record);

        try {
            $record->forceDelete();
        } catch (QueryException) {
            return back()->with('error', 'تعذّر الحذف النهائي — السجل مرتبط بسجلات أخرى في النظام. أبقِه في الأرشيف.');
        }

        return back()->with('success', 'تم حذف '.Archive::label($type).' «'.$name.'» نهائيًا');
    }

    /**
     * صفوف نوعٍ واحد بعد تطبيق المرشّحات.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function rowsOf(string $type, Request $request): Collection
    {
        $search = $request->string('search')->toString();

        return Archive::query($type)
            ->when($request->date('from'), fn ($q, $d) => $q->whereDate('deleted_at', '>=', $d))
            ->when($request->date('to'), fn ($q, $d) => $q->whereDate('deleted_at', '<=', $d))
            ->when($search !== '', function ($query) use ($type, $search) {
                $columns = Archive::searchableColumns($type);

                // نوعٌ بلا عمود اسم لا يُطابق بحثًا نصيًا — ولا يُعرض صفًّا
                // لا علاقة له بما كُتب في المرشّح.
                if ($columns === []) {
                    $query->whereRaw('1 = 0');

                    return;
                }

                $query->where(function ($sub) use ($columns, $search) {
                    foreach ($columns as $column) {
                        $sub->orWhere($column, 'like', "%{$search}%");
                    }
                });
            })
            ->orderByDesc('deleted_at')
            ->limit(self::PER_TYPE_CAP)
            ->get()
            ->map(fn (Model $record) => [
                'type' => $type,
                'type_label' => Archive::label($type),
                'group' => Archive::TYPES[$type]['group'],
                'id' => $record->getKey(),
                'name' => Archive::nameOf($record),
                'deleted_at' => $record->{$record->getDeletedAtColumn()}?->format('Y-m-d H:i:s'),
                'created_at' => $record->created_at?->format('Y-m-d'),
                'model' => $record::class,
            ]);
    }

    /**
     * من حذف كل سجل — يُقرأ من السجل الرقابي، لا من الجدول نفسه.
     *
     * الجداول لا تحفظ فاعل الحذف، والسجل الرقابي يحفظه. فالربط هنا يجيب عن
     * السؤال الأول الذي يُطرح أمام صفٍّ مؤرشف: من حذفه؟
     *
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function withActors(Collection $rows): array
    {
        if ($rows->isEmpty()) {
            return [];
        }

        $actors = AuditLog::query()
            ->where('event', 'deleted')
            ->whereIn('auditable_type', $rows->pluck('model')->unique()->all())
            ->whereIn('auditable_id', $rows->pluck('id')->unique()->all())
            ->orderBy('id')
            ->get(['auditable_type', 'auditable_id', 'actor_name', 'created_at'])
            // الأحدث يغلب: السجل قد يكون حُذف واسترجع ثم حُذف ثانية.
            ->keyBy(fn (AuditLog $log) => $log->auditable_type.'|'.$log->auditable_id);

        return $rows->map(function (array $row) use ($actors) {
            $log = $actors->get($row['model'].'|'.$row['id']);

            return [...$row, 'deleted_by' => $log?->actor_name];
        })->all();
    }

    /**
     * الأنواع مع عدد المؤرشف في كل منها — الأنواع الخالية تُعرض بصفر لا تُخفى،
     * حتى يعرف المستخدم أن النوع مشمول بالأرشفة أصلًا.
     *
     * @return list<array<string, mixed>>
     */
    private function typeOptions(): array
    {
        return collect(Archive::TYPES)
            ->map(fn (array $meta, string $type) => [
                'key' => $type,
                'label' => $meta['label'],
                'group' => $meta['group'],
                'count' => Archive::query($type)->count(),
            ])
            ->values()
            ->all();
    }
}
