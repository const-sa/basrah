<?php

namespace App\Console\Commands;

use App\Models\Booking;
use App\Models\BookingPayment;
use App\Models\Contract;
use App\Models\JournalEntry;
use App\Models\Voucher;
use App\Services\Accounting\BookingAccounting;
use App\Services\Accounting\Ledger;
use App\Services\Accounting\VoucherService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Take out of the books money that was typed in by mistake and never received.
 *
 * In the first days the front desk wrote bookings and contracts, got them
 * wrong, deleted or cancelled them and wrote them again — but the payments and
 * receipts typed onto the wrong ones had already posted. The till and the
 * deposits account still carry them, under documents that will never be
 * served. The operator confirms the money was never taken; this reverses each
 * entry and cancels each receipt, so the books, the voucher register and the
 * document all read zero.
 *
 * Only a deleted or cancelled document, named one by one. A live one is
 * corrected on the document itself, by a refund the client can see. And not
 * one that has already earned or been invoiced: unwinding its money under
 * that would leave the revenue standing on nothing.
 */
class VoidMistakenPayments extends Command
{
    protected $signature = 'accounting:void-mistaken-payments
        {references* : Booking references or contract numbers, e.g. a-1 a-27 CT-2026-0141}
        {--pretend : List what would be reversed without reversing it}';

    protected $description = 'Reverse the payments of deleted or cancelled bookings and contracts whose money was never received';

    private float $total = 0.0;

    public function handle(Ledger $ledger, VoucherService $vouchers, BookingAccounting $bookings): int
    {
        $failed = false;

        foreach ($this->argument('references') as $reference) {
            $booking = Booking::withTrashed()->where('reference', $reference)->with('payments')->first();
            $contract = $booking ? null : Contract::withTrashed()->where('number', $reference)->first();

            $done = match (true) {
                $booking !== null => $this->voidBooking($booking, $ledger, $vouchers, $bookings),
                $contract !== null => $this->voidContract($contract, $vouchers),
                default => $this->refuse($reference, 'no booking or contract by that reference'),
            };

            $failed = $failed || ! $done;
        }

        $this->line('Total: '.$this->n($this->total));
        $this->comment($this->option('pretend') ? 'Pretend — nothing was changed.' : 'Done.');

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    private function voidBooking(Booking $booking, Ledger $ledger, VoucherService $vouchers, BookingAccounting $accounting): bool
    {
        $ref = "booking {$booking->reference}";

        if (! $booking->trashed() && $booking->status !== 'cancelled') {
            return $this->refuse($ref, "is {$booking->status} — only a deleted or cancelled booking; correct a live one with a refund");
        }

        if ($entry = $accounting->standingEntry($booking)) {
            return $this->refuse($ref, "its revenue still stands in entry {$entry->number}");
        }

        if ($booking->payments->isEmpty()) {
            $this->line("{$ref}: no payments.");

            return true;
        }

        foreach ($booking->payments as $payment) {
            $this->item($ref, $payment->type, (float) $payment->amount, $payment->paid_on?->toDateString(), $payment->signedAmount());
        }

        if ($this->option('pretend')) {
            return true;
        }

        DB::transaction(function () use ($booking, $ledger, $vouchers) {
            $reason = "الحجز {$booking->reference} أُدخل خطأً ولم يُقبض مبلغه";

            foreach ($booking->payments as $payment) {
                // Through its receipt where it has one, so the register reads
                // cancelled; then whatever entry is still standing.
                $voucher = Voucher::where('booking_payment_id', $payment->id)->where('status', 'posted')->first();

                if ($voucher) {
                    $vouchers->cancel($voucher, $reason);
                }

                JournalEntry::where('reference_type', BookingPayment::class)
                    ->where('reference_id', $payment->id)
                    ->where('status', 'posted')
                    ->get()
                    ->each(fn (JournalEntry $entry) => $ledger->reverse($entry, $reason));

                // Gone, so restoring or reopening the booking cannot bring
                // the money back with it. The reversal keeps the trail.
                $payment->delete();
            }

            $booking->updateQuietly(['paid_amount' => 0]);
        });

        $this->info("{$ref}: {$booking->payments->count()} payment(s) reversed.");

        return true;
    }

    private function voidContract(Contract $contract, VoucherService $vouchers): bool
    {
        $ref = "contract {$contract->number}";

        if (! $contract->trashed() && $contract->status !== 'cancelled') {
            return $this->refuse($ref, "is {$contract->status} — only a deleted or cancelled contract");
        }

        // Revenue recognized on it, or its deposits applied to an invoice:
        // the receipts are no longer the whole story.
        $earned = JournalEntry::where('reference_type', Contract::class)
            ->where('reference_id', $contract->id)
            ->where('status', 'posted')
            ->first();

        if ($earned) {
            return $this->refuse($ref, "entry {$earned->number} still stands on it");
        }

        $receipts = Voucher::where('contract_id', $contract->id)->where('status', 'posted')->orderBy('id')->get();

        if ($receipts->isEmpty()) {
            $this->line("{$ref}: no posted receipts.");

            return true;
        }

        foreach ($receipts as $voucher) {
            $amount = (float) $voucher->amount;
            $this->item($ref, "{$voucher->type} {$voucher->number}", $amount, $voucher->voucher_date?->toDateString(), $voucher->type === 'receipt' ? $amount : -$amount);
        }

        if ($this->option('pretend')) {
            return true;
        }

        DB::transaction(function () use ($contract, $receipts, $vouchers) {
            $reason = "العقد {$contract->number} أُدخل خطأً ولم يُقبض مبلغه";

            foreach ($receipts as $voucher) {
                $vouchers->cancel($voucher, $reason);
            }

            // Set outright: a deleted contract is not reached through the
            // voucher, so cancelling cannot settle its paid amount down.
            $contract->updateQuietly(['paid_amount' => 0]);
        });

        $this->info("{$ref}: {$receipts->count()} voucher(s) cancelled.");

        return true;
    }

    private function item(string $ref, string $what, float $amount, ?string $date, float $signed): void
    {
        $this->line("{$ref}: {$what} {$this->n($amount)} on {$date}");
        $this->total += $signed;
    }

    private function refuse(string $ref, string $why): bool
    {
        $this->error("{$ref}: {$why} — nothing done.");

        return false;
    }

    private function n(float $amount): string
    {
        return number_format($amount, 2);
    }
}
