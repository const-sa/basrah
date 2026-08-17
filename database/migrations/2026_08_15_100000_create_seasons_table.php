<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * المواسم: فترات لها تسعيرة مختلفة (الأعياد، الإجازات، الصيف).
 * الموسم ذو الأولوية الأعلى يتغلّب عند تداخل موسمين.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('seasons', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->date('starts_on');
            $table->date('ends_on');

            // نسبة تُطبَّق على السعر الأساسي (مثل 150 = زيادة 50%)
            $table->decimal('rate_percent', 6, 2)->default(100);

            $table->unsignedInteger('priority')->default(0)->comment('الأعلى يتغلّب عند تداخل موسمين');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['starts_on', 'ends_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seasons');
    }
};
