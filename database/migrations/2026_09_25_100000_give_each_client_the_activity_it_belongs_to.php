<?php

use App\Support\ClientType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Which activity is this client ours through? The three businesses shared one
 * directory, so every list was the sum of all three.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('clients', 'type')) {
            Schema::table('clients', function (Blueprint $table) {
                $table->string('type', 20)->default(ClientType::DEFAULT)->after('city')->index();
            });
        }

        // Filed under the latest booking's activity — ascending order leaves that
        // row last per client — and whoever never booked stays with the pools.
        $byClient = DB::table('bookings')
            ->join('units', 'units.id', '=', 'bookings.unit_id')
            ->whereNotNull('bookings.client_id')
            ->orderBy('bookings.booking_date')
            ->orderBy('bookings.id')
            ->pluck('units.type', 'bookings.client_id');

        $clientIds = [];

        foreach ($byClient as $clientId => $type) {
            $clientIds[$type][] = $clientId;
        }

        foreach ($clientIds as $type => $ids) {
            DB::table('clients')
                ->where('is_walk_in', false)
                ->whereIn('id', $ids)
                ->update(['type' => $type]);
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('clients', 'type')) {
            Schema::table('clients', function (Blueprint $table) {
                $table->dropIndex(['type']);
                $table->dropColumn('type');
            });
        }
    }
};
