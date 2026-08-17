<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ربط حساب الدخول بملف الموظف.
 *
 * الفصل مقصود: جدول users للدخول والصلاحيات، وجدول employees لملف
 * الموارد البشرية والراتب. ليس كل موظف له حساب دخول، وليس كل حساب
 * دخول موظفًا (المالك مثلًا). الربط اختياري يجمع الاثنين عند اللزوم.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('employee_id')->nullable()->after('role_id')
                ->constrained('employees')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('employee_id');
        });
    }
};
