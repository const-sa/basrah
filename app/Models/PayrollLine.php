<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * سطر موظف في مسيّر الرواتب — قيمه مُجمَّدة وقت التوليد.
 */
class PayrollLine extends Model
{
    protected $fillable = [
        'payroll_id', 'employee_id', 'unit_id',
        'basic_salary', 'allowances', 'overtime_amount', 'bonus',
        'absence_deduction', 'advance_deduction', 'other_deduction',
        'gross', 'net', 'worked_days', 'absent_days', 'overtime_hours', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'basic_salary' => 'decimal:2',
            'allowances' => 'decimal:2',
            'overtime_amount' => 'decimal:2',
            'bonus' => 'decimal:2',
            'absence_deduction' => 'decimal:2',
            'advance_deduction' => 'decimal:2',
            'other_deduction' => 'decimal:2',
            'gross' => 'decimal:2',
            'net' => 'decimal:2',
            'overtime_hours' => 'decimal:2',
        ];
    }

    public function payroll(): BelongsTo
    {
        return $this->belongsTo(Payroll::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    public function totalDeductions(): float
    {
        return round(
            (float) $this->absence_deduction
            + (float) $this->advance_deduction
            + (float) $this->other_deduction,
            2,
        );
    }
}
