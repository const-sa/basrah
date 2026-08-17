<?php

namespace App\Services;

use App\Models\Advance;
use App\Models\Attendance;
use App\Models\CostCenter;
use App\Models\Employee;
use App\Models\Payroll;
use App\Services\Accounting\Ledger;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * توليد مسيّر الرواتب الشهري (§الطبقة أ - بند 5):
 * أساسي + بدلات + إضافي − خصومات − سلف.
 */
class PayrollService
{
    /** أيام الشهر المعتمدة في احتساب أجر اليوم — من افتراضات التشغيل. */
    public static function monthDays(): int
    {
        return (int) config('operations.hr.month_days', 30);
    }

    public function __construct(private readonly Ledger $ledger) {}

    /**
     * توليد مسيّر شهر — يُعاد توليده ما دام مسوّدة.
     */
    public function generate(int $year, int $month, ?int $userId = null): Payroll
    {
        return DB::transaction(function () use ($year, $month, $userId) {
            $payroll = Payroll::firstOrNew(['year' => $year, 'month' => $month]);

            if ($payroll->exists && ! $payroll->isDraft()) {
                throw new RuntimeException('المسيّر معتمد ولا يُعاد توليده — اعكسه أولًا.');
            }

            if (! $payroll->exists) {
                $payroll->fill([
                    'number' => sprintf('PR-%d-%02d', $year, $month),
                    'status' => 'draft',
                    'created_by' => $userId,
                ])->save();
            }

            // إعادة التوليد تمسح السطور القديمة حتى لا تتضاعف.
            $payroll->lines()->delete();

            $start = CarbonImmutable::create($year, $month, 1)->startOfMonth();
            $end = $start->endOfMonth();

            foreach (Employee::where('is_active', true)->get() as $employee) {
                $payroll->lines()->create($this->buildLine($employee, $start, $end));
            }

            $payroll->recalculateTotals();

            return $payroll->fresh('lines');
        });
    }

    /**
     * سطر موظف واحد.
     *
     * @return array<string, mixed>
     */
    private function buildLine(Employee $employee, CarbonImmutable $start, CarbonImmutable $end): array
    {
        $records = Attendance::where('employee_id', $employee->id)
            ->whereBetween('attendance_date', [$start->toDateString(), $end->toDateString()])
            ->get();

        $absentDays = $records->where('status', 'absent')->count();
        $overtimeHours = round((float) $records->sum('overtime_hours'), 2);

        // بلا سجل حضور يُفترض الحضور الكامل — الغياب يُثبَت لا يُفترض.
        $workedDays = self::monthDays() - $absentDays;

        $basic = (float) $employee->basic_salary;
        $allowances = $employee->totalAllowances();
        $overtimeAmount = round($overtimeHours * $employee->overtimeHourlyRate(), 2);
        $absenceDeduction = round($absentDays * $employee->dailyRate(), 2);

        $advanceDeduction = round(
            (float) $employee->advances()
                ->where('status', 'approved')
                ->get()
                ->sum(fn (Advance $a) => $a->dueInstallment()),
            2,
        );

        $gross = round($basic + $allowances + $overtimeAmount, 2);
        $net = round(max(0, $gross - $absenceDeduction - $advanceDeduction), 2);

        return [
            'employee_id' => $employee->id,
            'unit_id' => $employee->unit_id,
            'basic_salary' => $basic,
            'allowances' => $allowances,
            'overtime_amount' => $overtimeAmount,
            'bonus' => 0,
            'absence_deduction' => $absenceDeduction,
            'advance_deduction' => $advanceDeduction,
            'other_deduction' => 0,
            'gross' => $gross,
            'net' => $net,
            'worked_days' => max(0, $workedDays),
            'absent_days' => $absentDays,
            'overtime_hours' => $overtimeHours,
        ];
    }

    /**
     * اعتماد المسيّر: تثبيت القيد المحاسبي وتسجيل استقطاع السلف.
     *
     * الاستقطاع يُسجَّل هنا لا عند التوليد، لأن التوليد قابل للتكرار
     * والاستقطاع لا يجوز أن يتكرر.
     */
    public function approve(Payroll $payroll, ?int $userId = null): Payroll
    {
        if (! $payroll->isDraft()) {
            throw new RuntimeException('المسيّر معتمد مسبقًا.');
        }

        return DB::transaction(function () use ($payroll, $userId) {
            $payroll->loadMissing('lines.employee');

            $entry = $this->postEntry($payroll, $userId);

            foreach ($payroll->lines as $line) {
                $remaining = (float) $line->advance_deduction;

                if ($remaining <= 0) {
                    continue;
                }

                $advances = $line->employee->advances()->where('status', 'approved')->get();

                foreach ($advances as $advance) {
                    if ($remaining <= 0) {
                        break;
                    }

                    $take = min($advance->dueInstallment(), $remaining);

                    if ($take > 0) {
                        $advance->recordDeduction($take);
                        $remaining = round($remaining - $take, 2);
                    }
                }
            }

            $payroll->update([
                'status' => 'approved',
                'approved_by' => $userId,
                'approved_at' => now(),
                'journal_entry_id' => $entry?->id,
            ]);

            return $payroll->fresh();
        });
    }

    /**
     * قيد الرواتب: مدين مصروف الرواتب لكل مركز تكلفة، دائن رواتب مستحقة.
     */
    private function postEntry(Payroll $payroll, ?int $userId)
    {
        if ((float) $payroll->total_net <= 0) {
            return null;
        }

        $lines = [];

        // توزيع مصروف الراتب على مركز تكلفة وحدة كل موظف — هو ما يجعل
        // ربحية الوحدة صحيحة بدل تحميل كل الرواتب على المحل.
        $byUnit = $payroll->lines->groupBy('unit_id');

        foreach ($byUnit as $unitId => $group) {
            $amount = round((float) $group->sum('gross') - (float) $group->sum('absence_deduction'), 2);

            if ($amount <= 0) {
                continue;
            }

            $costCenter = $unitId
                ? CostCenter::firstWhere('unit_id', $unitId)?->id
                : CostCenter::general()->id;

            $lines[] = [
                'account' => Ledger::SALARIES_EXPENSE,
                'debit' => $amount,
                'cost_center_id' => $costCenter,
                'description' => 'رواتب '.$payroll->periodLabel(),
            ];
        }

        $totalExpense = round(array_sum(array_column($lines, 'debit')), 2);
        $advances = round((float) $payroll->lines->sum('advance_deduction'), 2);

        // الصافي مستحق للموظفين، والسلف المستقطعة تُقفل ذمة الموظف.
        $lines[] = ['account' => Ledger::SALARIES_PAYABLE, 'credit' => round($totalExpense - $advances, 2)];

        if ($advances > 0) {
            $lines[] = ['account' => Ledger::RECEIVABLES, 'credit' => $advances, 'description' => 'استقطاع سلف'];
        }

        return $this->ledger->post(
            now()->toDateString(),
            'مسيّر رواتب '.$payroll->periodLabel(),
            $lines,
            'payroll',
            $payroll,
            $userId,
        );
    }
}
