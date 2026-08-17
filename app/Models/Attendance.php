<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Attendance extends Model
{
    public const STATUSES = [
        'present' => 'حاضر',
        'absent' => 'غائب',
        'late' => 'متأخر',
        'leave' => 'إجازة',
        'holiday' => 'عطلة',
    ];

    /** الحالات التي لا تُحتسب غيابًا في الراتب. */
    public const PAID_STATUSES = ['present', 'late', 'leave', 'holiday'];

    protected $fillable = [
        'employee_id', 'attendance_date', 'check_in', 'check_out',
        'status', 'worked_hours', 'overtime_hours', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'attendance_date' => 'date',
            'worked_hours' => 'decimal:2',
            'overtime_hours' => 'decimal:2',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function isPaid(): bool
    {
        return in_array($this->status, self::PAID_STATUSES, true);
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? $this->status;
    }
}
