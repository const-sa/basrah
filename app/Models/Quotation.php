<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Quotation extends Model
{
    use SoftDeletes;

    // `status` was missing here, so every write to it was silently dropped by
    // mass-assignment guarding: quotations stayed «pending» forever on the
    // column default, and marking one accepted did nothing. Contracts are now
    // drawn from accepted quotations, which made the dead status visible.
    protected $fillable = [
        'number', 'client_id', 'user_id', 'department_id', 'status',
        'subtotal', 'discount_amount', 'tax_amount', 'total_amount',
        'valid_until', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'subtotal' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'tax_amount' => 'decimal:2',
            'total_amount' => 'decimal:2',
            'valid_until' => 'date',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(QuotationItem::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function contracts(): HasMany
    {
        return $this->hasMany(Contract::class);
    }

    public function invoice(): HasOne
    {
        return $this->hasOne(Sale::class)->where('type', 'sale');
    }

    public function isInvoiced(): bool
    {
        return $this->invoice()->exists();
    }
}
