<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * مصدر الحجز: من شاشة الموظف أم من الموقع العام.
 *
 * الحجز القادم من الموقع لا موظف وراءه ولا اتفاق سبقه، فهو يحتاج متابعة
 * قبل أن يُعتمد. وبلا هذا العمود لا يفرّق سجل الحجوزات بين حجزٍ أكّده
 * موظف وحجزٍ ضغط زرّه زائر — فيُعامَلان سواءً وهما ليسا سواء.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->enum('source', ['admin', 'online'])->default('admin')->after('created_by');
            $table->index('source');
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropIndex(['source']);
            $table->dropColumn('source');
        });
    }
};
