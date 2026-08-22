<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * رقم الواتساب المراد ربطه وزمن آخر ربط ناجح.
 *
 * البوابة لا تسمح بالاستعلام عن المعرّفات بالرقم، فالرقم يُحفظ عندنا
 * ليُقارَن بالرقم الذي تُعيده الجلسة بعد المسح — بلا هذه المقارنة قد
 * يُربط جهاز آخر ويبقى النظام يظن أنه يراسل من رقم النشاط.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            $table->string('wa_number', 30)->nullable()->after('wa_access_token');
            $table->timestamp('wa_connected_at')->nullable()->after('wa_number');
        });
    }

    public function down(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            $table->dropColumn(['wa_number', 'wa_connected_at']);
        });
    }
};
