<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * صنف من أصناف المحل — أربعة أنواع (§1.3).
 */
class Item extends Model
{
    use SoftDeletes;

    public const TYPES = [
        'stock' => 'مخزني',
        'service' => 'خدمي',
        'bundle' => 'حزمة/مشروع',
        'measured' => 'بالقياس',
    ];

    public const UNITS = [
        'piece' => 'قطعة',
        'meter' => 'متر',
        'sqm' => 'متر مربع',
        'hour' => 'ساعة',
        'kg' => 'كجم',
        'liter' => 'لتر',
    ];

    /** الأنواع التي لها رصيد مخزني يتحرّك. */
    public const STOCKED_TYPES = ['stock', 'measured'];

    protected $fillable = [
        'code', 'barcode', 'name', 'item_category_id', 'department_id',
        'type', 'unit', 'measure_unit_id',
        'cost', 'price', 'tax_rate', 'stock_qty', 'reorder_point',
        'description', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'cost' => 'decimal:2',
            'price' => 'decimal:2',
            'tax_rate' => 'decimal:2',
            'stock_qty' => 'decimal:3',
            'reorder_point' => 'decimal:3',
            'is_active' => 'boolean',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ItemCategory::class, 'item_category_id');
    }

    /** القسم (النشاط) الذي يتبعه الصنف — به يُنظَّم المستودع. */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function measureUnit(): BelongsTo
    {
        return $this->belongsTo(MeasureUnit::class);
    }

    public function movements(): HasMany
    {
        return $this->hasMany(StockMovement::class);
    }

    /** مكوّنات الحزمة. */
    public function components(): BelongsToMany
    {
        return $this->belongsToMany(self::class, 'item_components', 'item_id', 'component_id')
            ->withPivot('quantity')
            ->withTimestamps();
    }

    /**
     * هل يحرّك هذا الصنف رصيدًا مخزنيًا؟
     * الخدمي لا رصيد له، والحزمة تخصم مكوّناتها لا نفسها.
     */
    public function tracksStock(): bool
    {
        return in_array($this->type, self::STOCKED_TYPES, true);
    }

    public function isBundle(): bool
    {
        return $this->type === 'bundle';
    }

    /**
     * هل تُقبل الكميات الكسرية؟
     *
     * القاعدة تتبع وحدة القياس أولًا (المتر المربع يقبل الكسر والقطعة لا)،
     * وتقع على نوع الصنف إن لم تُسند وحدة — أدقّ من ربطها بالنوع وحده،
     * فصنف مخزني يُباع بالمتر يقبل الكسر أيضًا.
     */
    public function allowsFractionalQuantity(): bool
    {
        if ($this->measure_unit_id && $this->relationLoaded('measureUnit') === false) {
            $this->load('measureUnit');
        }

        return $this->measureUnit?->allows_fraction ?? ($this->type === 'measured');
    }

    public function isBelowReorderPoint(): bool
    {
        return $this->tracksStock()
            && (float) $this->reorder_point > 0
            && (float) $this->stock_qty <= (float) $this->reorder_point;
    }

    public function typeLabel(): string
    {
        return self::TYPES[$this->type] ?? $this->type;
    }

    public function unitLabel(): string
    {
        if ($this->measure_unit_id) {
            $this->loadMissing('measureUnit');

            if ($this->measureUnit) {
                return $this->measureUnit->name;
            }
        }

        return self::UNITS[$this->unit] ?? $this->unit;
    }

    /**
     * الأصناف التي بلغت حد إعادة الطلب أو دونه.
     */
    public function scopeLowStock(Builder $query): Builder
    {
        return $query->whereIn('type', self::STOCKED_TYPES)
            ->where('reorder_point', '>', 0)
            ->whereColumn('stock_qty', '<=', 'reorder_point');
    }
}
