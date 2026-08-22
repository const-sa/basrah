<?php

namespace Tests\Feature;

use App\Models\CostCenter;
use App\Models\Department;
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
 * شاشة الإيرادات: الإيراد يُقرأ من الدفاتر ويُقسَّم على القاعات والشاليهات
 * والمسابح بمركز التكلفة لا بوسمٍ يدوي.
 */
class RevenuesTest extends TestCase
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
        $this->seedRevenue();
    }

    /**
     * إيرادٌ على قاعة وشاليه ومسابح — ثلاثة نطاقات في ثلاثة قيود.
     */
    private function seedRevenue(): void
    {
        $hall = CostCenter::forUnit(Unit::where('type', 'hall')->firstOrFail());
        $chalet = CostCenter::forUnit(Unit::where('type', 'chalet')->firstOrFail());
        $pools = CostCenter::forDepartment(Department::where('code', 'POOLS')->firstOrFail());

        foreach ([[$hall, 1000.0], [$chalet, 600.0], [$pools, 400.0]] as [$center, $amount]) {
            $this->ledger->post('2026-08-10', "إيراد {$center->name}", [
                ['account' => Ledger::CASH, 'debit' => $amount, 'cost_center_id' => $center->id],
                ['account' => Ledger::BOOKING_REVENUE, 'credit' => $amount, 'cost_center_id' => $center->id],
            ]);
        }
    }

    public function test_revenues_page_totals_all_segments(): void
    {
        $this->actingAs($this->owner)
            ->get('/admin/accounting/revenues?from=2026-08-01&to=2026-08-31')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('admin/accounting/Revenues')
                ->where('stats.total', 2000)
                ->where('stats.count', 3)
                ->where('bySegment', fn ($rows) => collect($rows)->firstWhere('key', 'halls')['amount'] == 1000
                    && collect($rows)->firstWhere('key', 'chalets')['amount'] == 600
                    && collect($rows)->firstWhere('key', 'pools')['amount'] == 400)
                ->has('lines.data', 3));
    }

    /**
     * اختيار النطاق يحصر السطور والمجاميع فيه وحده.
     */
    public function test_segment_filter_narrows_to_halls(): void
    {
        $this->actingAs($this->owner)
            ->get('/admin/accounting/revenues?from=2026-08-01&to=2026-08-31&segment=halls')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('stats.total', 1000)
                ->has('lines.data', 1)
                ->where('lines.data.0.segment', 'halls'));
    }

    public function test_segment_filter_narrows_to_pools(): void
    {
        $this->actingAs($this->owner)
            ->get('/admin/accounting/revenues?from=2026-08-01&to=2026-08-31&segment=pools')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('stats.total', 400)
                ->has('lines.data', 1)
                ->where('lines.data.0.segment', 'pools'));
    }

    /**
     * حصر النطاق على وحدةٍ بعينها — «قاعة المشام» لا كل القاعات.
     */
    public function test_cost_center_filter_narrows_to_one_unit(): void
    {
        $chalet = CostCenter::forUnit(Unit::where('type', 'chalet')->firstOrFail());

        $this->actingAs($this->owner)
            ->get("/admin/accounting/revenues?from=2026-08-01&to=2026-08-31&cost_center_id={$chalet->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('stats.total', 600)
                ->has('lines.data', 1));
    }

    /**
     * المرتجع والاسترداد يُنقصان الإيراد لا يُضافان إليه: سطر مدين على
     * حساب إيراديّ يُطرح من الصافي.
     */
    public function test_debit_on_revenue_account_reduces_the_total(): void
    {
        $hall = CostCenter::forUnit(Unit::where('type', 'hall')->firstOrFail());

        $this->ledger->post('2026-08-12', 'استرداد جزئي', [
            ['account' => Ledger::BOOKING_REVENUE, 'debit' => 250.0, 'cost_center_id' => $hall->id],
            ['account' => Ledger::CASH, 'credit' => 250.0, 'cost_center_id' => $hall->id],
        ]);

        $this->actingAs($this->owner)
            ->get('/admin/accounting/revenues?from=2026-08-01&to=2026-08-31&segment=halls')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('stats.total', 750));
    }

    /**
     * القيد بلا مركز تكلفة لا يضيع: يقع في «إيرادات أخرى» فيبقى مجموع
     * النطاقات مساويًا للإجمالي.
     */
    public function test_revenue_without_cost_center_falls_into_other(): void
    {
        $this->ledger->post('2026-08-14', 'إيراد متنوّع', [
            ['account' => Ledger::CASH, 'debit' => 120.0],
            ['account' => '4130', 'credit' => 120.0],
        ]);

        $this->actingAs($this->owner)
            ->get('/admin/accounting/revenues?from=2026-08-01&to=2026-08-31&segment=other')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('stats.total', 120)
                ->has('lines.data', 1));
    }

    public function test_export_streams_a_csv(): void
    {
        $this->actingAs($this->owner)
            ->get('/admin/accounting/revenues/export?from=2026-08-01&to=2026-08-31')
            ->assertOk()
            ->assertHeader('content-type', 'text/csv; charset=UTF-8');
    }

    /**
     * الشاشة تتبع صلاحيتها: من لا يملك revenues.view لا يراها.
     */
    public function test_page_is_guarded_by_permission(): void
    {
        $role = Role::create([
            'name' => 'كاشير',
            'slug' => 'cashier-test',
            'permissions' => ['pos.view'],
        ]);

        $cashier = User::factory()->create(['role_id' => $role->id, 'is_active' => true]);

        $this->actingAs($cashier)->get('/admin/accounting/revenues')->assertForbidden();
    }
}
