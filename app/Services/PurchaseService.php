<?php

namespace App\Services;

use App\Models\CostCenter;
use App\Models\PaymentMethod;
use App\Models\Purchase;
use App\Services\Accounting\Ledger;
use App\Services\Accounting\PaymentMethodAccounts;
use Illuminate\Support\Facades\DB;

/**
 * The entry behind a purchase invoice.
 *
 * Purchases moved stock and never touched the books, so inventory was only
 * ever credited — by the cost of goods sold on each invoice — and drifted
 * further below zero with every sale. Supplier payables did not exist at all,
 * and cash handed to a supplier never left the treasury.
 *
 *   Dr inventory   the goods at what the stock was valued in at
 *   Dr input VAT   the tax, which is a claim on the authority
 *   Cr discount    what the supplier knocked off
 *   Cr treasury    what was paid now
 *   Cr payables    what is still owed
 */
class PurchaseService
{
    public function __construct(
        private readonly Ledger $ledger,
        private readonly PaymentMethodAccounts $methodAccounts,
    ) {}

    public function post(Purchase $purchase, ?int $userId = null): Purchase
    {
        if ($purchase->journal_entry_id) {
            return $purchase;
        }

        $lines = $this->lines($purchase);

        if ($lines === []) {
            return $purchase;
        }

        return DB::transaction(function () use ($purchase, $lines, $userId) {
            $entry = $this->ledger->post(
                $this->dateOf($purchase),
                "فاتورة مشتريات {$purchase->number}",
                $lines,
                'purchase',
                $purchase,
                $userId ?? $purchase->user_id,
            );

            $purchase->update(['journal_entry_id' => $entry->id]);

            return $purchase->fresh();
        });
    }

    /** An edited invoice reverses what it posted and posts what it now says. */
    public function repost(Purchase $purchase, ?int $userId = null): Purchase
    {
        $this->reverse($purchase, "تعديل فاتورة المشتريات {$purchase->number}", $userId);

        return $this->post($purchase->fresh(), $userId);
    }

    public function reverse(Purchase $purchase, ?string $reason = null, ?int $userId = null): void
    {
        $entry = $purchase->journalEntry;

        if (! $entry || $entry->status !== 'posted') {
            $purchase->update(['journal_entry_id' => null]);

            return;
        }

        $this->ledger->reverse($entry, $reason ?? "إلغاء فاتورة المشتريات {$purchase->number}", $userId);
        $purchase->update(['journal_entry_id' => null]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function lines(Purchase $purchase): array
    {
        $goods = round((float) $purchase->subtotal, 2);
        $tax = round((float) $purchase->tax_amount, 2);
        $discount = round((float) $purchase->discount_amount, 2);
        $total = round((float) $purchase->total_amount, 2);
        $paid = round(min((float) $purchase->paid_amount, $total), 2);
        $owed = round($total - $paid, 2);

        if ($goods + $tax <= 0) {
            return [];
        }

        $centre = $this->costCenterFor($purchase);

        return [
            ['account' => Ledger::INVENTORY, 'debit' => $goods, 'cost_center_id' => $centre],
            ['account' => Ledger::INPUT_VAT, 'debit' => $tax, 'cost_center_id' => $centre],
            ['account' => Ledger::PURCHASE_DISCOUNT, 'credit' => $discount, 'cost_center_id' => $centre],
            ['account' => $this->treasuryAccount($purchase, $centre), 'credit' => $paid, 'cost_center_id' => $centre],
            ['account' => Ledger::PAYABLES, 'credit' => $owed, 'cost_center_id' => $centre],
        ];
    }

    private function costCenterFor(Purchase $purchase): ?int
    {
        $purchase->loadMissing('department');

        return $purchase->department ? CostCenter::forDepartment($purchase->department)->id : CostCenter::general()->id;
    }

    /**
     * The till or bank the money left by — the same resolution the sales side
     * uses, so the pools' bank stays the pools' bank on the way out too.
     */
    private function treasuryAccount(Purchase $purchase, ?int $centre): string|int
    {
        $method = $purchase->payment_method_id ? PaymentMethod::find($purchase->payment_method_id) : null;

        if (! $method) {
            return Ledger::CASH;
        }

        return $this->methodAccounts->resolveForInvoiceCenter($centre, $method) ?? $method->ledgerAccount();
    }

    /** The supplier's own date, so an invoice entered late books in its month. */
    private function dateOf(Purchase $purchase): string
    {
        return ($purchase->purchase_date ?? $purchase->created_at)->toDateString();
    }
}
