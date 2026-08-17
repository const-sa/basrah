<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * نوع المناسبة في قاعة بعينها — زواج، ملكة، تخرّج، اجتماع…
 *
 * النوع ليس تصنيفًا مجرّدًا بل منتَجٌ تبيعه القاعة بسعر: «زواج» في قاعة
 * المشام غيره في روكانا اسمًا واحدًا وسعرين. لذلك يتبع النوع قاعته، ويحمل
 * سعرها الذي يملأ السعر الأساسي للحجز عند اختياره.
 */
class EventType extends Model
{
    /**
     * ألوان الشارات المتاحة — محصورة حتى لا تدخل الواجهة أصنافًا لا تعرفها.
     */
    public const COLORS = [
        'emerald' => 'أخضر',
        'sky' => 'أزرق',
        'violet' => 'بنفسجي',
        'amber' => 'ذهبي',
        'rose' => 'وردي',
        'slate' => 'رمادي',
    ];

    protected $fillable = [
        'unit_id', 'name', 'description', 'color', 'price', 'sort_order', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'sort_order' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /** القاعة التي يخصّها هذا النوع. */
    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class);
    }

    /**
     * هل يحمل النوع سعرًا خاصًا؟
     *
     * الصفر ليس سعرًا بل غيابه: النوع بلا سعر يترك الحجز على تسعيرة القاعة
     * (يوم عادي / نهاية أسبوع / موسم) كما لو لم يُختر نوع أصلًا.
     */
    public function hasOwnPrice(): bool
    {
        return (float) $this->price > 0;
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeForUnit(Builder $query, int $unitId): Builder
    {
        return $query->where('unit_id', $unitId);
    }
}
