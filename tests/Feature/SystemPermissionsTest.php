<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\Unit;
use App\Models\User;
use Database\Seeders\RolesSeeder;
use Database\Seeders\UnitsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SystemPermissionsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([RolesSeeder::class, UnitsSeeder::class]);
    }

    private function userWithRole(string $slug): User
    {
        return User::factory()->create([
            'role_id' => Role::where('slug', $slug)->firstOrFail()->id,
            'is_active' => true,
        ]);
    }

    public function test_roles_page_renders_systems_tree(): void
    {
        $owner = $this->userWithRole('super-admin');

        $this->actingAs($owner)
            ->get('/admin/roles')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('admin/roles/Index')
                ->has('systems', 8)
                ->where('systems.0.key', 'core')
                ->has('systems.0.modules')
                ->has('systems.0.permission_keys'),
            );
    }

    public function test_cashier_is_blocked_from_accounting_system(): void
    {
        $cashier = $this->userWithRole('cashier');

        $this->assertTrue($cashier->hasSystemAccess('pos'));
        $this->assertFalse($cashier->hasSystemAccess('accounting'));
        $this->assertFalse($cashier->hasPermission('journal.create'));
    }

    public function test_unit_supervisor_is_scoped_to_assigned_units_only(): void
    {
        $supervisor = $this->userWithRole('unit-supervisor');
        $mine = Unit::firstOrFail();
        $other = Unit::where('id', '!=', $mine->id)->firstOrFail();

        $supervisor->units()->sync([$mine->id]);

        $this->assertTrue($supervisor->canAccessUnit($mine));
        $this->assertFalse($supervisor->canAccessUnit($other));
        $this->assertSame([$mine->id], $supervisor->accessibleUnitIds());

        $visible = Unit::visibleTo($supervisor->fresh())->pluck('id')->all();
        $this->assertSame([$mine->id], $visible);
    }

    public function test_owner_sees_every_unit(): void
    {
        $owner = $this->userWithRole('super-admin');

        $this->assertNull($owner->accessibleUnitIds());
        $this->assertSame(Unit::count(), Unit::visibleTo($owner)->count());
    }

    public function test_inactive_user_loses_all_access(): void
    {
        $user = $this->userWithRole('accountant');
        $user->update(['is_active' => false]);

        $this->assertSame([], $user->fresh()->accessibleSystems());
        $this->assertFalse($user->fresh()->hasPermission('journal.create'));
    }
}
