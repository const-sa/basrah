<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * مكافأة على موظف تُضاف إلى راتب الشهر الذي مُنحت فيه.
 *
 * المكافأة عكس السلفة: تُضاف إلى الإجمالي ولا تُستقطع، وتُصرف دفعة واحدة
 * لا على أقساط. ولذلك لا حاجة هنا لعمود «المستقطع» ولا لحساب قسط.
 */
class Bonus extends Model
{
    use SoftDeletes;

    public const STATUSES = [
        'pending' => 'قيد الاعتماد',
        'approved' => 'معتمدة',
        'paid' => 'مصروفة',
        'cancelled' => 'ملغاة',
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
     * المكافآت المستحقة الصرف: معتمدة ولم يحملها مسيّر بعد.
     *
     * المصروفة تُستثنى بحالتها، والمرتبطة بمسيّر تُستثنى بارتباطها — والثاني
     * يحمي من ازدواج الصرف لو بقيت الحالة معتمدة لسببٍ ما.
     */
    public function scopePayable(Builder $query): Builder
    {
        return $query->where('status', 'approved')->whereNull('payroll_id');
    }

    /**
     * المكافآت الواقعة في شهر معيّن — بتاريخ منحها.
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
