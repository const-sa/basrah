<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * السجل الرقابي (§14 من العرض المعتمد): من أنشأ ومن عدّل ومن حذف، ومتى.
 *
 * السجل يُكتب ولا يُقرأ إلا للمراجعة، فلا عمود updated_at له: صفٌّ رقابي
 * يُعدَّل بعد كتابته لا يشهد على شيء. ولا مفاتيح أجنبية على السجل نفسه
 * غير المستخدم، لأن الصف يجب أن يبقى بعد اختفاء ما يشير إليه — وهو
 * بالضبط ما يُراجَع: الحذف.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            // اسم الفاعل يُصوَّر وقت الفعل: حذف المستخدم لاحقًا يُفرِّغ المفتاح
            // الأجنبي، ولو لم يبقَ الاسم لصار السجل «عمليةٌ فعلها أحدٌ ما».
            $table->string('actor_name')->nullable();

            $table->string('event', 30)->comment('created / updated / deleted / restored');

            $table->string('auditable_type');
            $table->unsignedBigInteger('auditable_id');
            // وصف السجل المتأثر كما يفهمه المراجع: «الحجز a-12» لا «Booking#12»
            $table->string('auditable_label')->nullable();

            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();

            $table->string('ip_address', 45)->nullable();
            $table->string('url')->nullable();
            $table->string('method', 10)->nullable();
            $table->text('user_agent')->nullable();

            $table->timestamp('created_at')->useCurrent();

            $table->index(['auditable_type', 'auditable_id']);
            $table->index(['user_id', 'created_at']);
            $table->index(['event', 'created_at']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
