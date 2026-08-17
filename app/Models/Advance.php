<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * سلفة على الموظف تُستقطع من رواتبه على أقساط.
 */
class Advance extends Model
{
    public const STATUSES = [
        'pending' => 'قيد الاعتماد',
        'approved' => 'معتمدة',
        'settled' => 'مسدَّدة',
        'cancelled' => 'ملغاة',
    ];

    protected $fillable = [
        'employee_id', 'amount', 'deducted_amount', 'installments',
        'installment_amount', 'granted_on', 'status', 'notes', 'approved_by',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'deducted_amount' => 'decimal:2',
            'installment_amount' => 'decimal:2',
            'granted_on' => 'date',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function remainingAmount(): float
    {
        return round((float) $this->amount - (float) $this->deducted_amount, 2);
    }

    /**
     * قسط هذا الشهر — لا يتجاوز المتبقي حتى لا يُستقطع أكثر من السلفة.
     */
    public function dueInstallment(): float
    {
        if ($this->status !== 'approved') {
            return 0.0;
        }

        return round(min((float) $this->installment_amount, $this->remainingAmount()), 2);
    }

    /**
     * تسجيل استقطاع، وإقفال السلفة تلقائيًا عند اكتمالها.
     */
    public function recordDeduction(float $amount): void
    {
        $deducted = round((float) $this->deducted_amount + $amount, 2);

        $this->update([
            'deducted_amount' => $deducted,
            'status' => $deducted >= (float) $this->amount ? 'settled' : $this->status,
        ]);
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? $this->status;
    }
}
