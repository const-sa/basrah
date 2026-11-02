<?php

namespace App\Services\Accounting;

use App\Models\Contract;
use App\Models\CostCenter;
use App\Models\Department;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\Sale;
use App\Models\Voucher;
use App\Support\ActivitySegment;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Contract revenue — when what was collected on a contract becomes earned.
 *
 * A contract posted nothing at all. Its receipts credited unearned revenue,
 * a liability, and nothing ever moved it out: the pools could collect 26,450
 * against signed contracts and still read zero income, while the invoices
 * beside them read in full. That is the whole of «العقود وسنداتها مش بتظهر».
 *
 * What earns, and when, follows the paper it is written on:
 *  - تركيب، خدمات القاعات، تأجير شاليه بلا حجز: each receipt as it is taken —
 *    the client's choice — and the rest on handover or the booking's day. A
 *    contract cancelled midway keeps what was paid.
 *  - صيانة شهرية: each receipt is that month's fee, so it credits revenue
 *    as it is taken — ContractReceipts does that, not this class.
 */
class ContractAccounting
{
    public function __construct(
        private readonly Ledger $ledger,
        private readonly RevenueAccounts $revenueAccounts,
        private readonly BookingAccounting $bookings,
        private readonly ActivitySegment $segments,
    ) {}

    /**
     * The contract's cost centre — frozen on it once, worked out only before that.
     */
    public function costCenterFor(Contract $contract): int
    {
        if ($contract->cost_center_id) {
            return (int) $contract->cost_center_id;
        }

        $contract->loadMissing(['quotation.department', 'booking.unit']);

        if ($department = $contract->quotation?->department) {
            return CostCenter::forDepartment($department)->id;
        }

        // A services list earns where its hall does, as the rental payments do.
        if ($unit = $contract->booking?->unit ?? $contract->unit) {
            return CostCenter::forUnit($unit)->id;
        }

        $code = match (true) {
            $contract->isPoolsForm() => 'POOLS',
            $contract->isChaletRentalForm(),
            $contract->isHallRentalForm(),
            $contract->isHallServicesForm() => 'VENUES',
            default => null,
        };

        if ($code && $department = Department::where('code', $code)->first()) {
            return CostCenter::forDepartment($department)->id;
        }

        return CostCenter::general()->id;
    }

    /** Freeze the centre onto the contract, so a later edit cannot move it. */
    public function bindCostCenter(Contract $contract): Contract
    {
        if (! $contract->cost_center_id) {
            $contract->update(['cost_center_id' => $this->costCenterFor($contract)]);
        }

        return $contract;
    }

    /** Has this contract's revenue ever been recognized? A reversed entry counts. */
    public function recognizedEntry(Contract $contract): ?JournalEntry
    {
        return $this->entries($contract)
            ->whereIn('status', JournalEntry::EFFECTIVE_STATUSES)
            ->latest('id')
            ->first();
    }

    /** The entry that still stands, the one a revision or a cancellation reverses. */
    public function standingEntry(Contract $contract): ?JournalEntry
    {
        return $this->entries($contract)->where('status', 'posted')->latest('id')->first();
    }

    /**
     * The day the contract was delivered, or null while it is still open.
     *
     * Handover where it was recorded; otherwise the day the booking behind it
     * runs, which is what a hall services list is delivered on.
     */
    public function deliveryDate(Contract $contract): ?string
    {
        if ($contract->completed_at) {
            return $contract->completed_at->toDateString();
        }

        $contract->loadMissing('booking');

        return $contract->booking ? $this->bookings->deliveryDate($contract->booking) : null;
    }

    public function isDelivered(Contract $contract, ?string $asOf = null): bool
    {
        $date = $this->deliveryDate($contract);

        return $date !== null && $date <= ($asOf ?? now()->toDateString());
    }

    /**
     * Why this contract cannot be recognized, in words for the screen — or null.
     */
    public function refusal(Contract $contract): ?string
    {
        return match (true) {
            ! $contract->takesReceipts() => 'العقد لا يحمل سجل سندات، وإيراده يُثبت على حجزه.',
            $contract->isMaintenanceForm() => 'عقد الصيانة الشهرية يُثبت إيراده مع كل سند قبض.',
            $contract->status === 'cancelled' => 'العقد ملغي، فلا إيراد له.',
            $contract->totalAmount() === null => 'العقد بلا قيمة مكتوبة، فلا إيراد يُحتسب له.',
            $this->recognizedEntry($contract) !== null => 'أُثبت إيراد هذا العقد من قبل.',
            $this->invoiceFor($contract) !== null => 'صدرت للعقد فاتورة، وإيرادها هو إيراده.',
            ! $this->isDelivered($contract) => 'لم يُسجَّل تنفيذ العقد بعد.',
            $this->earnedOnReceipt($contract) >= (float) $contract->totalAmount() - 0.005 => 'استُحق إيراد العقد كله مع سندات القبض.',
            default => null,
        };
    }

