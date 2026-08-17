<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * خدمة إضافية تُضاف على الحجز.
 */
class Addon extends Model
{
    public const PRICING = [
        'fixed' => 'سعر ثابت',
        'per_person' => 'لكل شخص',
        'per_hour' => 'لكل ساعة',
    ];

    protected $fillable = [
        'name',
        'price',
        'pricing',
        'description',
        'is_active',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function bookings(): BelongsToMany
    {
        return $this->belongsToMany(Booking::class, 'booking_addon')
            ->withPivot(['quantity', 'unit_price', 'total'])
            ->withTimestamps();
    }

    /**
     * إجمالي هذه الخدمة حسب طريقة تسعيرها.
     */
    public function totalFor(int $quantity): float
    {
        return round((float) $this->price * max($quantity, 1), 2);
    }
}
