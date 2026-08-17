<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * قوالب العقود بحقول ديناميكية على شكل {{مفتاح}}.
 * القالب يُحرَّر من الواجهة، ويُملأ وقت التوليد من بيانات الحجز والعميل.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contract_templates', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();
            $table->longText('body');
            $table->text('terms')->nullable()->comment('الشروط والأحكام تُلحق بنهاية العقد');
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contract_templates');
    }
};
