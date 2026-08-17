<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * قيد يومية.
 */
class JournalEntry extends Model
{
    public const STATUSES = [
        'draft' => 'مسوّدة',
        'posted' => 'مرحَّل',
        'reversed' => 'معكوس',
    ];

    /**
     * الحالات التي تؤثر في الأرصدة.
     *
     * القيد المعكوس يبقى محتسبًا: العكس يُلغيه بقيد مضاد لا بحذفه من
     * الأرصدة. استبعاده هنا يجعل القيد المضاد يُحتسب وحده فيقلب الرصيد
     * بدل أن يصفّره.
     */
    public const EFFECTIVE_STATUSES = ['posted', 'reversed'];

    public const SOURCES = [
        'manual' => 'يدوي',
        'booking' => 'حجز',
        'payment' => 'دفعة حجز',
        'sale' => 'مبيعات',
        'expense' => 'مصروف',
        'voucher' => 'سند',
        'payroll' => 'رواتب',
    ];

    protected $fillable = [
        'number', 'entry_date', 'description', 'status', 'source',
        'total_debit', 'total_credit', 'reference_type', 'reference_id',
        'created_by', 'posted_by', 'posted_at', 'reversed_by_entry_id',
    ];

    protected function casts(): array
    {
        return [
            'entry_date' => 'date',
            'posted_at' => 'datetime',
            'total_debit' => 'decimal:2',
            'total_credit' => 'decimal:2',
        ];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(JournalLine::class);
    }

    public function reference(): MorphTo
    {
        return $this->morphTo();
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isPosted(): bool
    {
        return $this->status === 'posted';
    }

    /**
     * القيد متوازن إذا تساوى مجموع المدين والدائن.
     * لا يُرحَّل قيد غير متوازن مهما كان مصدره.
     */
    public function isBalanced(): bool
    {
        return abs((float) $this->total_debit - (float) $this->total_credit) < 0.01;
    }

    /**
     * القيد التلقائي لا يُحرَّر يدويًا — يُصحَّح من مصدره.
     */
    public function isSystemGenerated(): bool
    {
        return $this->source !== 'manual';
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? $this->status;
    }

    public function sourceLabel(): string
    {
        return self::SOURCES[$this->source] ?? $this->source;
    }

    public function scopePosted(Builder $query): Builder
    {
        return $query->where('status', 'posted');
    }
}
