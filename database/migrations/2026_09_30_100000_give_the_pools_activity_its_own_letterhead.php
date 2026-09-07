<?php

use App\Support\PoolsLetterhead;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            $table->string('pools_name')->nullable()->after('business_name');
            $table->string('pools_logo_path')->nullable()->after('pools_name');
            $table->string('pools_phone', 50)->nullable()->after('pools_logo_path');
        });

        // The activity trades under its own name, so an install already running
        // gets it written in rather than left to be typed.
        DB::table('settings')->whereNull('pools_name')->update([
            'pools_name' => PoolsLetterhead::NAME,
            'pools_phone' => PoolsLetterhead::PHONE,
        ]);
    }

    public function down(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            $table->dropColumn(['pools_name', 'pools_logo_path', 'pools_phone']);
        });
    }
};
