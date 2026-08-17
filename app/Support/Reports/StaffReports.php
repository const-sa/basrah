<?php

namespace App\Support\Reports;

use App\Models\Attendance;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\Payroll;
use App\Models\PayrollLine;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * تقارير الموظفين (§12 من العرض المعتمد).
 *
 * الرواتب تُقرأ من مسيّرات الرواتب لا من ملفات الموظفين: الملف يحمل الراتب
 * المتفق عليه، والمسيّر يحمل ما صُرف فعلًا بعد الحضور والسلف والمكافآت —
 * وهو ما يُسأل عنه في التقرير.
 */
class StaffReports
{
    /**
     * @return list<ReportDefinition>
     */
    public static function all(): array
    {
        return [
            self::employees(),
            self::salaries(),
            self::deductions(),
            self::overtime(),
            self::performance(),
            self::operations(),
        ];
    }

    private static function employees(): ReportDefinition
    {
        return new ReportDefinition(
            key: 'employees',
            label: 'الموظفون',
            description: 'ملفات الموظفين بأقسامهم ووحداتهم ورواتبهم الأساسية.',
            group: 'الموظفون',
            filters: ['department', 'unit'],
            columns: [
                ['key' => 'employee_no', 'label' => 'الرقم الوظيفي'],
                ['key' => 'name', 'label' => 'الاسم'],
                ['key' => 'position', 'label' => 'الوظيفة'],
                ['key' => 'department', 'label' => 'القسم'],
                ['key' => 'unit', 'label' => 'الوحدة'],
                ['key' => 'hired_on', 'label' => 'تاريخ المباشرة', 'type' => 'date'],
                ['key' => 'basic_salary', 'label' => 'الراتب الأساسي', 'type' => 'currency'],
                ['key' => 'allowances', 'label' => 'البدلات', 'type' => 'currency'],
                ['key' => 'status', 'label' => 'الحالة', 'type' => 'badge'],
            ],
            builder: function (array $filters) {
                $employees = Employee::query()
                    ->when($filters['department_id'] ?? null, fn ($q, $id) => $q->where('department_id', $id))
                    ->when($filters['unit_id'] ?? null, fn ($q, $id) => $q->where('unit_id', $id))
                    ->with(['department:id,name', 'unit:id,name'])
                    ->orderBy('name')
                    ->get();

                $rows = $employees->map(fn (Employee $e) => [
                    'employee_no' => $e->employee_no ?? '—',
                    'name' => $e->name,
                    'position' => $e->position ?? '—',
                    'department' => $e->department?->name ?? '—',
                    'unit' => $e->unit?->name ?? '—',
                    'hired_on' => $e->hired_on?->format('Y-m-d'),
                    'basic_salary' => round((float) $e->basic_salary, 2),
                    'allowances' => round(
                        (float) $e->housing_allowance + (float) $e->transport_allowance + (float) $e->other_allowance,
                        2,
                    ),
                    'status' => $e->is_active ? 'على رأس العمل' : 'موقوف',
                ]);

                return [
                    'rows' => $rows->all(),
                    'summary' => [
                        ['label' => 'عدد الموظفين', 'value' => $rows->count(), 'type' => 'number'],
                        ['label' => 'على رأس العمل', 'value' => $employees->where('is_active', true)->count(), 'type' => 'number'],
                        ['label' => 'إجمالي الرواتب الأساسية', 'value' => round((float) $rows->sum('basic_salary'), 2), 'type' => 'currency'],
                        ['label' => 'إجمالي البدلات', 'value' => round((float) $rows->sum('allowances'), 2), 'type' => 'currency'],
                    ],
                ];
            },
        );
    }

