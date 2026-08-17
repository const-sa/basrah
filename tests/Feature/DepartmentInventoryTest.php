<?php

namespace Tests\Feature;

use App\Models\CostCenter;
use App\Models\Department;
use App\Models\Item;
use App\Models\MeasureUnit;
use App\Models\Role;
use App\Models\User;
use App\Services\SalesService;
use Database\Seeders\AccountsSeeder;
use Database\Seeders\BookingSetupSeeder;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\DepartmentsSeeder;
use Database\Seeders\RolesSeeder;
use Database\Seeders\UnitsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * تنظيم المستودع بالأقسام، وربط قسم المسابح بالمبيعات،
 * والتحكم بوحدات القياس.
 */
class DepartmentInventoryTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([
            RolesSeeder::class, DepartmentsSeeder::class, UnitsSeeder::class,
            BookingSetupSeeder::class, AccountsSeeder::class, CatalogSeeder::class,
        ]);

        $this->owner = User::factory()->create([
            'role_id' => Role::where('slug', 'super-admin')->value('id'),
            'is_active' => true,
            'has_all_units' => true,
        ]);
    }

    public function test_pools_is_the_selling_department_and_owns_the_catalog(): void
    {
        $pools = Department::firstWhere('code', 'POOLS');

        $this->assertTrue($pools->sells);
        $this->assertSame($pools->id, Department::defaultSelling()->id);
        // كل الأصناف المزروعة تتبع المسابح — نشاط مستقل بمنتجاته
        $this->assertSame(Item::count(), $pools->items()->count());
        $this->assertGreaterThan(0, $pools->stockValue());
    }

    public function test_non_selling_departments_are_not_offered_on_the_invoice_screen(): void
    {
        $venues = Department::firstWhere('code', 'VENUES');

        $this->assertFalse($venues->sells);
        $this->assertFalse(Department::selling()->pluck('id')->contains($venues->id));
    }

    public function test_invoice_screen_shows_only_the_selected_departments_items(): void
    {
        $pools = Department::firstWhere('code', 'POOLS');
        $venues = Department::firstWhere('code', 'VENUES');

        // صنف في قسم آخر يجب ألا يظهر ضمن أصناف المسابح
        Item::create([
            'code' => 'OTH-001', 'name' => 'صنف قسم آخر',
            'department_id' => $venues->id, 'type' => 'stock', 'unit' => 'piece',
            'cost' => 10, 'price' => 20, 'tax_rate' => 15, 'stock_qty' => 5, 'reorder_point' => 1,
            'is_active' => true,
        ]);

        $this->actingAs($this->owner)
            ->get('/admin/pos')
            ->assertOk()
            ->assertInertia(fn ($p) => $p
                ->where('departmentId', $pools->id)
                ->has('items', Item::where('department_id', $pools->id)->count()),
            );
    }

    public function test_a_sale_lands_on_its_departments_cost_center(): void
    {
        $pools = Department::firstWhere('code', 'POOLS');
        $part = Item::where('code', 'SPR-001')->firstOrFail();

        app(SalesService::class)->checkout([
            'lines' => [['item_id' => $part->id, 'quantity' => 10]],
            'department_id' => $pools->id,
            'method' => 'cash',
        ], $this->owner->id);

        $profit = CostCenter::forDepartment($pools)->profitability();

        $this->assertSame(747.5, $profit['revenue']);
        $this->assertSame(350.0, $profit['expense']);
        $this->assertSame(397.5, $profit['profit']);
    }

    public function test_every_department_gets_its_own_cost_center(): void
    {
        foreach (Department::all() as $department) {
            $this->assertNotNull(
                CostCenter::firstWhere('department_id', $department->id),
                "القسم {$department->name} بلا مركز تكلفة",
            );
        }
    }

    // ── وحدات القياس ─────────────────────────────────────────

    public function test_fraction_rule_follows_the_measure_unit_not_the_item_type(): void
    {
        $sqm = Item::where('code', 'MSR-001')->firstOrFail();   // متر مربع
        $piece = Item::where('code', 'SPR-001')->firstOrFail(); // قطعة

        $this->assertTrue($sqm->allowsFractionalQuantity());
        $this->assertFalse($piece->allowsFractionalQuantity());
        $this->assertSame('متر مربع', $sqm->unitLabel());
        $this->assertSame('قطعة', $piece->unitLabel());
    }

    public function test_a_stock_item_sold_by_a_fractional_unit_accepts_fractions(): void
    {
        // كلور حبيبي مخزني لكنه بالكيلو — يقبل الكسر رغم أن نوعه stock
        $chlorine = Item::where('code', 'CHM-001')->firstOrFail();

        $this->assertSame('stock', $chlorine->type);
        $this->assertTrue($chlorine->allowsFractionalQuantity());

        $sale = app(SalesService::class)->checkout([
            'lines' => [['item_id' => $chlorine->id, 'quantity' => 2.5]],
            'method' => 'cash',
        ], $this->owner->id);

        $this->assertSame(80.0, (float) $sale->subtotal); // 2.5 × 32
        $this->assertSame(197.5, (float) $chlorine->fresh()->stock_qty);
    }

    public function test_a_piece_unit_still_rejects_fractions(): void
    {
        $this->expectException(ValidationException::class);

        app(SalesService::class)->checkout([
            'lines' => [['item_id' => Item::where('code', 'PMP-001')->value('id'), 'quantity' => 1.5]],
            'method' => 'cash',
        ], $this->owner->id);
    }

    public function test_measure_units_can_be_managed_from_the_screen(): void
    {
        $this->actingAs($this->owner)
            ->get('/admin/inventory/units')
            ->assertOk()
            ->assertInertia(fn ($p) => $p
                ->component('admin/items/Units')
                ->has('units', MeasureUnit::count())
                ->has('departments', Department::count()),
            );

        $this->actingAs($this->owner)->post('/admin/inventory/units', [
            'code' => 'TON', 'name' => 'طن', 'symbol' => 'ط',
            'allows_fraction' => true, 'is_active' => true,
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('measure_units', ['code' => 'TON', 'allows_fraction' => true]);
    }

    public function test_a_measure_unit_in_use_cannot_be_deleted(): void
    {
        $used = MeasureUnit::firstWhere('code', 'M2');

        $this->actingAs($this->owner)
            ->delete("/admin/inventory/units/{$used->id}")
            ->assertSessionHas('warning');

        $this->assertNotNull($used->fresh());
    }

    public function test_creating_a_department_creates_its_cost_center(): void
    {
        $this->actingAs($this->owner)->post('/admin/inventory/departments', [
            'code' => 'GARDEN', 'name' => 'تنسيق الحدائق',
            'sells' => true, 'is_active' => true,
        ])->assertSessionHasNoErrors();

        $department = Department::firstWhere('code', 'GARDEN');

        $this->assertNotNull($department);
        $this->assertNotNull(CostCenter::firstWhere('department_id', $department->id));
        // صار قسمًا بائعًا ⇒ يظهر في شاشة الفواتير
        $this->assertTrue(Department::selling()->pluck('id')->contains($department->id));
    }

    public function test_a_department_with_items_cannot_be_deleted(): void
    {
        $pools = Department::firstWhere('code', 'POOLS');

        $this->actingAs($this->owner)
            ->delete("/admin/inventory/departments/{$pools->id}")
            ->assertSessionHas('warning');

        $this->assertNotNull($pools->fresh());
    }
}
