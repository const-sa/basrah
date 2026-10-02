<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * إذن صرف المخزن — ما يخرج مع الفني من كلور ومواد تعقيم لصيانة المسابح.
 *
 * لا هو بيع ولا تسوية جرد: المادة تُستهلك في عمل القسم نفسه، فتنقص من
 * الرصيد وتُحمَّل على مصروف القسم بتكلفتها، ويُثبت ذلك بقيد في الدفاتر.
 */
return new class extends Migration
{
    private const MOVEMENT_TYPES = [
        'purchase', 'purchase_revert', 'sale', 'return',
        'adjustment', 'bundle_consume', 'opening', 'issue', 'issue_revert',
    ];

    private const OLD_MOVEMENT_TYPES = [
        'purchase', 'purchase_revert', 'sale', 'return',
        'adjustment', 'bundle_consume', 'opening',
    ];

    private const SOURCES = ['manual', 'booking', 'payment', 'sale', 'expense', 'voucher', 'payroll', 'stock_issue'];

    private const OLD_SOURCES = ['manual', 'booking', 'payment', 'sale', 'expense', 'voucher', 'payroll'];

    /** من يصحّح رصيد صنف فهو من يصرف منه. */
    private const GRANTED_TO = 'inventory.edit';

    private const GRANTED = ['stock_issues.view', 'stock_issues.create', 'stock_issues.delete'];

    public function up(): void
    {
        Schema::create('stock_issues', function (Blueprint $table) {
            $table->id();
            $table->string('number', 30)->unique();
            $table->date('issue_date');
            $table->foreignId('department_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('employee_id')->nullable()->constrained()->nullOnDelete()->comment('الفني المستلم');
            $table->string('recipient_name')->nullable()->comment('المستلم إن لم يكن موظفًا مسجلًا');
            $table->foreignId('contract_id')->nullable()->constrained()->nullOnDelete()->comment('عقد الصيانة الذي صُرفت له');
            $table->foreignId('expense_account_id')->constrained('accounts');
            $table->decimal('total_cost', 14, 2)->default(0);
            $table->string('status', 20)->default('posted')->comment('posted / cancelled');
            $table->foreignId('journal_entry_id')->nullable()->constrained()->nullOnDelete();
            $table->text('notes')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('issue_date');
        });

        Schema::create('stock_issue_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_issue_id')->constrained()->cascadeOnDelete();
            $table->foreignId('item_id')->constrained();
            $table->decimal('quantity', 14, 3);
            $table->decimal('unit_cost', 14, 2)->default(0);
            $table->decimal('total_cost', 14, 2)->default(0);
            $table->timestamps();
        });

        Schema::table('stock_movements', function (Blueprint $table) {
            $table->enum('type', self::MOVEMENT_TYPES)
                ->comment('شراء / إلغاء شراء / بيع / مرتجع / تسوية جرد / خصم مكوّنات حزمة / رصيد افتتاحي / إذن صرف / إلغاء إذن صرف')
                ->change();
        });

        Schema::table('journal_entries', function (Blueprint $table) {
            $table->enum('source', self::SOURCES)->default('manual')->change();
        });

        foreach (DB::table('roles')->get(['id', 'permissions']) as $role) {
            $permissions = json_decode((string) $role->permissions, true);

            if (! is_array($permissions) || ! in_array(self::GRANTED_TO, $permissions, true)) {
                continue;
            }

            $missing = array_diff(self::GRANTED, $permissions);

            if ($missing === []) {
                continue;
            }

            DB::table('roles')->where('id', $role->id)->update([
                'permissions' => json_encode(array_values(array_merge($permissions, $missing)), JSON_UNESCAPED_UNICODE),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('stock_movements')->whereIn('type', ['issue', 'issue_revert'])->update(['type' => 'adjustment']);
        DB::table('journal_entries')->where('source', 'stock_issue')->update(['source' => 'manual']);

        Schema::table('journal_entries', function (Blueprint $table) {
            $table->enum('source', self::OLD_SOURCES)->default('manual')->change();
        });

        Schema::table('stock_movements', function (Blueprint $table) {
            $table->enum('type', self::OLD_MOVEMENT_TYPES)
                ->comment('شراء / إلغاء شراء / بيع / مرتجع / تسوية جرد / خصم مكوّنات حزمة / رصيد افتتاحي')
                ->change();
        });

        Schema::dropIfExists('stock_issue_items');
        Schema::dropIfExists('stock_issues');
    }
};
