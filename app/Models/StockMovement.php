<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class StockMovement extends Model
{
    public const TYPES = [
        'purchase' => 'شراء',
        'sale' => 'بيع',
        'return' => 'مرتجع',
        'adjustment' => 'تسوية جرد',
        'bundle_consume' => 'خصم مكوّنات حزمة',
        'opening' => 'رصيد افتتاحي',
    ];

    protected $fillable = [
        'item_id', 'user_id', 'type', 'quantity', 'unit_cost',
        'balance_after', 'reference_type', 'reference_id', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:3',
            'unit_cost' => 'decimal:2',
            'balance_after' => 'decimal:3',
        ];
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function reference(): MorphTo
    {
        return $this->morphTo();
    }

    public function typeLabel(): string
    {
        return self::TYPES[$this->type] ?? $this->type;
    }

    public function isIncoming(): bool
    {
        return (float) $this->quantity > 0;
    }
}
