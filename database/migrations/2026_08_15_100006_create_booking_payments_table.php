<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * دفعات الحجز: العربون ثم الدفعات التالية حتى السداد الكامل.
 * كل دفعة ستولّد لاحقًا قيدًا محاسبيًا على مركز تكلفة الوحدة.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('booking_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->constrained('bookings')->cascadeOnDelete();
            $table->foreignId('received_by')->nullable()->constrained('users')->nullOnDelete();

            $table->enum('type', ['deposit', 'payment', 'refund'])->default('payment');
            $table->enum('method', ['cash', 'transfer', 'card', 'online'])->default('cash');
            $table->decimal('amount', 12, 2);
            $table->date('paid_on');
            $table->string('reference')->nullable()->comment('رقم العملية أو الإيصال');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['booking_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('booking_payments');
    }
};
