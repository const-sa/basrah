<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * غرضان جديدان لرسائل الواتساب (§14): إشعار الإلغاء، وتذكير المبلغ المتبقي.
 *
 * الغرض عمودٌ محصور بقائمة لا نصٌّ حر، لأن سجل الرسائل يُقرأ للمحاسبة على
 * استهلاك المحادثات — وقائمةٌ مفتوحة تجعل الجمع على «تذكير» و«تذكيرات»
 * و«reminder» ثلاثة أبواب لبابٍ واحد.
 */
return new class extends Migration
{
    private const PURPOSES = [
        'booking_confirm', 'reminder', 'balance_reminder', 'contract',
        'invoice', 'payment', 'cancellation', 'welcome', 'other',
    ];

    public function up(): void
    {
        Schema::table('whatsapp_messages', function (Blueprint $table) {
            $table->enum('purpose', self::PURPOSES)->default('other')->change();
        });
    }

    public function down(): void
    {
        // ما أُرسل بغرضٍ يزول يعود «أخرى» — وإلا رفضت القاعدة قيمًا قائمة.
        DB::table('whatsapp_messages')
            ->whereIn('purpose', ['balance_reminder', 'cancellation'])
            ->update(['purpose' => 'other']);

        Schema::table('whatsapp_messages', function (Blueprint $table) {
            $table->enum('purpose', [
                'booking_confirm', 'reminder', 'contract', 'invoice', 'payment', 'welcome', 'other',
            ])->default('other')->change();
        });
    }
};
