<?php

namespace App\Services\Accounting;

use App\Models\Account;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Support\ActivitySegment;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * كشف حساب إيراد واحد — حركته سطرًا سطرًا برصيدٍ متدرّج.
 *
 * شاشة الإيرادات تجيب «كم دخل ومن أين»، وهذا يجيب سؤالًا آخر: «ماذا جرى على
 * حساب إيرادات القاعات بالضبط؟» — بما قبل الفترة، ثم كل حركة عليه بتاريخها
 * وقيدها ومركزها، ثم ما استقرّ عليه في آخرها. وهو الشكل الذي يُسلَّم للمحاسب
 * أو يُطابَق به، لا جدولُ مجاميع.
 *
 * الإيراد طبيعته دائنة، فالرصيد هنا = دائن − مدين. والمرتجع والاسترداد يظهران
 * مدينَين فينقصان الرصيد كما ينقصان الدخل فعلًا.
 */
class RevenueStatementService
{
    public function __construct(private readonly ActivitySegment $segments) {}

    /**
     * @param  array{from: string, to: string, cost_center_id?: int|null, segment?: string|null, source?: string|null}  $filters
     * @return array{
     *     opening: float,
     *     rows: list<array<string, mixed>>,
     *     total_debit: float,
     *     total_credit: float,
     *     net: float,
     *     closing: float,
     * }
     */
    public function statementFor(Account $account, array $filters): array
    {
        $opening = $this->opening($account, $filters);

        $lines = $this->query($account->id, $filters)
            ->orderBy('journal_entries.entry_date')
            ->orderBy('journal_lines.id')
            ->get();

        $running = $opening;
        $rows = [];

        foreach ($lines as $line) {
            $amount = round((float) $line->credit - (float) $line->debit, 2);
            $running = round($running + $amount, 2);

            $rows[] = [
                'id' => (int) $line->id,
                'entry_id' => (int) $line->entry_id,
                'date' => (string) $line->entry_date,
                'number' => $line->number,
                'source' => $line->source,
                'source_label' => JournalEntry::SOURCES[$line->source] ?? $line->source,
                'center' => ActivitySegment::nameFrom(
                    $line->unit_name,
                    $line->section_unit_name,
                    $line->section_name,
                    $line->department_name,
                    $line->center_name,
                ),
                'segment_label' => ActivitySegment::label($this->segments->of(
                    $line->cost_center_id !== null ? (int) $line->cost_center_id : null,
                )),
                'label' => $line->line_description ?: (string) $line->entry_description,
                'debit' => round((float) $line->debit, 2),
                'credit' => round((float) $line->credit, 2),
                'balance' => $running,
            ];
        }

        $debit = round($lines->sum(fn ($l) => (float) $l->debit), 2);
        $credit = round($lines->sum(fn ($l) => (float) $l->credit), 2);

        return [
            'opening' => $opening,
            'rows' => $rows,
            'total_debit' => $debit,
            'total_credit' => $credit,
            'net' => round($credit - $debit, 2),
            'closing' => $running,
        ];
    }

    /**
     * توزيع حركة الفترة على مراكز التكلفة — أي قاعة أو شاليه صنع الرقم.
     *
     * @param  array<string, mixed>  $filters
     * @return list<array<string, mixed>>
     */
    public function byCenter(Account $account, array $filters): array
    {
        $rows = $this->base($account->id, $filters)
            ->leftJoin('cost_centers', 'cost_centers.id', '=', 'journal_lines.cost_center_id')
            ->leftJoin('units', 'units.id', '=', 'cost_centers.unit_id')
            ->leftJoin('unit_sections', 'unit_sections.id', '=', 'cost_centers.unit_section_id')
            ->leftJoin('units as section_units', 'section_units.id', '=', 'unit_sections.unit_id')
            ->leftJoin('departments', 'departments.id', '=', 'cost_centers.department_id')
            ->groupBy('journal_lines.cost_center_id', 'units.name', 'unit_sections.name',
                'section_units.name', 'departments.name', 'cost_centers.name')
            ->select([
                'journal_lines.cost_center_id',
                'units.name as unit_name',
                'unit_sections.name as section_name',
                'section_units.name as section_unit_name',
                'departments.name as department_name',
                'cost_centers.name as center_name',
                DB::raw('COUNT(*) AS movements'),
                DB::raw('SUM(journal_lines.credit) - SUM(journal_lines.debit) AS amount'),
            ])
            ->get();

        $total = round((float) $rows->sum('amount'), 2);

        return $rows
            ->map(fn ($r) => [
                'cost_center_id' => $r->cost_center_id !== null ? (int) $r->cost_center_id : null,
                'name' => ActivitySegment::nameFrom($r->unit_name, $r->section_unit_name, $r->section_name,
                    $r->department_name, $r->center_name) ?? 'بلا مركز تكلفة',
                'count' => (int) $r->movements,
                'amount' => round((float) $r->amount, 2),
                'share' => $total > 0 ? round((float) $r->amount / $total * 100, 1) : 0.0,
            ])
            ->sortByDesc('amount')
            ->values()
            ->all();
    }