    /**
     * Dr unearned revenue (what was collected) + receivables (the rest),
     * Cr the activity's revenue — all on the contract's centre.
     */
    public function recognizeRevenue(Contract $contract, ?int $userId = null, ?string $date = null): ?JournalEntry
    {
        if ($this->refusal($contract) !== null) {
            return null;
        }

        $this->bindCostCenter($contract);

        return $this->post($contract, $date ?? $this->deliveryDate($contract), $userId);
    }

    /**
     * The deposits a contract collected, applied to the invoice that earned them.
     *
     * Where the quotation was invoiced, the sale already credited revenue in
     * full and raised a receivable for all of it. The contract's deposits are
     * sitting in unearned revenue beside it, so the client reads as owing the
     * whole invoice and as having paid nothing. This moves the one onto the other.
     */
    public function applyDepositsToInvoice(Contract $contract, Sale $sale, ?int $userId = null): ?JournalEntry
    {
        // The invoice carries the revenue now: what the receipts earned goes
        // back to deposits first, or the job would be earned twice.
        $this->reverseEach($this->earnings($contract), "صدرت للعقد {$contract->number} الفاتورة {$sale->number}", $userId);

        $held = $this->heldInDeposits($contract);
        $outstanding = round(max(0, (float) $sale->total_amount - (float) $sale->paid_amount), 2);
        $amount = round(min($held, $outstanding), 2);

        if ($amount <= 0) {
            return null;
        }

        $centre = $this->costCenterFor($contract);

        return $this->ledger->post(
            now()->toDateString(),
            "تنزيل عربون العقد {$contract->number} على الفاتورة {$sale->number}",
            [
                ['account' => Ledger::UNEARNED_REVENUE, 'debit' => $amount, 'cost_center_id' => $centre],
                ['account' => Ledger::RECEIVABLES, 'credit' => $amount, 'cost_center_id' => $centre],
            ],
            'contract',
            $contract,
            $userId,
        );
    }

    /**
     * Move a receipt just posted on a contract out of «عرابين مقدمًا», on the
     * day it was taken — or leave it there.
     *
     * Before handover, an installation's receipt is earned as it is taken: the
     * client chose this, because each payment funds work done at that stage —
     * the digging, the equipment — and those costs reach the pools the day
     * they are spent. Held back until handover, the pools read the costs of a
     * job without the money paid for it, and then the whole of it in one month.
     *
     * After handover the job is already earned in full, so the payment pays
     * what the client owes instead. A pool is often finished off six months
     * later, and every month in between still reads the client as owing it.
     *
     * A services list and a chalet let without a booking earn the same way.
     * Only a contract whose quotation was invoiced keeps its receipts as
     * deposits: it earns on the invoice.
     */
    public function takeReceipt(Voucher $voucher, ?int $userId = null): ?JournalEntry
    {
        $contract = $voucher->contract()->first();

        if (! $contract || (int) $voucher->account_id !== $this->ledger->accountId(Ledger::UNEARNED_REVENUE)) {
            return null;
        }

        if ($this->standingEntry($contract)) {
            return $this->settleReceivable($contract, $voucher, $userId);
        }

        return $this->earnsOnReceipt($contract) ? $this->earn($contract, $voucher, (float) $voucher->amount, $userId) : null;
    }

    /**
     * A receipt cancelled puts back what it moved: the revenue it earned, or
     * the debt it paid.
     */
    public function releaseReceipt(Voucher $voucher, ?string $reason = null, ?int $userId = null): void
    {
        $this->reverseEach($this->movedBy()->where('reference_id', $voucher->id)->get(), $reason, $userId);
    }

    /**
     * Undo the handover's revenue with a counter-entry, not by deleting it —
     * on a cancellation, or a handover recorded by mistake.
     *
     * The payments that settled its receivable since owed nothing once it is
     * gone. What the client paid is not given back on either: on a handover
     * undone the job goes on, and on a cancelled installation the money is
     * kept against the work already done. So every receipt is earned again on
     * its own date, and only the balance the client will never owe is dropped.
     */
    public function reverseRevenue(Contract $contract, string $reason, ?int $userId = null): ?JournalEntry
    {
        $entry = $this->standingEntry($contract);

        if (! $entry) {
            return null;
        }

        $this->reverseEach($this->settlements($contract), $reason, $userId);
        $reversal = $this->ledger->reverse($entry, $reason, $userId);

        if ($this->earnsOnReceipt($contract)) {
            $this->earnHeldReceipts($contract, $userId);
        }

        return $reversal;
    }

