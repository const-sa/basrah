<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * مسيّر رواتب شهري.
 */
class Payroll extends Model
{
    public const STATUSES = [
        'draft' => 'مسوّدة',
        'approved' => 'معتمد',
        'paid' => 'مدفوع',
    ];

    public const MONTHS = [
        1 => 'يناير', 2 => 'فبراير', 3 => 'مارس', 4 => 'أبريل',
        5 => 'مايو', 6 => 'يونيو', 7 => 'يوليو', 8 => 'أغسطس',
        9 => 'سبتمبر', 10 => 'أكتوبر', 11 => 'نوفمبر', 12 => 'ديسمبر',
    ];

    protected $fillable = [
        'number', 'year', 'month', 'status', 'total_gross', 'total_deductions',
        'total_net', 'created_by', 'approved_by', 'approved_at', 'journal_entry_id', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'year' => 'integer',
            'month' => 'integer',
            'total_gross' => 'decimal:2',
            'total_deductions' => 'decimal:2',
            'total_net' => 'decimal:2',
            'approved_at' => 'datetime',
        ];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(PayrollLine::class);
    }

    public function entry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'journal_entry_id');
    }

    public function isDraft(): bool
    {
        return $this->status === 'draft';
    }

    public function periodLabel(): string
    {
        return (self::MONTHS[$this->month] ?? $this->month).' '.$this->year;
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? $this->status;
    }

    /**
     * إعادة احتساب مجاميع المسيّر من سطوره.
     */
    public function recalculateTotals(): void
    {
        $lines = $this->lines()->get();

        $gross = round((float) $lines->sum('gross'), 2);
        $net = round((float) $lines->sum('net'), 2);

        $this->update([
            'total_gross' => $gross,
            'total_net' => $net,
            'total_deductions' => round($gross - $net, 2),
        ]);
    }
}
