<?php

use App\Models\Booking;
use App\Models\BookingPayment;
use App\Models\CostCenter;
use App\Services\Accounting\BookingReceipts;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Booking payments join the receipt book the rest of the business writes in.
 *
 * A pools sale and a pools contract have always entered the voucher register;
 * a hall or chalet payment posted to the ledger and stopped there. Two things
 * followed. The register showed no booking receipts at all, and the till read
 * short: Treasury::balance() counts vouchers, not the ledger, so every deposit
 * taken at the front desk was invisible to it while the payments out were not,
 * driving a cash box that holds money into a negative balance on screen.
 *
 * The column is the link back to the payment, and it is what stops the two
 * being counted twice wherever both are read — the client statement reads the
 * payment itself and skips its mirror.
 *
 * The backfill walks the payments already taken. Each voucher carries the
 * entry that payment posted when it was taken, so nothing is written to the
 * books here: the ledger was never what was missing.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('vouchers', 'booking_payment_id')) {
            return;
        }

        Schema::table('vouchers', function (Blueprint $table) {
            $table->foreignId('booking_payment_id')->nullable()->after('contract_id')
                ->constrained('booking_payments')->nullOnDelete();

            // One receipt per payment: the register mirrors the ledger, and a
            // second row would be a second receipt for money taken once.
            $table->unique('booking_payment_id');
        });

        $this->backfill();
    }

    /**
     * Write the receipt that each payment already taken should have had.
     */
    private function backfill(): void
    {
        $receipts = app(BookingReceipts::class);

        BookingPayment::with(['booking.unit', 'paymentMethod'])
            ->whereDoesntHave('voucher')
            ->orderBy('id')
            ->chunkById(200, function ($payments) use ($receipts) {
                foreach ($payments as $payment) {
                    $booking = $payment->booking;

                    // A payment whose entry never posted has nothing to mirror;
                    // it is left alone rather than given a receipt the books
                    // know nothing about.
                    $entry = $payment->entries()->latest('id')->first();

                    if (! $booking instanceof Booking || $entry === null) {
                        continue;
                    }

                    $receipts->mirror(
                        $payment,
                        $booking,
                        $entry,
                        $booking->unit ? CostCenter::forUnit($booking->unit)->id : null,
                        $payment->received_by,
                    );
                }
            });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('vouchers', 'booking_payment_id')) {
            return;
        }

        Schema::table('vouchers', function (Blueprint $table) {
            $table->dropUnique(['booking_payment_id']);
            $table->dropConstrainedForeignId('booking_payment_id');
        });
    }
};
