<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * مجموعات الأصناف — تحديد محفوظ لأصناف تُطلب معًا.
 *
 * المجموعة اختصارٌ لا سلعة: لا سعر لها ولا رصيد، وإنما تُملأ بها سطور
 * الفاتورة أو عرض السعر دفعةً واحدة بدل اختيار الأصناف واحدًا واحدًا.
 * ولذلك لا تُحفظ فيها أسعار — كل صنف يدخل بسعره يوم البيع لا يوم الحفظ.
 *
 * وهي غير «باقات القاعات» (packages): تلك بنود وصفية بأعداد تخصّ الحجز،
 * وهذه أصنافٌ حقيقية من المستودع تخصّ الفواتير.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('item_groups', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('item_group_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('item_group_id')->constrained('item_groups')->cascadeOnDelete();
            $table->foreignId('item_id')->constrained('items')->cascadeOnDelete();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            // الصنف مذكور مرة واحدة في المجموعة — تكراره لا يعني شيئًا،
            // فالمجموعة تحديدٌ لا كمية.
            $table->unique(['item_group_id', 'item_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('item_group_items');
        Schema::dropIfExists('item_groups');
    }
};
