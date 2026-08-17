<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Advance;
use App\Models\Attendance;
use App\Models\Bonus;
use App\Models\Department;
use App\Models\Employee;
use App\Models\EmployeeGroup;
use App\Models\Leave;
use App\Models\Payroll;
use App\Models\Unit;
use App\Services\PayrollService;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

/**
 * الموارد البشرية: الملفات، الحضور، الإجازات، السلف، الرواتب.
 */
class HrController extends Controller
{
    public function __construct(private readonly PayrollService $payroll) {}

    // ── ملفات الموظفين ───────────────────────────────────────

    public function staff(Request $request): Response
    {
        $query = Employee::with(['unit:id,name', 'department:id,name', 'group:id,name'])
            ->when($request->integer('unit_id'), fn ($q, $id) => $q->where('unit_id', $id))
            ->when($request->boolean('expiring'), fn ($q) => $q->withExpiringDocuments(60))
            ->when($request->string('search')->toString(), fn ($q, $t) => $q->where(
                fn ($s) => $s->where('name', 'like', "%{$t}%")
                    ->orWhere('employee_no', 'like', "%{$t}%")
                    ->orWhere('phone', 'like', "%{$t}%"),
            ));

        return Inertia::render('admin/hr/Staff', [
            'employees' => (clone $query)->orderBy('name')->paginate(25)->withQueryString()
                ->through(fn (Employee $e) => [
                    'id' => $e->id,
                    'employee_no' => $e->employee_no,
                    'name' => $e->name,
                    'national_id' => $e->national_id,
                    'nationality' => $e->nationality,
                    'phone' => $e->phone,
                    'email' => $e->email,
                    'position' => $e->position,
                    'unit_id' => $e->unit_id,
                    'unit_name' => $e->unit?->name,
                    'department_id' => $e->department_id,
                    'group_id' => $e->group_id,
                    'hired_on' => $e->hired_on?->toDateString(),
                    'basic_salary' => (float) $e->basic_salary,
                    'housing_allowance' => (float) $e->housing_allowance,
                    'transport_allowance' => (float) $e->transport_allowance,
                    'other_allowance' => (float) $e->other_allowance,
                    'gross_salary' => $e->grossSalary(),
                    'iqama_expiry' => $e->iqama_expiry?->toDateString(),
                    'passport_expiry' => $e->passport_expiry?->toDateString(),
                    'contract_expiry' => $e->contract_expiry?->toDateString(),
                    'bank_iban' => $e->bank_iban,
                    'is_active' => $e->is_active,
                    'alerts' => $e->expiringDocuments(60),
                ]),
            'filters' => $request->only(['unit_id', 'search', 'expiring']),
            'units' => Unit::orderBy('name')->get(['id', 'name']),
            'departments' => Department::orderBy('name')->get(['id', 'name']),
            'groups' => EmployeeGroup::orderBy('name')->get(['id', 'name']),
            'stats' => [
                'total' => Employee::count(),
                'active' => Employee::where('is_active', true)->count(),
                'expiring' => Employee::withExpiringDocuments(60)->count(),
                'payroll_cost' => round((float) Employee::where('is_active', true)
                    ->selectRaw('SUM(basic_salary + housing_allowance + transport_allowance + other_allowance) AS t')
                    ->value('t'), 2),
            ],
        ]);
    }

    public function storeStaff(Request $request): RedirectResponse
    {
        Employee::create([...$this->staffRules($request), 'is_active' => true]);

        return back()->with('success', 'تم إضافة الموظف');
    }

    public function updateStaff(Request $request, Employee $employee): RedirectResponse
    {
        $employee->update($this->staffRules($request, $employee));

        return back()->with('success', 'تم تحديث بيانات الموظف');
    }

    public function destroyStaff(Employee $employee): RedirectResponse
    {
        if ($employee->payrollLines()->exists()) {
            return back()->with('warning', 'لا يُحذف موظف له سجل رواتب — عطّله بدل حذفه.');
        }

        $employee->delete();

        return back()->with('success', 'تم حذف الموظف');
    }

