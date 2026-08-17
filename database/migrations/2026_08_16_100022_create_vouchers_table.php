<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * سندات القبض والصرف والمصروفات.
 * كل سند مرحَّل يولّد قيدًا محاسبيًا مقابلًا على مركز التكلفة المحدد.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vouchers', function (Blueprint $table) {
            $table->id();
            $table->string('number')->unique();
            $table->enum('type', ['receipt', 'payment', 'expense'])->comment('قبض / صرف / مصروف');

            $table->date('voucher_date');
            $table->decimal('amount', 14, 2);

            $table->foreignId('treasury_id')->constrained('treasuries')->cascadeOnDelete();
            $table->foreignId('account_id')->nullable()->constrained('accounts')->nullOnDelete()
                ->comment('الحساب المقابل: إيراد للقبض، مصروف للصرف');
            $table->foreignId('cost_center_id')->nullable()->constrained('cost_centers')->nullOnDelete();

            // الطرف الآخر: عميل أو مورد أو موظف
            $table->foreignId('client_id')->nullable()->constrained('clients')->nullOnDelete();
            $table->foreignId('supplier_id')->nullable()->constrained('suppliers')->nullOnDelete();

            $table->enum('method', ['cash', 'transfer', 'card'])->default('cash');
            $table->string('reference')->nullable();
            $table->text('description')->nullable();

            $table->enum('status', ['draft', 'posted', 'cancelled'])->default('draft');
            $table->foreignId('journal_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['type', 'voucher_date']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vouchers');
    }
};