    private static function salaries(): ReportDefinition
    {
        return new ReportDefinition(
            key: 'salaries',
            label: 'الرواتب',
            description: 'ما صُرف لكل موظف من مسيّرات الرواتب خلال المدة.',
            group: 'الموظفون',
            filters: ['range', 'unit'],
            columns: [
                ['key' => 'employee', 'label' => 'الموظف'],
                ['key' => 'months', 'label' => 'عدد الأشهر', 'type' => 'number'],
                ['key' => 'basic', 'label' => 'الأساسي', 'type' => 'currency'],
                ['key' => 'allowances', 'label' => 'البدلات', 'type' => 'currency'],
                ['key' => 'overtime', 'label' => 'الإضافي', 'type' => 'currency'],
                ['key' => 'bonus', 'label' => 'المكافآت', 'type' => 'currency'],
                ['key' => 'deductions', 'label' => 'الخصومات', 'type' => 'currency'],
                ['key' => 'gross', 'label' => 'الإجمالي', 'type' => 'currency'],
                ['key' => 'net', 'label' => 'الصافي', 'type' => 'currency'],
            ],
            builder: function (array $filters) {
                $lines = self::payrollLines($filters);

                $rows = $lines
                    ->groupBy(fn (PayrollLine $l) => $l->employee?->name ?? 'غير محدد')
                    ->map(fn (Collection $group, string $employee) => [
                        'employee' => $employee,
                        'months' => $group->count(),
                        'basic' => round((float) $group->sum('basic_salary'), 2),
                        'allowances' => round((float) $group->sum('allowances'), 2),
                        'overtime' => round((float) $group->sum('overtime_amount'), 2),
                        'bonus' => round((float) $group->sum('bonus'), 2),
                        'deductions' => round((float) $group->sum(fn (PayrollLine $l) => $l->totalDeductions()), 2),
                        'gross' => round((float) $group->sum('gross'), 2),
                        'net' => round((float) $group->sum('net'), 2),
                    ])
                    ->sortByDesc('net')
                    ->values();

                return [
                    'rows' => $rows->all(),
                    'summary' => [
                        ['label' => 'عدد الموظفين', 'value' => $rows->count(), 'type' => 'number'],
                        ['label' => 'إجمالي المستحق', 'value' => round((float) $rows->sum('gross'), 2), 'type' => 'currency'],
                        ['label' => 'إجمالي الخصومات', 'value' => round((float) $rows->sum('deductions'), 2), 'type' => 'currency'],
                        ['label' => 'صافي المصروف', 'value' => round((float) $rows->sum('net'), 2), 'type' => 'currency'],
                    ],
                ];
            },
            defaultRange: 'year',
        );
    }

    private static function deductions(): ReportDefinition
    {
        return new ReportDefinition(
            key: 'deductions',
            label: 'الخصومات',
            description: 'خصومات الغياب وأقساط السلف وغيرها، موظفًا بموظف.',
            group: 'الموظفون',
            filters: ['range', 'unit'],
            columns: [
                ['key' => 'employee', 'label' => 'الموظف'],
                ['key' => 'absent_days', 'label' => 'أيام الغياب', 'type' => 'number'],
                ['key' => 'absence', 'label' => 'خصم الغياب', 'type' => 'currency'],
                ['key' => 'advance', 'label' => 'أقساط السلف', 'type' => 'currency'],
                ['key' => 'other', 'label' => 'خصومات أخرى', 'type' => 'currency'],
                ['key' => 'total', 'label' => 'إجمالي الخصم', 'type' => 'currency'],
                ['key' => 'share', 'label' => 'من الإجمالي %', 'type' => 'number'],
            ],
            builder: function (array $filters) {
                $lines = self::payrollLines($filters);

                $rows = $lines
                    ->groupBy(fn (PayrollLine $l) => $l->employee?->name ?? 'غير محدد')
                    ->map(function (Collection $group, string $employee) {
                        $absence = round((float) $group->sum('absence_deduction'), 2);
                        $advance = round((float) $group->sum('advance_deduction'), 2);
                        $other = round((float) $group->sum('other_deduction'), 2);
                        $gross = round((float) $group->sum('gross'), 2);
                        $total = round($absence + $advance + $other, 2);

                        return [
                            'employee' => $employee,
                            'absent_days' => (int) $group->sum('absent_days'),
                            'absence' => $absence,
                            'advance' => $advance,
                            'other' => $other,
                            'total' => $total,
                            'share' => $gross > 0 ? round($total / $gross * 100, 1) : 0.0,
                        ];
                    })
                    ->filter(fn (array $r) => $r['total'] > 0)
                    ->sortByDesc('total')
                    ->values();

                return [
                    'rows' => $rows->all(),
                    'summary' => [
                        ['label' => 'خصم الغياب', 'value' => round((float) $rows->sum('absence'), 2), 'type' => 'currency'],
                        ['label' => 'أقساط السلف', 'value' => round((float) $rows->sum('advance'), 2), 'type' => 'currency'],
                        ['label' => 'خصومات أخرى', 'value' => round((float) $rows->sum('other'), 2), 'type' => 'currency'],
                        ['label' => 'الإجمالي', 'value' => round((float) $rows->sum('total'), 2), 'type' => 'currency'],
                    ],
                ];
            },
            defaultRange: 'year',
        );
    }

