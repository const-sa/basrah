<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            // المدير
            $table->string('manager_name')->nullable()->after('commercial_register');
            $table->string('manager_signature_path')->nullable()->after('manager_name');
            // المدير المالي
            $table->string('finance_manager_name')->nullable()->after('manager_signature_path');
            $table->string('finance_manager_signature_path')->nullable()->after('finance_manager_name');
        });
    }

    public function down(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            $table->dropColumn([
                'manager_name',
                'manager_signature_path',
                'finance_manager_name',
                'finance_manager_signature_path',
            ]);
        });
    }
};
