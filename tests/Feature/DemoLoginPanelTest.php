<?php

namespace Tests\Feature;

use App\Http\Controllers\Admin\DemoAccountsController;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * لوحة حسابات التجربة في شاشة الدخول.
 */
class DemoLoginPanelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesSeeder::class);
    }

    private function activate(array $keys): void
    {
        $admin = User::factory()->create([
            'role_id' => Role::where('slug', 'super-admin')->value('id'),
            'is_active' => true,
        ]);

        $this->actingAs($admin)
            ->post('/admin/employees/demo', ['accounts' => $keys])
            ->assertRedirect();

        $this->post('/admin/logout');
    }

    public function test_the_panel_is_absent_when_no_demo_account_is_activated(): void
    {
        $this->get('/admin/login')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('auth/Login')->where('demo', null));
    }

    public function test_activated_demo_accounts_appear_on_the_login_screen(): void
    {
        $this->activate(['owner', 'cashier']);

        $this->get('/admin/login')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('demo.password', DemoAccountsController::PASSWORD)
                ->has('demo.accounts', 2)
                ->where('demo.accounts.0.email', 'demo.owner@example.test')
                ->where('demo.accounts.0.label', 'المالك — كل الأنظمة')
                ->where('demo.accounts.1.email', 'demo.cashier@example.test'),
            );
    }

    public function test_a_suspended_demo_account_drops_out_of_the_panel(): void
    {
        $this->activate(['owner', 'cashier']);

        User::where('email', 'demo.cashier@example.test')->update(['is_active' => false]);

        $this->get('/admin/login')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('demo.accounts', 1)
                ->where('demo.accounts.0.email', 'demo.owner@example.test'),
            );
    }

    public function test_the_published_password_actually_signs_the_demo_account_in(): void
    {
        $this->activate(['cashier']);

        $this->post('/admin/login', [
            'email' => 'demo.cashier@example.test',
            'password' => DemoAccountsController::PASSWORD,
        ])->assertRedirect();

        $this->assertAuthenticated();
    }
}
