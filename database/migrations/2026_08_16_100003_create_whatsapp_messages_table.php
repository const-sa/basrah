<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * سجل رسائل واتساب.
 *
 * سبب وجوده: تسعير Meta لكل *محادثة* لا لكل رسالة (§3.1)، وبند التجديد
 * السنوي يحدّ عدد المحادثات (§4.4). بلا هذا السجل لا سبيل لإثبات
 * الاستهلاك الفعلي عند الخلاف مع العميل.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_messages', function (Blueprint $table) {
            $table->id();
            $table->string('to_number');
            $table->text('body');

            $table->enum('category', ['utility', 'authentication', 'marketing'])->default('utility');
            $table->enum('purpose', ['booking_confirm', 'reminder', 'contract', 'invoice', 'payment', 'welcome', 'other'])
                ->default('other');

            $table->enum('status', ['queued', 'sent', 'failed'])->default('queued');
            $table->text('error')->nullable();
            $table->timestamp('sent_at')->nullable();

            // مرجع اختياري للكيان الذي تسبّب بالرسالة (حجز، عقد، فاتورة)
            $table->nullableMorphs('related');
            $table->foreignId('sent_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->index(['to_number', 'created_at']);
            $table->index(['status', 'category']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_messages');
    }
};
