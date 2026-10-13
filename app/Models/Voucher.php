<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
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

    public const STATUSES = [
        'draft' => 'مسوّدة',
        'posted' => 'مرحَّل',
        'cancelled' => 'ملغي',
    ];

    protected $fillable = [
        'number', 'type', 'voucher_date', 'amount', 'treasury_id', 'account_id',
        'cost_center_id', 'client_id', 'supplier_id', 'sale_id', 'contract_id', 'booking_payment_id', 'payment_method_id', 'reference',
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

    /**
     * The booking payment this receipt was written for.
     *
     * A hall or chalet booking keeps its own payment ledger, and every row on
     * it is mirrored here so the till and the receipt book cover the whole
     * business. The link is what keeps the two from being counted twice.
     */
    public function bookingPayment(): BelongsTo
    {
        return $this->belongsTo(BookingPayment::class);
    }

    /** Was this receipt written off a booking's payment ledger? */
    public function mirrorsBookingPayment(): bool
    {
        return $this->booking_payment_id !== null;
    }

    public function paymentMethod(): BelongsTo
    {
        return $this->belongsTo(PaymentMethod::class);
    }

    /** Supporting documents — transfer slips, supplier invoices. */
    public function attachments(): HasMany
    {
        return $this->hasMany(VoucherAttachment::class)->oldest('id');
    }

    public function methodLabel(): string
    {
        return $this->paymentMethod?->name ?? '—';
    }

    /** العقد الذي يسدّده السند — فارغ في السندات العامة. */
    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }

    /** هل يزيد هذا السند المسدَّد من فاتورة؟ */
    public function settlesSale(): bool
    {
        return $this->sale_id !== null && $this->type === 'receipt';
    }

    /**
     * هل يزيد هذا السند المقبوض على عقد؟
     *
     * The deposit taken when a pools contract is drawn, and every payment
     * after it — what moves «المدفوع والمتبقي» on the sheet.
     */
    public function settlesContract(): bool
    {
        return $this->contract_id !== null && $this->type === 'receipt';
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