    /**
     * ما قبل الفترة — رصيد يُفتح به الكشف بدل أن يبدأ من صفر كاذب.
     *
     * الرصيد الافتتاحي المسجَّل على الحساب يدخل فقط في الكشف غير المقيَّد
     * بمركز أو نشاط: هو رقمٌ على الحساب كله لا على قاعةٍ بعينها، وإضافته إلى
     * كشف قاعة واحدة تنسب إليها مالًا لم تكسبه.
     *
     * @param  array<string, mixed>  $filters
     */
    private function opening(Account $account, array $filters): float
    {
        $scoped = ($filters['cost_center_id'] ?? null) !== null || ($filters['segment'] ?? null) !== null;

        $row = $this->base($account->id, [...$filters, 'from' => null, 'to' => null])
            ->whereDate('journal_entries.entry_date', '<', $filters['from'])
            ->select([
                DB::raw('SUM(journal_lines.credit) AS c'),
                DB::raw('SUM(journal_lines.debit) AS d'),
            ])
            ->first();

        $movement = round((float) ($row->c ?? 0) - (float) ($row->d ?? 0), 2);

        return round($movement + ($scoped ? 0.0 : (float) $account->opening_balance), 2);
    }

    /**
     * سطور الحساب بأعمدتها المعروضة.
     *
     * @param  array<string, mixed>  $filters
     * @return Builder<JournalLine>
     */
    private function query(int $accountId, array $filters)
    {
        return $this->base($accountId, $filters)
            ->leftJoin('cost_centers', 'cost_centers.id', '=', 'journal_lines.cost_center_id')
            ->leftJoin('units', 'units.id', '=', 'cost_centers.unit_id')
            ->leftJoin('unit_sections', 'unit_sections.id', '=', 'cost_centers.unit_section_id')
            ->leftJoin('units as section_units', 'section_units.id', '=', 'unit_sections.unit_id')
            ->leftJoin('departments', 'departments.id', '=', 'cost_centers.department_id')
            ->select([
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
                'units.name as unit_name',
                'unit_sections.name as section_name',
                'section_units.name as section_unit_name',
                'departments.name as department_name',
                'cost_centers.name as center_name',
            ]);
    }

    /**
     * الأساس المشترك: سطور حساب واحد من قيودٍ ذات أثر، بمرشّحات الشاشة.
     *
     * @param  array<string, mixed>  $filters
     * @return Builder<JournalLine>
     */
    private function base(int $accountId, array $filters)
    {
        return JournalLine::query()
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->where('journal_lines.account_id', $accountId)
            // المسوّدة ليست في الدفاتر، والملغى خرج منها — والمعكوس يبقى
            // لأن قيده المضاد معه فيتصافيان أمام العين لا خلفها.
            ->whereIn('journal_entries.status', JournalEntry::EFFECTIVE_STATUSES)
            ->when($filters['from'] ?? null, fn ($q, $d) => $q->whereDate('journal_entries.entry_date', '>=', $d))
            ->when($filters['to'] ?? null, fn ($q, $d) => $q->whereDate('journal_entries.entry_date', '<=', $d))
            ->when($filters['cost_center_id'] ?? null, fn ($q, $id) => $q->where('journal_lines.cost_center_id', $id))
            ->when($filters['source'] ?? null, fn ($q, $s) => $q->where('journal_entries.source', $s))
            ->when($filters['segment'] ?? null, fn ($q, $s) => $this->scopeSegment($q, $s));
    }

    /**
     * قصر الكشف على مراكز نشاطٍ واحد — و«أخرى» تضمّ ما لا مركز له.
     *
     * @param  Builder<JournalLine>  $query
     */
    private function scopeSegment($query, string $segment)
    {
        $ids = $this->segments->centerIds($segment);

        if ($segment === ActivitySegment::OTHER) {
            return $query->where(fn ($q) => $q
                ->whereNull('journal_lines.cost_center_id')
                ->orWhereIn('journal_lines.cost_center_id', $ids));
        }

        return $query->whereIn('journal_lines.cost_center_id', $ids);
    }
}
