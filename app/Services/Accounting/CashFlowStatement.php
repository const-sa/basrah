<?php

namespace App\Services\Accounting;

use App\Models\Account;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\PaymentMethodAccount;
use App\Models\Treasury;
use App\Models\Voucher;
use Illuminate\Support\Collection;

/**
 * قائمة التدفقات النقدية — كل ريال دخل الخزائن أو خرج منها في الفترة.
 *
 * Read from the ledger, not from the vouchers table. Money reaches the cash
 * box and the bank through several doors: receipt vouchers (a pools contract's
 * deposit among them), booking payments, POS sales, expenses, payroll. Only
 * the first kind is a voucher, so a statement built on vouchers would miss
 * most of the till. Every one of those doors posts a journal entry against a
 * cash account, and that is what is read here.
 *
 * Each entry touching cash is split by its counter lines: whatever the entry
 * credits outside cash is where the money came from, whatever it debits is
 * where it went. Because the entry balances, those counter amounts add up to
 * exactly the cash movement — nothing is estimated or apportioned. An entry
 * whose cash lines cancel out (cash box to bank) is a transfer between
 * treasuries, not a flow, and is reported apart.
 *
 * The closing balance of each cash account is its opening plus its movement,
 * the same figure the chart of accounts shows for it — so the statement
 * reconciles to the books by construction.
 */
class CashFlowStatement
{
    /** The group holding the cash box, the bank and anything added under them. */
    public const CASH_GROUP = '1100';

    /** The fixed-assets group — buying or selling one is an investing flow. */
    public const FIXED_ASSETS_GROUP = '1400';

    /** Beyond this many cash lines the detail list is cut; the totals never are. */
    public const MOVEMENTS_LIMIT = 2000;

    public const SECTIONS = [
        'operating' => 'الأنشطة التشغيلية',
        'investing' => 'الأنشطة الاستثمارية',
        'financing' => 'الأنشطة التمويلية',
    ];

    /** Finer than the entry's source: a contract's receipt is named as one. */
    private const CONTRACT_RECEIPTS = 'contract_receipt';

    private const CONTRACT_RECEIPTS_LABEL = 'سندات قبض العقود (عربون ودفعات)';

    /** @var array<int, int>|null fixed-asset account ids, read once per statement */
    private ?array $investing = null;

