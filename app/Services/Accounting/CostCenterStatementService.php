<?php

namespace App\Services\Accounting;

use App\Models\CostCenter;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use Illuminate\Database\Eloquent\Builder;

/**
 * Cost centre movements and the accounts behind its profit.
 *
 * CostCenter::profitability() gives one number per centre; this opens it up,
 * and costs one query for a whole list instead of one query per row.
 */
class CostCenterStatementService
{
    /**
     * The centre's movements over a period, effective entries only, in date order.
     *
     * @return array{
     *     rows: list<array<string, mixed>>,
     *     total_debit: float,
     *     total_credit: float,
     *     revenue: float,
     *     expense: float,
     *     profit: float,
     * }
     */
    public function statementFor(CostCenter $center, ?string $from = null, ?string $to = null): array
    {
        $lines = $this->linesQuery($center->id, $from, $to)
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->with(['account:id,code,name,type', 'entry:id,number,entry_date,description,source'])
            ->orderBy('journal_entries.entry_date')
            ->orderBy('journal_lines.id')
            ->select('journal_lines.*')
            ->get();

        $rows = $lines->map(fn (JournalLine $line) => [
            'date' => $line->entry?->entry_date?->toDateString(),
            'entry_number' => $line->entry?->number,
            'source_label' => $line->entry?->sourceLabel(),
            'label' => $line->description ?: (string) $line->entry?->description,
            'account' => $line->account ? $line->account->code.' — '.$line->account->name : '—',
            'account_type' => $line->account?->type,
            'debit' => (float) $line->debit,
            'credit' => (float) $line->credit,
        ])->values()->all();

        return [
            'rows' => $rows,
            'total_debit' => round($lines->sum(fn (JournalLine $l) => (float) $l->debit), 2),
            'total_credit' => round($lines->sum(fn (JournalLine $l) => (float) $l->credit), 2),
            ...$center->profitability($from, $to),
        ];
    }

    /**
     * Revenue and expense per account — which line lifted the profit, which sank it.
     *
     * @return list<array{code: string, name: string, type: string, amount: float}>
     */
    public function breakdownFor(CostCenter $center, ?string $from = null, ?string $to = null): array
    {
        return $this->linesQuery($center->id, $from, $to)
            ->join('accounts', 'accounts.id', '=', 'journal_lines.account_id')
            ->whereIn('accounts.type', ['revenue', 'expense'])
            ->groupBy('accounts.id', 'accounts.code', 'accounts.name', 'accounts.type')
            ->selectRaw('accounts.code AS code, accounts.name AS name, accounts.type AS type, SUM(journal_lines.debit) AS d, SUM(journal_lines.credit) AS c')
            ->get()
            ->map(fn ($row) => [
                'code' => (string) $row->code,
                'name' => (string) $row->name,
                'type' => (string) $row->type,
                'amount' => $this->net((string) $row->type, (float) $row->d, (float) $row->c),
            ])
            ->sortByDesc('amount')
            ->values()
            ->all();
    }

    /**
     * Profitability for many centres in one query, not one per list row.
     *
     * @param  list<int>  $centerIds
     * @return array<int, array{revenue: float, expense: float, profit: float}>
     */
    public function profitabilityForMany(array $centerIds, ?string $from = null, ?string $to = null): array
    {
        $totals = array_fill_keys($centerIds, ['revenue' => 0.0, 'expense' => 0.0, 'profit' => 0.0]);

        if ($centerIds === []) {
            return $totals;
        }

        $rows = $this->linesQuery($centerIds, $from, $to)
            ->join('accounts', 'accounts.id', '=', 'journal_lines.account_id')
            ->whereIn('accounts.type', ['revenue', 'expense'])
            ->groupBy('journal_lines.cost_center_id', 'accounts.type')
            ->selectRaw('journal_lines.cost_center_id AS center_id, accounts.type AS type, SUM(journal_lines.debit) AS d, SUM(journal_lines.credit) AS c')
            ->get();

        foreach ($rows as $row) {
            $id = (int) $row->center_id;

            if (! isset($totals[$id])) {
                continue;
            }

            $totals[$id][(string) $row->type === 'revenue' ? 'revenue' : 'expense']
                += $this->net((string) $row->type, (float) $row->d, (float) $row->c);
        }

        foreach ($totals as $id => $total) {
            $totals[$id] = [
                'revenue' => round($total['revenue'], 2),
                'expense' => round($total['expense'], 2),
                'profit' => round($total['revenue'] - $total['expense'], 2),
            ];
        }

        return $totals;
    }

    /**
     * Booking deposits each centre holds on the given day — collected, not yet earned.
     *
     * A deposit is a liability until the booking closes, so it never shows as
     * revenue; without this figure a hall that took 12,500 in deposits reads
     * as having taken nothing. A balance, so it runs from the start of the books.
     *
     * @param  list<int>  $centerIds
     * @return array<int, float>
     */
    public function depositsHeldForMany(array $centerIds, ?string $to = null): array
    {
        $held = array_fill_keys($centerIds, 0.0);

        if ($centerIds === []) {
            return $held;
        }

        $rows = $this->linesQuery($centerIds, null, $to)
            ->join('accounts', 'accounts.id', '=', 'journal_lines.account_id')
            ->where('accounts.code', Ledger::UNEARNED_REVENUE)
            ->groupBy('journal_lines.cost_center_id')
            ->selectRaw('journal_lines.cost_center_id AS center_id, SUM(journal_lines.credit) - SUM(journal_lines.debit) AS balance')
            ->get();

        foreach ($rows as $row) {
            if (isset($held[(int) $row->center_id])) {
                $held[(int) $row->center_id] = round((float) $row->balance, 2);
            }
        }

        return $held;
    }

