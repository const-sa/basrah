<?php

namespace App\Services\Accounting;

use App\Models\Account;
use App\Models\Booking;
use App\Models\BookingPayment;
use App\Models\JournalEntry;
use App\Models\Treasury;
use App\Models\Voucher;

/**
 * Receipts written off a booking's payment ledger.
 *
 * A pools sale and a pools contract have entered the voucher register since
 * they were written — ContractReceipts calls it «one receipt book for the
 * whole business». A hall or chalet booking never did: its payments posted
 * straight to the ledger and stopped there, so the register showed none of
 * them, and Treasury::balance(), which counts vouchers rather than the
 * ledger, read the till short by every deposit ever taken at the front desk.
 *
 * So every payment is mirrored here as an already-posted voucher. The entry
 * is the one BookingAccounting has just written — the voucher carries its id
 * rather than posting a second one, because the books were never the thing
 * that was missing.
 */
class BookingReceipts
{
    /**
     * Payments that move money in or out of a till, and which way.
     *
     * A forfeited security deposit is absent on purpose: it turns held money
     * into revenue without a riyal entering or leaving the drawer, so putting
     * it in the receipt book would overstate the till.
     *
     * @var array<string, string>
     */
    private const DIRECTIONS = [
        'deposit' => 'receipt',
        'payment' => 'receipt',
        'security_deposit' => 'receipt',
        'refund' => 'payment',
        'security_refund' => 'payment',
    ];

    public function __construct(private readonly VoucherService $vouchers) {}

    /**
     * Mirror a booking payment into the voucher register.
     *
     * Returns null where the payment moves no cash, and where one was already
     * written — the register is a mirror, and a second row would be a second
     * receipt for money taken once.
     */
    public function mirror(
        BookingPayment $payment,
        Booking $booking,
        JournalEntry $entry,
        ?int $costCenter,
        ?int $userId = null,
    ): ?Voucher {
        $type = self::DIRECTIONS[$payment->type] ?? null;

        if ($type === null || Voucher::where('booking_payment_id', $payment->id)->exists()) {
            return null;
        }

        $treasury = $this->treasuryFor($payment);

        // Held money is owed back to the guest, so it is never credited to
        // the booking's unearned revenue — the same split the entry makes.
        $counter = $this->accountId($payment->isSecurity()
            ? Ledger::REFUNDABLE_DEPOSITS
            : Ledger::UNEARNED_REVENUE);

        if ($treasury === null || $counter === null) {
            return null;
        }

        $label = BookingPayment::TYPES[$payment->type] ?? $payment->type;

        return $this->vouchers->createPosted([
            'type' => $type,
            'voucher_date' => $payment->paid_on->toDateString(),
            'amount' => round((float) $payment->amount, 2),
            'treasury_id' => $treasury->id,
            'account_id' => $counter,
            'cost_center_id' => $costCenter,
            'client_id' => $booking->client_id,
            'booking_payment_id' => $payment->id,
            'payment_method_id' => $payment->payment_method_id,
            'reference' => $booking->reference,
            'description' => "{$label} على الحجز {$booking->reference}",
        ], $entry, $userId);
    }

    /**
     * The till the money sits in.
     *
     * Where the booking's activity posts its payments to an account a till
     * is kept in, that till is the one — the register then reads the same as
     * the books. Otherwise the payment method decides, as it does elsewhere.
     */
    private function treasuryFor(BookingPayment $payment): ?Treasury
    {
        $method = $payment->paymentMethod;

        if ($method === null) {
            return null;
        }

        $account = $this->accountId($method->ledgerAccount());

        return ($account ? Treasury::where('is_active', true)->where('account_id', $account)->first() : null)
            ?? Treasury::forMethod($method);
    }

    private function accountId(string|int|null $account): ?int
    {
        if ($account === null) {
            return null;
        }

        return is_int($account) ? $account : Account::where('code', $account)->value('id');
    }
}
