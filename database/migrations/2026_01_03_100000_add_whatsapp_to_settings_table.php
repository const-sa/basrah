<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            // تكامل واتساب (مكتبة c-wts.com)
            $table->boolean('wa_enabled')->default(false)->after('commercial_register');
            $table->string('wa_instance_id')->nullable()->after('wa_enabled');
            $table->string('wa_access_token')->nullable()->after('wa_instance_id');
            // رسالة الترحيب بالعميل
            $table->boolean('wa_welcome_enabled')->default(false)->after('wa_access_token');
            $table->text('wa_welcome_template')->nullable()->after('wa_welcome_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            $table->dropColumn([
                'wa_enabled',
                'wa_instance_id',
                'wa_access_token',
                'wa_welcome_enabled',
                'wa_welcome_template',
            ]);
        });
    }
};
