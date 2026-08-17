<?php

namespace Tests\Feature;

use App\Models\Package;
use App\Models\Role;
use App\Models\Unit;
use App\Models\User;
use Database\Seeders\BookingSetupSeeder;
use Database\Seeders\RolesSeeder;
use Database\Seeders\UnitsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * باقات القاعات: اسم وبنود بأعدادها.
 */
class PackagesTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([RolesSeeder::class, UnitsSeeder::class, BookingSetupSeeder::class]);

        $this->owner = User::factory()->create([
            'role_id' => Role::where('slug', 'super-admin')->value('id'),
            'is_active' => true,
            'has_all_units' => true,
        ]);
    }

    public function test_a_package_is_created_with_its_items_and_counts(): void
    {
        $this->actingAs($this->owner)->post('/admin/packages', [
            'name' => 'باقة الزفاف الذهبية',
            'price' => 32000,
            'is_active' => true,
            'items' => [
                ['name' => 'عدد المعازيم رجال', 'quantity' => 500, 'unit_label' => 'شخص'],
                ['name' => 'عدد المعازيم نساء', 'quantity' => 300, 'unit_label' => 'شخص'],
                ['name' => 'صبّابين', 'quantity' => 4, 'unit_label' => 'فرد'],
            ],
        ])->assertSessionHasNoErrors();

        $package = Package::with('items')->firstWhere('name', 'باقة الزفاف الذهبية');

        $this->assertNotNull($package);
        $this->assertCount(3, $package->items);
        $this->assertSame(500.0, (float) $package->items[0]->quantity);
        $this->assertSame('500 شخص', $package->items[0]->quantityLabel());
        $this->assertSame('4 فرد', $package->items[2]->quantityLabel());
    }

    public function test_items_keep_the_order_they_were_entered_in(): void
    {
        $this->actingAs($this->owner)->post('/admin/packages', [
            'name' => 'باقة مرتّبة', 'is_active' => true,
            'items' => [
                ['name' => 'أول', 'quantity' => 1],
                ['name' => 'ثانٍ', 'quantity' => 2],
                ['name' => 'ثالث', 'quantity' => 3],
            ],
        ])->assertSessionHasNoErrors();

        $names = Package::firstWhere('name', 'باقة مرتّبة')->items->pluck('name')->all();

        $this->assertSame(['أول', 'ثانٍ', 'ثالث'], $names);
    }

    public function test_editing_replaces_the_item_list(): void
    {
        $package = Package::create(['name' => 'باقة', 'is_active' => true]);
        $package->items()->createMany([
            ['name' => 'قديم أ', 'quantity' => 1],
            ['name' => 'قديم ب', 'quantity' => 2],
        ]);

        $this->actingAs($this->owner)->put("/admin/packages/{$package->id}", [
            'name' => 'باقة محدَّثة', 'is_active' => true,
            'items' => [['name' => 'جديد', 'quantity' => 9, 'unit_label' => 'شخص']],
        ])->assertSessionHasNoErrors();

        $package->refresh()->load('items');

        $this->assertSame('باقة محدَّثة', $package->name);
        $this->assertCount(1, $package->items);
        $this->assertSame('جديد', $package->items[0]->name);
    }

    public function test_a_blank_item_row_is_ignored(): void
    {
        $this->actingAs($this->owner)->post('/admin/packages', [
            'name' => 'باقة بصف فارغ', 'is_active' => true,
            'items' => [
                ['name' => 'بند صحيح', 'quantity' => 5],
                ['name' => '', 'quantity' => 0],
            ],
        ])->assertSessionHasErrors('items.1.name');
    }

    public function test_a_package_can_target_one_hall_or_all_halls(): void
    {
        $hall = Unit::where('type', 'hall')->firstOrFail();

        $general = Package::create(['name' => 'عامة', 'is_active' => true]);
        $specific = Package::create(['name' => 'خاصة', 'unit_id' => $hall->id, 'is_active' => true]);

        $this->assertTrue($general->isGeneral());
        $this->assertFalse($specific->isGeneral());

        // باقات القاعة = الخاصة بها + العامة
        $available = Package::forUnit($hall->id)->pluck('name')->all();

        $this->assertEqualsCanonicalizing(['عامة', 'خاصة'], $available);
    }

    public function test_the_screen_only_offers_halls_not_chalets(): void
    {
        $halls = Unit::where('type', 'hall')->count();

        $this->actingAs($this->owner)
            ->get('/admin/packages')
            ->assertOk()
            ->assertInertia(fn ($p) => $p
                ->component('admin/units/Packages')
                ->has('halls', $halls),
            );
    }

    public function test_duplicating_copies_the_name_and_all_items(): void
    {
        $package = Package::create(['name' => 'باقة أصلية', 'price' => 5000, 'is_active' => true]);
        $package->items()->createMany([
            ['name' => 'عدد المعازيم رجال', 'quantity' => 200, 'unit_label' => 'شخص'],
            ['name' => 'صبّابين', 'quantity' => 3, 'unit_label' => 'فرد'],
        ]);

        $this->actingAs($this->owner)
            ->post("/admin/packages/{$package->id}/duplicate")
            ->assertSessionHas('success');

        $copy = Package::with('items')->firstWhere('name', 'باقة أصلية — نسخة');

        $this->assertNotNull($copy);
        $this->assertCount(2, $copy->items);
        $this->assertSame(5000.0, (float) $copy->price);
        // النسخة مستقلة: تعديلها لا يمسّ الأصل
        $this->assertNotSame($package->id, $copy->id);
    }

    public function test_a_cashier_cannot_reach_packages(): void
    {
        $cashier = User::factory()->create([
            'role_id' => Role::where('slug', 'cashier')->value('id'),
            'is_active' => true,
        ]);

        $this->actingAs($cashier)->get('/admin/packages')->assertForbidden();
    }

    public function test_a_unit_supervisor_can_manage_but_not_delete_packages(): void
    {
        $supervisor = User::factory()->create([
            'role_id' => Role::where('slug', 'unit-supervisor')->value('id'),
            'is_active' => true,
            'has_all_units' => true,
        ]);

        $this->actingAs($supervisor)->get('/admin/packages')->assertOk();

        $package = Package::create(['name' => 'باقة', 'is_active' => true]);

        $this->actingAs($supervisor)
            ->delete("/admin/packages/{$package->id}")
            ->assertForbidden();
    }

    public function test_fractional_quantities_render_without_trailing_zeros(): void
    {
        $package = Package::create(['name' => 'باقة كسور', 'is_active' => true]);
        $whole = $package->items()->create(['name' => 'أفراد', 'quantity' => 4, 'unit_label' => 'فرد']);
        $half = $package->items()->create(['name' => 'ساعات', 'quantity' => 2.5, 'unit_label' => 'ساعة']);

        $this->assertSame('4 فرد', $whole->quantityLabel());
        $this->assertSame('2.5 ساعة', $half->quantityLabel());
    }
}
