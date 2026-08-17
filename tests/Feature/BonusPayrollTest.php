<?php

namespace Tests\Feature;

use App\Models\Bonus;
use App\Models\Employee;
use App\Models\Role;
use App\Models\Unit;
use App\Models\User;
use App\Services\PayrollService;
use Database\Seeders\AccountsSeeder;
use Database\Seeders\BookingSetupSeeder;
use Database\Seeders\RolesSeeder;
use Database\Seeders\UnitsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * المكافآت (§8 من العرض): تُضاف إلى مسيّر شهرها ولا تُصرف مرتين.
 */
class BonusPayrollTest extends TestCase
{
    use RefreshDatabase;

    private PayrollService $payroll;

    private Employee $employee;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([RolesSeeder::class, UnitsSeeder::class, BookingSetupSeeder::class, AccountsSeeder::class]);

        $this->payroll = app(PayrollService::class);

        $this->employee = Employee::create([
            'employee_no' => 'EMP-100',
            'name' => 'سعيد المكافأ',
            'unit_id' => Unit::where('code', 'CH-BSR1')->firstOrFail()->id,
            'basic_salary' => 3000,
            'housing_allowance' => 750,
            'transport_allowance' => 250,
            'is_active' => true,
        ]);

        $this->owner = User::factory()->create([
            'role_id' => Role::where('slug', 'super-admin')->firstOrFail()->id,
            'is_active' => true,
            'has_all_units' => true,
        ]);
    }

    private function bonus(array $overrides = []): Bonus
    {
        return Bonus::create([
            'employee_id' => $this->employee->id,
            'amount' => 500,
            'reason' => 'أداء متميز',
            'granted_on' => '2026-08-10',
            'status' => 'approved',
            ...$overrides,
        ]);
    }

    public function test_approved_bonus_is_added_to_the_month_payroll(): void
    {
        $this->bonus();

        $line = $this->payroll->generate(2026, 8)->lines->first();

        $this->assertSame(500.0, (float) $line->bonus);
        // الإجمالي = أساسي 3000 + بدلات 1000 + مكافأة 500
        $this->assertSame(4500.0, (float) $line->gross);
        $this->assertSame(4500.0, (float) $line->net);
    }

    public function test_pending_bonus_is_not_paid_until_approved(): void
    {
        $this->bonus(['status' => 'pending']);

        $line = $this->payroll->generate(2026, 8)->lines->first();

        $this->assertSame(0.0, (float) $line->bonus);
        $this->assertSame(4000.0, (float) $line->gross);
    }

    /**
     * المكافأة تتبع شهر منحها لا شهر التوليد.
     */
    public function test_bonus_belongs_to_the_month_it_was_granted_in(): void
    {
        $this->bonus(['granted_on' => '2026-07-20']);

        $august = $this->payroll->generate(2026, 8)->lines->first();
        $july = $this->payroll->generate(2026, 7)->lines->first();

        $this->assertSame(0.0, (float) $august->bonus);
        $this->assertSame(500.0, (float) $july->bonus);
    }

    /**
     * الحماية الجوهرية: إعادة التوليد لا تُضاعف المكافأة ولا تُسقطها.
     */
    public function test_regenerating_the_payroll_keeps_the_bonus_once(): void
    {
        $this->bonus();

        $this->payroll->generate(2026, 8);
        $line = $this->payroll->generate(2026, 8)->lines->first();

        $this->assertSame(500.0, (float) $line->bonus);
        $this->assertSame(1, Bonus::count());
    }

    /**
     * اعتماد المسيّر يُقفل المكافأة على مسيّره فلا تعود في الشهر التالي.
     */
    public function test_approving_the_payroll_settles_the_bonus(): void
    {
        $bonus = $this->bonus();

        $payroll = $this->payroll->generate(2026, 8);
        $this->payroll->approve($payroll, $this->owner->id);

        $bonus->refresh();

        $this->assertSame('paid', $bonus->status);
        $this->assertSame($payroll->id, $bonus->payroll_id);
        $this->assertSame(0, Bonus::payable()->count());
    }

    /**
     * المسيّر التالي يحمل مكافآت شهره وحدها — لا مكافأةً صُرفت قبله.
     */
    public function test_next_month_payroll_carries_only_its_own_bonuses(): void
    {
        $this->bonus(['granted_on' => '2026-08-10', 'amount' => 500]);

        $this->payroll->approve($this->payroll->generate(2026, 8), $this->owner->id);

        $this->bonus(['granted_on' => '2026-09-05', 'amount' => 300]);

        $september = $this->payroll->generate(2026, 9)->lines->first();

        $this->assertSame(300.0, (float) $september->bonus);
        // المكافأة المصروفة بقيت مقفلة على مسيّر أغسطس
        $this->assertSame(1, Bonus::where('status', 'paid')->count());
    }

    public function test_bonus_can_be_created_and_approved_from_the_hr_screen(): void
    {
        $this->actingAs($this->owner)
            ->post('/admin/hr/bonuses', [
                'employee_id' => $this->employee->id,
                'amount' => 750,
                'reason' => 'مكافأة موسم',
                'granted_on' => '2026-08-12',
            ])
            ->assertRedirect();

        $bonus = Bonus::firstOrFail();
        $this->assertSame('pending', $bonus->status);

        $this->actingAs($this->owner)
            ->patch("/admin/hr/bonuses/{$bonus->id}/approve")
            ->assertRedirect();

        $this->assertSame('approved', $bonus->fresh()->status);
    }

    public function test_paid_bonus_cannot_be_deleted(): void
    {
        $bonus = $this->bonus();

        $this->payroll->approve($this->payroll->generate(2026, 8), $this->owner->id);

        $this->actingAs($this->owner)
            ->delete("/admin/hr/bonuses/{$bonus->id}")
            ->assertSessionHas('warning');

        $this->assertDatabaseHas('bonuses', ['id' => $bonus->id]);
    }

    public function test_hr_screen_lists_bonuses(): void
    {
        $this->bonus();

        $this->actingAs($this->owner)
            ->get('/admin/hr/leaves')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('admin/hr/Leaves')
                ->has('bonuses', 1)
                ->where('bonuses.0.reason', 'أداء متميز')
                ->where('bonuses.0.status', 'approved'),
            );
    }
}
