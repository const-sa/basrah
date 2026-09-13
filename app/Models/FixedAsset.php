<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * أصل ثابت (تكييف، أثاث، معدة…) يُهلَك بالقسط الثابت على عمره الإنتاجي.
 */
class FixedAsset extends Model
{
    use SoftDeletes;

    public const STATUSES = [
        'active' => 'نشط',
        'disposed' => 'مستبعد',
    ];

    protected $fillable = [
        'code', 'name', 'category', 'cost_center_id',
        'purchase_date', 'cost', 'salvage_value', 'useful_life_months',
        'status', 'disposed_at', 'notes', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'purchase_date' => 'date',
            'cost' => 'decimal:2',
            'salvage_value' => 'decimal:2',
            'useful_life_months' => 'integer',
            'disposed_at' => 'datetime',
        ];
    }

    public function costCenter(): BelongsTo
    {
        return $this->belongsTo(CostCenter::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function depreciationEntries(): HasMany
    {
        return $this->hasMany(AssetDepreciationEntry::class);
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? $this->status;
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    /**
     * القسط الشهري بطريقة القسط الثابت — القيمة القابلة للإهلاك على العمر
     * الإنتاجي بالأشهر.
     */
    public function monthlyDepreciation(): float
    {
        if ($this->useful_life_months <= 0) {
            return 0.0;
        }

        return round(((float) $this->cost - (float) $this->salvage_value) / $this->useful_life_months, 2);
    }

    /**
     * مجموع ما رُحِّل فعلًا من إهلاك — يُقرأ من السجلات لا يُحسب من العمر
     * والتاريخ، فلا ينحرف عن القيود الفعلية إن توقف الترحيل شهرًا أو أكثر.
     */
    public function accumulatedDepreciation(): float
    {
        return round((float) $this->depreciationEntries()->sum('amount'), 2);
    }

    public function bookValue(): float
    {
        return round((float) $this->cost - $this->accumulatedDepreciation(), 2);
    }

    /**
     * هل استُهلكت كل القيمة القابلة للإهلاك؟ لا يُرحَّل بعدها شيء.
     */
    public function isFullyDepreciated(): bool
    {
        return $this->bookValue() <= (float) $this->salvage_value + 0.01;
    }
}
