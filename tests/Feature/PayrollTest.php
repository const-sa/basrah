<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Advance;
use App\Models\Attendance;
use App\Models\CostCenter;
use App\Models\Employee;
use App\Models\Unit;
use App\Services\Accounting\Ledger;
use App\Services\PayrollService;
use Database\Seeders\AccountsSeeder;
use Database\Seeders\BookingSetupSeeder;
use Database\Seeders\RolesSeeder;
use Database\Seeders\UnitsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * مسيّر الرواتب: البدلات، الإضافي، خصم الغياب، استقطاع السلف، القيد.
 */
class PayrollTest extends TestCase
{
    use RefreshDatabase;

    private PayrollService $payroll;

    private Employee $employee;

    private Unit $unit;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([RolesSeeder::class, UnitsSeeder::class, BookingSetupSeeder::class, AccountsSeeder::class]);

        $this->payroll = app(PayrollService::class);
        $this->unit = Unit::where('code', 'CH-BSR1')->firstOrFail();

        $this->employee = Employee::create([
            'employee_no' => 'EMP-001',
            'name' => 'أحمد العامل',
            'position' => 'مشرف',
            'unit_id' => $this->unit->id,
            'basic_salary' => 3000,
            'housing_allowance' => 750,
            'transport_allowance' => 250,
            'is_active' => true,
        ]);
    }

    public function test_gross_salary_is_basic_plus_allowances(): void
    {
        $this->assertSame(1000.0, $this->employee->totalAllowances());
        $this->assertSame(4000.0, $this->employee->grossSalary());
        $this->assertSame(133.33, $this->employee->dailyRate());
    }

    public function test_payroll_line_defaults_to_full_attendance(): void
    {
        $payroll = $this->payroll->generate(2026, 9);
        $line = $payroll->lines->firstWhere('employee_id', $this->employee->id);

        // بلا سجل غياب يُفترض الحضور الكامل — الغياب يُثبَت لا يُفترض
        $this->assertSame(30, $line->worked_days);
        $this->assertSame(0, $line->absent_days);
        $this->assertSame(4000.0, (float) $line->gross);
        $this->assertSame(4000.0, (float) $line->net);
    }

    public function test_absence_is_deducted_at_the_daily_rate(): void
    {
        foreach (['2026-09-03', '2026-09-04'] as $date) {
            Attendance::create([
                'employee_id' => $this->employee->id,
                'attendance_date' => $date,
                'status' => 'absent',
            ]);
        }

        $payroll = $this->payroll->generate(2026, 9);
        $line = $payroll->lines->firstWhere('employee_id', $this->employee->id);

        $this->assertSame(2, $line->absent_days);
        $this->assertSame(28, $line->worked_days);
        $this->assertSame(266.66, (float) $line->absence_deduction); // 2 × 133.33
        $this->assertSame(3733.34, (float) $line->net);
    }

    public function test_overtime_is_paid_at_one_and_a_half_times(): void
    {
        Attendance::create([
            'employee_id' => $this->employee->id,
            'attendance_date' => '2026-09-05',
            'status' => 'present',
            'overtime_hours' => 10,
        ]);

        $payroll = $this->payroll->generate(2026, 9);
        $line = $payroll->lines->firstWhere('employee_id', $this->employee->id);

        // (3000 ÷ 30 ÷ 8) × 1.5 = 18.75 للساعة × 10 = 187.5
        $this->assertSame(18.75, $this->employee->overtimeHourlyRate());
        $this->assertSame(187.5, (float) $line->overtime_amount);
        $this->assertSame(4187.5, (float) $line->net);
    }

    public function test_approved_advance_is_deducted_by_installment(): void
    {
        Advance::create([
            'employee_id' => $this->employee->id,
            'amount' => 1200,
            'installments' => 4,
            'installment_amount' => 300,
            'granted_on' => '2026-08-01',
            'status' => 'approved',
        ]);

        $payroll = $this->payroll->generate(2026, 9);
        $line = $payroll->lines->firstWhere('employee_id', $this->employee->id);

        $this->assertSame(300.0, (float) $line->advance_deduction);
        $this->assertSame(3700.0, (float) $line->net);
    }

    public function test_pending_advance_is_not_deducted(): void
    {
        Advance::create([
            'employee_id' => $this->employee->id,
            'amount' => 900,
            'installments' => 3,
            'installment_amount' => 300,
            'granted_on' => '2026-08-01',
            'status' => 'pending',
        ]);

        $payroll = $this->payroll->generate(2026, 9);
        $line = $payroll->lines->firstWhere('employee_id', $this->employee->id);

        $this->assertSame(0.0, (float) $line->advance_deduction);
    }

    public function test_approving_the_payroll_records_the_advance_deduction_once(): void
    {
        $advance = Advance::create([
            'employee_id' => $this->employee->id,
            'amount' => 1200,
            'installments' => 4,
            'installment_amount' => 300,
            'granted_on' => '2026-08-01',
            'status' => 'approved',
        ]);

        $payroll = $this->payroll->generate(2026, 9);

        // التوليد وحده لا يستقطع — التوليد قابل للتكرار والاستقطاع لا
        $this->assertSame(0.0, (float) $advance->fresh()->deducted_amount);

        $this->payroll->approve($payroll);

        $this->assertSame(300.0, (float) $advance->fresh()->deducted_amount);
        $this->assertSame(900.0, $advance->fresh()->remainingAmount());
    }

    public function test_advance_settles_itself_when_fully_deducted(): void
    {
        $advance = Advance::create([
            'employee_id' => $this->employee->id,
            'amount' => 300,
            'installments' => 1,
            'installment_amount' => 300,
            'granted_on' => '2026-08-01',
            'status' => 'approved',
        ]);

        $this->payroll->approve($this->payroll->generate(2026, 9));

        $this->assertSame('settled', $advance->fresh()->status);
        $this->assertSame(0.0, $advance->fresh()->remainingAmount());
    }

    public function test_regenerating_a_draft_does_not_duplicate_lines(): void
    {
        $this->payroll->generate(2026, 9);
        $payroll = $this->payroll->generate(2026, 9);

        $this->assertSame(1, $payroll->lines()->count());
    }

    public function test_an_approved_payroll_cannot_be_regenerated(): void
    {
        $this->payroll->approve($this->payroll->generate(2026, 9));

        $this->expectException(RuntimeException::class);
        $this->payroll->generate(2026, 9);
    }

    public function test_approving_posts_the_salary_entry_on_the_units_cost_center(): void
    {
        $payroll = $this->payroll->approve($this->payroll->generate(2026, 9));

        $this->assertNotNull($payroll->journal_entry_id);
        $this->assertSame(4000.0, Account::where('code', Ledger::SALARIES_EXPENSE)->first()->balance());
        $this->assertSame(4000.0, Account::where('code', Ledger::SALARIES_PAYABLE)->first()->balance());

        // مصروف الراتب يُحمَّل على مركز تكلفة وحدة الموظف لا على المحل
        $profit = CostCenter::forUnit($this->unit)->profitability();
        $this->assertSame(4000.0, $profit['expense']);
    }

    public function test_expiring_documents_are_detected(): void
    {
        $this->employee->update(['iqama_expiry' => now()->addDays(20)->toDateString()]);

        $alerts = $this->employee->fresh()->expiringDocuments(60);

        $this->assertCount(1, $alerts);
        $this->assertSame('الإقامة', $alerts[0]['label']);
        $this->assertTrue(Employee::withExpiringDocuments(60)->pluck('id')->contains($this->employee->id));
    }
}
