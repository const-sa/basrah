<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * صندوق نقدي أو حساب بنكي.
 */
class Treasury extends Model
{
    public const TYPES = ['cash' => 'صندوق نقدي', 'bank' => 'حساب بنكي'];

    protected $fillable = [
        'name', 'type', 'account_id', 'bank_name', 'iban', 'opening_balance', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'opening_balance' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /**
     * The till a payment method deposits into — cash at the box, a transfer
     * or a card at the bank.
     *
     * One rule, read by every receipt written without an explicit choice, so
     * a booking's deposit and a contract's land in the same place.
     */
    public static function forMethod(PaymentMethod $method): ?self
    {
        $active = self::where('is_active', true)->orderBy('id');

        return (clone $active)->where('type', $method->deposits_to)->first() ?? $active->first();
    }

    public function vouchers(): HasMany
    {
        return $this->hasMany(Voucher::class);
    }

    public function expenses(): HasMany
    {
        return $this->hasMany(Expense::class);
    }

    /**
     * الرصيد الحالي = الافتتاحي + المقبوض − المصروف، من المرحَّل وحده.
     *
     * المصروف يخرج من الخزينة عبر بابين: سند صرف، ومستند مصروف (§9). وكلٌّ
     * في جدوله، فيُطرحان معًا — وإسقاط أحدهما يُظهر رصيدًا لا وجود له في
     * الصندوق.
     */
    public function balance(): float
    {
        $in = (float) $this->vouchers()->where('status', 'posted')->where('type', 'receipt')->sum('amount');

        $out = (float) $this->vouchers()->where('status', 'posted')->whereIn('type', ['payment', 'expense'])->sum('amount')
            + (float) $this->expenses()->posted()->sum('amount');

        return round((float) $this->opening_balance + $in - $out, 2);
    }

    public function typeLabel(): string
    {
        return self::TYPES[$this->type] ?? $this->type;
    }
}
