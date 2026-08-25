<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * مجموعة أصناف — اسمٌ وتحديدٌ محفوظ لأصناف تُطلب معًا.
 *
 * تُستعمل اختصارًا في الفواتير وعروض الأسعار والمشتريات: يُختار اسم
 * المجموعة فتُضاف أصنافها سطورًا دفعةً واحدة. ولا سعر للمجموعة نفسها —
 * كل صنف يدخل بسعره (أو تكلفته في المشتريات) وقت الإضافة.
 */
class ItemGroup extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'name', 'description', 'is_active', 'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(ItemGroupItem::class)->orderBy('sort_order');
    }

    /**
     * أصناف المجموعة — الفعّالة منها وحدها، فالصنف الموقوف لا يُباع.
     */
    public function items(): BelongsToMany
    {
        return $this->belongsToMany(Item::class, 'item_group_items')
            ->withPivot('sort_order')
            ->withTimestamps()
            ->orderBy('item_group_items.sort_order');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
