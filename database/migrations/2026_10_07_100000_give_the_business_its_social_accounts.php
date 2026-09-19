<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            // Social accounts beside the phone: a handle or a profile link.
            $table->string('instagram')->nullable()->after('email');
            $table->string('tiktok')->nullable()->after('instagram');
            $table->string('snapchat')->nullable()->after('tiktok');
        });
    }

    public function down(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            $table->dropColumn(['instagram', 'tiktok', 'snapchat']);
        });
    }
};
