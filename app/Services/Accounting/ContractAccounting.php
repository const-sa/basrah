<?php

namespace App\Services\Accounting;

use App\Models\Contract;
use App\Models\CostCenter;
use App\Models\Department;
use App\Models\JournalEntry;
use App\Models\Sale;
use App\Support\ActivitySegment;
use Illuminate\Database\Eloquent\Builder;

/**
 * Contract revenue — when what was collected on a contract becomes earned.
 *
 * A contract posted nothing at all. Its receipts credited unearned revenue,
 * a liability, and nothing ever moved it out: the pools could collect 26,450
 * against signed contracts and still read zero income, while the invoices
 * beside them read in full. That is the whole of «العقود وسنداتها مش بتظهر».
 *
 * What earns, and when, follows the paper it is written on:
 *  - تركيب: on handover. The terms are half at signing and half when the
 *    equipment is ordered, so signing is demonstrably not delivery.
 *  - صيانة شهرية: each receipt is that month's fee, so it credits revenue
 *    as it is taken — ContractReceipts does that, not this class.
 *  - خدمات القاعات: delivered the day its booking runs.
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
        $held = round($contract->paidAmount(), 2);
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

    /** Undo a contract's revenue with a counter-entry, not by deleting it. */
    public function reverseRevenue(Contract $contract, string $reason, ?int $userId = null): ?JournalEntry
    {
        $entry = $this->standingEntry($contract);

        return $entry ? $this->ledger->reverse($entry, $reason, $userId) : null;
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
        $stream = RevenueAccounts::SECTION_STREAMS[$this->segments->of($this->costCenterFor($contract))] ?? 'sales';

        return $this->revenueAccounts->idFor($stream);
    }

    private function post(Contract $contract, string $date, ?int $userId): JournalEntry
    {
        $total = round((float) $contract->totalAmount(), 2);
        $collected = round(min($contract->paidAmount(), $total), 2);
        $outstanding = round($total - $collected, 2);
        $centre = $this->costCenterFor($contract);

        return $this->ledger->post(
            $date,
            "إثبات إيراد العقد {$contract->number}",
            [
                ['account' => Ledger::UNEARNED_REVENUE, 'debit' => $collected, 'cost_center_id' => $centre],
                ['account' => Ledger::RECEIVABLES, 'debit' => $outstanding, 'cost_center_id' => $centre],
                ['account' => $this->revenueAccountFor($contract), 'credit' => $total, 'cost_center_id' => $centre],
            ],
            'contract',
            $contract,
            $userId,
        );
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
