<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * أصناف المحل — أربعة أنواع (§1.3):
 *   stock    : مخزني، له رصيد ينقص بالبيع
 *   service  : خدمي، بلا رصيد
 *   bundle   : حزمة/مشروع، بيعه يخصم مكوّناته من المخزون
 *   measured : بالقياس (متر / متر مربع)، الكمية كسرية
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('item_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('items', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('barcode')->nullable()->unique();
            $table->string('name');
            $table->foreignId('item_category_id')->nullable()->constrained('item_categories')->nullOnDelete();

            $table->enum('type', ['stock', 'service', 'bundle', 'measured'])->default('stock');
            $table->enum('unit', ['piece', 'meter', 'sqm', 'hour', 'kg', 'liter'])->default('piece');

            $table->decimal('cost', 12, 2)->default(0);
            $table->decimal('price', 12, 2)->default(0);
            $table->decimal('tax_rate', 5, 2)->default(0);

            // الرصيد يخص النوعين stock و measured فقط
            $table->decimal('stock_qty', 14, 3)->default(0);
            $table->decimal('reorder_point', 14, 3)->default(0)->comment('حد التنبيه بإعادة الطلب');

            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['type', 'is_active']);
        });

        // مكوّنات الحزمة: بيع الحزمة يخصم هذه المكوّنات من المخزون
        Schema::create('item_components', function (Blueprint $table) {
            $table->id();
            $table->foreignId('item_id')->constrained('items')->cascadeOnDelete();
            $table->foreignId('component_id')->constrained('items')->cascadeOnDelete();
            $table->decimal('quantity', 14, 3)->default(1);
            $table->timestamps();

            $table->unique(['item_id', 'component_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('item_components');
        Schema::dropIfExists('items');
        Schema::dropIfExists('item_categories');
    }
};
