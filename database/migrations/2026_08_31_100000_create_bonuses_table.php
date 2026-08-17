<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * مكافآت الموظفين — تُصرف مع مسيّر الشهر الذي مُنحت فيه.
 *
 * المكافأة سجلٌّ مستقل لا حقلٌ يُكتب في سطر المسيّر مباشرةً، لأن التوليد
 * يمسح السطور ويعيد بناءها: مكافأةٌ مكتوبة في السطر تضيع مع أول إعادة
 * توليد، بينما السجل المستقل يُقرأ في كل مرة فيثبت. وهو نفس ما تفعله
 * السلف لنفس السبب.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bonuses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();

            $table->decimal('amount', 12, 2);
            $table->string('reason')->nullable()->comment('سبب المكافأة كما يظهر في المسيّر');
            $table->date('granted_on')->comment('تاريخ المنح — يحدد الشهر الذي تُصرف فيه');

            $table->enum('status', ['pending', 'approved', 'paid', 'cancelled'])->default('pending');

            // المسيّر الذي صُرفت ضمنه — يمنع صرفها مرتين ويبيّن أين ذهبت.
            $table->foreignId('payroll_id')->nullable()->constrained('payrolls')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();

            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['employee_id', 'granted_on']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bonuses');
    }
};
