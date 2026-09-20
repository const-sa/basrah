<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\CostCenter;
use App\Models\Department;
use App\Models\JournalEntry;
use App\Models\RevenueAccount;
use App\Models\Role;
use App\Models\Unit;
use App\Models\User;
use App\Services\Accounting\Ledger;
use Database\Seeders\AccountsSeeder;
use Database\Seeders\DepartmentsSeeder;
use Database\Seeders\RolesSeeder;
use Database\Seeders\UnitsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * كشف حساب الإيراد: يُختار الإيراد فيُفتح حسابه بحركته ورصيده المتدرّج.
 *
 * ما يميّزه عن شاشة الإيرادات أنه كشفٌ لا جدول مجاميع: يبدأ برصيد ما قبل
 * الفترة، ويُظهر الرصيد بعد كل حركة، وينتهي برصيدٍ يُطابَق عليه.
 */
class RevenueStatementTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Ledger $ledger;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([RolesSeeder::class, UnitsSeeder::class, DepartmentsSeeder::class, AccountsSeeder::class]);

        $this->owner = User::factory()->create([
            'role_id' => Role::where('slug', 'super-admin')->firstOrFail()->id,
            'is_active' => true,
            'has_all_units' => true,
        ]);

        $this->ledger = app(Ledger::class);
    }

    private function hallCenter(): CostCenter
    {
        return CostCenter::forUnit(Unit::where('type', 'hall')->firstOrFail());
    }

    private function poolsCenter(): CostCenter
    {
        return CostCenter::forDepartment(Department::where('code', 'POOLS')->firstOrFail());
    }

    private function earn(string $date, float $amount, ?int $center, string $account = Ledger::BOOKING_REVENUE): void
    {
        $this->ledger->post($date, 'إيراد', [
            ['account' => Ledger::CASH, 'debit' => $amount, 'cost_center_id' => $center],
            ['account' => $account, 'credit' => $amount, 'cost_center_id' => $center],
        ]);
    }

    public function test_the_statement_opens_on_a_revenue_account_by_default(): void
    {
        $this->earn('2026-08-10', 1000, $this->hallCenter()->id);

        $this->actingAs($this->owner)
            ->get('/admin/accounting/revenue-statement?from=2026-08-01&to=2026-08-31')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('admin/accounting/RevenueStatement')
                // الافتراضي حساب أول مصادر الدخل — لا شاشة فارغة تنتظر اختيارًا
                ->where('account.code', Ledger::BOOKING_REVENUE)
                ->where('statement.opening', 0)
                ->where('statement.total_credit', 1000)
                ->where('statement.closing', 1000)
                ->has('statement.rows', 1)
                ->has('accounts'));
    }

    /**
     * ما قبل الفترة رصيدٌ يُفتح به الكشف لا صفرٌ كاذب، والرصيد يتدرّج مع الحركات.
     */
    public function test_movement_before_the_period_becomes_the_opening_balance(): void
    {
        $hall = $this->hallCenter()->id;

        $this->earn('2026-07-20', 400, $hall);
        $this->earn('2026-08-05', 600, $hall);
        $this->earn('2026-08-09', 250, $hall);

        $this->actingAs($this->owner)
            ->get('/admin/accounting/revenue-statement?from=2026-08-01&to=2026-08-31')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('statement.opening', 400)
                ->has('statement.rows', 2)
                ->where('statement.rows.0.balance', 1000)
                ->where('statement.rows.1.balance', 1250)
                ->where('statement.closing', 1250));
    }

    /**
     * المرتجع مدينٌ على حساب إيرادي فينقص الرصيد — لا يُضاف إليه.
     */
    public function test_a_debit_lowers_the_running_balance(): void
    {
        $hall = $this->hallCenter()->id;

        $this->earn('2026-08-05', 1000, $hall);

        $this->ledger->post('2026-08-06', 'استرداد جزئي', [
            ['account' => Ledger::BOOKING_REVENUE, 'debit' => 250, 'cost_center_id' => $hall],
            ['account' => Ledger::CASH, 'credit' => 250, 'cost_center_id' => $hall],
        ]);

        $this->actingAs($this->owner)
            ->get('/admin/accounting/revenue-statement?from=2026-08-01&to=2026-08-31')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('statement.total_debit', 250)
                ->where('statement.net', 750)
                ->where('statement.closing', 750));
    }

    /**
     * اختيار الإيراد بمصدره لا بكوده: «حجوزات الشاليهات» تفتح الحساب المربوط بها.
     */
    public function test_choosing_a_stream_opens_the_account_mapped_to_it(): void
    {
        $account = Account::create([
            'code' => '4140',
            'name' => 'إيرادات الشاليهات',
            'type' => 'revenue',
            'is_group' => false,
            'is_active' => true,
        ]);

        RevenueAccount::updateOrCreate(['stream' => 'chalet_bookings'], ['account_id' => $account->id]);

        $this->actingAs($this->owner)
            ->get('/admin/accounting/revenue-statement?stream=chalet_bookings')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('account.code', '4140')
                // والقائمة تسمّي المصادر التي تهبط على الحساب لا كوده وحده
                ->where('account.streams', ['حجوزات الشاليهات']));
    }

    public function test_an_account_can_be_opened_by_its_id(): void
    {
        $sales = Account::where('code', Ledger::SALES_REVENUE)->firstOrFail();

        $this->actingAs($this->owner)
            ->get("/admin/accounting/revenue-statement?account_id={$sales->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('account.code', Ledger::SALES_REVENUE));
    }

    /**
     * حصر الكشف على نشاطٍ واحد — ومعه يسقط الرصيد الافتتاحي المسجَّل على
     * الحساب كله، لأنه ليس مال قاعةٍ بعينها.
     */
    public function test_activity_filter_narrows_the_statement_to_its_centres(): void
    {
        $this->earn('2026-08-05', 1000, $this->hallCenter()->id);
        $this->earn('2026-08-06', 400, $this->poolsCenter()->id);

        $this->actingAs($this->owner)
            ->get('/admin/accounting/revenue-statement?from=2026-08-01&to=2026-08-31&segment=halls')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('statement.rows', 1)
                ->where('statement.closing', 1000)
                ->where('byCenter.0.amount', 1000));
    }

    /**
     * المسوّدة ليست في الدفاتر فلا تدخل الكشف.
     */
    public function test_a_draft_entry_stays_out_of_the_statement(): void
    {
        $this->earn('2026-08-05', 1000, $this->hallCenter()->id);

        JournalEntry::query()->update(['status' => 'draft']);

        $this->actingAs($this->owner)
            ->get('/admin/accounting/revenue-statement?from=2026-08-01&to=2026-08-31')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('statement.rows', 0)
                ->where('statement.closing', 0));
    }

    public function test_export_streams_a_csv(): void
    {
        $this->earn('2026-08-05', 1000, $this->hallCenter()->id);

        $response = $this->actingAs($this->owner)
            ->get('/admin/accounting/revenue-statement/export?from=2026-08-01&to=2026-08-31');

        $response->assertOk()->assertHeader('content-type', 'text/csv; charset=UTF-8');

        $this->assertStringContainsString('رصيد آخر الفترة', $response->streamedContent());
    }

    /**
     * الكشف قراءةُ دفاتر، فمن لا يملك صلاحية الإيرادات لا يفتحه.
     */
    public function test_a_role_without_the_revenues_permission_is_refused(): void
    {
        $cashier = User::factory()->create([
            'role_id' => Role::where('slug', 'cashier')->firstOrFail()->id,
            'is_active' => true,
            'has_all_units' => true,
        ]);

        $this->actingAs($cashier)
            ->get('/admin/accounting/revenue-statement')
            ->assertForbidden();
    }
}
