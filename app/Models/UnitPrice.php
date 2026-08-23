<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * تسعيرة وحدة أو قسم لفترة محددة.
 * unit_section_id = null يعني سعر الوحدة كاملة.
 */
class UnitPrice extends Model
{
    protected $fillable = [
        'unit_id',
        'unit_section_id',
        'period',
        'weekday_price',
        'weekend_price',
        'day_prices',
        'deposit_amount',
        'deposit_percent',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'weekday_price' => 'decimal:2',
            'weekend_price' => 'decimal:2',
            'day_prices' => 'array',
            'deposit_amount' => 'decimal:2',
            'deposit_percent' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    public function section(): BelongsTo
    {
        return $this->belongsTo(UnitSection::class, 'unit_section_id');
    }

    /**
     * السعر الأساسي ليوم بعينه.
     *
     * سعر اليوم المحدد يتقدّم على ثنائية أيام الأسبوع/نهايته: من أدخل سعرًا
     * للخميس أراده هو لا سعر «بقية الأيام». وغياب سعر اليوم — أو تركه فارغًا —
     * يُرجع اليوم إلى الثنائية القديمة، فتبقى الوحدات المسعَّرة بها تعمل كما هي.
     *
     * @param  int|null  $dayOfWeek  رقم يوم Carbon (0 الأحد … 6 السبت)
     */
    public function priceFor(bool $isWeekend, ?int $dayOfWeek = null): float
    {
        return $this->dayPrice($dayOfWeek)
            ?? (float) ($isWeekend ? $this->weekend_price : $this->weekday_price);
    }

    /**
     * سعر اليوم المُدخَل صراحةً، أو null إن لم يُدخَل.
     */
    public function dayPrice(?int $dayOfWeek): ?float
    {
        if ($dayOfWeek === null) {
            return null;
        }

        $price = $this->day_prices[$dayOfWeek] ?? null;

        // الصفر سعر مقصود (ليلة مجانية في عرض ترويجي) لا «قيمة غائبة»،
        // فالفارغ وحده هو الذي يسقط إلى الثنائية.
        return ($price === null || $price === '') ? null : (float) $price;
    }

    /**
     * Does this row carry a usable price at all?
     *
     * A row exists as soon as the pricing screen is saved, even with every
     * box left empty, so "the row exists" is not the same as "it is priced".
     * Used to decide whether a period may be offered when booking, keeping
     * an unpriced period from quoting 0.
     */
    public function hasAnyPrice(): bool
    {
        foreach ($this->day_prices ?? [] as $price) {
            if ((float) $price > 0) {
                return true;
            }
        }

        return (float) $this->weekday_price > 0 || (float) $this->weekend_price > 0;
    }

    /**
     * العربون المطلوب على إجمالي معيّن — المبلغ الثابت يتقدّم على النسبة.
     */
    public function depositFor(float $total): float
    {
        if ($this->deposit_amount !== null) {
            return round((float) $this->deposit_amount, 2);
        }

        if ($this->deposit_percent !== null) {
            return round($total * (float) $this->deposit_percent / 100, 2);
        }

        return 0.0;
    }
}