    /**
     * Parent centre id => the centres it answers for: itself, and its unit's rooms.
     *
     * A room's centre is never a row of its own on the report, so whatever it
     * earned has to be read through its unit or it is read nowhere.
     *
     * @param  list<int>  $centerIds
     * @return array<int, list<int>>
     */
    public function reportingMap(array $centerIds): array
    {
        $map = [];

        foreach ($centerIds as $id) {
            $map[(int) $id] = [(int) $id];
        }

        if ($map === []) {
            return $map;
        }

        $units = CostCenter::query()
            ->whereIn('id', array_keys($map))
            ->whereNotNull('unit_id')
            ->pluck('unit_id', 'id');

        if ($units->isEmpty()) {
            return $map;
        }

        // Every parent's rooms in one query, so a long list still costs two.
        // A room since removed keeps its place here: the unit earned that
        // money, and dropping it would lose a closed month's revenue.
        $rooms = CostCenter::query()
            ->join('unit_sections', 'unit_sections.id', '=', 'cost_centers.unit_section_id')
            ->whereIn('unit_sections.unit_id', $units->values())
            ->orderBy('unit_sections.sort_order')
            ->orderBy('unit_sections.id')
            ->get(['cost_centers.id', 'unit_sections.unit_id']);

        foreach ($units as $centerId => $unitId) {
            foreach ($rooms->where('unit_id', $unitId) as $room) {
                $map[(int) $centerId][] = (int) $room->id;
            }
        }

        return $map;
    }

    /**
     * Profitability with each unit's rooms folded into it — two queries, not one per row.
     *
     * @param  list<int>  $centerIds
     * @return array<int, array{revenue: float, expense: float, profit: float}>
     */
    public function profitabilityRolledUp(array $centerIds, ?string $from = null, ?string $to = null): array
    {
        $map = $this->reportingMap($centerIds);
        $flat = $this->profitabilityForMany($this->centresOf($map), $from, $to);

        $totals = [];

        foreach ($map as $parent => $ids) {
            $revenue = 0.0;
            $expense = 0.0;

            foreach ($ids as $id) {
                $revenue += $flat[$id]['revenue'] ?? 0.0;
                $expense += $flat[$id]['expense'] ?? 0.0;
            }

            $totals[$parent] = [
                'revenue' => round($revenue, 2),
                'expense' => round($expense, 2),
                'profit' => round($revenue - $expense, 2),
            ];
        }

        return $totals;
    }

    /**
     * The same folding for the deposits balance.
     *
     * @param  list<int>  $centerIds
     * @return array<int, float>
     */
    public function depositsHeldRolledUp(array $centerIds, ?string $to = null): array
    {
        $map = $this->reportingMap($centerIds);
        $flat = $this->depositsHeldForMany($this->centresOf($map), $to);

        $held = [];

        foreach ($map as $parent => $ids) {
            $held[$parent] = round(array_sum(array_map(fn ($id) => $flat[$id] ?? 0.0, $ids)), 2);
        }

        return $held;
    }

    /**
     * Revenue and expense posted with no cost centre at all.
     *
     * Money that is in the books but on no screen: it is counted in the trial
     * balance and in nothing else, so a profit read centre by centre quietly
     * misses it. Surfaced as its own row, it is a number to drive to zero
     * rather than a discrepancy to discover.
     *
     * @return array{revenue: float, expense: float, profit: float}
     */
    public function unattributed(?string $from = null, ?string $to = null): array
    {
        $rows = JournalLine::query()
            ->whereNull('journal_lines.cost_center_id')
            ->whereHas('entry', fn ($q) => $q->whereIn('status', JournalEntry::EFFECTIVE_STATUSES)
                ->when($from, fn ($sub) => $sub->whereDate('entry_date', '>=', $from))
                ->when($to, fn ($sub) => $sub->whereDate('entry_date', '<=', $to)))
            ->join('accounts', 'accounts.id', '=', 'journal_lines.account_id')
            ->whereIn('accounts.type', ['revenue', 'expense'])
            ->groupBy('accounts.type')
            ->selectRaw('accounts.type AS type, SUM(journal_lines.debit) AS d, SUM(journal_lines.credit) AS c')
            ->get();

        $revenue = 0.0;
        $expense = 0.0;

        foreach ($rows as $row) {
            $net = $this->net((string) $row->type, (float) $row->d, (float) $row->c);
            (string) $row->type === 'revenue' ? $revenue += $net : $expense += $net;
        }

        return [
            'revenue' => round($revenue, 2),
            'expense' => round($expense, 2),
            'profit' => round($revenue - $expense, 2),
        ];
    }

    /**
     * Every centre named in a reporting map, parents and rooms, each once.
     *
     * @param  array<int, list<int>>  $map
     * @return list<int>
     */
    private function centresOf(array $map): array
    {
        $ids = [];

        foreach ($map as $group) {
            foreach ($group as $id) {
                $ids[(int) $id] = true;
            }
        }

        return array_map('intval', array_keys($ids));
    }

    /**
     * Revenue grows on the credit side, expense on the debit side.
     */
    private function net(string $type, float $debit, float $credit): float
    {
        return round($type === 'revenue' ? $credit - $debit : $debit - $credit, 2);
    }

    /**
     * @param  int|list<int>  $centerIds
     */
    private function linesQuery(int|array $centerIds, ?string $from, ?string $to): Builder
    {
        return JournalLine::query()
            ->whereIn('journal_lines.cost_center_id', (array) $centerIds)
            ->whereHas('entry', fn ($q) => $q->whereIn('status', JournalEntry::EFFECTIVE_STATUSES)
                ->when($from, fn ($sub) => $sub->whereDate('entry_date', '>=', $from))
                ->when($to, fn ($sub) => $sub->whereDate('entry_date', '<=', $to)));
    }
}
