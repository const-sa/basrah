<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * فواتير البيع والمرتجعات.
 * unit_id يربط الفاتورة بمركز تكلفة (قاعة/شاليه) عند البيع لحساب حجز،
 * وإلا فهي مبيعات المحل نفسه.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales', function (Blueprint $table) {
            $table->id();
            $table->string('number')->unique();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('client_id')->nullable()->constrained('clients')->nullOnDelete();
            $table->foreignId('unit_id')->nullable()->constrained('units')->nullOnDelete();
            $table->foreignId('booking_id')->nullable()->constrained('bookings')->nullOnDelete();

            $table->enum('type', ['sale', 'return'])->default('sale');
            $table->foreignId('original_sale_id')->nullable()->constrained('sales')->nullOnDelete()
                ->comment('الفاتورة الأصلية عند المرتجع');

            $table->decimal('subtotal', 12, 2)->default(0);
            $table->decimal('discount_amount', 12, 2)->default(0);
            $table->decimal('tax_amount', 12, 2)->default(0);
            $table->decimal('total_amount', 12, 2)->default(0);
            $table->decimal('cost_amount', 12, 2)->default(0)->comment('تكلفة المباع — تُجمَّد لحساب الربح');

            $table->enum('method', ['cash', 'card', 'transfer', 'account'])->default('cash');
            $table->decimal('paid_amount', 12, 2)->default(0);

            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['type', 'created_at']);
        });

        Schema::create('sale_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sale_id')->constrained('sales')->cascadeOnDelete();
            $table->foreignId('item_id')->constrained('items')->cascadeOnDelete();

            $table->decimal('quantity', 14, 3)->default(1);
            $table->decimal('unit_price', 12, 2)->default(0);
            $table->decimal('unit_cost', 12, 2)->default(0);
            $table->decimal('discount_amount', 12, 2)->default(0);
            $table->decimal('tax_amount', 12, 2)->default(0);
            $table->decimal('total', 12, 2)->default(0);

            $table->timestamps();
            $table->index('item_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sale_items');
        Schema::dropIfExists('sales');
    }
};
