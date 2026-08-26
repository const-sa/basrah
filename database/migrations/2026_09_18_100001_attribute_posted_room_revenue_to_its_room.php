<?php

use App\Support\Accounting\SectionRevenueAttribution;
use Illuminate\Database\Migrations\Migration;

/**
 * Booking revenue posted before rooms had cost centres, moved onto the rooms.
 *
 * Without this the new breakdown answers only for stays sold after the change,
 * and the operator opening «الإيرادات» on last month sees exactly what they saw
 * before — which reads as nothing having shipped.
 *
 * It moves no money. One credit line on the booking-revenue account becomes one
 * line per room for the same total, on the same account, in the same entry; the
 * only column that changes is the cost centre. Balances, totals and the trial
 * balance are all identical after it as before. Re-running it is a no-op: an
 * entry whose revenue is already on more than one line is passed over.
 */
return new class extends Migration
{
    public function up(): void
    {
        app(SectionRevenueAttribution::class)->apply();
    }

    public function down(): void
    {
        // Nothing to undo that is worth undoing: re-merging the lines would put
        // the ledger back to reporting a chalet's rooms as one figure, which is
        // the state this exists to leave behind. The amounts are untouched
        // either way.
    }
};
