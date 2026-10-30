<?php

use App\Models\Booking;
use App\Services\Accounting\BookingAccounting;
use Illuminate\Database\Migrations\Migration;

/**
 * A closed booking's revenue entry was written once and never revisited: a
 * total raised after closing (a-51: 9,000 paid, 8,000 earned) or money taken
 * after a manual close (a-46) left the difference in unearned revenue and
 * receivables. Each such entry is reversed and re-posted on its own date.
 * Bookings whose entry already matches are left alone.
 */
return new class extends Migration
{
    public function up(): void
    {
        $accounting = app(BookingAccounting::class);

        Booking::where('status', 'paid_in_full')
            ->orderBy('id')
            ->each(fn (Booking $booking) => $accounting->reviseRevenue($booking));
    }

    public function down(): void
    {
        // The reversals stay in the books as their own audit trail.
    }
};
