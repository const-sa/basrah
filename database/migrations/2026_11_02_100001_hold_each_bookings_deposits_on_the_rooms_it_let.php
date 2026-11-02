<?php

use App\Models\Booking;
use App\Services\Accounting\BookingAccounting;
use Illuminate\Database\Migrations\Migration;

/**
 * Move the deposits of bookings let by the room off the hall and onto the room.
 *
 * Revenue has been credited room by room since 1 November, but the money
 * taken before the event stayed on the hall's centre. A men's-side booking
 * still ahead of its date has nothing but a deposit, so «قسم الرجال» read
 * zero on every column while the hall above it held the money — which is
 * «حجوزات قسم الرجال مش ظاهره ف مراكز التكلفة» still, after the room got
 * its row.
 *
 * Only the centre of each unearned-revenue line moves: the same amount on the
 * same account, inside one transaction, so no balance changes. The standing
 * revenue entry's debit follows, so each room clears what it held when the
 * booking earns. Idempotent: lines already on their rooms are left alone.
 */
return new class extends Migration
{
    public function up(): void
    {
        $accounting = app(BookingAccounting::class);

        Booking::query()
            ->where('scope', 'sections')
            ->with(['unit', 'sections'])
            ->orderBy('id')
            ->chunkById(200, function ($bookings) use ($accounting) {
                foreach ($bookings as $booking) {
                    $accounting->realignHeld($booking);
                    $accounting->realignRecognized($booking);
                }
            });
    }

    public function down(): void
    {
        // Nothing to undo: the money never moved, only the centre it is read on.
    }
};
