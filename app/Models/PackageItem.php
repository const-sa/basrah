<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * بند في باقة: صنف وعدد («عدد المعازيم رجال» × 500).
 */
class PackageItem extends Model
{
    protected $fillable = [
        'package_id', 'name', 'quantity', 'unit_label', 'notes', 'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:2',
            'sort_order' => 'integer',
        ];
    }

    public function package(): BelongsTo
    {
        return $this->belongsTo(Package::class);
    }

    /**
     * العدد بصيغة مقروءة: الكسر يظهر فقط إن وُجد.
     */
    public function quantityLabel(): string
    {
        $qty = (float) $this->quantity;
        $text = fmod($qty, 1.0) === 0.0 ? (string) (int) $qty : rtrim(rtrim((string) $qty, '0'), '.');

        return $this->unit_label ? "{$text} {$this->unit_label}" : $text;
    }
}