    private static function overtime(): ReportDefinition
    {
        return new ReportDefinition(
            key: 'overtime',
            label: 'الإضافي',
            description: 'ساعات العمل الإضافي من سجل الحضور، وما صُرف مقابلها في المسيّر.',
            group: 'الموظفون',
            filters: ['range', 'unit'],
            columns: [
                ['key' => 'employee', 'label' => 'الموظف'],
                ['key' => 'attendance_hours', 'label' => 'ساعات مسجَّلة بالحضور', 'type' => 'number'],
                ['key' => 'payroll_hours', 'label' => 'ساعات محتسَبة بالمسيّر', 'type' => 'number'],
                ['key' => 'amount', 'label' => 'المصروف', 'type' => 'currency'],
            ],
            builder: function (array $filters) {
                $attendance = Attendance::query()
                    ->when($filters['from'] ?? null, fn ($q, $d) => $q->whereDate('attendance_date', '>=', $d))
                    ->when($filters['to'] ?? null, fn ($q, $d) => $q->whereDate('attendance_date', '<=', $d))
                    ->where('overtime_hours', '>', 0)
                    ->with('employee:id,name')
                    ->get()
                    ->groupBy(fn (Attendance $a) => $a->employee?->name ?? 'غير محدد');

                $payroll = self::payrollLines($filters)
                    ->groupBy(fn (PayrollLine $l) => $l->employee?->name ?? 'غير محدد');

                $rows = $attendance->keys()->merge($payroll->keys())->unique()
                    ->map(function (string $employee) use ($attendance, $payroll) {
                        $lines = $payroll->get($employee, collect());

                        return [
                            'employee' => $employee,
                            'attendance_hours' => round((float) $attendance->get($employee, collect())->sum('overtime_hours'), 2),
                            'payroll_hours' => round((float) $lines->sum('overtime_hours'), 2),
                            'amount' => round((float) $lines->sum('overtime_amount'), 2),
                        ];
                    })
                    ->filter(fn (array $r) => $r['attendance_hours'] > 0 || $r['payroll_hours'] > 0 || $r['amount'] > 0)
                    ->sortByDesc('amount')
                    ->values();

                return [
                    'rows' => $rows->all(),
                    'summary' => [
                        ['label' => 'ساعات الحضور الإضافية', 'value' => round((float) $rows->sum('attendance_hours'), 2), 'type' => 'number'],
                        ['label' => 'ساعات المسيّر', 'value' => round((float) $rows->sum('payroll_hours'), 2), 'type' => 'number'],
                        ['label' => 'المصروف على الإضافي', 'value' => round((float) $rows->sum('amount'), 2), 'type' => 'currency'],
                    ],
                ];
            },
            defaultRange: 'year',
        );
    }

    /**
     * أداء الموظفين — ما أدخله كل موظف من حجوزات وفواتير.
     */
    private static function performance(): ReportDefinition
    {
        return new ReportDefinition(
            key: 'staff-performance',
            label: 'أداء الموظفين',
            description: 'حجوزات كل موظف وفواتيره ومتوسط قيمة ما يُدخله.',
            group: 'الموظفون',
            filters: ['range'],
            columns: [
                ['key' => 'employee', 'label' => 'الموظف'],
                ['key' => 'bookings', 'label' => 'حجوزات', 'type' => 'number'],
                ['key' => 'sales', 'label' => 'فواتير', 'type' => 'number'],
                ['key' => 'operations', 'label' => 'إجمالي العمليات', 'type' => 'number'],
                ['key' => 'total', 'label' => 'قيمة ما أدخله', 'type' => 'currency'],
                ['key' => 'average', 'label' => 'متوسط العملية', 'type' => 'currency'],
            ],
            builder: function (array $filters) {
                $result = FinanceReports::perUser($filters);

                $rows = collect($result['rows'])->map(function (array $row) {
                    $operations = (int) $row['bookings'] + (int) $row['sales'];

                    return [
                        'employee' => $row['employee'],
                        'bookings' => $row['bookings'],
                        'sales' => $row['sales'],
                        'operations' => $operations,
                        'total' => $row['total'],
                        'average' => $operations > 0 ? round($row['total'] / $operations, 2) : 0.0,
                    ];
                })->sortByDesc('total')->values();

                return [
                    'rows' => $rows->all(),
                    'summary' => [
                        ['label' => 'عدد الموظفين', 'value' => $rows->count(), 'type' => 'number'],
                        ['label' => 'إجمالي العمليات', 'value' => (int) $rows->sum('operations'), 'type' => 'number'],
                        ['label' => 'قيمة ما أُدخل', 'value' => round((float) $rows->sum('total'), 2), 'type' => 'currency'],
                    ],
                ];
            },
        );
    }

