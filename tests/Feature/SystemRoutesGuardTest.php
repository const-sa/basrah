<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\Unit;
use App\Models\User;
use App\Services\BookingService;
use App\Services\ContractService;
use Database\Seeders\AccountsSeeder;
use Database\Seeders\BookingSetupSeeder;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\ContractTemplateSeeder;
use Database\Seeders\RolesSeeder;
use Database\Seeders\UnitsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * حراسة الأنظمة: كل دور يصل إلى أنظمته فقط.
 */
class SystemRoutesGuardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([
            RolesSeeder::class, UnitsSeeder::class, BookingSetupSeeder::class,
            AccountsSeeder::class, CatalogSeeder::class, ContractTemplateSeeder::class,
        ]);
    }

    private function user(string $slug): User
    {
        return User::factory()->create([
            'role_id' => Role::where('slug', $slug)->firstOrFail()->id,
            'is_active' => true,
            'has_all_units' => true,
        ]);
    }

    /**
     * @return array<string, array{0: string, 1: list<string>, 2: list<string>}>
     */
    public static function roleAccessProvider(): array
    {
        return [
            'الكاشير' => [
                'cashier',
                // مسموح
                ['/admin/pos', '/admin/items'],
                // ممنوع
                ['/admin/accounting/accounts', '/admin/accounting/journal', '/admin/hr/staff', '/admin/hr/payroll', '/admin/bookings/halls', '/admin/contracts'],
            ],
            'المحاسب' => [
                'accountant',
                ['/admin/accounting/accounts', '/admin/accounting/journal', '/admin/accounting/vouchers', '/admin/accounting/reports', '/admin/hr/staff', '/admin/hr/payroll', '/admin/bookings/halls'],
                [],
            ],
            'مشرف الوحدة' => [
                'unit-supervisor',
                ['/admin/bookings/halls', '/admin/calendar/halls', '/admin/contracts'],
                ['/admin/accounting/journal', '/admin/hr/payroll', '/admin/pos', '/admin/items'],
            ],
            'المالك' => [
                'super-admin',
                ['/admin/pos', '/admin/accounting/journal', '/admin/hr/payroll', '/admin/contracts', '/admin/suppliers', '/admin/accounting/reports'],
                [],
            ],
        ];
    }

    /**
     * @param  list<string>  $allowed
     * @param  list<string>  $forbidden
     */
    #[DataProvider('roleAccessProvider')]
    public function test_role_reaches_only_its_own_systems(string $slug, array $allowed, array $forbidden): void
    {
        $user = $this->user($slug);

        foreach ($allowed as $url) {
            $this->actingAs($user)->get($url)->assertOk();
        }

        foreach ($forbidden as $url) {
            $this->actingAs($user)->get($url)->assertForbidden();
        }
    }

    public function test_cashier_cannot_adjust_inventory_without_approval_permission(): void
    {
        // الكاشير يملك inventory.view دون inventory.approve
        $this->actingAs($this->user('cashier'))
            ->post('/admin/inventory/adjust', ['adjustments' => [['item_id' => 1, 'counted_qty' => 5]]])
            ->assertForbidden();
    }

    public function test_accountant_cannot_manage_users_or_groups(): void
    {
        $accountant = $this->user('accountant');

        $this->actingAs($accountant)->get('/admin/employees')->assertForbidden();
        $this->actingAs($accountant)->get('/admin/groups')->assertForbidden();
    }

    public function test_unit_supervisor_cannot_delete_contracts(): void
    {
        // عقد فعلي: ربط النموذج يسبق فحص الصلاحية، فبدونه يأتي 404 لا 403
        $booking = app(BookingService::class)->create([
            'unit_id' => Unit::firstOrFail()->id,
            'scope' => 'whole',
            'booking_date' => '2026-12-01',
            'period' => 'full_day',
            'status' => 'confirmed',
        ]);
        $contract = app(ContractService::class)->generate($booking);

        // مشرف الوحدة يملك contracts.create و contracts.send دون contracts.delete
        $this->actingAs($this->user('unit-supervisor'))
            ->delete("/admin/contracts/{$contract->id}")
            ->assertForbidden();
    }

    public function test_inactive_user_is_blocked_everywhere(): void
    {
        $user = $this->user('super-admin');
        $user->update(['is_active' => false]);

        foreach (['/admin/pos', '/admin/accounting/journal', '/admin/hr/payroll'] as $url) {
            $this->actingAs($user->fresh())->get($url)->assertForbidden();
        }
    }
}
