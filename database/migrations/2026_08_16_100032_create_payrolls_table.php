<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * مسيّر الرواتب الشهري وسطوره.
 * سطر كل موظف يُجمَّد بقيمه وقت الاعتماد حتى لا يتغيّر مسيّر مُعتمد
 * إذا عُدِّل راتب الموظف الأساسي لاحقًا.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payrolls', function (Blueprint $table) {
            $table->id();
            $table->string('number')->unique();
            $table->unsignedSmallInteger('year');
            $table->unsignedTinyInteger('month');

            $table->enum('status', ['draft', 'approved', 'paid'])->default('draft');

            $table->decimal('total_gross', 14, 2)->default(0);
            $table->decimal('total_deductions', 14, 2)->default(0);
            $table->decimal('total_net', 14, 2)->default(0);

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('journal_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();

            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['year', 'month']);
        });

        Schema::create('payroll_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payroll_id')->constrained('payrolls')->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->foreignId('unit_id')->nullable()->constrained('units')->nullOnDelete()
                ->comment('مركز تكلفة الراتب');

            $table->decimal('basic_salary', 12, 2)->default(0);
            $table->decimal('allowances', 12, 2)->default(0);
            $table->decimal('overtime_amount', 12, 2)->default(0);
            $table->decimal('bonus', 12, 2)->default(0);

            $table->decimal('absence_deduction', 12, 2)->default(0);
            $table->decimal('advance_deduction', 12, 2)->default(0);
            $table->decimal('other_deduction', 12, 2)->default(0);

            $table->decimal('gross', 12, 2)->default(0);
            $table->decimal('net', 12, 2)->default(0);

            $table->unsignedSmallInteger('worked_days')->default(0);
            $table->unsignedSmallInteger('absent_days')->default(0);
            $table->decimal('overtime_hours', 8, 2)->default(0);

            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['payroll_id', 'employee_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_lines');
        Schema::dropIfExists('payrolls');
    }
};