    /**
     * Earn every receipt still sitting in deposits, each on its own date —
     * for receipts taken before installations earned on receipt, and after a
     * handover is undone.
     */
    public function earnHeldReceipts(Contract $contract, ?int $userId = null): int
    {
        $earned = 0;

        $vouchers = Voucher::where('contract_id', $contract->id)
            ->where('status', 'posted')
            ->where('type', 'receipt')
            ->where('account_id', $this->ledger->accountId(Ledger::UNEARNED_REVENUE))
            ->orderBy('voucher_date')
            ->orderBy('id')
            ->get();

        foreach ($vouchers as $voucher) {
            $held = round((float) $voucher->amount - $this->movedOff($voucher), 2);

            if ($held > 0 && $this->earn($contract, $voucher, $held, $userId)) {
                $earned++;
            }
        }

        return $earned;
    }

    /** What the receipts have already earned of this contract. */
    public function earnedOnReceipt(Contract $contract): float
    {
        return round((float) JournalLine::query()
            ->whereIn('journal_entry_id', $this->earnings($contract)->pluck('id'))
            ->whereHas('account', fn ($q) => $q->where('type', 'revenue'))
            ->selectRaw('COALESCE(SUM(credit) - SUM(debit), 0) AS earned')
            ->value('earned'), 2);
    }

    /**
     * Is this a contract whose receipts are revenue the day they are taken?
     *
     * Every contract that keeps its own receipts: a pool installation, a hall's
     * services list, a chalet let without a booking — the client's rule for
     * the pools and the venues alike. Monthly maintenance credits revenue on
     * the receipt itself, and an invoiced quotation earns on its invoice.
     */
    public function earnsOnReceipt(Contract $contract): bool
    {
        return $contract->takesReceipts()
            && ! $contract->isMaintenanceForm()
            && $this->invoiceFor($contract) === null;
    }

    private function earn(Contract $contract, Voucher $voucher, float $amount, ?int $userId): ?JournalEntry
    {
        $amount = round($amount, 2);

        if ($amount <= 0) {
            return null;
        }

        $centre = $this->costCenterFor($contract);

        return $this->ledger->post(
            $voucher->voucher_date->toDateString(),
            "إيراد العقد {$contract->number} بالسند {$voucher->number}",
            [
                ['account' => Ledger::UNEARNED_REVENUE, 'debit' => $amount, 'cost_center_id' => $centre],
                ['account' => $this->revenueAccountFor($contract), 'credit' => $amount, 'cost_center_id' => $centre],
            ],
            'contract',
            $voucher,
            $userId,
        );
    }

    /**
     * A payment after handover pays what the client owes, capped at what is
     * still owed so a receipt entered twice cannot drive it below zero.
     */
    private function settleReceivable(Contract $contract, Voucher $voucher, ?int $userId): ?JournalEntry
    {
        $receivables = $this->ledger->accountId(Ledger::RECEIVABLES);
        $owed = round((float) $this->standingEntry($contract)->lines()->where('account_id', $receivables)->sum('debit')
            - (float) JournalLine::whereIn('journal_entry_id', $this->settlements($contract)->pluck('id'))
                ->where('account_id', $receivables)
                ->sum('credit'), 2);
        $amount = round(min((float) $voucher->amount, $owed), 2);

        if ($amount <= 0) {
            return null;
        }

        $centre = $this->costCenterFor($contract);

        return $this->ledger->post(
            $voucher->voucher_date->toDateString(),
            "سداد مستحق العقد {$contract->number} بالسند {$voucher->number}",
            [
                ['account' => Ledger::UNEARNED_REVENUE, 'debit' => $amount, 'cost_center_id' => $centre],
                ['account' => Ledger::RECEIVABLES, 'credit' => $amount, 'cost_center_id' => $centre],
            ],
            'contract',
            $voucher,
            $userId,
        );
    }

    /** How much of one receipt has already been moved off deposits. */
    private function movedOff(Voucher $voucher): float
    {
        return (float) JournalLine::query()
            ->whereIn('journal_entry_id', $this->movedBy()->where('reference_id', $voucher->id)->select('id'))
            ->where('account_id', $this->ledger->accountId(Ledger::UNEARNED_REVENUE))
            ->selectRaw('COALESCE(SUM(debit) - SUM(credit), 0) AS moved')
            ->value('moved');
    }

