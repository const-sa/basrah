<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * الحضور والانصراف والإجازات والسلف.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->date('attendance_date');

            $table->time('check_in')->nullable();
            $table->time('check_out')->nullable();

            $table->enum('status', ['present', 'absent', 'late', 'leave', 'holiday'])->default('present');
            $table->decimal('worked_hours', 6, 2)->default(0);
            $table->decimal('overtime_hours', 6, 2)->default(0);
            $table->text('notes')->nullable();

            $table->timestamps();

            $table->unique(['employee_id', 'attendance_date']);
            $table->index('attendance_date');
        });

        Schema::create('leaves', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();

            $table->enum('type', ['annual', 'sick', 'unpaid', 'emergency'])->default('annual');
            $table->date('starts_on');
            $table->date('ends_on');
            $table->unsignedInteger('days')->default(1);

            $table->enum('status', ['pending', 'approved', 'rejected'])->default('pending');
            $table->text('reason')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();

            $table->timestamps();
            $table->index(['employee_id', 'status']);
        });

        Schema::create('advances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();

            $table->decimal('amount', 12, 2);
            $table->decimal('deducted_amount', 12, 2)->default(0)->comment('المستقطع حتى الآن من الرواتب');
            $table->unsignedInteger('installments')->default(1);
            $table->decimal('installment_amount', 12, 2)->default(0);

            $table->date('granted_on');
            $table->enum('status', ['pending', 'approved', 'settled', 'cancelled'])->default('pending');
            $table->text('notes')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
            $table->index(['employee_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('advances');
        Schema::dropIfExists('leaves');
        Schema::dropIfExists('attendances');
    }
};
