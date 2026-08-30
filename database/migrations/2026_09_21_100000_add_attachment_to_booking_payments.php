<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * إيصال الدفعة مرفقًا بها.
 *
 * الحوالة البنكية لا يشهد عليها إلا صورتها: رقم العملية في «المرجع» يُكتب
 * باليد ويُخطئ، أما الإيصال فهو ما يُراجَع عليه الحساب ويُحتجّ به إن أنكر
 * العميل. فيُحفظ مع الدفعة نفسها لا مع الحجز — لكل تحويلٍ إيصاله.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('booking_payments', function (Blueprint $table) {
            $table->string('attachment_path')->nullable()->after('reference')
                ->comment('إيصال التحويل أو ما يُثبت الدفعة');
        });
    }

    public function down(): void
    {
        Schema::table('booking_payments', function (Blueprint $table) {
            $table->dropColumn('attachment_path');
        });
    }
};
