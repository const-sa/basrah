<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * مصروف واحد — مستندٌ تشغيلي يولّد قيده، كالفاتورة والمسيّر.
 *
 * الحالة ثلاث: مسوّدة (نيّة لم تُقيَّد)، مرحَّل (له قيدٌ في الدفاتر)، ملغى
 * (رُدَّ بقيدٍ مضاد). والمرحَّل وحده مصروفٌ فعلي في كل حساب أو تقرير.
 */
class Expense extends Model
{
    use SoftDeletes;

    public const STATUSES = [
        'draft' => 'مسوّدة',
        'posted' => 'مرحَّل',
        'cancelled' => 'ملغى',
    ];

    /** بادئة رقم المصروف — EXP-2026-1. */
    public const PREFIX = 'EXP-';

    protected $fillable = [
        'number', 'expense_date', 'expense_category_id', 'amount',
        'cost_center_id', 'treasury_id', 'payment_method_id', 'supplier_id',
        'reference', 'description', 'status', 'journal_entry_id',
        'created_by', 'posted_by', 'posted_at', 'cancelled_at', 'cancellation_reason',
    ];

    protected function casts(): array
    {
        return [
            'expense_date' => 'date',
            'amount' => 'decimal:2',
            'posted_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ExpenseCategory::class, 'expense_category_id');
    }

    public function costCenter(): BelongsTo
    {
        return $this->belongsTo(CostCenter::class);
    }

    public function treasury(): BelongsTo
    {
        return $this->belongsTo(Treasury::class);
    }

    public function paymentMethod(): BelongsTo
    {
        return $this->belongsTo(PaymentMethod::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function entry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'journal_entry_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isPosted(): bool
    {
        return $this->status === 'posted';
    }

    public function isDraft(): bool
    {
        return $this->status === 'draft';
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? $this->status;
    }

    /**
     * المرحَّل وحده يُحتسب — المسوّدة نيّة والملغى رُدَّ.
     */
    public function scopePosted(Builder $query): Builder
    {
        return $query->where('status', 'posted');
    }

    public function scopeBetween(Builder $query, ?string $from, ?string $to): Builder
    {
        return $query
            ->when($from, fn (Builder $q) => $q->whereDate('expense_date', '>=', $from))
            ->when($to, fn (Builder $q) => $q->whereDate('expense_date', '<=', $to));
    }
}
