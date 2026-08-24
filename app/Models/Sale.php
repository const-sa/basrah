<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * فاتورة بيع أو مرتجع.
 */
class Sale extends Model
{
    use SoftDeletes;

    /**
     * حالة سداد الفاتورة — محسوبة لا مخزّنة، فلا تتناقض مع المبالغ.
     */
    public const PAYMENT_STATUSES = [
        'paid' => 'مسدّدة',
        'partial' => 'مسدّدة جزئيًا',
        'unpaid' => 'غير مسدّدة',
    ];

    /** هامش التقريب: فرق أقل من نصف هللة لا يُعدّ دَينًا. */
    private const EPSILON = 0.005;

    protected $fillable = [
        'number', 'user_id', 'client_id', 'unit_id', 'department_id', 'booking_id',
        'quotation_id', 'type', 'original_sale_id', 'subtotal', 'discount_amount', 'tax_amount',
        'total_amount', 'cost_amount', 'payment_method_id', 'paid_amount', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'subtotal' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'tax_amount' => 'decimal:2',
            'total_amount' => 'decimal:2',
            'cost_amount' => 'decimal:2',
            'paid_amount' => 'decimal:2',
        ];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(SaleItem::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function quotation(): BelongsTo
    {
        return $this->belongsTo(Quotation::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function originalSale(): BelongsTo
    {
        return $this->belongsTo(self::class, 'original_sale_id');
    }

    public function returns(): HasMany
    {
        return $this->hasMany(self::class, 'original_sale_id')->where('type', 'return');
    }

    /**
     * سندات القبض المسدِّدة لهذه الفاتورة.
     */
    public function vouchers(): HasMany
    {
        return $this->hasMany(Voucher::class);
    }

    public function isReturn(): bool
    {
        return $this->type === 'return';
    }

    /**
     * تعبير SQL لمجموع عمود من مرتجعات الفاتورة — يُستعمل في الفلترة
     * والتجميع حيث لا تصلح دالة PHP لأن الحساب يجري داخل قاعدة البيانات.
     *
     * العمود من اختيارنا لا من الطلب (total_amount أو tax_amount)، فلا
     * يدخل نصٌّ خارجي في الاستعلام.
     */
    public static function returnedAmountExpression(string $column = 'total_amount'): string
    {
        return "COALESCE((SELECT SUM(r.{$column}) FROM sales r"
            ." WHERE r.original_sale_id = sales.id AND r.type = 'return' AND r.deleted_at IS NULL), 0)";
    }

    /**
     * إجمالي ما أُرجع من الفاتورة.
     *
     * يُقرأ من `returned_amount` إن كان الاستعلام قد جمعه (withSum) تفاديًا
     * لاستعلام لكل صف في القوائم.
     */
    public function returnedAmount(): float
    {
        if (array_key_exists('returned_amount', $this->attributes)) {
            return round((float) $this->attributes['returned_amount'], 2);
        }

        return round((float) $this->returns()->sum('total_amount'), 2);
    }

    /**
     * صافي المستحق بعد المرتجعات — هو ما يُقاس عليه السداد.
     */
    public function netTotal(): float
    {
        return round((float) $this->total_amount - $this->returnedAmount(), 2);
    }

    /**
     * المتبقي على العميل، ولا ينزل تحت الصفر: الفاتورة النقدية المرتجَع
     * منها استُردَّ نقدها فعلًا فلا تصير «دائنة» على المنشأة.
     */
    public function remainingAmount(): float
    {
        return round(max(0, $this->netTotal() - (float) $this->paid_amount), 2);
    }

    public function paymentStatus(): string
    {
        if ($this->remainingAmount() <= self::EPSILON) {
            return 'paid';
        }

        return (float) $this->paid_amount > self::EPSILON ? 'partial' : 'unpaid';
    }

    public function paymentStatusLabel(): string
    {
        return self::PAYMENT_STATUSES[$this->paymentStatus()];
    }

    public function isFullyPaid(): bool
    {
        return $this->paymentStatus() === 'paid';
    }

    /**
     * هل تقبل الفاتورة سند قبض؟ المرتجع لا يُقبض عليه.
     */
    public function acceptsSettlement(): bool
    {
        return ! $this->isReturn() && $this->remainingAmount() > self::EPSILON;
    }

    /**
     * زيادة المسدَّد بمبلغ سند مرحَّل — لا يتجاوز صافي المستحق.
     */
    public function addSettlement(float $amount): void
    {
        $this->update([
            'paid_amount' => round(min($this->netTotal(), (float) $this->paid_amount + $amount), 2),
        ]);
    }

    /**
     * إنقاص المسدَّد عند إلغاء سند مرحَّل.
     */
    public function reverseSettlement(float $amount): void
    {
        $this->update([
            'paid_amount' => round(max(0, (float) $this->paid_amount - $amount), 2),
        ]);
    }

    /**
     * مجمل ربح الفاتورة = الإجمالي − تكلفة المباع.
     */
    public function grossProfit(): float
    {
        return round((float) $this->total_amount - (float) $this->cost_amount, 2);
    }

    /**
     * الكمية المرتجعة من صنف في هذه الفاتورة — يمنع تجاوز المرتجع للمباع.
     */
    public function returnedQuantityFor(int $itemId): float
    {
        return (float) SaleItem::whereIn('sale_id', $this->returns()->pluck('id'))
            ->where('item_id', $itemId)
            ->sum('quantity');
    }

    public function paymentMethod(): BelongsTo
    {
        return $this->belongsTo(PaymentMethod::class);
    }

    public function methodLabel(): string
    {
        return $this->paymentMethod?->name ?? '—';
    }

    /**
     * فاتورة آجلة — لا تُقبض عند الإصدار، ومرتجعها على ذمة العميل.
     */
    public function isCredit(): bool
    {
        return (bool) $this->paymentMethod?->is_credit;
    }

    public function scopeSales(Builder $query): Builder
    {
        return $query->where('type', 'sale');
    }

    /**
     * تصفية بحالة السداد داخل قاعدة البيانات.
     *
     * المرتجع نفسه لا حالة سداد له، فيُستبعد من هذه التصفية.
     */
    public function scopePaymentStatus(Builder $query, string $status): Builder
    {
        $returned = self::returnedAmountExpression();
        $due = "(sales.total_amount - {$returned})";
        $eps = self::EPSILON;

        return $query->where('sales.type', 'sale')->where(fn (Builder $q) => match ($status) {
            'paid' => $q->whereRaw("sales.paid_amount >= {$due} - {$eps}"),
            'partial' => $q->whereRaw("sales.paid_amount > {$eps}")
                ->whereRaw("sales.paid_amount < {$due} - {$eps}"),
            'unpaid' => $q->whereRaw("sales.paid_amount <= {$eps}")
                ->whereRaw("{$due} > {$eps}"),
            default => $q,
        });
    }
}