    /**
     * العمليات التي قام بها كل موظف — من السجل الرقابي.
     */
    private static function operations(): ReportDefinition
    {
        return new ReportDefinition(
            key: 'staff-operations',
            label: 'العمليات التي قام بها كل موظف',
            description: 'ما أنشأه كل مستخدم وعدّله وحذفه واسترجعه، من السجل الرقابي.',
            group: 'الموظفون',
            filters: ['range'],
            columns: [
                ['key' => 'employee', 'label' => 'المستخدم'],
                ['key' => 'created', 'label' => 'إنشاء', 'type' => 'number'],
                ['key' => 'updated', 'label' => 'تعديل', 'type' => 'number'],
                ['key' => 'deleted', 'label' => 'حذف', 'type' => 'number'],
                ['key' => 'restored', 'label' => 'استرجاع', 'type' => 'number'],
                ['key' => 'total', 'label' => 'الإجمالي', 'type' => 'number'],
                ['key' => 'last_activity', 'label' => 'آخر نشاط'],
            ],
            builder: function (array $filters) {
                $logs = AuditLog::query()
                    ->when($filters['from'] ?? null, fn ($q, $d) => $q->whereDate('created_at', '>=', $d))
                    ->when($filters['to'] ?? null, fn ($q, $d) => $q->whereDate('created_at', '<=', $d))
                    ->get(['user_id', 'actor_name', 'event', 'created_at']);

                $rows = $logs
                    ->groupBy(fn (AuditLog $log) => $log->actor_name ?? 'النظام')
                    ->map(fn (Collection $group, string $actor) => [
                        'employee' => $actor,
                        'created' => $group->where('event', 'created')->count(),
                        'updated' => $group->where('event', 'updated')->count(),
                        'deleted' => $group->where('event', 'deleted')->count(),
                        'restored' => $group->where('event', 'restored')->count(),
                        'total' => $group->count(),
                        'last_activity' => $group->max('created_at')?->format('Y-m-d H:i'),
                    ])
                    ->sortByDesc('total')
                    ->values();

                return [
                    'rows' => $rows->all(),
                    'summary' => [
                        ['label' => 'إجمالي العمليات', 'value' => $logs->count(), 'type' => 'number'],
                        ['label' => 'عمليات الحذف', 'value' => $logs->where('event', 'deleted')->count(), 'type' => 'number'],
                        ['label' => 'المستخدمون الفاعلون', 'value' => $rows->count(), 'type' => 'number'],
                    ],
                ];
            },
        );
    }

    /**
     * بنود مسيّرات الرواتب الواقعة داخل المدة.
     *
     * المسيّر يُؤرَّخ بسنته وشهره لا بتاريخ يوم، فالمقارنة تقع على ترتيب
     * الشهر (سنة×12 + شهر) لا على عمود تاريخ لا وجود له.
     *
     * @param  array<string, mixed>  $filters
     * @return Collection<int, PayrollLine>
     */
    private static function payrollLines(array $filters): Collection
    {
        $from = $filters['from'] ?? null;
        $to = $filters['to'] ?? null;

        $order = fn (int $year, int $month) => $year * 12 + $month;

        $payrollIds = Payroll::query()
            ->get(['id', 'year', 'month'])
            ->filter(function (Payroll $p) use ($from, $to, $order) {
                $value = $order((int) $p->year, (int) $p->month);

                if ($from && $value < $order((int) Carbon::parse($from)->year, (int) Carbon::parse($from)->month)) {
                    return false;
                }

                return ! ($to && $value > $order((int) Carbon::parse($to)->year, (int) Carbon::parse($to)->month));
            })
            ->pluck('id');

        return PayrollLine::query()
            ->whereIn('payroll_id', $payrollIds)
            ->when($filters['unit_id'] ?? null, fn ($q, $id) => $q->where('unit_id', $id))
            ->with('employee:id,name')
            ->get();
    }

    /**
     * المستخدمون الذين لهم أثر — يُستعملون في مرشّح تقارير الموظفين.
     *
     * @return Collection<int, User>
     */
    public static function actors(): Collection
    {
        return User::query()->orderBy('name')->get(['id', 'name']);
    }
}
