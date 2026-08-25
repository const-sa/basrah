<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * صنف داخل مجموعة — انتماء لا أكثر: لا كمية ولا سعر.
 */
class ItemGroupItem extends Model
{
    protected $fillable = [
        'item_group_id', 'item_id', 'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
        ];
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(ItemGroup::class, 'item_group_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }
}
