<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settings', function (Blueprint $table) {
            $table->id();
            $table->string('business_name')->nullable();
            $table->string('logo_path')->nullable();
            $table->string('favicon_path')->nullable();
            // بيانات التواصل
            $table->string('phone')->nullable();
            $table->string('whatsapp')->nullable();
            $table->string('email')->nullable();
            $table->string('address')->nullable();
            // الضريبة
            $table->boolean('tax_enabled')->default(false);
            $table->string('tax_number')->nullable();
            $table->decimal('tax_rate', 5, 2)->default(15);
            $table->string('commercial_register')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('settings');
    }
};
