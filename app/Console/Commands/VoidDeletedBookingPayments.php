<?php

namespace App\Console\Commands;

use App\Models\Booking;
use App\Models\BookingPayment;
use App\Models\JournalEntry;
use App\Models\Voucher;
use App\Services\Accounting\Ledger;
use App\Services\Accounting\VoucherService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Take out of the books the payments of a booking that was deleted because it
 * was entered by mistake, and whose money never came in.
 *
 * In the first days the front desk wrote bookings, got them wrong, deleted
 * them and wrote them again — but the payments typed onto the wrong ones had
 * already posted. The till and the deposits account still carry them, under a
 * booking no screen shows. The operator confirms the money was never taken;
 * this reverses each payment's entry (cancelling its receipt where one was
 * written, so the register agrees) and removes the payment, so restoring the
 * booking later cannot bring the phantom money back with it.
 *
 * Deleted bookings only, named one by one: a live booking's payments are
 * corrected on the booking, by a refund, where the client can see it.
 */
class VoidDeletedBookingPayments extends Command
{
    protected $signature = 'accounting:void-deleted-booking-payments
        {references* : The references of the deleted bookings, e.g. a-1 a-27}
        {--pretend : List what would be reversed without reversing it}';

    protected $description = 'Reverse the payments of deleted bookings whose money was never received';

    public function handle(Ledger $ledger, VoucherService $vouchers): int
    {
        $failed = false;

        foreach ($this->argument('references') as $reference) {
            $booking = Booking::onlyTrashed()->where('reference', $reference)->with('payments')->first();

            if (! $booking) {
                $this->error("{$reference}: not a deleted booking — nothing done.");
                $failed = true;

                continue;
            }

            if ($booking->payments->isEmpty()) {
                $this->line("{$reference}: no payments.");

                continue;
            }

            foreach ($booking->payments as $payment) {
                $this->line("{$reference}: {$payment->type} {$this->n($payment->amount)} on {$payment->paid_on?->toDateString()}");
            }

            if ($this->option('pretend')) {
                continue;
            }

            DB::transaction(function () use ($booking, $ledger, $vouchers) {
                $reason = "الحجز {$booking->reference} حُذف لإدخاله خطأً ولم يُقبض مبلغه";

                foreach ($booking->payments as $payment) {
                    $this->void($payment, $reason, $ledger, $vouchers);
                }

                $booking->updateQuietly(['paid_amount' => 0]);
            });

            $this->info("{$reference}: {$booking->payments->count()} payment(s) reversed.");
        }

        if ($this->option('pretend')) {
            $this->comment('Pretend — nothing was changed.');
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Reverse one payment: through its receipt where it has one, so the
     * voucher register reads cancelled; straight on the ledger where not.
     */
    private function void(BookingPayment $payment, string $reason, Ledger $ledger, VoucherService $vouchers): void
    {
        $voucher = Voucher::where('booking_payment_id', $payment->id)->where('status', 'posted')->first();

        if ($voucher) {
            $vouchers->cancel($voucher, $reason);
        }

        JournalEntry::where('reference_type', BookingPayment::class)
            ->where('reference_id', $payment->id)
            ->where('status', 'posted')
            ->get()
            ->each(fn (JournalEntry $entry) => $ledger->reverse($entry, $reason));

        $payment->delete();
    }

    private function n(float|string|null $amount): string
    {
        return number_format((float) $amount, 2);
    }
}
