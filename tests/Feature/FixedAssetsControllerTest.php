<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Database\Seeders\AccountsSeeder;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * شاشة الأصول الثابتة: التسجيل، الاستبعاد، وترحيل الإهلاك عبر الواجهة.
 */
class FixedAssetsControllerTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([RolesSeeder::class, AccountsSeeder::class]);

        $this->owner = User::factory()->create([
            'role_id' => Role::where('slug', 'super-admin')->firstOrFail()->id,
            'is_active' => true,
            'has_all_units' => true,
        ]);
    }

    public function test_an_asset_can_be_registered_and_its_depreciation_posted(): void
    {
        $this->actingAs($this->owner)
            ->post('/admin/accounting/fixed-assets', [
                'name' => 'مكيف شباك',
                'purchase_date' => '2026-01-01',
                'cost' => 6000,
                'salvage_value' => 0,
                'useful_life_months' => 60,
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('fixed_assets', ['name' => 'مكيف شباك', 'status' => 'active']);

        $this->post('/admin/accounting/fixed-assets/post-depreciation', ['period' => '2026-09'])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertDatabaseHas('asset_depreciation_entries', ['amount' => 100]);
    }

    public function test_a_cashier_without_the_permission_is_forbidden(): void
    {
        $cashier = User::factory()->create([
            'role_id' => Role::where('slug', 'cashier')->firstOrFail()->id,
            'is_active' => true,
            'has_all_units' => true,
        ]);

        $this->actingAs($cashier)->get('/admin/accounting/fixed-assets')->assertForbidden();
    }
}
