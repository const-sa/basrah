<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * تسعيرة الوحدة أو القسم لكل فترة، مفصولة بين أيام الأسبوع ونهاية الأسبوع.
 *
 * section_id = null  →  سعر الوحدة كاملة
 * section_id = قيمة  →  سعر ذلك القسم منفردًا
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('unit_prices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('unit_id')->constrained('units')->cascadeOnDelete();
            $table->foreignId('unit_section_id')->nullable()->constrained('unit_sections')->cascadeOnDelete();

            $table->enum('period', ['morning', 'evening', 'full_day', 'overnight']);
            $table->decimal('weekday_price', 12, 2)->default(0);
            $table->decimal('weekend_price', 12, 2)->default(0);

            // العربون المطلوب: مبلغ ثابت أو نسبة من الإجمالي
            $table->decimal('deposit_amount', 12, 2)->nullable();
            $table->decimal('deposit_percent', 5, 2)->nullable();

            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['unit_id', 'unit_section_id', 'period'], 'unit_prices_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('unit_prices');
    }
};
