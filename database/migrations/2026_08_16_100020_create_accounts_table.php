<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * شجرة الحسابات ومراكز التكلفة.
 *
 * مركز تكلفة لكل قاعة وشاليه وللمحل (§الطبقة أ - بند 4) — هو ما يجعل
 * قياس ربحية كل وحدة على حدة ممكنًا.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accounts', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->foreignId('parent_id')->nullable()->constrained('accounts')->cascadeOnDelete();

            $table->enum('type', ['asset', 'liability', 'equity', 'revenue', 'expense']);

            // الحساب التجميعي لا يُرحَّل عليه مباشرة، إنما يُجمِّع أبناءه
            $table->boolean('is_group')->default(false);
            $table->decimal('opening_balance', 14, 2)->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['type', 'is_group']);
        });

        Schema::create('cost_centers', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            // ربط مركز التكلفة بوحدة قابلة للحجز — هو ما يعطي ربحية الوحدة
            $table->foreignId('unit_id')->nullable()->constrained('units')->nullOnDelete();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('treasuries', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->enum('type', ['cash', 'bank'])->default('cash');
            $table->foreignId('account_id')->nullable()->constrained('accounts')->nullOnDelete();
            $table->string('bank_name')->nullable();
            $table->string('iban')->nullable();
            $table->decimal('opening_balance', 14, 2)->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('treasuries');
        Schema::dropIfExists('cost_centers');
        Schema::dropIfExists('accounts');
    }
};
