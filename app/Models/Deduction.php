<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * خصم/استقطاع على موظف يُطرح من راتب الشهر الذي وقع فيه.
 *
 * الخصم عكس المكافأة: يُطرح من الإجمالي لا يُضاف إليه، ويُصرف دفعة واحدة
 * لا على أقساط كالسلفة — فبنيته مطابقة لبنية المكافأة تمامًا.
 */
class Deduction extends Model
{
    use SoftDeletes;

    public const STATUSES = [
        'pending' => 'قيد الاعتماد',
        'approved' => 'معتمد',
        'paid' => 'مُستقطع',
        'cancelled' => 'ملغى',
    ];

    protected $fillable = [
        'employee_id', 'amount', 'reason', 'deducted_on',
        'status', 'payroll_id', 'approved_by', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'deducted_on' => 'date',
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
     * الخصومات المستحقة الاستقطاع: معتمدة ولم يحملها مسيّر بعد.
     */
    public function scopePayable(Builder $query): Builder
    {
        return $query->where('status', 'approved')->whereNull('payroll_id');
    }

    /**
     * الخصومات الواقعة في شهر معيّن — بتاريخ استحقاقها.
     */
    public function scopeAppliesIn(Builder $query, string $start, string $end): Builder
    {
        return $query->whereBetween('deducted_on', [$start, $end]);
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? $this->status;
    }
}
