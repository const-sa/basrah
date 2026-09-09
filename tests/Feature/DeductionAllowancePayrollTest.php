<?php

namespace Tests\Feature;

use App\Models\Allowance;
use App\Models\Deduction;
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
 * الخصومات والبدلات الظرفية: نفس قواعد المكافأة — تُقفل على مسيّر شهرها
 * ولا تُصرف/تُستقطع مرتين، لكن الخصم يُطرح من الإجمالي بدل أن يُضاف إليه.
 */
class DeductionAllowancePayrollTest extends TestCase
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
            'employee_no' => 'EMP-200',
            'name' => 'خالد المخصوم',
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

    private function deduction(array $overrides = []): Deduction
    {
        return Deduction::create([
            'employee_id' => $this->employee->id,
            'amount' => 200,
            'reason' => 'مخالفة تأخير',
            'deducted_on' => '2026-08-10',
            'status' => 'approved',
            ...$overrides,
        ]);
    }

    private function allowance(array $overrides = []): Allowance
    {
        return Allowance::create([
            'employee_id' => $this->employee->id,
            'amount' => 400,
            'reason' => 'بدل انتداب',
            'granted_on' => '2026-08-10',
            'status' => 'approved',
            ...$overrides,
        ]);
    }

    public function test_approved_deduction_is_subtracted_from_the_month_payroll(): void
    {
        $this->deduction();

        $line = $this->payroll->generate(2026, 8)->lines->first();

        // الإجمالي = أساسي 3000 + بدلات 1000 = 4000، والصافي بعد خصم 200 = 3800
        $this->assertSame(200.0, (float) $line->other_deduction);
        $this->assertSame(4000.0, (float) $line->gross);
        $this->assertSame(3800.0, (float) $line->net);
    }

    public function test_approved_allowance_is_added_to_the_month_payroll(): void
    {
        $this->allowance();

        $line = $this->payroll->generate(2026, 8)->lines->first();

        // الإجمالي = أساسي 3000 + بدلات 1000 + بدل ظرفي 400
        $this->assertSame(400.0, (float) $line->other_allowance);
        $this->assertSame(4400.0, (float) $line->gross);
        $this->assertSame(4400.0, (float) $line->net);
    }

    public function test_pending_deduction_and_allowance_are_not_applied_until_approved(): void
    {
        $this->deduction(['status' => 'pending']);
        $this->allowance(['status' => 'pending']);

        $line = $this->payroll->generate(2026, 8)->lines->first();

        $this->assertSame(0.0, (float) $line->other_deduction);
        $this->assertSame(0.0, (float) $line->other_allowance);
        $this->assertSame(4000.0, (float) $line->gross);
        $this->assertSame(4000.0, (float) $line->net);
    }

    public function test_regenerating_the_payroll_keeps_them_once(): void
    {
        $this->deduction();
        $this->allowance();

        $this->payroll->generate(2026, 8);
        $line = $this->payroll->generate(2026, 8)->lines->first();

        $this->assertSame(200.0, (float) $line->other_deduction);
        $this->assertSame(400.0, (float) $line->other_allowance);
        $this->assertSame(1, Deduction::count());
        $this->assertSame(1, Allowance::count());
    }

    public function test_approving_the_payroll_settles_both(): void
    {
        $deduction = $this->deduction();
        $allowance = $this->allowance();

        $payroll = $this->payroll->generate(2026, 8);
        $this->payroll->approve($payroll, $this->owner->id);

        $deduction->refresh();
        $allowance->refresh();

        $this->assertSame('paid', $deduction->status);
        $this->assertSame($payroll->id, $deduction->payroll_id);
        $this->assertSame('paid', $allowance->status);
        $this->assertSame($payroll->id, $allowance->payroll_id);
        $this->assertSame(0, Deduction::payable()->count());
        $this->assertSame(0, Allowance::payable()->count());
    }

    public function test_deduction_can_be_created_and_approved_from_the_hr_screen(): void
    {
        $this->actingAs($this->owner)
            ->post('/admin/hr/deductions', [
                'employee_id' => $this->employee->id,
                'amount' => 150,
                'reason' => 'غياب غير مبرر',
                'deducted_on' => '2026-08-12',
            ])
            ->assertRedirect();

        $deduction = Deduction::firstOrFail();
        $this->assertSame('pending', $deduction->status);

        $this->actingAs($this->owner)
            ->patch("/admin/hr/deductions/{$deduction->id}/approve")
            ->assertRedirect();

        $this->assertSame('approved', $deduction->fresh()->status);
    }

    public function test_allowance_can_be_created_and_approved_from_the_hr_screen(): void
    {
        $this->actingAs($this->owner)
            ->post('/admin/hr/allowances', [
                'employee_id' => $this->employee->id,
                'amount' => 300,
                'reason' => 'بدل سفر',
                'granted_on' => '2026-08-12',
            ])
            ->assertRedirect();

        $allowance = Allowance::firstOrFail();
        $this->assertSame('pending', $allowance->status);

        $this->actingAs($this->owner)
            ->patch("/admin/hr/allowances/{$allowance->id}/approve")
            ->assertRedirect();

        $this->assertSame('approved', $allowance->fresh()->status);
    }

    public function test_paid_deduction_and_allowance_cannot_be_deleted(): void
    {
        $deduction = $this->deduction();
        $allowance = $this->allowance();

        $this->payroll->approve($this->payroll->generate(2026, 8), $this->owner->id);

        $this->actingAs($this->owner)
            ->delete("/admin/hr/deductions/{$deduction->id}")
            ->assertSessionHas('warning');
        $this->actingAs($this->owner)
            ->delete("/admin/hr/allowances/{$allowance->id}")
            ->assertSessionHas('warning');

        $this->assertDatabaseHas('deductions', ['id' => $deduction->id]);
        $this->assertDatabaseHas('allowances', ['id' => $allowance->id]);
    }

    public function test_hr_screen_lists_deductions_and_allowances(): void
    {
        $this->deduction();
        $this->allowance();

        $this->actingAs($this->owner)
            ->get('/admin/hr/leaves')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('admin/hr/Leaves')
                ->has('deductions', 1)
                ->where('deductions.0.reason', 'مخالفة تأخير')
                ->has('allowances', 1)
                ->where('allowances.0.reason', 'بدل انتداب'),
            );
    }
}
