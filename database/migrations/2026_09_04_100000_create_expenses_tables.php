<?php

use App\Models\ExpenseCategory;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * المصروفات والتكاليف (§9): مستندٌ له جدوله، لا سندًا متنكّرًا.
 *
 * المشروع كله على قاعدة واحدة: الحجز والفاتورة ومسيّر الرواتب — كلٌّ في
 * جدوله ويولّد قيده في الدفاتر. فالمصروف مثلها: مستندٌ تشغيلي يحمل ما لا
 * يحمله السند (نوعه، مرجع فاتورته، من اعتمده ومتى، سبب إلغائه)، ويشير إلى
 * قيده كما تشير إليه الفاتورة والمسيّر.
 *
 * وأنواع المصروف جدولٌ تديره الإدارة: «غاز» يُضاف من شاشة الأنواع لا من
 * شجرة الحسابات، والنوع هو الذي يعرف حسابه في الشجرة — فيبقى الموظف
 * المالي في لغته، ويبقى الدفتر في لغته.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expense_categories', function (Blueprint $table) {
            $table->id();

            $table->string('code', 50)->unique();
            $table->string('name');
            $table->string('description')->nullable();

            // الجسر إلى الدفاتر: النوع يعرف حسابه، فلا يُسأل عنه من يصرف.
            $table->foreignId('account_id')->constrained('accounts')->restrictOnDelete();

            // مركز التكلفة الافتراضي — إيجارُ قاعةٍ بعينها يقع عليها دائمًا.
            $table->foreignId('cost_center_id')->nullable()->constrained('cost_centers')->nullOnDelete();

            // نوعٌ نصّ عليه العرض لا يُحذف: حذفه يُيتّم مصروفات سُجّلت عليه.
            $table->boolean('is_system')->default(false);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);

            $table->timestamps();
            $table->softDeletes();
            $table->index('deleted_at');
        });

        Schema::create('expenses', function (Blueprint $table) {
            $table->id();

            $table->string('number', 30)->unique();
            $table->date('expense_date');

            $table->foreignId('expense_category_id')->constrained('expense_categories')->restrictOnDelete();
            $table->decimal('amount', 12, 2);

            // على أي وحدة أو فرع يقع المصروف — والفارغ مصروفٌ عام.
            $table->foreignId('cost_center_id')->nullable()->constrained('cost_centers')->nullOnDelete();

            $table->foreignId('treasury_id')->constrained('treasuries')->restrictOnDelete();
            $table->foreignId('payment_method_id')->constrained('payment_methods')->restrictOnDelete();
            $table->foreignId('supplier_id')->nullable()->constrained('suppliers')->nullOnDelete();

            $table->string('reference', 100)->nullable()->comment('رقم فاتورة المورّد أو العدّاد');
            $table->text('description')->nullable();

            $table->enum('status', ['draft', 'posted', 'cancelled'])->default('draft');

            // القيد الذي ولّده الترحيل — كما في الفاتورة والمسيّر.
            $table->foreignId('journal_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('posted_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancellation_reason', 500)->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['expense_date', 'status']);
            $table->index(['expense_category_id', 'status']);
            $table->index('cost_center_id');
            $table->index('deleted_at');
        });

        // القاعدة القائمة تُملأ هنا، والجديدة تملؤها البذرة — والقائمة واحدة
        // في ExpenseCategory::DEFAULTS فلا تتباعد النسختان.
        ExpenseCategory::seedDefaults();

        $this->migrateExpenseVouchers();
    }

    public function down(): void
    {
        Schema::dropIfExists('expenses');
        Schema::dropIfExists('expense_categories');
    }

    /**
     * ما سُجّل مصروفًا في جدول السندات ينتقل إلى جدوله بقيده.
     *
     * السند الأصلي يُحذف حذفًا ناعمًا لا نهائيًا: قيده باقٍ ويشير إليه،
     * ومحوُه يُيتّم القيد. والحذف الناعم يمنع احتساب المصروف مرتين في
     * التقارير ورصيد الخزينة.
     */
    private function migrateExpenseVouchers(): void
    {
        if (! Schema::hasTable('vouchers')) {
            return;
        }

        $vouchers = DB::table('vouchers')
            ->where('type', 'expense')
            ->whereNull('deleted_at')
            ->get();

        if ($vouchers->isEmpty()) {
            return;
        }

        $categories = DB::table('expense_categories')->pluck('id', 'account_id');
        $fallback = DB::table('expense_categories')->where('code', 'operational')->value('id');

        foreach ($vouchers as $voucher) {
            DB::table('expenses')->insert([
                'number' => $voucher->number,
                'expense_date' => $voucher->voucher_date,
                'expense_category_id' => $categories[$voucher->account_id] ?? $fallback,
                'amount' => $voucher->amount,
                'cost_center_id' => $voucher->cost_center_id,
                'treasury_id' => $voucher->treasury_id,
                'payment_method_id' => $voucher->payment_method_id,
                'supplier_id' => $voucher->supplier_id,
                'reference' => $voucher->reference,
                'description' => $voucher->description,
                'status' => $voucher->status,
                'journal_entry_id' => $voucher->journal_entry_id,
                'created_by' => $voucher->created_by,
                'posted_by' => $voucher->status === 'posted' ? $voucher->created_by : null,
                'posted_at' => $voucher->status === 'posted' ? $voucher->updated_at : null,
                'created_at' => $voucher->created_at,
                'updated_at' => $voucher->updated_at,
            ]);
        }

        DB::table('vouchers')
            ->whereIn('id', $vouchers->pluck('id'))
            ->update(['deleted_at' => now()]);
    }
};
