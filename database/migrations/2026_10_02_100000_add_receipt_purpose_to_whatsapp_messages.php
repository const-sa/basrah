<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * غرضٌ جديد لرسائل الواتساب: سند دفعة بعينها يُرسل بمعزل عن إشعار السداد
 * العام — الأول إيصالٌ بمبلغ دفعة واحدة، والثاني تنبيهٌ بأن دفعة وصلت.
 */
return new class extends Migration
{
    private const PURPOSES = [
        'booking_confirm', 'reminder', 'balance_reminder', 'contract',
        'invoice', 'payment', 'receipt', 'cancellation', 'welcome', 'other',
    ];

    public function up(): void
    {
        Schema::table('whatsapp_messages', function (Blueprint $table) {
            $table->enum('purpose', self::PURPOSES)->default('other')->change();
        });
    }

    public function down(): void
    {
        DB::table('whatsapp_messages')->where('purpose', 'receipt')->update(['purpose' => 'other']);

        Schema::table('whatsapp_messages', function (Blueprint $table) {
            $table->enum('purpose', [
                'booking_confirm', 'reminder', 'balance_reminder', 'contract',
                'invoice', 'payment', 'cancellation', 'welcome', 'other',
            ])->default('other')->change();
        });
    }
};
