<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\Unit;
use App\Models\UnitPrice;
use App\Models\User;
use App\Services\BookingPricing;
use App\Support\StayPeriod;
use Database\Seeders\BookingSetupSeeder;
use Database\Seeders\RolesSeeder;
use Database\Seeders\UnitsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * تسعير ليالي الشاليه بسعر مستقل لكل يوم من أيام الأسبوع.
 *
 * ما يحرسه الاختبار: أن كل ليلة تُسعَّر بيومها هي لا بيوم الوصول، وأن اليوم
 * المتروك بلا سعر يرجع إلى سعر أيام الأسبوع/نهايته بدل أن يُحسب صفرًا.
 */
class ChaletDayPricingTest extends TestCase
{
    use RefreshDatabase;

    private BookingPricing $pricing;

    private Unit $unit;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([RolesSeeder::class, UnitsSeeder::class, BookingSetupSeeder::class]);
        $this->pricing = app(BookingPricing::class);
        $this->unit = Unit::where('code', 'CH-BSR1')->firstOrFail();

        $this->owner = User::factory()->create([
            'role_id' => Role::where('slug', 'super-admin')->value('id'),
            'is_active' => true,
            'has_all_units' => true,
        ]);
    }

    private function priceRow(): UnitPrice
    {
        return $this->unit->prices()
            ->whereNull('unit_section_id')
            ->where('period', StayPeriod::PERIOD)
            ->firstOrFail();
    }

    public function test_each_night_is_priced_by_its_own_weekday(): void
    {
        $this->priceRow()->update([
            'day_prices' => [0 => 100, 1 => 200, 2 => 300, 3 => 400, 4 => 500, 5 => 600, 6 => 700],
        ]);

        // 2026-09-06 أحد → ثلاث ليالٍ: الأحد والاثنين والثلاثاء.
        $quote = $this->pricing->quoteStay($this->unit, '2026-09-06', '2026-09-09');

        $this->assertSame(3, $quote['nights']);
        $this->assertSame(600.0, $quote['base_amount']);
    }

    public function test_a_day_left_empty_falls_back_to_the_weekly_price(): void
    {
        $this->priceRow()->update([
            'weekday_price' => 900,
            'weekend_price' => 1500,
            // الاثنين وحده مسعَّر؛ الأحد يسقط على سعر أيام الأسبوع.
            'day_prices' => [1 => 250],
        ]);

        // 2026-09-06 أحد → ليلتان: الأحد (900 افتراضي) والاثنين (250).
        $quote = $this->pricing->quoteStay($this->unit, '2026-09-06', '2026-09-08');

        $this->assertSame(1150.0, $quote['base_amount']);
    }

    public function test_a_zero_day_price_is_honoured_not_treated_as_missing(): void
    {
        // الصفر سعر مقصود — ليلة مجانية في عرض ترويجي — لا «قيمة غائبة».
        $this->priceRow()->update([
            'weekday_price' => 900,
            'day_prices' => [0 => 0],
        ]);

        $quote = $this->pricing->quoteStay($this->unit, '2026-09-06', '2026-09-07');

        $this->assertSame(0.0, $quote['base_amount']);
    }

    public function test_the_pricing_screen_saves_a_price_for_every_day(): void
    {
        $days = [0 => 100, 1 => 110, 2 => 120, 3 => 130, 4 => 140, 5 => 150, 6 => 160];

        $this->actingAs($this->owner)
            ->put("/admin/units/{$this->unit->id}/prices", [
                'prices' => [[
                    'unit_section_id' => null,
                    'period' => StayPeriod::PERIOD,
                    'weekday_price' => 900,
                    'weekend_price' => 1500,
                    'day_prices' => $days,
                ]],
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame($days, $this->priceRow()->fresh()->day_prices);
    }

    public function test_clearing_every_day_returns_the_unit_to_the_weekly_pair(): void
    {
        $this->priceRow()->update(['day_prices' => [0 => 100]]);

        $this->actingAs($this->owner)
            ->put("/admin/units/{$this->unit->id}/prices", [
                'prices' => [[
                    'unit_section_id' => null,
                    'period' => StayPeriod::PERIOD,
                    'weekday_price' => 900,
                    'weekend_price' => 1500,
                    'day_prices' => [0 => null, 1 => null, 2 => null, 3 => null, 4 => null, 5 => null, 6 => null],
                ]],
            ])
            ->assertSessionHasNoErrors();

        $row = $this->priceRow()->fresh();

        $this->assertNull($row->day_prices);
        $this->assertSame(900.0, $row->priceFor(false, 0));
    }

    /**
     * القسم غير المسعَّر لفترةٍ نهارية يأخذ سعر الشاليه فيها.
     *
     * الفترات النهارية تُدخَل على الشاليه مرةً واحدة في الغالب، والحجز يقع على
     * قسمٍ منه، فحصرُ السعر على صفّ القسم كان يُخرِج إجمالي صفر لفترةٍ سعرها
     * مكتوب. وسعر القسم — متى وُجد — يبقى المقدَّم.
     */
    public function test_a_section_without_a_period_price_takes_the_chalet_price(): void
    {
        $section = $this->unit->sections()->firstOrFail();

        UnitPrice::updateOrCreate(
            ['unit_id' => $this->unit->id, 'unit_section_id' => null, 'period' => 'evening'],
            ['weekday_price' => 200, 'weekend_price' => 500, 'deposit_percent' => 25, 'is_active' => true],
        );

        // صفّ القسم موجود بلا سعر — كما يتركه حفظ شاشة الأسعار بخاناتٍ فارغة.
        UnitPrice::updateOrCreate(
            ['unit_id' => $this->unit->id, 'unit_section_id' => $section->id, 'period' => 'evening'],
            ['weekday_price' => 0, 'weekend_price' => 0, 'is_active' => true],
        );

        // 2026-09-09 أربعاء — يوم عاديّ لا نهاية أسبوع.
        $quote = $this->pricing->quote($this->unit->fresh(), 'sections', '2026-09-09', 'evening', [$section->id]);

        $this->assertSame(200.0, $quote['base_amount']);
        $this->assertSame(200.0, $quote['total_amount']);
        // شروط العربون تأتي مع الصفّ الذي سُعِّر به الحجز.
        $this->assertSame(50.0, $quote['deposit_amount']);
        $this->assertStringContainsString('بسعر الشاليه', $quote['lines'][0]['label']);
    }

    public function test_a_section_priced_for_the_period_keeps_its_own_price(): void
    {
        $section = $this->unit->sections()->firstOrFail();

        UnitPrice::updateOrCreate(
            ['unit_id' => $this->unit->id, 'unit_section_id' => null, 'period' => 'evening'],
            ['weekday_price' => 200, 'weekend_price' => 500, 'is_active' => true],
        );
        UnitPrice::updateOrCreate(
            ['unit_id' => $this->unit->id, 'unit_section_id' => $section->id, 'period' => 'evening'],
            ['weekday_price' => 120, 'weekend_price' => 300, 'is_active' => true],
        );

        $quote = $this->pricing->quote($this->unit->fresh(), 'sections', '2026-09-09', 'evening', [$section->id]);

        $this->assertSame(120.0, $quote['base_amount']);
        $this->assertStringNotContainsString('بسعر الشاليه', $quote['lines'][0]['label']);
    }
}
