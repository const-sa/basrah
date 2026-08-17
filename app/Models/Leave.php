<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Leave extends Model
{
    public const TYPES = [
        'annual' => 'سنوية',
        'sick' => 'مرضية',
        'unpaid' => 'بدون راتب',
        'emergency' => 'اضطرارية',
    ];

    public const STATUSES = [
        'pending' => 'قيد الاعتماد',
        'approved' => 'معتمدة',
        'rejected' => 'مرفوضة',
    ];

    protected $fillable = [
        'employee_id', 'type', 'starts_on', 'ends_on', 'days',
        'status', 'reason', 'approved_by', 'approved_at',
    ];

    protected function casts(): array
    {
        return [
            'starts_on' => 'date',
            'ends_on' => 'date',
            'approved_at' => 'datetime',
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

    /** الإجازة بدون راتب وحدها تُخصم من المسيّر. */
    public function isUnpaid(): bool
    {
        return $this->type === 'unpaid';
    }

    public function typeLabel(): string
    {
        return self::TYPES[$this->type] ?? $this->type;
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? $this->status;
    }
}
