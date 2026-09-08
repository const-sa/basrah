<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A role without the dashboard is forwarded to its own first screen, because
 * login and the sidebar logo both lead to /admin whoever the employee is.
 */
class DashboardLandingTest extends TestCase
{
    use RefreshDatabase;

    private function userWith(array $permissions): User
    {
        $role = Role::create([
            'name' => 'دور اختبار',
            'slug' => 'test-role-'.uniqid(),
            'permissions' => $permissions,
        ]);

        return User::factory()->create(['role_id' => $role->id, 'is_active' => true]);
    }

    public function test_a_pools_role_lands_on_the_invoices_screen(): void
    {
        $user = $this->userWith(['pos.view', 'sales.view', 'items.view']);

        $this->actingAs($user)->get('/admin')->assertRedirect('/admin/pos');
    }

    public function test_a_halls_role_lands_on_its_bookings(): void
    {
        $user = $this->userWith(['hall_bookings.view', 'halls.view', 'hall_contract.view']);

        $this->actingAs($user)->get('/admin')->assertRedirect('/admin/bookings/halls');
    }

    public function test_the_landing_screen_actually_opens(): void
    {
        $user = $this->userWith(['pos.view']);

        $this->actingAs($user)->get('/admin/pos')->assertOk();
    }

    public function test_logging_in_leads_to_that_screen_not_to_a_refusal(): void
    {
        $user = $this->userWith(['pos.view']);

        $this->post('/admin/login', ['email' => $user->email, 'password' => 'password'])
            ->assertRedirect('/admin');

        $this->actingAs($user)->get('/admin')->assertRedirect('/admin/pos');
    }

    public function test_a_role_with_no_screen_at_all_is_still_refused(): void
    {
        $this->actingAs($this->userWith([]))->get('/admin')->assertForbidden();
    }
}
