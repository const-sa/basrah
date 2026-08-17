<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * سند قبض أو صرف أو مصروف.
 */
class Voucher extends Model
{
    use SoftDeletes;

    public const TYPES = [
        'receipt' => 'سند قبض',
        'payment' => 'سند صرف',
        'expense' => 'مصروف',
    ];

    public const METHODS = [
        'cash' => 'نقدًا',
        'transfer' => 'تحويل',
        'card' => 'شبكة',
    ];

    public const STATUSES = [
        'draft' => 'مسوّدة',
        'posted' => 'مرحَّل',
        'cancelled' => 'ملغي',
    ];

    protected $fillable = [
        'number', 'type', 'voucher_date', 'amount', 'treasury_id', 'account_id',
        'cost_center_id', 'client_id', 'supplier_id', 'sale_id', 'method', 'reference',
        'description', 'status', 'journal_entry_id', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'voucher_date' => 'date',
            'amount' => 'decimal:2',
        ];
    }

    public function treasury(): BelongsTo
    {
        return $this->belongsTo(Treasury::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function costCenter(): BelongsTo
    {
        return $this->belongsTo(CostCenter::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function entry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'journal_entry_id');
    }

    /** الفاتورة التي يسدّدها السند — فارغة في السندات العامة. */
    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    /** هل يزيد هذا السند المسدَّد من فاتورة؟ */
    public function settlesSale(): bool
    {
        return $this->sale_id !== null && $this->type === 'receipt';
    }

    public function isPosted(): bool
    {
        return $this->status === 'posted';
    }

    /** هل يزيد السند رصيد الخزينة؟ */
    public function increasesTreasury(): bool
    {
        return $this->type === 'receipt';
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
