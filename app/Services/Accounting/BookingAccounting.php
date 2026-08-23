<?php

namespace App\Services\Accounting;

use App\Models\Booking;
use App\Models\BookingPayment;
use App\Models\CostCenter;
use App\Models\JournalEntry;

/**
 * القيود التلقائية من نظام الحجوزات (§الطبقة أ - بند 4).
 *
 * المعالجة المحاسبية المتبعة:
 *  - العربون عند استلامه = التزام (إيراد غير مكتسب) لا إيراد،
 *    لأن الخدمة لم تُقدَّم بعد. إثباته إيرادًا فورًا يضخّم دخل الشهر
 *    ويُنقص دخل شهر التنفيذ.
 *  - عند اكتمال الحجز يُعترف بالإيراد ويُقفل الالتزام.
 */
class BookingAccounting
{
    public function __construct(private readonly Ledger $ledger) {}

    /**
     * قيد استلام دفعة على حجز.
     * مدين: الخزينة — دائن: إيراد غير مكتسب.
     */
    public function recordPayment(BookingPayment $payment, ?int $userId = null): JournalEntry
    {
        $booking = $payment->booking()->with('unit')->firstOrFail();
        $costCenter = $this->costCenter($booking);

        if ($payment->isSecurity()) {
            return $this->recordSecurityMovement($payment, $booking, $costCenter, $userId);
        }

        // الطريقة تحمل حسابها، فلا تبقى ترجمةٌ في الكود تهبط بالمجهول على الصندوق.
        $treasuryAccount = $payment->paymentMethod()->firstOrFail()->ledgerAccount();

        $amount = (float) $payment->amount;
        $isRefund = $payment->type === 'refund';

        $lines = $isRefund
            ? [
                ['account' => Ledger::UNEARNED_REVENUE, 'debit' => $amount, 'cost_center_id' => $costCenter],
                ['account' => $treasuryAccount, 'credit' => $amount, 'cost_center_id' => $costCenter],
            ]
            : [
                ['account' => $treasuryAccount, 'debit' => $amount, 'cost_center_id' => $costCenter],
                ['account' => Ledger::UNEARNED_REVENUE, 'credit' => $amount, 'cost_center_id' => $costCenter],
            ];

        $label = $isRefund ? 'استرداد' : ($payment->type === 'deposit' ? 'عربون' : 'دفعة');

        return $this->ledger->post(
            $payment->paid_on->toDateString(),
            "{$label} على الحجز {$booking->reference}",
            $lines,
            'payment',
            $payment,
            $userId,
        );
    }

    /**
     * A security deposit taken, given back, or kept against damage.
     *
     * None of the three touches unearned revenue: the money is held on the
     * guest's behalf until it goes back to them. Only a forfeit crosses into
     * revenue, and it moves no cash — nothing leaves the till, the claim on it
     * simply ends.
     *
     *  - taken:    Dr treasury      / Cr deposits held
     *  - refunded: Dr deposits held / Cr treasury
     *  - forfeit:  Dr deposits held / Cr booking revenue
     */
    private function recordSecurityMovement(
        BookingPayment $payment,
        Booking $booking,
        ?int $costCenter,
        ?int $userId,
    ): JournalEntry {
        $amount = (float) $payment->amount;

        $lines = match ($payment->type) {
            'security_deposit' => [
                ['account' => $payment->paymentMethod()->firstOrFail()->ledgerAccount(), 'debit' => $amount, 'cost_center_id' => $costCenter],
                ['account' => Ledger::REFUNDABLE_DEPOSITS, 'credit' => $amount, 'cost_center_id' => $costCenter],
            ],
            'security_refund' => [
                ['account' => Ledger::REFUNDABLE_DEPOSITS, 'debit' => $amount, 'cost_center_id' => $costCenter],
                ['account' => $payment->paymentMethod()->firstOrFail()->ledgerAccount(), 'credit' => $amount, 'cost_center_id' => $costCenter],
            ],
            default => [
                ['account' => Ledger::REFUNDABLE_DEPOSITS, 'debit' => $amount, 'cost_center_id' => $costCenter],
                ['account' => Ledger::BOOKING_REVENUE, 'credit' => $amount, 'cost_center_id' => $costCenter],
            ],
        };

        $label = BookingPayment::TYPES[$payment->type] ?? $payment->type;

        return $this->ledger->post(
            $payment->paid_on->toDateString(),
            "{$label} على الحجز {$booking->reference}",
            $lines,
            'payment',
            $payment,
            $userId,
        );
    }

    /**
     * الاعتراف بالإيراد عند اكتمال الحجز.
     * مدين: إيراد غير مكتسب (بقدر المحصَّل) + ذمم العملاء (بقدر المتبقي)
     * دائن: إيرادات الحجوزات (بالإجمالي).
     */
    public function recognizeRevenue(Booking $booking, ?int $userId = null): ?JournalEntry
    {
        $total = (float) $booking->total_amount;

        if ($total <= 0) {
            return null;
        }

        // لا يُعترف بالإيراد مرتين على نفس الحجز.
        $exists = JournalEntry::where('source', 'booking')
            ->where('reference_type', Booking::class)
            ->where('reference_id', $booking->id)
            ->where('status', 'posted')
            ->exists();

        if ($exists) {
            return null;
        }

        $booking->loadMissing('unit');
        $costCenter = $this->costCenter($booking);

        $collected = min((float) $booking->paid_amount, $total);
        $outstanding = round($total - $collected, 2);

        $lines = [
            ['account' => Ledger::UNEARNED_REVENUE, 'debit' => $collected, 'cost_center_id' => $costCenter],
            ['account' => Ledger::RECEIVABLES, 'debit' => $outstanding, 'cost_center_id' => $costCenter],
            ['account' => Ledger::BOOKING_REVENUE, 'credit' => $total, 'cost_center_id' => $costCenter],
        ];

        return $this->ledger->post(
            now()->toDateString(),
            "إثبات إيراد الحجز {$booking->reference}",
            $lines,
            'booking',
            $booking,
            $userId,
        );
    }

    /**
     * مركز تكلفة الحجز = مركز تكلفة وحدته.
     */
    private function costCenter(Booking $booking): ?int
    {
        return $booking->unit ? CostCenter::forUnit($booking->unit)->id : null;
    }
}
