<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ساعتا المناسبة حين تخالفان ساعات الفترة.
 *
 * القاعة تُباع يوماً كاملاً بساعاتٍ مكتوبة في الإعدادات، لكن المناسبة قد
 * تُتفق على غيرها: من الرابعة عصراً إلى العاشرة مساءً في قاعةٍ يومها الكامل
 * من التاسعة إلى الواحدة. فتُكتب الساعتان على الحجز نفسه، ويُبنى منهما مداه
 * (starts_at → ends_at) الذي يُكشف به التعارض.
 *
 * وفارغتان تعنيان «كما في الفترة»: الحجز الذي لم يُخصَّص له شيء يقرأ ساعاته
 * من الإعدادات كما كان، فلا يتغيّر حجزٌ قائم بهذه الهجرة.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->time('start_time')->nullable()->after('days_count');
            $table->time('end_time')->nullable()->after('start_time');
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropColumn(['start_time', 'end_time']);
        });
    }
};
