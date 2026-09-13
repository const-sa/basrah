<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * سطر واحد من كشف البنك، يُطابَق (تلقائيًا أو يدويًا) بسند أو مصروف مرحَّل
 * على نفس الخزينة. treasury_id مكرَّر من الاستيراد عمدًا: يوفّر join عند كل
 * استعلام مطابقة.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bank_statement_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bank_statement_import_id')->constrained('bank_statement_imports')->cascadeOnDelete();
            $table->foreignId('treasury_id')->constrained('treasuries')->cascadeOnDelete();

            $table->date('txn_date');
            $table->string('description')->nullable();
            $table->decimal('amount', 14, 2)->comment('موجب = إيداع، سالب = سحب');
            $table->string('reference')->nullable();

            $table->foreignId('matched_voucher_id')->nullable()->constrained('vouchers')->nullOnDelete();
            $table->foreignId('matched_expense_id')->nullable()->constrained('expenses')->nullOnDelete();
            $table->timestamp('matched_at')->nullable();
            $table->foreignId('matched_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->index(['treasury_id', 'txn_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_statement_lines');
    }
};
