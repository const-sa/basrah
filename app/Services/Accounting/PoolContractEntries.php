<?php

namespace App\Services\Accounting;

use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\Sale;
use App\Models\Voucher;
use App\Support\ActivitySegment;
use Illuminate\Database\Eloquent\Builder;

/**
 * The revenue lines the pools earned on contract work, not over the counter.
 *
 * Both used to credit «إيرادات المبيعات», so the pools' revenue could not be
 * told apart from what the shop sold. Contract work is any entry posted on a
 * contract, a receipt voucher written on one, or an invoice raised off a
 * quotation (and its returns) — plus the reversals of all of these, which
 * carry no reference of their own.
 */
class PoolContractEntries
{
    public function __construct(
        private readonly ActivitySegment $segments,
        private readonly RevenueAccounts $revenueAccounts,
    ) {}

    /** Revenue lines on the pools' centres that belong to contract work. */
    public function revenueLines(): Builder
    {
        $entryIds = $this->entries()->select('id');

        return JournalLine::query()
            ->whereIn('cost_center_id', $this->segments->centerIds(ActivitySegment::POOLS))
            ->whereIn('journal_entry_id', $entryIds)
            ->whereHas('account', fn ($q) => $q->where('type', 'revenue'));
    }

    /**
     * Move the pools' contract revenue onto the contracts' account.
     *
     * Only lines still on the shop's account move: a line the operator put on
     * some other revenue account by hand is left where it was put.
     */
    public function moveToContractsAccount(): int
    {
        $from = $this->revenueAccounts->idFor('pool_revenue');
        $to = $this->revenueAccounts->idFor('pool_contracts');

        if ($from === $to) {
            return 0;
        }

        return $this->revenueLines()->where('account_id', $from)->update(['account_id' => $to]);
    }

    /** Contract entries and the reversals that undid them. */
    public function entries(): Builder
    {
        $originals = JournalEntry::query()->where(fn (Builder $q) => $this->isContractWork($q));

        return JournalEntry::query()->where(fn (Builder $q) => $q
            ->where(fn (Builder $sub) => $this->isContractWork($sub))
            ->orWhereIn('id', $originals->whereNotNull('reversed_by_entry_id')->select('reversed_by_entry_id')));
    }

    private function isContractWork(Builder $q): void
    {
        $vouchers = Voucher::withTrashed()->whereNotNull('contract_id')->select('id');

        $quoted = Sale::withTrashed()->whereNotNull('quotation_id')->select('id');
        $sales = Sale::withTrashed()
            ->where(fn ($s) => $s->whereNotNull('quotation_id')->orWhereIn('original_sale_id', $quoted))
            ->select('id');

        $q->where('source', 'contract')
            ->orWhere(fn ($v) => $v->where('reference_type', Voucher::class)->whereIn('reference_id', $vouchers))
            ->orWhere(fn ($s) => $s->where('reference_type', Sale::class)->whereIn('reference_id', $sales));
    }
}
