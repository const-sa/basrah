<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * توسعة ملف الموظف لما يلزم الرواتب والوثائق (§الطبقة أ - بند 5).
 * تواريخ انتهاء الإقامة والجواز والعقد تُستخدم للتنبيه قبل الانتهاء.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->string('employee_no')->nullable()->unique()->after('id');
            $table->string('national_id')->nullable()->after('name');
            $table->string('nationality')->nullable()->after('national_id');
            $table->date('hired_on')->nullable()->after('position');
            $table->date('birth_date')->nullable()->after('hired_on');

            $table->foreignId('unit_id')->nullable()->after('group_id')
                ->constrained('units')->nullOnDelete()
                ->comment('الوحدة التي يعمل بها — مركز تكلفة راتبه');
            $table->foreignId('department_id')->nullable()->after('unit_id')
                ->constrained('departments')->nullOnDelete();

            // مكوّنات الراتب
            $table->decimal('basic_salary', 12, 2)->default(0)->after('department_id');
            $table->decimal('housing_allowance', 12, 2)->default(0)->after('basic_salary');
            $table->decimal('transport_allowance', 12, 2)->default(0)->after('housing_allowance');
            $table->decimal('other_allowance', 12, 2)->default(0)->after('transport_allowance');

            // الوثائق ومواعيد انتهائها
            $table->date('iqama_expiry')->nullable()->after('other_allowance');
            $table->date('passport_expiry')->nullable()->after('iqama_expiry');
            $table->date('contract_expiry')->nullable()->after('passport_expiry');

            $table->string('bank_iban')->nullable()->after('contract_expiry');
            $table->text('notes')->nullable()->after('bank_iban');
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropConstrainedForeignId('unit_id');
            $table->dropConstrainedForeignId('department_id');
            $table->dropColumn([
                'employee_no', 'national_id', 'nationality', 'hired_on', 'birth_date',
                'basic_salary', 'housing_allowance', 'transport_allowance', 'other_allowance',
                'iqama_expiry', 'passport_expiry', 'contract_expiry', 'bank_iban', 'notes',
            ]);
        });
    }
};
