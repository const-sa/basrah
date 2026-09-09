<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * الخصومات والبدلات — تنضمّ إلى السلف والمكافآت في شاشة واحدة:
 *
 * الخصم عكس المكافأة: يُطرح من راتب شهره لا يُضاف إليه (غرامة أو استقطاع
 * تأديبي)، وهو فوري لا على أقساط كالسلفة — فبنيته مطابقة لبنية المكافأة
 * تمامًا. والبدل هنا هو المكافأة نفسها بنيةً: إضافة مستقلة عن بدلات
 * الموظف الثابتة (سكن/نقل) لمناسبة شهر بعينه.
 *
 * وسجلٌّ مستقل لا حقلٌ في سطر المسيّر مباشرةً لنفس سبب المكافأة والسلفة:
 * التوليد يمسح السطور ويعيد بناءها.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('deductions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();

            $table->decimal('amount', 12, 2);
            $table->string('reason')->nullable()->comment('سبب الخصم كما يظهر في المسيّر');
            $table->date('deducted_on')->comment('تاريخ الخصم — يحدد الشهر الذي يُستقطع فيه');

            $table->enum('status', ['pending', 'approved', 'paid', 'cancelled'])->default('pending');

            $table->foreignId('payroll_id')->nullable()->constrained('payrolls')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();

            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['employee_id', 'deducted_on']);
            $table->index('status');
        });

        Schema::create('allowances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();

            $table->decimal('amount', 12, 2);
            $table->string('reason')->nullable()->comment('سبب البدل كما يظهر في المسيّر');
            $table->date('granted_on')->comment('تاريخ المنح — يحدد الشهر الذي يُصرف فيه');

            $table->enum('status', ['pending', 'approved', 'paid', 'cancelled'])->default('pending');

            $table->foreignId('payroll_id')->nullable()->constrained('payrolls')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();

            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['employee_id', 'granted_on']);
            $table->index('status');
        });

        // عمود البدل الظرفي موجود هنا، أما الخصم الظرفي فعموده (other_deduction)
        // كان موجودًا في سطر المسيّر منذ إنشائه بلا مصدر يملؤه — هذه الهجرة تمنحه مصدره.
        Schema::table('payroll_lines', function (Blueprint $table) {
            $table->decimal('other_allowance', 12, 2)->default(0)->after('bonus');
        });
    }

    public function down(): void
    {
        Schema::table('payroll_lines', function (Blueprint $table) {
            $table->dropColumn('other_allowance');
        });

        Schema::dropIfExists('allowances');
        Schema::dropIfExists('deductions');
    }
};
