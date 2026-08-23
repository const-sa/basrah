<?php

namespace Tests\Feature;

use App\Models\Addon;
use App\Models\Role;
use App\Models\Unit;
use App\Models\User;
use App\Services\BookingService;
use Database\Seeders\AccountsSeeder;
use Database\Seeders\BookingSetupSeeder;
use Database\Seeders\FacilitiesSeeder;
use Database\Seeders\RolesSeeder;
use Database\Seeders\UnitsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * الخدمات الإضافية: الكتالوج الذي تقرؤه نماذج الحجز صار يُدار من شاشة.
 */
class AddonsManagementTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([
            RolesSeeder::class, FacilitiesSeeder::class,
            UnitsSeeder::class, BookingSetupSeeder::class, AccountsSeeder::class,
        ]);

        $this->owner = User::factory()->create([
            'role_id' => Role::where('slug', 'super-admin')->value('id'),
            'is_active' => true,
            'has_all_units' => true,
        ]);
    }

    public function test_the_screen_lists_the_seeded_addons_with_their_pricing_mode(): void
    {
        $this->actingAs($this->owner)
            ->get('/admin/addons')
            ->assertOk()
            ->assertInertia(fn ($p) => $p
                ->component('admin/bookings/Addons')
                ->has('addons', Addon::count())
                ->where('addons.0.name', 'ضيافة كاملة')
                ->where('addons.0.pricing', 'per_person')
                ->where('addons.0.pricing_label', 'لكل شخص')
                ->has('pricingModes', 3),
            );
    }

    public function test_an_addon_is_created_edited_and_toggled(): void
    {
        $this->actingAs($this->owner)->post('/admin/addons', [
            'name' => 'دي جي', 'price' => 1200, 'pricing' => 'fixed', 'is_active' => true,
        ])->assertSessionHasNoErrors();

        $addon = Addon::where('name', 'دي جي')->firstOrFail();
        $this->assertSame('1200.00', $addon->price);

        $this->actingAs($this->owner)->put("/admin/addons/{$addon->id}", [
            'name' => 'دي جي', 'price' => 1500, 'pricing' => 'per_hour', 'is_active' => true,
        ])->assertSessionHasNoErrors();

        $this->assertSame('1500.00', $addon->fresh()->price);
        $this->assertSame('per_hour', $addon->fresh()->pricing);

        $this->actingAs($this->owner)->patch("/admin/addons/{$addon->id}/toggle");
        $this->assertFalse($addon->fresh()->is_active);
    }

    public function test_a_duplicate_name_is_rejected(): void
    {
        $this->actingAs($this->owner)->post('/admin/addons', [
            'name' => 'ضيافة كاملة', 'price' => 50, 'pricing' => 'fixed',
        ])->assertSessionHasErrors('name');
    }

    public function test_a_disabled_addon_drops_out_of_the_booking_form(): void
    {
        $addon = Addon::where('name', 'تنظيف إضافي')->firstOrFail();

        $this->actingAs($this->owner)->patch("/admin/addons/{$addon->id}/toggle");

        $this->actingAs($this->owner)
            ->get('/admin/bookings/chalets/create')
            ->assertOk()
            ->assertInertia(fn ($p) => $p->has('addons', Addon::where('is_active', true)->count()));
    }

    public function test_an_addon_sold_on_a_booking_is_disabled_not_deleted(): void
    {
        $addon = Addon::where('name', 'إضاءة وتنسيق')->firstOrFail();

        $booking = app(BookingService::class)->create([
            'unit_id' => Unit::where('type', 'hall')->value('id'),
            'scope' => 'whole',
            'booking_date' => '2026-12-24',
            'period' => 'full_day',
            'status' => 'confirmed',
        ]);

        $booking->addons()->attach($addon->id, ['quantity' => 1, 'unit_price' => 800, 'total' => 800]);

        $this->actingAs($this->owner)
            ->delete("/admin/addons/{$addon->id}")
            ->assertSessionHas('warning');

        $this->assertNotNull($addon->fresh());
    }

    public function test_an_unused_addon_is_deleted(): void
    {
        $addon = Addon::create(['name' => 'خدمة مؤقتة', 'price' => 10, 'pricing' => 'fixed', 'is_active' => true]);

        $this->actingAs($this->owner)
            ->delete("/admin/addons/{$addon->id}")
            ->assertSessionHas('success');

        $this->assertNull($addon->fresh());
    }

    public function test_the_screen_is_closed_without_the_permission(): void
    {
        $role = Role::create(['name' => 'بلا خدمات', 'slug' => 'no-addons', 'permissions' => ['dashboard.view']]);

        $user = User::factory()->create(['role_id' => $role->id, 'is_active' => true, 'has_all_units' => true]);

        $this->actingAs($user)->get('/admin/addons')->assertForbidden();
        $this->actingAs($user)->post('/admin/addons', [
            'name' => 'تسلل', 'price' => 1, 'pricing' => 'fixed',
        ])->assertForbidden();
    }
}