    /**
     * @return array<string, mixed>
     */
    public function build(string $from, string $to): array
    {
        $cashIds = $this->cashAccountIds();

        $accounts = Account::whereIn('id', $cashIds)->orderBy('code')->get(['id', 'code', 'name', 'type', 'opening_balance']);
        $treasuries = Treasury::whereIn('account_id', $cashIds)->get(['name', 'account_id'])->groupBy('account_id');

        $lines = $this->periodLines($cashIds, $from, $to);
        $byEntry = $lines->groupBy('journal_entry_id');
        $contractVouchers = $this->contractVoucherIds($lines);

        $sections = collect(self::SECTIONS)->map(fn () => ['in' => [], 'out' => []])->all();
        $sources = [];
        $transfers = 0.0;
        $totalIn = 0.0;
        $totalOut = 0.0;

        foreach ($byEntry as $entryLines) {
            $cashDelta = round($entryLines->whereIn('account_id', $cashIds)->sum(fn ($l) => (float) $l->debit - (float) $l->credit), 2);

            if (abs($cashDelta) < 0.01) {
                // Money moved between two cash accounts — the till is no richer.
                $transfers += round($entryLines->whereIn('account_id', $cashIds)->sum(fn ($l) => (float) $l->debit), 2);

                continue;
            }

            if ($cashDelta > 0) {
                $totalIn += $cashDelta;
            } else {
                $totalOut += -$cashDelta;
            }

            $first = $entryLines->first();
            $sourceKey = $this->sourceKey($first, $contractVouchers);
            $sources[$sourceKey] ??= ['key' => $sourceKey, 'label' => $this->sourceLabel($sourceKey), 'inflow' => 0.0, 'outflow' => 0.0];
            $sources[$sourceKey][$cashDelta > 0 ? 'inflow' : 'outflow'] += abs($cashDelta);

            // What the entry credited outside cash is where the money came
            // from; what it debited is where it went.
            $counter = $entryLines->reject(fn ($l) => in_array((int) $l->account_id, $cashIds, true))->groupBy('account_id');

            foreach ($counter as $accountLines) {
                $amount = round($accountLines->sum(fn ($l) => (float) $l->credit - (float) $l->debit), 2);

                if (abs($amount) < 0.01) {
                    continue;
                }

                $line = $accountLines->first();
                $section = $this->sectionOf((int) $line->account_id, $line->account_type);
                $side = $amount > 0 ? 'in' : 'out';
                $code = $line->account_code;

                $sections[$section][$side][$code] ??= ['code' => $code, 'name' => $line->account_name, 'amount' => 0.0];
                $sections[$section][$side][$code]['amount'] += abs($amount);
            }
        }

        $accountRows = $accounts->map(function (Account $account) use ($lines, $from, $treasuries) {
            $own = $lines->where('account_id', $account->id);
            $inflow = round((float) $own->sum(fn ($l) => (float) $l->debit), 2);
            $outflow = round((float) $own->sum(fn ($l) => (float) $l->credit), 2);
            $opening = $this->openingBalance($account, $from);

            return [
                'id' => $account->id,
                'code' => $account->code,
                'name' => $account->name,
                'treasuries' => $treasuries->get($account->id)?->pluck('name')->values()->all() ?? [],
                'opening' => $opening,
                'inflow' => $inflow,
                'outflow' => $outflow,
                'closing' => round($opening + $inflow - $outflow, 2),
            ];
        })->values();

        $opening = round((float) $accountRows->sum('opening'), 2);
        $net = round($totalIn - $totalOut, 2);

        return [
            'sections' => collect($sections)->map(fn (array $s, string $key) => [
                'key' => $key,
                'label' => self::SECTIONS[$key],
                'inflows' => $this->rows($s['in']),
                'outflows' => $this->rows($s['out']),
                'net' => round(array_sum(array_column($s['in'], 'amount')) - array_sum(array_column($s['out'], 'amount')), 2),
            ])->values()->all(),
            'sources' => collect($sources)
                ->map(fn (array $s) => [...$s, 'inflow' => round($s['inflow'], 2), 'outflow' => round($s['outflow'], 2)])
                ->sortByDesc(fn (array $s) => $s['inflow'] + $s['outflow'])
                ->values()->all(),
            'accounts' => $accountRows->all(),
            'movements' => $this->movements($lines, $cashIds, $contractVouchers),
            'movements_truncated' => $lines->whereIn('account_id', $cashIds)->count() > self::MOVEMENTS_LIMIT,
            'transfers' => round($transfers, 2),
            'totals' => [
                'opening' => $opening,
                'inflow' => round($totalIn, 2),
                'outflow' => round($totalOut, 2),
                'net' => $net,
                'closing' => round($opening + $net, 2),
            ],
        ];
    }

    /**
     * Every account money can sit in: the cash group and what is under it,
     * any account a treasury is tied to, and any account a cash-paying method
     * is set to deposit into — wherever in the chart it was placed.
     *
     * @return list<int>
     */
    public function cashAccountIds(): array
    {
        $ids = $this->postableUnder(self::CASH_GROUP)
            ->merge(Treasury::whereNotNull('account_id')->pluck('account_id'))
            ->merge(
                PaymentMethodAccount::query()
                    ->join('payment_methods', 'payment_methods.id', '=', 'payment_method_accounts.payment_method_id')
                    ->where('payment_methods.is_credit', false)
                    ->pluck('payment_method_accounts.account_id'),
            );

        // A credit method that is not money, or an account outside assets, never
        // counts as cash however it was mapped.
        return Account::whereIn('id', $ids->unique()->all())
            ->where('type', 'asset')
            ->where('is_group', false)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();
    }

    /**
     * All lines of every effective entry in the period that touches cash.
     *
     * @param  list<int>  $cashIds
     */
    private function periodLines(array $cashIds, string $from, string $to): Collection
    {
        if ($cashIds === []) {
            return collect();
        }

        $entryIds = JournalLine::query()
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->whereIn('journal_entries.status', JournalEntry::EFFECTIVE_STATUSES)
            ->whereDate('journal_entries.entry_date', '>=', $from)
            ->whereDate('journal_entries.entry_date', '<=', $to)
            ->whereIn('journal_lines.account_id', $cashIds)
            ->distinct()
            ->pluck('journal_lines.journal_entry_id');

        return JournalLine::query()
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->join('accounts', 'accounts.id', '=', 'journal_lines.account_id')
            ->whereIn('journal_lines.journal_entry_id', $entryIds)
            ->orderBy('journal_entries.entry_date')
            ->orderBy('journal_entries.id')
            ->orderBy('journal_lines.id')
            ->get([
                'journal_lines.journal_entry_id', 'journal_lines.account_id', 'journal_lines.debit', 'journal_lines.credit',
                'journal_entries.number AS entry_number', 'journal_entries.entry_date', 'journal_entries.description AS entry_description',
                'journal_entries.source', 'journal_entries.reference_type', 'journal_entries.reference_id',
                'accounts.code AS account_code', 'accounts.name AS account_name', 'accounts.type AS account_type',
            ]);
    }

