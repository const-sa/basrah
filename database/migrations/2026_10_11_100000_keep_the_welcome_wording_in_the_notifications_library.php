<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * نصّ الترحيب كان يُكتب في شاشة بوابة الواتساب ويُقرأ احتياطاً لقالب المكتبة،
 * فصار للترحيب نصّان في مكانين. المكتبة هي موضع نصوص الرسائل، فما كُتب في
 * الإعدادات يُنقل إليها قالباً عاماً ثم يُطوى العمود.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('settings', 'wa_welcome_template')) {
            return;
        }

        $this->moveWordingToLibrary();

        Schema::table('settings', function (Blueprint $table) {
            $table->dropColumn('wa_welcome_template');
        });
    }

    public function down(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            $table->text('wa_welcome_template')->nullable()->after('wa_welcome_enabled');
        });
    }

    /** نصٌّ كتبه العميل لا يُرمى: يصير قالب ترحيبٍ عام إن لم يكن له واحد. */
    private function moveWordingToLibrary(): void
    {
        $wording = trim((string) DB::table('settings')->value('wa_welcome_template'));

        $alreadyHasOne = DB::table('notification_templates')
            ->where('event', 'welcome')
            ->where('category', 'general')
            ->exists();

        if ($wording === '' || $alreadyHasOne) {
            return;
        }

        DB::table('notification_templates')->insert([
            'category' => 'general',
            'event' => 'welcome',
            'title' => 'ترحيب بعميل جديد',
            'body' => $wording,
            'is_active' => true,
            'sort_order' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
};
