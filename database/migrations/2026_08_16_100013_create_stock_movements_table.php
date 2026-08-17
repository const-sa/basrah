<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * حركات المخزون — كل تغيّر في الرصيد يُسجَّل هنا بلا استثناء.
 *
 * السبب: عمود stock_qty على الصنف رقم مُجمَّع سريع القراءة، لكنه وحده
 * لا يُثبت شيئًا عند اختلاف الجرد. هذا الجدول هو الأثر الذي يُراجَع.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('item_id')->constrained('items')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->enum('type', ['purchase', 'sale', 'return', 'adjustment', 'bundle_consume', 'opening'])
                ->comment('شراء / بيع / مرتجع / تسوية جرد / خصم مكوّنات حزمة / رصيد افتتاحي');

            $table->decimal('quantity', 14, 3)->comment('موجب يزيد الرصيد وسالب ينقصه');
            $table->decimal('unit_cost', 12, 2)->default(0);
            $table->decimal('balance_after', 14, 3)->default(0)->comment('الرصيد بعد الحركة — لتتبّع التسلسل');

            $table->nullableMorphs('reference');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['item_id', 'created_at']);
            $table->index('type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_movements');
    }
};