    /**
     * The receipt vouchers among these entries that were drawn on a contract.
     *
     * @return array<int, true>
     */
    private function contractVoucherIds(Collection $lines): array
    {
        $ids = $lines->where('reference_type', Voucher::class)->pluck('reference_id')->unique()->filter()->values();

        if ($ids->isEmpty()) {
            return [];
        }

        return Voucher::withTrashed()->whereIn('id', $ids)->whereNotNull('contract_id')
            ->pluck('id')->mapWithKeys(fn ($id) => [(int) $id => true])->all();
    }

    /**
     * @param  array<int, true>  $contractVouchers
     */
    private function sourceKey(object $line, array $contractVouchers): string
    {
        if ($line->reference_type === Voucher::class && isset($contractVouchers[(int) $line->reference_id])) {
            return self::CONTRACT_RECEIPTS;
        }

        return (string) $line->source;
    }

    private function sourceLabel(string $key): string
    {
        return $key === self::CONTRACT_RECEIPTS
            ? self::CONTRACT_RECEIPTS_LABEL
            : (JournalEntry::SOURCES[$key] ?? $key);
    }

    private function sectionOf(int $accountId, string $type): string
    {
        $this->investing ??= $this->postableUnder(self::FIXED_ASSETS_GROUP)->flip()->all();

        return match (true) {
            isset($this->investing[$accountId]) => 'investing',
            $type === 'equity' => 'financing',
            default => 'operating',
        };
    }

    /**
     * The account's balance the day before the period opens.
     */
    private function openingBalance(Account $account, string $from): float
    {
        $before = JournalLine::query()
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->where('journal_lines.account_id', $account->id)
            ->whereIn('journal_entries.status', JournalEntry::EFFECTIVE_STATUSES)
            ->whereDate('journal_entries.entry_date', '<', $from)
            ->selectRaw('COALESCE(SUM(journal_lines.debit), 0) AS debit, COALESCE(SUM(journal_lines.credit), 0) AS credit')
            ->first();

        return round((float) $account->opening_balance + (float) $before->debit - (float) $before->credit, 2);
    }

    /**
     * Each cash line of the period, newest last, as the till saw it.
     *
     * @param  list<int>  $cashIds
     * @param  array<int, true>  $contractVouchers
     * @return list<array<string, mixed>>
     */
    private function movements(Collection $lines, array $cashIds, array $contractVouchers): array
    {
        return $lines
            ->filter(fn ($l) => in_array((int) $l->account_id, $cashIds, true))
            ->take(self::MOVEMENTS_LIMIT)
            ->map(fn ($l) => [
                'date' => substr((string) $l->entry_date, 0, 10),
                'entry_number' => $l->entry_number,
                'description' => $l->entry_description,
                'source_label' => $this->sourceLabel($this->sourceKey($l, $contractVouchers)),
                'account_id' => (int) $l->account_id,
                'account' => $l->account_name,
                'inflow' => round((float) $l->debit, 2),
                'outflow' => round((float) $l->credit, 2),
            ])
            ->values()
            ->all();
    }

    /**
     * @param  array<string, array{code: string, name: string, amount: float}>  $rows
     * @return list<array{code: string, name: string, amount: float}>
     */
    private function rows(array $rows): array
    {
        return collect($rows)
            ->map(fn (array $r) => [...$r, 'amount' => round($r['amount'], 2)])
            ->sortBy('code')
            ->values()
            ->all();
    }

    /**
     * Postable accounts anywhere beneath a group, however deep.
     *
     * @return Collection<int, int>
     */
    private function postableUnder(string $groupCode): Collection
    {
        $parents = Account::withTrashed()->where('code', $groupCode)->pluck('id');
        $found = collect();

        while ($parents->isNotEmpty()) {
            $children = Account::withTrashed()->whereIn('parent_id', $parents)->get(['id', 'is_group']);
            $found = $found->merge($children->where('is_group', false)->pluck('id'));
            $parents = $children->where('is_group', true)->pluck('id');
        }

        return $found->map(fn ($id) => (int) $id)->unique()->values();
    }
}
