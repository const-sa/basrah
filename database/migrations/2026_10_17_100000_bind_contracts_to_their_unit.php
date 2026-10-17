<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Bind every hall and chalet contract to the unit it is written for.
 *
 * A contract knew its unit only through its booking, so a hall's pad written
 * straight onto a client — no booking behind it — belonged to no hall at all:
 * it showed in the register of every hall, and the supervisor of one hall read
 * the other's contracts as if they were his own. The unit is now the
 * contract's own column, copied from the booking where there is one and
 * chosen on the form where there is none.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contracts', function (Blueprint $table) {
            // nullOnDelete: a signed contract outlives the unit it was let on,
            // as it outlives its quotation — its paper is in its snapshot.
            $table->foreignId('unit_id')->nullable()->after('quotation_id')
                ->constrained('units')->nullOnDelete();
        });

        DB::table('contracts')->whereNotNull('booking_id')->update([
            'unit_id' => DB::raw('(SELECT unit_id FROM bookings WHERE bookings.id = contracts.booking_id)'),
        ]);

        // A direct pad of an activity that runs a single unit can only be that
        // unit's. Where there are several, an admin assigns it from the edit
        // screen — guessing would only move the mix-up somewhere else.
        $forms = [
            'hall' => ['hall_rental', 'hall_services'],
            'chalet' => ['chalet_rental'],
        ];

        foreach ($forms as $type => $names) {
            $units = DB::table('units')->where('type', $type)->whereNull('deleted_at')->pluck('id');

            if ($units->count() === 1) {
                DB::table('contracts')->whereNull('booking_id')->whereNull('unit_id')
                    ->whereIn('data->form', $names)
                    ->update(['unit_id' => $units->first()]);

                continue;
            }

            // With several, a pad whose client was only ever booked into one of
            // them is that one's — the services list written beside a booking
            // that was drawn without it.
            $pads = DB::table('contracts')->whereNull('booking_id')->whereNull('unit_id')
                ->whereNotNull('client_id')->whereIn('data->form', $names)
                ->get(['id', 'client_id']);

            foreach ($pads as $pad) {
                $booked = DB::table('bookings')->where('client_id', $pad->client_id)
                    ->whereIn('unit_id', $units)->whereNull('deleted_at')
                    ->distinct()->pluck('unit_id');

                if ($booked->count() === 1) {
                    DB::table('contracts')->where('id', $pad->id)->update(['unit_id' => $booked->first()]);
                }
            }
        }
    }

    public function down(): void
    {
        Schema::table('contracts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('unit_id');
        });
    }
};
