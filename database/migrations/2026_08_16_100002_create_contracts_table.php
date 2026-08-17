<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * العقد المولَّد من حجز.
 *
 * نص العقد يُجمَّد في العمود body وقت التوليد ولا يُعاد اشتقاقه من القالب،
 * لأن تعديل القالب لاحقًا يجب ألا يغيّر عقدًا وُقّع وأُرسل للعميل.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contracts', function (Blueprint $table) {
            $table->id();
            $table->string('number')->unique();
            $table->foreignId('booking_id')->constrained('bookings')->cascadeOnDelete();
            $table->foreignId('client_id')->nullable()->constrained('clients')->nullOnDelete();
            $table->foreignId('contract_template_id')->nullable()->constrained('contract_templates')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->longText('body')->comment('نص العقد بعد ملء الحقول — مُجمَّد');
            $table->json('data')->nullable()->comment('القيم المستخدمة في الملء وقت التوليد');

            $table->enum('status', ['draft', 'sent', 'signed', 'cancelled'])->default('draft');
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('signed_at')->nullable();
            $table->string('pdf_path')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['booking_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contracts');
    }
};
