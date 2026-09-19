<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            // The price agreed with this client, standing in for the table's.
            // Null means the booking is priced the usual way.
            $table->decimal('agreed_amount', 12, 2)->nullable()->after('base_amount')
                ->comment('المبلغ المتفق عليه — يحل محل تسعيرة الوحدة ونوع المناسبة');
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropColumn('agreed_amount');
        });
    }
};
