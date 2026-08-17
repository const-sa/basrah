<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * باقات القاعات: اسم الباقة وبنودها بأعدادها.
 *
 * البند سطر حر (اسم + عدد) لا صنف من المخزون: «عدد المعازيم رجال 500»
 * و«صبّابين 4» ليست أصنافًا تُخصم من مستودع، إنما مواصفات تُكتب في
 * العرض والعقد. لذلك اسم البند نص لا مفتاح أجنبي.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('packages', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();

            // فارغ = الباقة متاحة لكل القاعات، وإلا فهي خاصة بقاعة بعينها
            $table->foreignId('unit_id')->nullable()->constrained('units')->cascadeOnDelete();

            $table->decimal('price', 12, 2)->default(0);
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['unit_id', 'is_active']);
        });

        Schema::create('package_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('package_id')->constrained('packages')->cascadeOnDelete();

            $table->string('name')->comment('الصنف: عدد المعازيم رجال، صبّابين…');
            $table->decimal('quantity', 12, 2)->default(1);
            $table->string('unit_label')->nullable()->comment('شخص، فرد، طبق… اختياري');
            $table->text('notes')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('package_items');
        Schema::dropIfExists('packages');
    }
};
