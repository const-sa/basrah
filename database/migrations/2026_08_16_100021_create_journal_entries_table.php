<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * القيود اليومية وسطورها.
 *
 * القيد المرحَّل (posted) لا يُعدَّل ولا يُحذف — يُعكَس بقيد مضاد.
 * هذا شرط سلامة الدفاتر، وهو ما يفرّق نظامًا محاسبيًا عن جدول أرقام.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('journal_entries', function (Blueprint $table) {
            $table->id();
            $table->string('number')->unique();
            $table->date('entry_date');
            $table->text('description')->nullable();

            $table->enum('status', ['draft', 'posted', 'reversed'])->default('draft');
            $table->enum('source', ['manual', 'booking', 'payment', 'sale', 'expense', 'voucher', 'payroll'])
                ->default('manual')
                ->comment('مصدر القيد — التلقائي منها لا يُحرَّر يدويًا');

            $table->decimal('total_debit', 14, 2)->default(0);
            $table->decimal('total_credit', 14, 2)->default(0);

            $table->nullableMorphs('reference');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('posted_at')->nullable();
            $table->foreignId('reversed_by_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();

            $table->timestamps();

            $table->index(['entry_date', 'status']);
            $table->index('source');
        });

        Schema::create('journal_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('journal_entry_id')->constrained('journal_entries')->cascadeOnDelete();
            $table->foreignId('account_id')->constrained('accounts')->cascadeOnDelete();
            $table->foreignId('cost_center_id')->nullable()->constrained('cost_centers')->nullOnDelete();

            $table->decimal('debit', 14, 2)->default(0);
            $table->decimal('credit', 14, 2)->default(0);
            $table->text('description')->nullable();

            $table->timestamps();

            $table->index(['account_id', 'cost_center_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('journal_lines');
        Schema::dropIfExists('journal_entries');
    }
};
