<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * قسم = نشاط تجاري مستقل (المسابح، القاعات والشاليهات، المحل…).
 *
 * لا يُخلط بأقسام الوحدة (رجال/نساء) — تلك في UnitSection.
 * المستودع يُنظَّم بهذه الأقسام، والقسم الذي `sells` له فواتيره.
 */
class Department extends Model
{
    protected $fillable = [
        'code',
        'name',
        'description',
        'sells',
        'sort_order',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'sells' => 'boolean',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(Item::class);
    }

    public function sales(): HasMany
    {
        return $this->hasMany(Sale::class);
    }

    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class);
    }

    public function costCenter(): HasOne
    {
        return $this->hasOne(CostCenter::class);
    }

    /**
     * قيمة مخزون القسم بالتكلفة.
     */
    public function stockValue(): float
    {
        return round(
            (float) $this->items()
                ->whereIn('type', Item::STOCKED_TYPES)
                ->selectRaw('SUM(stock_qty * cost) AS v')
                ->value('v'),
            2,
        );
    }

    /**
     * الأقسام التي لها مبيعات — هي ما تظهر في شاشة الفواتير.
     */
    public function scopeSelling(Builder $query): Builder
    {
        return $query->where('is_active', true)->where('sells', true);
    }

    /**
     * القسم الافتراضي لشاشة الفواتير.
     */
    public static function defaultSelling(): ?self
    {
        return static::selling()->orderBy('sort_order')->first();
    }
}
