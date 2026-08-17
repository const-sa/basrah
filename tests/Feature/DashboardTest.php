<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_the_login_page(): void
    {
        $this->get('/admin')->assertRedirect('/admin/login');
    }

    public function test_a_user_with_the_permission_can_visit_the_dashboard(): void
    {
        $this->seed(RolesSeeder::class);

        $user = User::factory()->create([
            'role_id' => Role::where('slug', 'super-admin')->value('id'),
            'is_active' => true,
        ]);

        $this->actingAs($user)->get('/admin')->assertOk();
    }

    public function test_a_user_without_any_role_is_denied(): void
    {
        // المصادقة وحدها لا تكفي — الوصول محكوم بالصلاحية لا بتسجيل الدخول
        $user = User::factory()->create(['role_id' => null, 'is_active' => true]);

        $this->actingAs($user)->get('/admin')->assertForbidden();
    }

    public function test_a_deactivated_user_is_denied_even_with_a_full_role(): void
    {
        $this->seed(RolesSeeder::class);

        $user = User::factory()->create([
            'role_id' => Role::where('slug', 'super-admin')->value('id'),
            'is_active' => false,
        ]);

        $this->actingAs($user)->get('/admin')->assertForbidden();
    }
}
