<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Booking hours move out of config and into settings.
 *
 * The chalet check-in/check-out hours were already a config value the client
 * was meant to review before launch, while the hall day periods were a class
 * constant reachable only by editing code. Both decide real booking ranges,
 * so both belong on a screen the client can edit.
 *
 * Every column is nullable: null means "use the shipped default", so an
 * untouched install behaves exactly as before.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            // {period: {start, end}} — the keys and labels stay in code, only
            // the hours are editable.
            $table->json('booking_periods')->nullable()->after('address');

            $table->string('chalet_check_in_time', 5)->nullable()->after('booking_periods');
            $table->string('chalet_check_out_time', 5)->nullable()->after('chalet_check_in_time');
            $table->unsignedSmallInteger('chalet_max_nights')->nullable()->after('chalet_check_out_time');
        });
    }

    public function down(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            $table->dropColumn([
                'booking_periods',
                'chalet_check_in_time',
                'chalet_check_out_time',
                'chalet_max_nights',
            ]);
        });
    }
};
