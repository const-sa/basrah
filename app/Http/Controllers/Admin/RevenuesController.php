<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\CostCenter;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * شاشة الإيرادات — ما دخل المؤسسة موزّعًا على نشاطاتها.
 *
 * الإيراد يُقرأ من الدفاتر لا من الشاشات: سطرُ قيدٍ مرحَّل على حسابٍ إيرادي
 * هو الحقيقة التي تتفق عليها الفاتورة والحجز والسند. وقراءته من الحجز أو
 * الفاتورة مباشرة تحتسب ما بيع مرتين وتُسقط ما دخل من بابٍ ثالث.
 *
 * والنطاق (قاعات/شاليهات/مسابح) ليس عمودًا في جدول بل خلاصة مركز التكلفة:
 * مركز الوحدة يحمل نوعها، ومركز القسم يحمل ترميزه. فالتصنيف يتبع البيانات
 * القائمة ولا يطلب من المحاسب وسمًا إضافيًا عند كل قيد.
 */
class RevenuesController extends Controller
{
    /**
     * نطاقات الإيراد كما يراها المشغّل لا كما تراها شجرة الحسابات.
     */
    public const SEGMENTS = [
        'halls' => 'القاعات',
        'chalets' => 'الشاليهات',
        'pools' => 'المسابح',
        'other' => 'إيرادات أخرى',
    ];

    /**
     * ترميز قسم المسابح في جدول الأقسام — عليه يقع إيراد البيع والصيانة.
     */
    private const POOLS_DEPARTMENT = 'POOLS';

    /**
     * خريطة «مركز التكلفة ← نطاقه»، تُحسب مرة واحدة في الطلب.
     *
     * @var array<int, string>|null
     */
    private ?array $centerSegments = null;

    public function index(Request $request): Response
    {
        $filters = $this->filters($request);

        return Inertia::render('admin/accounting/Revenues', [
            'lines' => $this->rows($filters),
            'filters' => $filters,
            'stats' => $this->stats($filters),
            'bySegment' => $this->bySegment($filters),
            'byCenter' => $this->byCenter($filters),
            'byAccount' => $this->byAccount($filters),
            'segments' => collect(self::SEGMENTS)->map(fn ($l, $k) => ['key' => $k, 'label' => $l])->values(),
            'centers' => $this->centers(),
            'accounts' => Account::where('type', 'revenue')
                ->where('is_group', false)
                ->orderBy('code')
                ->get(['id', 'code', 'name']),
            'sources' => collect(JournalEntry::SOURCES)->map(fn ($l, $k) => ['key' => $k, 'label' => $l])->values(),
        ]);
    }

