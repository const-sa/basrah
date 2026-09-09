<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * بدل ظرفي على موظف يُضاف إلى راتب الشهر الذي مُنح فيه.
 *
 * مستقلٌّ عن بدلات الموظف الثابتة (سكن/نقل/أخرى) التي تدخل كل شهر — هذا
 * بدلٌ لمناسبة بعينها، فبنيته مطابقة لبنية المكافأة تمامًا: يُضاف إلى
 * الإجمالي، يُصرف دفعة واحدة، ويحتاج اعتمادًا قبل أن يدخل مسيّرًا.
 */
class Allowance extends Model
{
    use SoftDeletes;

    public const STATUSES = [
        'pending' => 'قيد الاعتماد',
        'approved' => 'معتمد',
        'paid' => 'مصروف',
        'cancelled' => 'ملغى',
    ];

    protected $fillable = [
        'employee_id', 'amount', 'reason', 'granted_on',
        'status', 'payroll_id', 'approved_by', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'granted_on' => 'date',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function payroll(): BelongsTo
    {
        return $this->belongsTo(Payroll::class);
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /**
     * البدلات المستحقة الصرف: معتمدة ولم يحملها مسيّر بعد.
     */
    public function scopePayable(Builder $query): Builder
    {
        return $query->where('status', 'approved')->whereNull('payroll_id');
    }

    /**
     * البدلات الواقعة في شهر معيّن — بتاريخ منحها.
     */
    public function scopeGrantedIn(Builder $query, string $start, string $end): Builder
    {
        return $query->whereBetween('granted_on', [$start, $end]);
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? $this->status;
    }
}
