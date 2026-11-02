<?php

use App\Models\Booking;
use App\Models\Contract;
use App\Services\Accounting\BookingAccounting;
use App\Services\Accounting\ContractAccounting;
use Illuminate\Database\Migrations\Migration;

/**
 * Earn the payments already taken on halls and chalets, each on its own date.
 *
 * A booking's payments waited in «عرابين مقدمًا» until the day it was served,
 * and a cancelled booking's stayed there as owed back. The client's rule is
 * the one the pools now follow: money is revenue the day it is received, is
 * kept if the booking is cancelled, and leaves revenue only by a refund.
 *
 * Bookings already recognized on their day are left alone — that entry cleared
 * their deposits already. Deleted bookings are left to the audit, which names
 * any still holding money. The receipts of a hall's services list, or of a
 * chalet let without a booking, are earned the same way. Idempotent.
 */
return new class extends Migration
{
    public function up(): void
    {
        $bookings = app(BookingAccounting::class);
        $contracts = app(ContractAccounting::class);

        Booking::query()
            ->orderBy('id')
            ->chunkById(200, function ($chunk) use ($bookings) {
                foreach ($chunk as $booking) {
                    if (! $bookings->standingEntry($booking)) {
                        $bookings->syncEarned($booking);
                    }
                }
            });

        Contract::query()
            ->orderBy('id')
            ->chunkById(200, function ($chunk) use ($contracts) {
                foreach ($chunk as $contract) {
                    if ($contracts->earnsOnReceipt($contract) && ! $contracts->standingEntry($contract)) {
                        $contracts->earnHeldReceipts($contract);
                    }
                }
            });
    }

    public function down(): void
    {
        // The entries stay; undoing a policy is a decision, not a rollback.
    }
};
