<?php

namespace Database\Seeders;

use App\Models\Addon;
use App\Models\Unit;
use App\Services\BookingPricing;
use App\Support\BookingPeriod;
use App\Support\StayPeriod;
use Illuminate\Database\Seeder;

/**
 * تسعيرة مبدئية وخدمات إضافية.
 *
 * ⚠️ الأرقام هنا افتراضية للتشغيل والاختبار فقط — تُستبدل بسياسة الأسعار
 * الفعلية عند ورودها من العميل (§القسم الخامس بند 5).
 */
class BookingSetupSeeder extends Seeder
{
    /**
     * أسعار مبدئية للقاعات حسب الفترة: [أيام الأسبوع, نهاية الأسبوع].
     */
    private const BASE = [
        'morning' => [1500, 2200],
        'evening' => [2500, 3500],
        'full_day' => [3500, 5000],
    ];

    /**
     * سعر ليلة الشاليه لكل يوم من أيام الأسبوع (0 الأحد … 6 السبت).
     *
     * يتدرّج مع الطلب: أهدأ الليالي أول الأسبوع، ثم يرتفع الأربعاء والخميس،
     * ويبلغ ذروته ليلة الجمعة. وهذا هو سبب التسعير اليومي أصلًا — الثنائية
     * القديمة كانت تسوّي الأحد بالأربعاء.
     */
    private const CHALET_NIGHTS = [
        0 => 900,
        1 => 900,
        2 => 950,
        3 => 1100,
        4 => 1600,
        5 => 1800,
        6 => 1300,
    ];

    public function run(): void
    {
        foreach (Unit::all() as $unit) {
            $unit->type === 'chalet'
                ? $this->seedChaletNights($unit)
                : $this->seedHallPeriods($unit);
        }

        $addons = [
            ['name' => 'ضيافة كاملة', 'price' => 45, 'pricing' => 'per_person'],
            ['name' => 'تنظيف إضافي', 'price' => 300, 'pricing' => 'fixed'],
            ['name' => 'إضاءة وتنسيق', 'price' => 800, 'pricing' => 'fixed'],
            ['name' => 'تشغيل المسبح وتدفئته', 'price' => 400, 'pricing' => 'fixed'],
            ['name' => 'ساعة إضافية', 'price' => 250, 'pricing' => 'per_hour'],
        ];

        foreach ($addons as $i => $addon) {
            Addon::updateOrCreate(
                ['name' => $addon['name']],
                [...$addon, 'is_active' => true, 'sort_order' => $i],
            );
        }

    }

    /**
     * القاعة: صفّان لكل فترة من فترات اليوم.
     */
    private function seedHallPeriods(Unit $unit): void
    {
        foreach (BookingPeriod::keys() as $period) {
            [$weekday, $weekend] = self::BASE[$period];

            $unit->prices()->updateOrCreate(
                ['unit_section_id' => null, 'period' => $period],
                [
                    'weekday_price' => $weekday,
                    'weekend_price' => $weekend,
                    'deposit_percent' => 25,
                    'is_active' => true,
                ],
            );

            foreach ($unit->sections as $section) {
                $unit->prices()->updateOrCreate(
                    ['unit_section_id' => $section->id, 'period' => $period],
                    [
                        'weekday_price' => $this->share($unit, $weekday),
                        'weekend_price' => $this->share($unit, $weekend),
                        'deposit_percent' => 25,
                        'is_active' => true,
                    ],
                );
            }
        }
    }

    /**
     * الشاليه: سعر ليلة لكل يوم من أيام الأسبوع.
     *
     * ويُملأ معه سعرا الأسبوع/نهايته كقاعدة احتياطية: من مسح سعر يوم لاحقًا
     * وقع عليها بدل أن يقع على صفر فيبيع ليلةً بلا مقابل.
     */
    private function seedChaletNights(Unit $unit): void
    {
        $weekend = BookingPricing::weekendDays();
        $ordinary = collect(self::CHALET_NIGHTS)->except($weekend);

        $base = [
            'weekday_price' => (int) round($ordinary->avg() ?: 0),
            'weekend_price' => (int) round(collect(self::CHALET_NIGHTS)->only($weekend)->avg() ?: 0),
        ];

        $unit->prices()->updateOrCreate(
            ['unit_section_id' => null, 'period' => StayPeriod::PERIOD],
            [
                ...$base,
                'day_prices' => self::CHALET_NIGHTS,
                'deposit_percent' => 25,
                'is_active' => true,
            ],
        );

        foreach ($unit->sections as $section) {
            $unit->prices()->updateOrCreate(
                ['unit_section_id' => $section->id, 'period' => StayPeriod::PERIOD],
                [
                    'weekday_price' => $this->share($unit, $base['weekday_price']),
                    'weekend_price' => $this->share($unit, $base['weekend_price']),
                    'day_prices' => array_map(
                        fn (int $price) => $this->share($unit, $price),
                        self::CHALET_NIGHTS,
                    ),
                    'deposit_percent' => 25,
                    'is_active' => true,
                ],
            );
        }
    }

    /**
     * حصة القسم من سعر الوحدة زائد 15% — مجموع الأقسام منفردة يجب أن يتجاوز
     * سعر الوحدة كاملة، وإلا خسرت المؤسسة على من قسّم حجزه.
     */
    private function share(Unit $unit, float $price): float
    {
        $count = $unit->sections->count();

        return $count > 0 ? round($price / $count * 1.15) : $price;
    }
}
