<?php

namespace Tests\Feature;

use App\Models\Addon;
use App\Models\Unit;
use App\Services\BookingPricing;
use Database\Seeders\BookingSetupSeeder;
use Database\Seeders\RolesSeeder;
use Database\Seeders\UnitsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * اختبارات التسعير: أيام الأسبوع، نهاية الأسبوع، الخدمات، العربون.
 *
 * الوحدة المختبَرة قاعة لا شاليه: quote() تُسعّر فترات اليوم، وهي أداة القاعة.
 * الشاليه يُسعَّر بالليلة عبر quoteStay()، وله ملف اختبار مستقل.
 */
class BookingPricingTest extends TestCase
{
    use RefreshDatabase;

    private BookingPricing $pricing;

    private Unit $unit;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([RolesSeeder::class, UnitsSeeder::class, BookingSetupSeeder::class]);
        $this->pricing = app(BookingPricing::class);
        $this->unit = Unit::where('code', 'HALL-01')->firstOrFail();
    }

    public function test_weekend_costs_more_than_a_weekday(): void
    {
        // 2026-09-10 خميس (يوم أسبوع) · 2026-09-11 جمعة (نهاية أسبوع)
        $weekday = $this->pricing->quote($this->unit, 'whole', '2026-09-10', 'full_day');
        $weekend = $this->pricing->quote($this->unit, 'whole', '2026-09-11', 'full_day');

        $this->assertFalse($weekday['is_weekend']);
        $this->assertTrue($weekend['is_weekend']);
        $this->assertGreaterThan($weekday['total_amount'], $weekend['total_amount']);
    }

    public function test_the_base_price_is_the_entered_price_with_no_multiplier(): void
    {
        // لا نِسَب مواسم في النظام: ما يُدخَل في جدول التسعيرة هو ما يظهر
        // في الحجز. الاختبار يحرس ذلك ضد إعادة إدخال معامل يضرب السعر.
        $price = $this->unit->prices()
            ->whereNull('unit_section_id')
            ->where('period', 'full_day')
            ->firstOrFail();

        $quote = $this->pricing->quote($this->unit, 'whole', '2026-09-10', 'full_day');

        $this->assertSame((float) $price->weekday_price, $quote['base_amount']);
    }

    public function test_sections_total_exceeds_the_whole_unit_price(): void
    {
        $sectionIds = $this->unit->sections->pluck('id')->all();

        $whole = $this->pricing->quote($this->unit, 'whole', '2026-09-10', 'full_day');
        $sections = $this->pricing->quote($this->unit, 'sections', '2026-09-10', 'full_day', $sectionIds);

        // حجز كل الأقسام منفردة يجب أن يكلّف أكثر من حجز الوحدة كاملة،
        // وإلا صار تقسيم الحجز حيلة لتخفيض الفاتورة.
        $this->assertGreaterThan($whole['base_amount'], $sections['base_amount']);
    }

    public function test_addons_and_discount_affect_the_total(): void
    {
        $cleaning = Addon::where('name', 'تنظيف إضافي')->firstOrFail();

        $base = $this->pricing->quote($this->unit, 'whole', '2026-09-10', 'full_day');
        $withAddon = $this->pricing->quote($this->unit, 'whole', '2026-09-10', 'full_day', [], [$cleaning->id => 1], 100);

        $this->assertSame(300.0, $withAddon['addons_amount']);
        $this->assertSame(100.0, $withAddon['discount_amount']);
        $this->assertEqualsWithDelta($base['base_amount'] + 300 - 100, $withAddon['total_amount'], 0.01);
    }

    public function test_per_person_addon_multiplies_by_quantity(): void
    {
        $hospitality = Addon::where('name', 'ضيافة كاملة')->firstOrFail();

        $quote = $this->pricing->quote($this->unit, 'whole', '2026-09-10', 'full_day', [], [$hospitality->id => 30]);

        $this->assertSame(30 * 45.0, $quote['addons_amount']);
    }

    public function test_deposit_is_a_quarter_of_the_total(): void
    {
        $quote = $this->pricing->quote($this->unit, 'whole', '2026-09-10', 'full_day');

        $this->assertEqualsWithDelta($quote['total_amount'] * 0.25, $quote['deposit_amount'], 0.01);
    }

    public function test_discount_never_pushes_the_total_below_zero(): void
    {
        $quote = $this->pricing->quote($this->unit, 'whole', '2026-09-10', 'full_day', [], [], 999999);

        $this->assertSame(0.0, $quote['total_amount']);
    }
}
