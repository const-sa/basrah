<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ساعات كل فترة على مستوى الوحدة نفسها.
 *
 * ساعات الحجز كانت إعدادًا واحدًا للنظام كله: دخول الشاليهات جميعًا الساعة
 * الرابعة وخروجها الثانية عشرة. والواقع أن كل شاليه يُدار بساعاته: واحد
 * يُسلَّم عصرًا وآخر ظهرًا، والقاعة تُفتح لمناسبة الصباح في وقت لا تُفتح فيه
 * الأخرى. فصار لكل وحدة أن تكتب ساعات فترتها، ويبقى إعداد النظام أصلًا
 * ترجع إليه الوحدة التي لم تُكتب لها ساعات.
 *
 * الشكل: {"overnight": {"start": "16:00", "end": "12:00"}, "morning": {...}}
 * والفترة الغائبة — أو المتروكة فارغة — تأخذ ساعات الإعدادات كما كانت.
 *
 * ولماذا على الوحدة لا على صف التسعيرة؟ لأن الساعة ليست سعرًا: صفوف التسعيرة
 * صفٌّ لكل (قسم × فترة)، وساعة دخول تختلف من غرفة إلى غرفة في الشاليه الواحد
 * تجعل مدى الحجز يتبدّل بتبدّل الغرفة المختارة، وعليه يُبنى كشف التعارض.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('units', function (Blueprint $table) {
            $table->json('period_hours')->nullable()->after('security_deposit');
        });
    }

    public function down(): void
    {
        Schema::table('units', function (Blueprint $table) {
            $table->dropColumn('period_hours');
        });
    }
};
