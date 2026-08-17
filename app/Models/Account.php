<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * حساب في شجرة الحسابات.
 */
class Account extends Model
{
    public const TYPES = [
        'asset' => 'أصول',
        'liability' => 'التزامات',
        'equity' => 'حقوق ملكية',
        'revenue' => 'إيرادات',
        'expense' => 'مصروفات',
    ];

    /** الأنواع التي يزيد رصيدها بالمدين. */
    public const DEBIT_NATURE = ['asset', 'expense'];

    protected $fillable = [
        'code', 'name', 'parent_id', 'type', 'is_group', 'opening_balance', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_group' => 'boolean',
            'is_active' => 'boolean',
            'opening_balance' => 'decimal:2',
        ];
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('code');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(JournalLine::class);
    }

    public function typeLabel(): string
    {
        return self::TYPES[$this->type] ?? $this->type;
    }

    /**
     * هل طبيعة الحساب مدينة؟ يحدد إشارة الرصيد.
     */
    public function isDebitNature(): bool
    {
        return in_array($this->type, self::DEBIT_NATURE, true);
    }

    /**
     * رصيد الحساب من القيود ذات الأثر، بإشارة طبيعته.
     * القيود المسوّدة لا تدخل الأرصدة — هذا شرط سلامة الدفاتر.
     */
    public function balance(?string $upTo = null): float
    {
        $query = JournalLine::where('account_id', $this->id)
            ->whereHas('entry', fn ($q) => $q->whereIn('status', JournalEntry::EFFECTIVE_STATUSES)
                ->when($upTo, fn ($sub) => $sub->whereDate('entry_date', '<=', $upTo)));

        $debit = (float) $query->clone()->sum('debit');
        $credit = (float) $query->clone()->sum('credit');

        $movement = $this->isDebitNature() ? $debit - $credit : $credit - $debit;

        return round((float) $this->opening_balance + $movement, 2);
    }

    /**
     * الحسابات التي يجوز الترحيل عليها (غير التجميعية).
     */
    public function scopePostable(Builder $query): Builder
    {
        return $query->where('is_group', false)->where('is_active', true);
    }
}
