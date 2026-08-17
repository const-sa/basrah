<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * إلغاء فكرة المواسم من النظام.
 *
 * كانت نسبة الموسم تُضرب في السعر الأساسي، فيدخل الموظف «زواج» بـ1,000 ثم
 * يرى في الحجز 1,300 دون أن يغيّر شيئًا. فرق الأعياد والإجازات يُدخَل الآن
 * صراحةً في تسعيرة القاعة أو في سعر نوع المناسبة: السعر المعروض هو المُدخَل.
 *
 * جدول التسعيرة (unit_prices) لا يشير إلى seasons بمفتاح أجنبي، فحذف الجدول
 * لا يمسّ أي بيانات أخرى. والحجوزات لا تحفظ الموسم أصلًا — كان يُحتسب لحظة
 * التسعير ويُعرض شارةً فقط، فالحجوزات القائمة تبقى بمبالغها المحفوظة كما هي.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('seasons');
    }

    /**
     * التراجع يعيد الجدول فارغًا لا ببياناته: المواسم المحذوفة لا تُستعاد،
     * والكود الذي كان يقرؤه محذوف أيضًا.
     */
    public function down(): void
    {
        Schema::create('seasons', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->date('starts_on');
            $table->date('ends_on');
            $table->decimal('rate_percent', 6, 2)->default(100);
            $table->unsignedInteger('priority')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['starts_on', 'ends_on']);
        });
    }
};
