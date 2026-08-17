<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Supplier extends Model
{
    protected $fillable = [
        'name', 'mobile', 'email', 'tax_number', 'address', 'notes', 'is_active',
    ];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function vouchers(): HasMany
    {
        return $this->hasMany(Voucher::class);
    }

    /**
     * المستحق للمورد = ما صُرف له مطروحًا مما قُبض منه (من السندات المرحَّلة).
     */
    public function balance(): float
    {
        $paid = (float) $this->vouchers()->where('status', 'posted')->where('type', 'payment')->sum('amount');
        $received = (float) $this->vouchers()->where('status', 'posted')->where('type', 'receipt')->sum('amount');

        return round($paid - $received, 2);
    }
}
