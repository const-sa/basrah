<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Employee extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'employee_no',
        'name',
        'national_id',
        'nationality',
        'phone',
        'email',
        'position',
        'hired_on',
        'birth_date',
        'group_id',
        'unit_id',
        'department_id',
        'basic_salary',
        'housing_allowance',
        'transport_allowance',
        'other_allowance',
        'iqama_expiry',
        'passport_expiry',
        'contract_expiry',
        'bank_iban',
        'notes',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'hired_on' => 'date',
            'birth_date' => 'date',
            'iqama_expiry' => 'date',
            'passport_expiry' => 'date',
            'contract_expiry' => 'date',
            'basic_salary' => 'decimal:2',
            'housing_allowance' => 'decimal:2',
            'transport_allowance' => 'decimal:2',
            'other_allowance' => 'decimal:2',
        ];
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(EmployeeGroup::class, 'group_id');
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    /**
     * حساب الدخول المرتبط بهذا الموظف، إن وُجد.
     */
    public function user(): HasOne
    {
        return $this->hasOne(User::class);
    }

    public function attendances(): HasMany
    {
        return $this->hasMany(Attendance::class);
    }

    public function leaves(): HasMany
    {
        return $this->hasMany(Leave::class);
    }

    public function advances(): HasMany
    {
        return $this->hasMany(Advance::class);
    }

    public function bonuses(): HasMany
    {
        return $this->hasMany(Bonus::class);
    }

    public function payrollLines(): HasMany
    {
        return $this->hasMany(PayrollLine::class);
    }

    /**
     * مجموع البدلات الشهرية.
     */
    public function totalAllowances(): float
    {
        return round(
            (float) $this->housing_allowance
            + (float) $this->transport_allowance
            + (float) $this->other_allowance,
            2,
        );
    }

    /**
     * الراتب الإجمالي قبل أي خصم أو إضافي.
     */
    public function grossSalary(): float
    {
        return round((float) $this->basic_salary + $this->totalAllowances(), 2);
    }

    /**
     * أجر اليوم الواحد — أيام الشهر من افتراضات التشغيل.
     */
    public function dailyRate(): float
    {
        return round($this->grossSalary() / (int) config('operations.hr.month_days', 30), 2);
    }

    /**
     * أجر ساعة الإضافي = (الأساسي ÷ أيام الشهر ÷ ساعات اليوم) × المعامل.
     */
    public function overtimeHourlyRate(): float
    {
        $days = (int) config('operations.hr.month_days', 30);
        $hours = (int) config('operations.hr.daily_hours', 8);
        $multiplier = (float) config('operations.hr.overtime_multiplier', 1.5);

        return round((float) $this->basic_salary / $days / $hours * $multiplier, 2);
    }

    /**
     * الوثائق المنتهية أو التي توشك خلال المهلة المحددة.
     *
     * @return list<array{type: string, label: string, date: string, days_left: int}>
     */
    public function expiringDocuments(int $withinDays = 60): array
    {
        $docs = [
            'iqama_expiry' => 'الإقامة',
            'passport_expiry' => 'الجواز',
            'contract_expiry' => 'العقد',
        ];

        $alerts = [];

        foreach ($docs as $field => $label) {
            if (! $this->{$field}) {
                continue;
            }

            $daysLeft = (int) now()->startOfDay()->diffInDays($this->{$field}, false);

            if ($daysLeft <= $withinDays) {
                $alerts[] = [
                    'type' => $field,
                    'label' => $label,
                    'date' => $this->{$field}->toDateString(),
                    'days_left' => $daysLeft,
                ];
            }
        }

        return $alerts;
    }

    /**
     * الموظفون الذين توشك وثائقهم على الانتهاء.
     */
    public function scopeWithExpiringDocuments(Builder $query, int $withinDays = 60): Builder
    {
        $limit = now()->addDays($withinDays)->toDateString();

        return $query->where('is_active', true)->where(
            fn ($q) => $q->whereDate('iqama_expiry', '<=', $limit)
                ->orWhereDate('passport_expiry', '<=', $limit)
                ->orWhereDate('contract_expiry', '<=', $limit),
        );
    }
}
