<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * الوحدات القابلة للحجز: القاعات والشاليهات.
 * كل وحدة مركز تكلفة مستقل لقياس ربحيتها على حدة.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('units', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique()->comment('ترميز فريد يمنع الالتباس بين الوحدات المتشابهة الأسماء');
            $table->string('name');
            $table->enum('type', ['hall', 'chalet'])->default('chalet')->comment('قاعة / شاليه');

            // نمط الحجز: الوحدة كاملة فقط، أقسام فقط، أو كلاهما (السيناريو الثالث)
            $table->enum('bookable_mode', ['whole', 'sections', 'both'])->default('both');

            /**
             * قاعدة الخصوصية بين الأقسام (§1.1 من وثيقة النطاق):
             *  - open      : أقسام مستقلة تمامًا، يجوز لعميلين مختلفين حجز قسمين في نفس اليوم
             *  - exclusive : حجز أي قسم يحجب بقية الأقسام عن أي عميل آخر (مرافق مشتركة)
             */
            $table->enum('privacy_mode', ['open', 'exclusive'])->default('exclusive');

            $table->unsignedInteger('capacity')->nullable();
            $table->text('description')->nullable();
            $table->text('notes')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('units');
    }
};