    /**
     * تصدير المعروض بمرشّحاته.
     */
    public function export(Request $request): StreamedResponse
    {
        $filters = $this->filters($request);
        $filename = 'revenues-'.now()->format('Y-m-d-His').'.csv';

        return response()->streamDownload(function () use ($filters) {
            $out = fopen('php://output', 'w');

            // BOM حتى يفتح إكسل العربية بترميزها الصحيح بدل رموز مبهمة.
            fwrite($out, "\xEF\xBB\xBF");

            fputcsv($out, ['التاريخ', 'رقم القيد', 'المصدر', 'الحساب', 'النطاق', 'الوحدة / القسم', 'البيان', 'المبلغ']);

            $this->selected($filters)
                ->orderBy('journal_entries.entry_date')
                ->orderBy('journal_lines.id')
                ->chunk(500, function (Collection $chunk) use ($out) {
                    foreach ($chunk as $line) {
                        $row = $this->row($line);

                        fputcsv($out, [
                            $row['entry_date'],
                            $row['number'],
                            $row['source_label'],
                            $row['account_code'].' — '.$row['account'],
                            $row['segment_label'],
                            $row['center'] ?? '—',
                            $row['description'] ?? '—',
                            $row['amount'],
                        ]);
                    }
                });

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * @return array<string, mixed>
     */
    private function filters(Request $request): array
    {
        $segment = $request->string('segment')->toString();

        return [
            'from' => $request->date('from')?->toDateString() ?? now()->startOfMonth()->toDateString(),
            'to' => $request->date('to')?->toDateString() ?? now()->toDateString(),
            'segment' => array_key_exists($segment, self::SEGMENTS) ? $segment : null,
            'cost_center_id' => $request->integer('cost_center_id') ?: null,
            'account_id' => $request->integer('account_id') ?: null,
            'source' => $request->string('source')->toString() ?: null,
            'search' => $request->string('search')->toString() ?: null,
        ];
    }

    /**
     * سطور الإيراد المرحَّلة بعد تطبيق المرشّحات.
     *
     * @param  array<string, mixed>  $filters
     * @return Builder<JournalLine>
     */
    private function filtered(array $filters): Builder
    {
        return JournalLine::query()
            ->join('accounts', 'accounts.id', '=', 'journal_lines.account_id')
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->leftJoin('cost_centers', 'cost_centers.id', '=', 'journal_lines.cost_center_id')
            ->leftJoin('units', 'units.id', '=', 'cost_centers.unit_id')
            // A centre may hold a section instead of a unit, and a section is
            // only named by the unit it belongs to: «شاليه ١» names nothing on
            // a screen listing every unit's rooms at once.
            ->leftJoin('unit_sections', 'unit_sections.id', '=', 'cost_centers.unit_section_id')
            ->leftJoin('units as section_units', 'section_units.id', '=', 'unit_sections.unit_id')
            ->leftJoin('departments', 'departments.id', '=', 'cost_centers.department_id')
            ->where('accounts.type', 'revenue')
            // المعكوس يبقى محتسبًا لأن قيده المضاد محتسبٌ معه، فيتصافيان.
            ->whereIn('journal_entries.status', JournalEntry::EFFECTIVE_STATUSES)
            ->when($filters['from'] ?? null, fn ($q, $d) => $q->whereDate('journal_entries.entry_date', '>=', $d))
            ->when($filters['to'] ?? null, fn ($q, $d) => $q->whereDate('journal_entries.entry_date', '<=', $d))
            ->when($filters['segment'] ?? null, fn ($q, $s) => $this->scopeSegment($q, $s))
            ->when($filters['cost_center_id'] ?? null, fn ($q, $id) => $q->where('journal_lines.cost_center_id', $id))
            ->when($filters['account_id'] ?? null, fn ($q, $id) => $q->where('accounts.id', $id))
            ->when($filters['source'] ?? null, fn ($q, $s) => $q->where('journal_entries.source', $s))
            ->when($filters['search'] ?? null, fn ($q, $term) => $q->where(
                fn ($sub) => $sub->where('journal_entries.description', 'like', "%{$term}%")
                    ->orWhere('journal_lines.description', 'like', "%{$term}%")
                    ->orWhere('journal_entries.number', 'like', "%{$term}%"),
            ));
    }

    /**
     * الاستعلام نفسه بأعمدته المعروضة — يشترك فيه الجدول والتصدير.
     *
     * @param  array<string, mixed>  $filters
     * @return Builder<JournalLine>
     */
    private function selected(array $filters): Builder
    {
        return $this->filtered($filters)->select([
            'journal_lines.id',
            'journal_lines.cost_center_id',
            'journal_lines.debit',
            'journal_lines.credit',
            'journal_lines.description as line_description',
            'journal_entries.id as entry_id',
            'journal_entries.number',
            'journal_entries.entry_date',
            'journal_entries.description as entry_description',
            'journal_entries.source',
            'accounts.code as account_code',
            'accounts.name as account_name',
            // اسم الوحدة أو القسم يسبق اسم المركز: إعادة تسمية القاعة يجب
            // أن تظهر في التقرير، واسم المركز يبقى كما كُتب يوم أُنشئ.
            'units.name as unit_name',
            'unit_sections.name as section_name',
            'section_units.name as section_unit_name',
            'departments.name as department_name',
            'cost_centers.name as center_name',
        ]);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    private function rows(array $filters): LengthAwarePaginator
    {
        return $this->selected($filters)
            ->orderByDesc('journal_entries.entry_date')
            ->orderByDesc('journal_lines.id')
            ->paginate(25)
            ->withQueryString()
            ->through(fn (JournalLine $line) => $this->row($line));
    }

    /**
     * @return array<string, mixed>
     */
    private function row(JournalLine $line): array
    {
        $segment = $this->segmentOfCenter($line->cost_center_id !== null ? (int) $line->cost_center_id : null);

        return [
            'id' => $line->id,
            'entry_id' => $line->entry_id,
            'number' => $line->number,
            'entry_date' => (string) $line->entry_date,
            'source' => $line->source,
            'source_label' => JournalEntry::SOURCES[$line->source] ?? $line->source,
            'account_code' => $line->account_code,
            'account' => $line->account_name,
            'cost_center_id' => $line->cost_center_id !== null ? (int) $line->cost_center_id : null,
            'center' => $this->centerName($line->unit_name, $line->section_unit_name, $line->section_name, $line->department_name, $line->center_name),
            'segment' => $segment,
            'segment_label' => self::SEGMENTS[$segment],
            'description' => $line->line_description ?: $line->entry_description,
            // الإيراد يزيد بالدائن، والاسترداد والمرتجع يردّانه بالمدين.
            'amount' => round((float) $line->credit - (float) $line->debit, 2),
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    private function stats(array $filters): array
    {
        $total = $this->sum($this->filtered($filters));
        $count = $this->filtered($filters)->count();

        // إيراد الشهر يبقى على نطاق الشاشة المختار: من ينظر إلى القاعات
        // يسأل عن شهر القاعات لا عن شهر المؤسسة كلها.
        $month = $this->sum($this->filtered([
            ...$filters,
            'from' => now()->startOfMonth()->toDateString(),
            'to' => now()->toDateString(),
        ]));

        return [
            'total' => $total,
            'count' => $count,
            'month' => $month,
            'average' => $count > 0 ? round($total / $count, 2) : 0.0,
        ];
    }

    /**
     * حصّة كل نطاق من الإيراد.
     *
     * تُحسب بلا مرشّح النطاق والوحدة عمدًا: البطاقات هنا أداة تنقّل قبل أن
     * تكون تقريرًا، فلو تبعت المرشّح لأظهرت نطاقًا واحدًا وأخفت ما يُقارَن به.
     *
     * @param  array<string, mixed>  $filters
     * @return list<array<string, mixed>>
     */
    private function bySegment(array $filters): array
    {
        $rows = $this->filtered([...$filters, 'segment' => null, 'cost_center_id' => null])
            ->groupBy('journal_lines.cost_center_id')
            ->selectRaw('journal_lines.cost_center_id AS cost_center_id,
                COUNT(*) AS movements,
                SUM(journal_lines.credit) - SUM(journal_lines.debit) AS amount')
            ->get();

        $totals = collect(self::SEGMENTS)->map(fn () => ['amount' => 0.0, 'count' => 0])->all();

        foreach ($rows as $row) {
            $segment = $this->segmentOfCenter($row->cost_center_id !== null ? (int) $row->cost_center_id : null);
            $totals[$segment]['amount'] += (float) $row->amount;
            $totals[$segment]['count'] += (int) $row->movements;
        }

        $total = round(array_sum(array_column($totals, 'amount')), 2);

        return collect($totals)
            ->map(fn (array $t, string $key) => [
                'key' => $key,
                'label' => self::SEGMENTS[$key],
                'amount' => round($t['amount'], 2),
                'count' => $t['count'],
                'share' => $total > 0 ? round($t['amount'] / $total * 100, 1) : 0.0,
            ])
            ->values()
            ->all();
    }

    /**
     * توزيع الإيراد على الوحدات والأقسام — «أيّ قاعة جاءت بالمال».
     *
     * @param  array<string, mixed>  $filters
     * @return list<array<string, mixed>>
     */
    private function byCenter(array $filters): array
    {
        $rows = $this->filtered($filters)
            ->groupBy('journal_lines.cost_center_id', 'units.name', 'unit_sections.name', 'section_units.name',
                'departments.name', 'cost_centers.name')
            ->selectRaw('journal_lines.cost_center_id AS cost_center_id,
                units.name AS unit_name, unit_sections.name AS section_name, section_units.name AS section_unit_name,
                departments.name AS department_name, cost_centers.name AS center_name,
                COUNT(*) AS movements,
                SUM(journal_lines.credit) - SUM(journal_lines.debit) AS amount')
            ->get();

        $total = round((float) $rows->sum('amount'), 2);

        return $rows
            ->map(function ($r) use ($total) {
                $segment = $this->segmentOfCenter($r->cost_center_id !== null ? (int) $r->cost_center_id : null);

                return [
                    'cost_center_id' => $r->cost_center_id !== null ? (int) $r->cost_center_id : null,
                    'name' => $this->centerName($r->unit_name, $r->section_unit_name, $r->section_name, $r->department_name, $r->center_name) ?? 'بلا مركز تكلفة',
                    'segment' => $segment,
                    'segment_label' => self::SEGMENTS[$segment],
                    'count' => (int) $r->movements,
                    'amount' => round((float) $r->amount, 2),
                    'share' => $total > 0 ? round((float) $r->amount / $total * 100, 1) : 0.0,
                ];
            })
            ->sortByDesc('amount')
            ->values()
            ->all();
    }

    /**
     * توزيع الإيراد على حساباته — حجوزات، مبيعات، خدمات إضافية.
     *
     * @param  array<string, mixed>  $filters
     * @return list<array<string, mixed>>
     */
    private function byAccount(array $filters): array
    {
        $rows = $this->filtered($filters)
            ->groupBy('accounts.id', 'accounts.code', 'accounts.name')
            ->selectRaw('accounts.code AS code, accounts.name AS name,
                COUNT(*) AS movements,
                SUM(journal_lines.credit) - SUM(journal_lines.debit) AS amount')
            ->get();

        $total = round((float) $rows->sum('amount'), 2);

        return $rows
            ->map(fn ($r) => [
                'code' => $r->code,
                'name' => $r->name,
                'count' => (int) $r->movements,
                'amount' => round((float) $r->amount, 2),
                'share' => $total > 0 ? round((float) $r->amount / $total * 100, 1) : 0.0,
            ])
            ->sortByDesc('amount')
            ->values()
            ->all();
    }

    /**
     * @param  Builder<JournalLine>  $query
     */
    private function sum(Builder $query): float
    {
        $row = $query
            ->selectRaw('SUM(journal_lines.credit) - SUM(journal_lines.debit) AS amount')
            ->first();

        return round((float) ($row->amount ?? 0), 2);
    }

    /**
     * قصر الاستعلام على مراكز تكلفة النطاق.
     *
     * «إيرادات أخرى» تشمل ما لا مركز له: قيدٌ إيرادي بلا مركز تكلفة موجودٌ
     * في الدفاتر، وإسقاطه من كل النطاقات يجعل مجموعها أقلّ من الإجمالي.
     *
     * @param  Builder<JournalLine>  $query
     * @return Builder<JournalLine>
     */
    private function scopeSegment(Builder $query, string $segment): Builder
    {
        $ids = array_keys(array_filter($this->segmentMap(), fn (string $s) => $s === $segment));

        if ($segment === 'other') {
            return $query->where(fn ($q) => $q
                ->whereNull('journal_lines.cost_center_id')
                ->orWhereIn('journal_lines.cost_center_id', $ids));
        }

        return $query->whereIn('journal_lines.cost_center_id', $ids);
    }

    /**
     * مراكز التكلفة مصنّفةً بنطاقاتها — تملأ قائمة «حدّد القاعة أو الشاليه».
     *
     * @return list<array<string, mixed>>
     */
    private function centers(): array
    {
        return CostCenter::with(['unit:id,name,type', 'section:id,name,unit_id', 'section.unit:id,name,type', 'department:id,name,code'])
            ->where('is_active', true)
            ->get()
            ->map(fn (CostCenter $c) => [
                'id' => $c->id,
                'name' => $this->centerName(
                    $c->unit?->name,
                    $c->section?->unit?->name,
                    $c->section?->name,
                    $c->department?->name,
                    $c->name,
                ),
                'segment' => $this->segmentOfCenter($c->id),
            ])
            ->sortBy('name')
            ->values()
            ->all();
    }

    /**
     * @return array<int, string>
     */
    private function segmentMap(): array
    {
        if ($this->centerSegments !== null) {
            return $this->centerSegments;
        }

        return $this->centerSegments = CostCenter::with(['unit:id,type', 'section:id,unit_id', 'section.unit:id,type', 'department:id,code'])
            ->get()
            ->mapWithKeys(function (CostCenter $c) {
                // A room belongs to the activity its unit belongs to. Reading
                // the unit through the section keeps a room out of «إيرادات
                // أخرى», where it would drop off the chalets total.
                $type = $c->unit?->type ?? $c->section?->unit?->type;

                return [$c->id => match (true) {
                    $type === 'hall' => 'halls',
                    $type === 'chalet' => 'chalets',
                    $c->department?->code === self::POOLS_DEPARTMENT => 'pools',
                    default => 'other',
                }];
            })
            ->all();
    }

    private function segmentOfCenter(?int $costCenterId): string
    {
        return $this->segmentMap()[$costCenterId] ?? 'other';
    }

    /**
     * ما يُسمّى به مركز التكلفة على الشاشة.
     *
     * الوحدة أولًا، ثم القسم مسبوقًا بوحدته، ثم قسم النشاط. واسم المركز
     * المحفوظ آخرها: يبقى كما كُتب يوم أُنشئ، فإعادة تسمية الشاليه يجب أن
     * تظهر في التقرير لا أن يظل يحمل اسمه القديم.
     */
    private function centerName(
        ?string $unit,
        ?string $sectionUnit,
        ?string $section,
        ?string $department,
        ?string $center,
    ): ?string {
        if ($unit !== null) {
            return $unit;
        }

        if ($section !== null) {
            return $sectionUnit !== null ? $sectionUnit.' — '.$section : $section;
        }

        return $department ?? $center;
    }
}
