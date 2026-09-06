<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * العربون المدفوع وقت تحرير عقد التركيب أو الصيانة — سند قبض على العقد.
 *
 * A pools contract is drawn from a quotation or on the client alone, and
 * neither collects money: the sheet printed «المدفوع 0.00» against the full
 * total for the life of the contract, and the deposit the client actually
 * handed over at signing was either never recorded or typed onto the paper by
 * hand, where the books never saw it.
 *
 * A booking has carried its own payment ledger since it was written, so its
 * contract reads the real figures. This gives a pools contract the same thing
 * by the door already open in the accounts: the receipt voucher. The deposit
 * becomes a posted سند قبض tied to the contract, exactly as a payment on an
 * invoice is tied to its sale, and `paid_amount` is what those vouchers add up
 * to — so «المدفوع والمتبقي» on the sheet is the till's answer, not a typed
 * one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contracts', function (Blueprint $table) {
            $table->decimal('paid_amount', 14, 2)->default(0)->after('data')
                ->comment('مجموع سندات القبض المرحَّلة على العقد');
        });

        Schema::table('vouchers', function (Blueprint $table) {
            $table->foreignId('contract_id')->nullable()->after('sale_id')
                ->constrained('contracts')->nullOnDelete()
                ->comment('العقد الذي يسدّده السند');
        });
    }

    public function down(): void
    {
        Schema::table('vouchers', function (Blueprint $table) {
            $table->dropConstrainedForeignId('contract_id');
        });

        Schema::table('contracts', function (Blueprint $table) {
            $table->dropColumn('paid_amount');
        });
    }
};
