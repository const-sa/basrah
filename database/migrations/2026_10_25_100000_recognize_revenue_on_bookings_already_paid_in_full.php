<?php

use App\Models\Booking;
use App\Services\BookingService;
use Illuminate\Database\Migrations\Migration;

/**
 * Close the bookings whose money arrived but whose status never followed.
 *
 * Revenue was recognized only when an employee picked «مسدد كامل» off the
 * status menu; taking the money merely rewrote paid_amount. A booking paid to
 * the last riyal therefore sat on «مدفوع العربون» with no revenue entry, and
 * its unit read a loss for a month it had earned in — the deposit stayed a
 * liability in unearned revenue because nothing ever told the books the stay
 * had been sold.
 *
 * BookingService now settles a booking as its last payment lands, so this is
 * only for the ones taken before that. It goes through settleInFull() rather
 * than writing the status in SQL, so each one gets the entry it was owed;
 * recognizeRevenue() refuses to post twice, so a booking that somehow already
 * has its entry keeps the one it has.
 */
return new class extends Migration
{
    /** كسور الهللة لا تمنع إقفال حجزٍ اكتمل مبلغه. */
    private const EPSILON = 0.009;

    public function up(): void
    {
        $bookings = BookingService::class;

        if (! class_exists($bookings)) {
            return;
        }

        $service = app($bookings);

        Booking::where('status', 'deposit_paid')
            ->where('total_amount', '>', 0)
            ->whereRaw('ROUND(paid_amount, 2) + ? >= ROUND(total_amount, 2)', [self::EPSILON])
            ->orderBy('id')
            ->chunkById(100, function ($chunk) use ($service) {
                foreach ($chunk as $booking) {
                    $service->settleInFull($booking, $booking->created_by);
                }
            });
    }

    /**
     * Not reversed: the entries this wrote are real revenue on real money, and
     * unwinding them belongs to a cancellation, not to a schema rollback.
     */
    public function down(): void {}
};
