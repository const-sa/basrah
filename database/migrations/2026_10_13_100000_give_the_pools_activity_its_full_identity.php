<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * المسابح جهةٌ مستقلة عن ديوان المسرة — فلها عنوانها وبريدها ورقمها الضريبي
 * وسجلّها التجاري ومديرها وتوقيعه وختمها، بدل أن تُطبع أوراقها ببيانات الديوان.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            $table->string('pools_whatsapp', 50)->nullable()->after('pools_phone');
            $table->string('pools_email')->nullable()->after('pools_whatsapp');
            $table->string('pools_address', 500)->nullable()->after('pools_email');
            $table->string('pools_tax_number', 50)->nullable()->after('pools_address');
            $table->string('pools_commercial_register', 100)->nullable()->after('pools_tax_number');
            $table->string('pools_manager_name')->nullable()->after('pools_commercial_register');
            $table->string('pools_signature_path')->nullable()->after('pools_manager_name');
            $table->string('pools_stamp_path')->nullable()->after('pools_signature_path');
        });
    }

    public function down(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            $table->dropColumn([
                'pools_whatsapp', 'pools_email', 'pools_address', 'pools_tax_number',
                'pools_commercial_register', 'pools_manager_name', 'pools_signature_path', 'pools_stamp_path',
            ]);
        });
    }
};
