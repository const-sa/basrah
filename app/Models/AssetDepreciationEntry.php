<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * إهلاك أصل واحد عن شهر واحد — سطر واحد لكل (أصل، شهر) مرحَّل.
 */
class AssetDepreciationEntry extends Model
{
    protected $fillable = ['fixed_asset_id', 'period', 'amount', 'journal_entry_id'];

    protected function casts(): array
    {
        return [
            'period' => 'date',
            'amount' => 'decimal:2',
        ];
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(FixedAsset::class, 'fixed_asset_id');
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }
}
