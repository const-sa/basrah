<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\CostCenter;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\JournalLine;
use App\Models\Role;
use App\Models\Treasury;
use App\Models\User;
use Database\Seeders\AccountsSeeder;
use Database\Seeders\RolesSeeder;
use Database\Seeders\UnitsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * المصروفات والتكاليف (§9): مستندٌ في جدوله، ونوعه صفٌّ يعرف حسابه.
 */
class ExpensesTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Treasury $treasury;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([RolesSeeder::class, UnitsSeeder::class, AccountsSeeder::class]);

        $this->owner = User::factory()->create([
            'name' => 'مالك النظام',
            'role_id' => Role::where('slug', 'super-admin')->firstOrFail()->id,
            'is_active' => true,
            'has_all_units' => true,
        ]);

        $this->treasury = Treasury::where('is_active', true)->firstOrFail();
    }

    private function category(string $code): ExpenseCategory
    {
        return ExpenseCategory::where('code', $code)->firstOrFail();
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return [
            'expense_date' => '2026-08-16',
            'amount' => 250,
            'expense_category_id' => $this->category('electricity')->id,
            'treasury_id' => $this->treasury->id,
            'payment_method_id' => $this->paymentMethodId(),
            'description' => 'فاتورة كهرباء أغسطس',
            'post_now' => true,
            ...$overrides,
        ];
    }

    /**
     * أنواع المصروف في العرض كلها مبذورة، وكلٌّ موصولٌ بحسابه في الشجرة.
     */
    public function test_the_specified_expense_categories_exist_and_map_to_accounts(): void
    {
        foreach ([
            'electricity' => 'كهرباء',
            'water' => 'مياه',
            'maintenance' => 'صيانة',
            'cleaning' => 'نظافة',
            'purchases' => 'مشتريات',
            'salaries' => 'رواتب',
            'rent' => 'إيجارات',
            'services' => 'خدمات',
            'marketing' => 'تسويق ودعاية',
            'internet' => 'إنترنت واتصالات',
            'spare_parts' => 'قطع غيار',
            'operational' => 'مصروفات تشغيلية أخرى',
        ] as $code => $name) {
            $category = ExpenseCategory::where('code', $code)->first();

            $this->assertNotNull($category, "نوع المصروف «{$name}» غير مبذور");
            $this->assertSame($name, $category->name);
            $this->assertSame('expense', $category->account?->type, "نوع «{$name}» موصول بحساب ليس مصروفًا");
        }
    }

    public function test_the_screen_renders_with_its_categories(): void
    {
        $this->actingAs($this->owner)
            ->get('/admin/accounting/expenses')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('admin/accounting/Expenses')
                ->has('expenses.data')
                ->has('categories', 12)
                ->has('stats'),
            );
    }

    /**
     * المصروف في جدوله لا في جدول السندات — وله رقمه المتسلسل.
     */
    public function test_an_expense_is_stored_as_its_own_document(): void
    {
        $this->actingAs($this->owner)
            ->post('/admin/accounting/expenses', $this->payload())
            ->assertRedirect()
            ->assertSessionHas('success');

        $expense = Expense::firstOrFail();

        $this->assertSame('EXP-2026-1', $expense->number);
        $this->assertSame($this->owner->id, $expense->created_by);
        $this->assertDatabaseCount('expenses', 1);
        $this->assertDatabaseMissing('vouchers', ['type' => 'expense']);
    }

    /**
     * الترحيل يولّد قيدًا متوازنًا: بند المصروف مدين والخزينة دائنة.
     */
    public function test_posting_an_expense_writes_a_balanced_entry(): void
    {
        $this->actingAs($this->owner)->post('/admin/accounting/expenses', $this->payload());

        $expense = Expense::firstOrFail();

        $this->assertSame('posted', $expense->status);
        $this->assertNotNull($expense->journal_entry_id);
        $this->assertSame($this->owner->id, $expense->posted_by);

        $lines = JournalLine::where('journal_entry_id', $expense->journal_entry_id)->get();

        $this->assertEqualsWithDelta(250, $lines->sum('debit'), 0.01);
        $this->assertEqualsWithDelta(250, $lines->sum('credit'), 0.01);
        $this->assertEqualsWithDelta(
            250,
            (float) $lines->firstWhere('account_id', $this->category('electricity')->account_id)?->debit,
            0.01,
        );

        // القيد يشير إلى مستنده كما تفعل الفاتورة والمسيّر.
        $this->assertSame(Expense::class, $expense->entry->reference_type);
        $this->assertSame($expense->id, $expense->entry->reference_id);
    }

    public function test_a_posted_expense_leaves_the_treasury(): void
    {
        $before = $this->treasury->balance();

        $this->actingAs($this->owner)->post('/admin/accounting/expenses', $this->payload(['amount' => 400]));

        $this->assertEqualsWithDelta($before - 400, $this->treasury->fresh()->balance(), 0.01);
    }

    public function test_an_expense_can_be_charged_to_a_unit(): void
    {
        $center = CostCenter::where('is_active', true)->firstOrFail();

        $this->actingAs($this->owner)
            ->post('/admin/accounting/expenses', $this->payload(['cost_center_id' => $center->id]));

        $expense = Expense::firstOrFail();

        $this->assertSame($center->id, $expense->cost_center_id);
        $this->assertSame(
            $center->id,
            JournalLine::where('journal_entry_id', $expense->journal_entry_id)->value('cost_center_id'),
        );
    }

    /**
     * المسوّدة نيّةٌ لا حركة: لا قيد لها ولا أثر في الدفاتر حتى تُرحَّل.
     */
    public function test_a_draft_expense_has_no_entry_until_it_is_posted(): void
    {
        $this->actingAs($this->owner)->post('/admin/accounting/expenses', $this->payload(['post_now' => false]));

        $expense = Expense::firstOrFail();

        $this->assertSame('draft', $expense->status);
        $this->assertNull($expense->journal_entry_id);

        $this->post("/admin/accounting/expenses/{$expense->id}/post")->assertSessionHas('success');

        $this->assertSame('posted', $expense->fresh()->status);
        $this->assertNotNull($expense->fresh()->journal_entry_id);
    }

    public function test_a_posted_expense_cannot_be_edited_or_deleted_but_can_be_cancelled(): void
    {
        $this->actingAs($this->owner)->post('/admin/accounting/expenses', $this->payload());

        $expense = Expense::firstOrFail();

        $this->put("/admin/accounting/expenses/{$expense->id}", $this->payload(['amount' => 900]))
            ->assertSessionHas('warning');

        $this->delete("/admin/accounting/expenses/{$expense->id}")->assertSessionHas('warning');

        $this->assertEqualsWithDelta(250, (float) $expense->fresh()->amount, 0.01);

        $this->post("/admin/accounting/expenses/{$expense->id}/cancel", ['reason' => 'سُجّل بالخطأ'])
            ->assertSessionHas('success');

        $expense = $expense->fresh();

        $this->assertSame('cancelled', $expense->status);
        $this->assertSame('سُجّل بالخطأ', $expense->cancellation_reason);

        // الإلغاء يعكس القيد ولا يمحوه، فيعود رصيد الخزينة كما كان.
        $this->assertSame('reversed', $expense->entry->fresh()->status);
    }

    public function test_a_draft_expense_is_editable_and_archived_on_delete(): void
    {
        $this->actingAs($this->owner)->post('/admin/accounting/expenses', $this->payload(['post_now' => false]));

        $expense = Expense::firstOrFail();

        $this->put("/admin/accounting/expenses/{$expense->id}", $this->payload(['amount' => 310, 'post_now' => false]))
            ->assertSessionHas('success');

        $this->assertEqualsWithDelta(310, (float) $expense->fresh()->amount, 0.01);

        $this->delete("/admin/accounting/expenses/{$expense->id}")->assertSessionHas('success');

        $this->assertSoftDeleted('expenses', ['id' => $expense->id]);
    }

    /**
     * الشاشة تجيب «فيمَ صُرف» — والمسوّدة لا تُحتسب مصروفًا.
     */
    public function test_statistics_count_posted_expenses_only_and_split_them_by_category(): void
    {
        $this->actingAs($this->owner);

        $this->post('/admin/accounting/expenses', $this->payload(['amount' => 300]));
        $this->post('/admin/accounting/expenses', $this->payload([
            'amount' => 100,
            'expense_category_id' => $this->category('cleaning')->id,
        ]));
        $this->post('/admin/accounting/expenses', $this->payload(['amount' => 999, 'post_now' => false]));

        $this->get('/admin/accounting/expenses?from=2026-08-01&to=2026-08-31')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('stats.total', 400)
                ->where('stats.count', 2)
                ->where('stats.drafts', 1)
                ->has('byCategory', 2)
                ->where('byCategory.0.category', 'كهرباء')
                ->where('byCategory.0.amount', 300)
                ->where('byCategory.0.share', 75),
            );
    }

    public function test_expenses_can_be_filtered_by_category(): void
    {
        $this->actingAs($this->owner);

        $this->post('/admin/accounting/expenses', $this->payload());
        $this->post('/admin/accounting/expenses', $this->payload([
            'expense_category_id' => $this->category('maintenance')->id,
        ]));

        $maintenance = $this->category('maintenance')->id;

        $this->get("/admin/accounting/expenses?from=2026-08-01&to=2026-08-31&expense_category_id={$maintenance}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('expenses.data', 1)
                ->where('expenses.data.0.category', 'صيانة'),
            );
    }

    /* ---------- أنواع المصروف ---------- */

    public function test_a_new_category_is_added_and_used(): void
    {
        $account = Account::where('type', 'expense')->postable()->firstOrFail();

        $this->actingAs($this->owner)
            ->post('/admin/accounting/expense-categories', [
                'code' => 'gas',
                'name' => 'غاز',
                'account_id' => $account->id,
            ])
            ->assertSessionHas('success');

        $gas = $this->category('gas');

        $this->assertTrue($gas->is_active);
        $this->assertFalse($gas->is_system);

        $this->post('/admin/accounting/expenses', $this->payload(['expense_category_id' => $gas->id]))
            ->assertSessionHas('success');

        $this->assertSame($gas->id, Expense::firstOrFail()->expense_category_id);
    }

    /**
     * النوع بلا حساب مصروفٍ صحيح لا يُقبل: مصروفٌ لا يصل إلى الدفاتر.
     */
    public function test_a_category_must_point_at_an_expense_account(): void
    {
        $revenue = Account::where('type', 'revenue')->firstOrFail();

        $this->actingAs($this->owner)
            ->post('/admin/accounting/expense-categories', [
                'code' => 'wrong',
                'name' => 'نوع خاطئ',
                'account_id' => $revenue->id,
            ])
            ->assertSessionHasErrors('account_id');
    }

    /**
     * النوع المستعمل يُعطَّل ولا يُحذف — حذفه يُيتّم مصروفاتٍ سُجّلت عليه.
     */
    public function test_a_used_or_system_category_is_not_deleted(): void
    {
        $this->actingAs($this->owner);

        $electricity = $this->category('electricity');

        $this->delete("/admin/accounting/expense-categories/{$electricity->id}")->assertSessionHas('warning');
        $this->assertDatabaseHas('expense_categories', ['id' => $electricity->id, 'deleted_at' => null]);

        $this->patch("/admin/accounting/expense-categories/{$electricity->id}/toggle");
        $this->assertFalse($electricity->fresh()->is_active);
    }

    public function test_an_unused_custom_category_can_be_deleted(): void
    {
        $account = Account::where('type', 'expense')->postable()->firstOrFail();

        $this->actingAs($this->owner)
            ->post('/admin/accounting/expense-categories', ['code' => 'temp', 'name' => 'مؤقت', 'account_id' => $account->id]);

        $temp = $this->category('temp');

        $this->delete("/admin/accounting/expense-categories/{$temp->id}")->assertSessionHas('success');
        $this->assertSoftDeleted('expense_categories', ['id' => $temp->id]);
    }

    /* ---------- الحماية والتصدير ---------- */

    public function test_the_screen_is_guarded_by_permission(): void
    {
        $cashier = User::factory()->create([
            'role_id' => Role::where('slug', 'cashier')->firstOrFail()->id,
            'is_active' => true,
            'has_all_units' => true,
        ]);

        $this->actingAs($cashier)->get('/admin/accounting/expenses')->assertForbidden();
        $this->actingAs($cashier)->post('/admin/accounting/expenses', $this->payload())->assertForbidden();
        $this->actingAs($cashier)->post('/admin/accounting/expense-categories', [])->assertForbidden();
    }

    public function test_expenses_export_as_csv(): void
    {
        $this->actingAs($this->owner)->post('/admin/accounting/expenses', $this->payload());

        $this->get('/admin/accounting/expenses/export?from=2026-08-01&to=2026-08-31')
            ->assertOk()
            ->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
    }

    /**
     * تقرير «المصروفات حسب التصنيف» يقرأ المستند الجديد.
     */
    public function test_the_expenses_report_reads_the_new_documents(): void
    {
        $this->actingAs($this->owner);

        $this->post('/admin/accounting/expenses', $this->payload(['amount' => 500]));

        $this->get('/admin/reports/expenses-by-category?from=2026-08-01&to=2026-08-31')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('rows.0.category', 'كهرباء')
                ->where('rows.0.amount', 500),
            );
    }
}
