<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * إهلاك كل أصل شهرًا بشهر — قيد فريد لكل (أصل، شهر) يمنع ترحيل نفس الشهر
 * مرتين، والقيد الفعلي مجمَّع في journal_entries لا سطرًا بسطر.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('asset_depreciation_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fixed_asset_id')->constrained('fixed_assets')->cascadeOnDelete();
            $table->date('period')->comment('أول يوم في شهر الإهلاك');
            $table->decimal('amount', 14, 2);
            $table->foreignId('journal_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();

            $table->timestamps();

            $table->unique(['fixed_asset_id', 'period']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_depreciation_entries');
    }
};
