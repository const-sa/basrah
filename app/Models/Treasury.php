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

    public function vouchers(): HasMany
    {
        return $this->hasMany(Voucher::class);
    }

    /**
     * الرصيد الحالي = الافتتاحي + المقبوض − المصروف (من السندات المرحَّلة).
     */
    public function balance(): float
    {
        $in = (float) $this->vouchers()->where('status', 'posted')->where('type', 'receipt')->sum('amount');
        $out = (float) $this->vouchers()->where('status', 'posted')->whereIn('type', ['payment', 'expense'])->sum('amount');

        return round((float) $this->opening_balance + $in - $out, 2);
    }

    public function typeLabel(): string
    {
        return self::TYPES[$this->type] ?? $this->type;
    }
}
