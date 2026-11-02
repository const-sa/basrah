<?php

use App\Models\Booking;
use App\Services\Accounting\BookingAccounting;
use Illuminate\Database\Migrations\Migration;

/**
 * Take back the revenue of bookings that were earned before they were served.
 *
 * Until 1 November a booking earned the day it was paid in full, and the
 * migration of 25 October posted every such booking at once — weddings months
 * ahead among them. Delivery is what earns now, so a booking paid today reads
 * as a deposit while one paid in September reads as revenue for an event that
 * has not happened: two identical bookings, two different figures, and the
 * cost centres no longer add up to the bookings register.
 *
 * Each such entry is reversed on its own date, so the month it sat in nets to
 * zero, and the money is held again until the nightly sweep recognizes it on
 * the day the booking runs.
 */
return new class extends Migration
{
    public function up(): void
    {
        $accounting = app(BookingAccounting::class);

        Booking::query()
            ->whereIn('status', Booking::BLOCKING_STATUSES)
            ->where('total_amount', '>', 0)
            ->whereDate('ends_at', '>=', now()->toDateString())
            ->with(['unit', 'sections'])
            ->orderBy('id')
            ->chunkById(200, function ($bookings) use ($accounting) {
                foreach ($bookings as $booking) {
                    if (! $accounting->isDelivered($booking) && $accounting->standingEntry($booking)) {
                        $accounting->reviseRevenue($booking);
                    }
                }
            });
    }

    public function down(): void
    {
        // The reversals stay in the books as their own audit trail.
    }
};