    /**
     * @return array<string, mixed>
     */
    private function staffRules(Request $request, ?Employee $employee = null): array
    {
        return $request->validate([
            'employee_no' => ['nullable', 'string', 'max:50', Rule::unique('employees', 'employee_no')->ignore($employee?->id)],
            'name' => ['required', 'string', 'max:255'],
            'national_id' => ['nullable', 'string', 'max:50'],
            'nationality' => ['nullable', 'string', 'max:100'],
            'phone' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:255'],
            'position' => ['nullable', 'string', 'max:150'],
            'unit_id' => ['nullable', 'exists:units,id'],
            'department_id' => ['nullable', 'exists:departments,id'],
            'group_id' => ['nullable', 'exists:employee_groups,id'],
            'hired_on' => ['nullable', 'date'],
            'basic_salary' => ['required', 'numeric', 'min:0'],
            'housing_allowance' => ['nullable', 'numeric', 'min:0'],
            'transport_allowance' => ['nullable', 'numeric', 'min:0'],
            'other_allowance' => ['nullable', 'numeric', 'min:0'],
            'iqama_expiry' => ['nullable', 'date'],
            'passport_expiry' => ['nullable', 'date'],
            'contract_expiry' => ['nullable', 'date'],
            'bank_iban' => ['nullable', 'string', 'max:50'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'is_active' => ['boolean'],
        ]);
    }

    // ── الحضور ───────────────────────────────────────────────

    public function attendance(Request $request): Response
    {
        $date = $request->string('date')->toString() ?: now()->toDateString();

        $employees = Employee::where('is_active', true)->orderBy('name')->get(['id', 'name', 'employee_no']);
        $records = Attendance::whereDate('attendance_date', $date)->get()->keyBy('employee_id');

        return Inertia::render('admin/hr/Attendance', [
            'date' => $date,
            'rows' => $employees->map(fn (Employee $e) => [
                'employee_id' => $e->id,
                'employee_no' => $e->employee_no,
                'name' => $e->name,
                'status' => $records[$e->id]->status ?? 'present',
                'check_in' => $records[$e->id]->check_in ?? null,
                'check_out' => $records[$e->id]->check_out ?? null,
                'overtime_hours' => (float) ($records[$e->id]->overtime_hours ?? 0),
                'notes' => $records[$e->id]->notes ?? null,
            ]),
            'statuses' => collect(Attendance::STATUSES)->map(fn ($l, $k) => ['key' => $k, 'label' => $l])->values(),
        ]);
    }

    public function saveAttendance(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'date' => ['required', 'date'],
            'rows' => ['required', 'array'],
            'rows.*.employee_id' => ['required', 'exists:employees,id'],
            'rows.*.status' => ['required', Rule::in(array_keys(Attendance::STATUSES))],
            'rows.*.check_in' => ['nullable', 'date_format:H:i'],
            'rows.*.check_out' => ['nullable', 'date_format:H:i'],
            'rows.*.overtime_hours' => ['nullable', 'numeric', 'min:0', 'max:24'],
            'rows.*.notes' => ['nullable', 'string', 'max:255'],
        ]);

        foreach ($data['rows'] as $row) {
            Attendance::updateOrCreate(
                ['employee_id' => $row['employee_id'], 'attendance_date' => $data['date']],
                [
                    'status' => $row['status'],
                    'check_in' => $row['check_in'] ?? null,
                    'check_out' => $row['check_out'] ?? null,
                    'overtime_hours' => $row['overtime_hours'] ?? 0,
                    'notes' => $row['notes'] ?? null,
                ],
            );
        }

