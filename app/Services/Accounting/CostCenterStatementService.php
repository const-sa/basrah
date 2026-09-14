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
