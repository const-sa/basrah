<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * الحجز — الجدول المحوري في النظام.
 *
 * scope = whole    →  الوحدة كاملة (يقفل كل أقسامها)
 * scope = sections →  أقسام محددة مذكورة في جدول booking_section
 *
 * التعارض يُحسب على مدى زمني صريح (starts_at → ends_at) مشتق من التاريخ والفترة،
 * وهو أدق من مقارنة الفترات اسميًا لأن «المبيت» يمتد إلى اليوم التالي.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bookings', function (Blueprint $table) {
            $table->id();
            $table->string('reference')->unique()->comment('رقم حجز فريد يظهر للعميل وفي العقد');

            $table->foreignId('unit_id')->constrained('units')->cascadeOnDelete();
            $table->foreignId('client_id')->nullable()->constrained('clients')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->enum('scope', ['whole', 'sections'])->default('whole');
            $table->enum('period', ['morning', 'evening', 'full_day', 'overnight']);

            $table->date('booking_date');
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');

            $table->enum('status', ['tentative', 'confirmed', 'completed', 'cancelled', 'no_show'])
                ->default('tentative')
                ->comment('مبدئي ← مؤكد ← مكتمل ← ملغي ← لم يحضر');

            // الحساب المالي
            $table->decimal('base_amount', 12, 2)->default(0)->comment('سعر الوحدة أو الأقسام قبل الإضافات');
            $table->decimal('addons_amount', 12, 2)->default(0);
            $table->decimal('discount_amount', 12, 2)->default(0);
            $table->decimal('total_amount', 12, 2)->default(0);
            $table->decimal('deposit_amount', 12, 2)->default(0)->comment('العربون المطلوب');
            $table->decimal('paid_amount', 12, 2)->default(0)->comment('المسدَّد فعليًا — يُحدَّث من الدفعات');

            $table->unsignedInteger('guests_count')->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->text('cancellation_reason')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['unit_id', 'starts_at', 'ends_at']);
            $table->index(['booking_date', 'status']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bookings');
    }
};
