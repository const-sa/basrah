<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * تحسينات الوحدات: شعار، مدير من الموظفين، ومرافق تُدار من جدول.
 *
 * المرافق كانت قائمة ثابتة في الكود ومصفوفة JSON على القسم. صارت جدولًا
 * وعلاقة، فإضافة مرفق (ساونا، ملعب بادل…) صارت إدخال بيانات لا تعديل مصدر،
 * وصار عدّ الأقسام التي تشترك في مرفق ممكنًا باستعلام لا بمسح JSON.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('facilities', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('icon')->nullable()->comment('اسم أيقونة lucide');
            $table->text('description')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // مرافق القسم — تحلّ محل العمود shared_facilities من نوع JSON
        Schema::create('facility_unit_section', function (Blueprint $table) {
            $table->id();
            $table->foreignId('facility_id')->constrained('facilities')->cascadeOnDelete();
            $table->foreignId('unit_section_id')->constrained('unit_sections')->cascadeOnDelete();
            $table->boolean('is_shared')->default(true)
                ->comment('مشترك مع بقية أقسام الوحدة — يؤثر على قاعدة الخصوصية');
            $table->timestamps();

            $table->unique(['facility_id', 'unit_section_id'], 'facility_section_unique');
        });

        Schema::table('units', function (Blueprint $table) {
            $table->string('logo_path')->nullable()->after('name');
            // مدير الوحدة يُختار من ملفات الموظفين
            $table->foreignId('manager_id')->nullable()->after('logo_path')
                ->constrained('employees')->nullOnDelete();
        });

        Schema::table('unit_sections', function (Blueprint $table) {
            // سعة القسم غير مستخدمة في أي حساب — سعة الوحدة تكفي
            $table->dropColumn(['capacity', 'shared_facilities']);
        });
    }

    public function down(): void
    {
        Schema::table('unit_sections', function (Blueprint $table) {
            $table->unsignedInteger('capacity')->nullable();
            $table->json('shared_facilities')->nullable();
        });

        Schema::table('units', function (Blueprint $table) {
            $table->dropConstrainedForeignId('manager_id');
            $table->dropColumn('logo_path');
        });

        Schema::dropIfExists('facility_unit_section');
        Schema::dropIfExists('facilities');
    }
};
