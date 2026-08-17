<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * باقة قاعة — اسم وبنود بأعدادها (معازيم، صبّابين، ضيافة…).
 */
class Package extends Model
{
    protected $fillable = [
        'name', 'description', 'unit_id', 'price', 'sort_order', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'sort_order' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(PackageItem::class)->orderBy('sort_order');
    }

    /** القاعة التي تخصّها الباقة — فارغة يعني متاحة لكل القاعات. */
    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    public function isGeneral(): bool
    {
        return $this->unit_id === null;
    }

    /**
     * الباقات المتاحة لقاعة: الخاصة بها والعامة معًا.
     */
    public function scopeForUnit(Builder $query, int $unitId): Builder
    {
        return $query->where('is_active', true)
            ->where(fn ($q) => $q->where('unit_id', $unitId)->orWhereNull('unit_id'));
    }
}
