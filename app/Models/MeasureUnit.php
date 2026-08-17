<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * وحدة قياس يُباع بها الصنف.
 */
class MeasureUnit extends Model
{
    protected $fillable = [
        'code', 'name', 'symbol', 'allows_fraction', 'sort_order', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'allows_fraction' => 'boolean',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(Item::class);
    }

    public function label(): string
    {
        return $this->symbol ? "{$this->name} ({$this->symbol})" : $this->name;
    }
}