        return back()->with('success', 'تم حفظ الحضور');
    }

    // ── الإجازات والسلف ──────────────────────────────────────

    public function leaves(Request $request): Response
    {
        return Inertia::render('admin/hr/Leaves', [
            'leaves' => Leave::with('employee:id,name')
                ->when($request->string('status')->toString(), fn ($q, $s) => $q->where('status', $s))
                ->latest('id')->paginate(20)->withQueryString()
                ->through(fn (Leave $l) => [
                    'id' => $l->id,
                    'employee_name' => $l->employee?->name,
                    'employee_id' => $l->employee_id,
                    'type' => $l->type,
                    'type_label' => $l->typeLabel(),
                    'starts_on' => $l->starts_on->toDateString(),
                    'ends_on' => $l->ends_on->toDateString(),
                    'days' => $l->days,
                    'status' => $l->status,
                    'status_label' => $l->statusLabel(),
                    'reason' => $l->reason,
                ]),
            'advances' => Advance::with('employee:id,name')
                ->latest('id')->limit(50)->get()
                ->map(fn (Advance $a) => [
                    'id' => $a->id,
                    'employee_name' => $a->employee?->name,
                    'employee_id' => $a->employee_id,
                    'amount' => (float) $a->amount,
                    'deducted_amount' => (float) $a->deducted_amount,
                    'remaining' => $a->remainingAmount(),
                    'installments' => $a->installments,
                    'installment_amount' => (float) $a->installment_amount,
                    'granted_on' => $a->granted_on->toDateString(),
                    'status' => $a->status,
                    'status_label' => $a->statusLabel(),
                ]),
            'bonuses' => Bonus::with(['employee:id,name', 'payroll:id,number'])
                ->latest('id')->limit(50)->get()
                ->map(fn (Bonus $b) => [
                    'id' => $b->id,
                    'employee_name' => $b->employee?->name,
                    'employee_id' => $b->employee_id,
                    'amount' => (float) $b->amount,
                    'reason' => $b->reason,
                    'granted_on' => $b->granted_on->toDateString(),
                    'status' => $b->status,
                    'status_label' => $b->statusLabel(),
                    'payroll_number' => $b->payroll?->number,
                ]),
            'filters' => $request->only(['status']),
            'employees' => Employee::where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'leaveTypes' => collect(Leave::TYPES)->map(fn ($l, $k) => ['key' => $k, 'label' => $l])->values(),
        ]);
    }

    public function storeLeave(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'employee_id' => ['required', 'exists:employees,id'],
            'type' => ['required', Rule::in(array_keys(Leave::TYPES))],
            'starts_on' => ['required', 'date'],
            'ends_on' => ['required', 'date', 'after_or_equal:starts_on'],
            'reason' => ['nullable', 'string', 'max:1000'],
        ]);

        $days = CarbonImmutable::parse($data['starts_on'])
            ->diffInDays(CarbonImmutable::parse($data['ends_on'])) + 1;

        Leave::create([...$data, 'days' => $days, 'status' => 'pending']);

        return back()->with('success', 'تم تسجيل طلب الإجازة');
    }

    public function decideLeave(Request $request, Leave $leave): RedirectResponse
    {
        $data = $request->validate(['status' => ['required', Rule::in(['approved', 'rejected'])]]);

        $leave->update([
            'status' => $data['status'],
            'approved_by' => $request->user()?->id,
            'approved_at' => now(),
        ]);

        return back()->with('success', $data['status'] === 'approved' ? 'تم اعتماد الإجازة' : 'تم رفض الإجازة');
    }

    public function storeAdvance(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'employee_id' => ['required', 'exists:employees,id'],
            'amount' => ['required', 'numeric', 'min:1'],
            'installments' => ['required', 'integer', 'min:1', 'max:36'],
            'granted_on' => ['required', 'date'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        Advance::create([
            ...$data,
            'installment_amount' => round($data['amount'] / $data['installments'], 2),
            'status' => 'pending',
        ]);

        return back()->with('success', 'تم تسجيل السلفة');
    }

    public function approveAdvance(Request $request, Advance $advance): RedirectResponse
    {
        $advance->update(['status' => 'approved', 'approved_by' => $request->user()?->id]);

        return back()->with('success', 'تم اعتماد السلفة — ستُستقطع من الرواتب القادمة');
    }

    // ── المكافآت ─────────────────────────────────────────────

    public function storeBonus(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'employee_id' => ['required', 'exists:employees,id'],
            'amount' => ['required', 'numeric', 'min:1'],
            'reason' => ['nullable', 'string', 'max:255'],
            'granted_on' => ['required', 'date'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        Bonus::create([...$data, 'status' => 'pending']);

        return back()->with('success', 'تم تسجيل المكافأة');
    }

    public function approveBonus(Request $request, Bonus $bonus): RedirectResponse
    {
        // المصروفة حُمّلت على مسيّر معتمد، فاعتمادها ثانيةً يعني صرفها مرتين.
        if ($bonus->status !== 'pending') {
            return back()->with('warning', 'لا تُعتمد إلا مكافأة قيد الاعتماد.');
        }

        $bonus->update(['status' => 'approved', 'approved_by' => $request->user()?->id]);

        return back()->with('success', 'تم اعتماد المكافأة — ستُضاف إلى مسيّر شهرها');
    }

    public function destroyBonus(Bonus $bonus): RedirectResponse
    {
        // المكافأة المصروفة جزءٌ من مسيّر معتمد وقيدٍ مرحَّل، فحذفها يخالف
        // ما دُفع فعلًا. تُلغى قبل الصرف لا بعده.
        if ($bonus->status === 'paid') {
            return back()->with('warning', 'لا تُحذف مكافأة صُرفت ضمن مسيّر معتمد.');
        }

        $bonus->delete();

        return back()->with('success', 'تم حذف المكافأة');
    }

    // ── الرواتب ──────────────────────────────────────────────

    public function payrolls(Request $request): Response
    {
        $payrolls = Payroll::with('lines.employee:id,name')
            ->orderByDesc('year')->orderByDesc('month')
            ->paginate(12)
            ->through(fn (Payroll $p) => [
                'id' => $p->id,
                'number' => $p->number,
                'year' => $p->year,
                'month' => $p->month,
                'period_label' => $p->periodLabel(),
                'status' => $p->status,
                'status_label' => $p->statusLabel(),
                'total_gross' => (float) $p->total_gross,
                'total_deductions' => (float) $p->total_deductions,
                'total_net' => (float) $p->total_net,
                'lines_count' => $p->lines->count(),
                'lines' => $p->lines->map(fn ($l) => [
                    'employee_name' => $l->employee?->name,
                    'basic_salary' => (float) $l->basic_salary,
                    'allowances' => (float) $l->allowances,
                    'overtime_amount' => (float) $l->overtime_amount,
                    'bonus' => (float) $l->bonus,
                    'absence_deduction' => (float) $l->absence_deduction,
                    'advance_deduction' => (float) $l->advance_deduction,
                    'worked_days' => $l->worked_days,
                    'absent_days' => $l->absent_days,
                    'gross' => (float) $l->gross,
                    'net' => (float) $l->net,
                ])->values(),
            ]);

        return Inertia::render('admin/hr/Payroll', [
            'payrolls' => $payrolls,
            'months' => collect(Payroll::MONTHS)->map(fn ($l, $k) => ['key' => $k, 'label' => $l])->values(),
            'currentYear' => (int) now()->year,
            'currentMonth' => (int) now()->month,
        ]);
    }

    public function generatePayroll(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'year' => ['required', 'integer', 'min:2020', 'max:2100'],
            'month' => ['required', 'integer', 'min:1', 'max:12'],
        ]);

        try {
            $payroll = $this->payroll->generate($data['year'], $data['month'], $request->user()?->id);
        } catch (RuntimeException $e) {
            return back()->with('warning', $e->getMessage());
        }

        return back()->with('success', "تم توليد مسيّر {$payroll->periodLabel()}");
    }

    public function approvePayroll(Request $request, Payroll $payroll): RedirectResponse
    {
        try {
            $this->payroll->approve($payroll, $request->user()?->id);
        } catch (RuntimeException $e) {
            return back()->with('warning', $e->getMessage());
        }

        return back()->with('success', 'تم اعتماد المسيّر وترحيل قيده المحاسبي');
    }
}
