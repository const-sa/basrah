<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ملاحظات العميل (§5 من العرض المعتمد).
 *
 * ما يُقال عن العميل ولا يُكتب يضيع بضياع من سمعه: «يفضّل القاعة مساءً»،
 * «لا يُقبل منه شيك»، «تأخّر في السداد مرتين». فملف العميل يحتاج موضعًا
 * لهذا، وإلا كُتب في خانة الاسم أو لم يُكتب.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->text('notes')->nullable()->after('is_walk_in');
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn('notes');
        });
    }
};