    /** Standing entries that moved a receipt off deposits — earned, or paying a debt. */
    private function movedBy(): Builder
    {
        return JournalEntry::where('source', 'contract')
            ->where('reference_type', Voucher::class)
            ->where('status', 'posted');
    }

    /** @return Collection<int, JournalEntry> */
    private function earnings(Contract $contract)
    {
        return $this->movedOnContract($contract)
            ->whereHas('lines', fn ($q) => $q->where('credit', '>', 0)->whereHas('account', fn ($a) => $a->where('type', 'revenue')))
            ->get();
    }

    /** @return Collection<int, JournalEntry> */
    private function settlements(Contract $contract)
    {
        return $this->movedOnContract($contract)
            ->whereHas('lines', fn ($q) => $q->where('credit', '>', 0)->where('account_id', $this->ledger->accountId(Ledger::RECEIVABLES)))
            ->get();
    }

    private function movedOnContract(Contract $contract): Builder
    {
        return $this->movedBy()->whereIn('reference_id', Voucher::where('contract_id', $contract->id)->select('id'));
    }

    /** @param  iterable<JournalEntry>  $entries */
    private function reverseEach(iterable $entries, ?string $reason, ?int $userId): void
    {
        foreach ($entries as $entry) {
            $this->ledger->reverse($entry, $reason, $userId);
        }
    }

    /** The invoice raised off this contract's quotation, if there is one. */
    public function invoiceFor(Contract $contract): ?Sale
    {
        if (! $contract->quotation_id) {
            return null;
        }

        return Sale::where('quotation_id', $contract->quotation_id)->where('type', 'sale')->first();
    }

    /** The revenue account this contract's activity earns on. */
    public function revenueAccountFor(Contract $contract): int
    {
        $segment = $this->segments->of($this->costCenterFor($contract));

        // The pools keep their contract work apart from the shop's sales.
        $stream = $segment === ActivitySegment::POOLS
            ? 'pool_contracts'
            : (RevenueAccounts::SECTION_STREAMS[$segment] ?? 'sales');

        return $this->revenueAccounts->idFor($stream);
    }

    /**
     * Earn at handover what the receipts have not already earned: whatever is
     * still held clears deposits, and the rest is what the client owes.
     */
    private function post(Contract $contract, string $date, ?int $userId): ?JournalEntry
    {
        $remaining = round((float) $contract->totalAmount() - $this->earnedOnReceipt($contract), 2);

        if ($remaining <= 0) {
            return null;
        }

        $collected = round(min($this->heldInDeposits($contract), $remaining), 2);
        $outstanding = round($remaining - $collected, 2);
        $centre = $this->costCenterFor($contract);

        return $this->ledger->post(
            $date,
            "إثبات إيراد العقد {$contract->number}",
            [
                ['account' => Ledger::UNEARNED_REVENUE, 'debit' => $collected, 'cost_center_id' => $centre],
                ['account' => Ledger::RECEIVABLES, 'debit' => $outstanding, 'cost_center_id' => $centre],
                ['account' => $this->revenueAccountFor($contract), 'credit' => $remaining, 'cost_center_id' => $centre],
            ],
            'contract',
            $contract,
            $userId,
        );
    }

    /**
     * What the contract's receipts still leave in unearned revenue — what the
     * handover entry has to clear.
     *
     * Read from the ledger rather than the paid amount: a receipt written in
     * the vouchers screen may credit another account, and one already earned
     * has left; clearing money that is not there drives deposits below zero.
     */
    private function heldInDeposits(Contract $contract): float
    {
        return round((float) JournalLine::query()
            ->where('account_id', $this->ledger->accountId(Ledger::UNEARNED_REVENUE))
            ->whereHas('entry', fn ($q) => $q->where('status', 'posted')
                ->where('reference_type', Voucher::class)
                ->whereIn('reference_id', Voucher::where('contract_id', $contract->id)->select('id')))
            ->selectRaw('COALESCE(SUM(credit) - SUM(debit), 0) AS held')
            ->value('held'), 2);
    }

    private function entries(Contract $contract): Builder
    {
        return JournalEntry::where('source', 'contract')
            ->where('reference_type', Contract::class)
            ->where('reference_id', $contract->id)
            // The deposit reclass is written against the contract too, but it
            // credits a receivable, not revenue — it is not the recognition.
            ->whereHas('lines', fn ($q) => $q->where('credit', '>', 0)
                ->whereHas('account', fn ($a) => $a->where('type', 'revenue')));
    }
}
