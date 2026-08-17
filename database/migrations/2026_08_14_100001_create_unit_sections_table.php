<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * أقسام الوحدة (قسم رجال / قسم نساء / جناح إضافي).
 * العميل يستطيع حجز قسم واحد دون بقية الوحدة، مع منع التعارض الهرمي:
 * حجز الوحدة كاملة يقفل كل أقسامها، ووجود قسم محجوز يمنع حجز الوحدة كاملة.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('unit_sections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('unit_id')->constrained('units')->cascadeOnDelete();
            $table->string('name');
            $table->enum('gender', ['men', 'women', 'mixed'])->default('mixed');
            $table->unsignedInteger('capacity')->nullable();

            // مرافق مشتركة مع بقية الأقسام (مسبح، مطبخ، مدخل، موقف) — تؤثر على قاعدة الخصوصية
            $table->json('shared_facilities')->nullable();

            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['unit_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('unit_sections');
    }
};
