<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            // رقم هوية العميل — اختياري لكلا النوعين (عادي/ضريبي)،
            // ويُستعمل في تعبئة حقل الهوية داخل العقود.
            $table->string('national_id')->nullable()->after('city');
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn('national_id');
        });
    }
};
