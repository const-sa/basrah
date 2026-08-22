<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Concerns\AuthorizesByUnitType;
use App\Http\Controllers\Controller;
use App\Models\Unit;
use App\Models\UnitPrice;
use App\Support\BookingPeriod;
use App\Support\StayPeriod;
use App\Support\Weekdays;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * تسعيرة الوحدات والمواسم.
 *
 * القاعة تُسعَّر صفّين لكل فترة: سعر أيام الأسبوع وسعر نهاية الأسبوع (الجمعة
 * والسبت حسب config('operations.weekend_days')). والشاليه يُسعَّر بالليلة، سعرًا
 * مستقلًا لكل يوم من أيام الأسبوع، لأن طلب لياليه يختلف من يوم إلى يوم ولا
 * تحتمله ثنائية «أسبوع/نهاية أسبوع». المواسم — كالأعياد — تُدخَل في السعر نفسه
 * لا كنسبة تُضرب فيه من خلف الموظف.
 */
class PricingController extends Controller
{
    use AuthorizesByUnitType;

    /**
     * حفظ تسعيرة وحدة: صف لكل (قسم أو الوحدة كاملة) × فترة.
     * العربون لا يُمَس هنا — يُحفظ من مكانه فلا تُصفّره شاشة الأسعار.
     */
    public function updatePrices(Request $request, Unit $unit): RedirectResponse
    {
        // تسعيرة الوحدة تعديلٌ عليها، فتلزمها صلاحية تعديل نوعها.
        $this->authorizeUnitAction($request, $unit, 'edit');

        // فترات الشاليه تختلف عن فترات القاعة، فيُقبَل من كل نوع ما يخصّه:
        // قبول «الليلة» على قاعة يخزّن صفًا لا يقرؤه أحد.
        $periods = $unit->type === 'chalet'
            ? [StayPeriod::PERIOD]
            : BookingPeriod::keys();

        $data = $request->validate([
            'prices' => ['array'],
            'prices.*.unit_section_id' => [
                'nullable',
                'integer',
                Rule::exists('unit_sections', 'id')->where('unit_id', $unit->id),
            ],
            'prices.*.period' => ['required', Rule::in($periods)],
            'prices.*.weekday_price' => ['nullable', 'numeric', 'min:0', 'max:9999999999'],
            'prices.*.weekend_price' => ['nullable', 'numeric', 'min:0', 'max:9999999999'],
            'prices.*.day_prices' => ['nullable', 'array'],
            'prices.*.day_prices.*' => ['nullable', 'numeric', 'min:0', 'max:9999999999'],
        ]);

        foreach ($data['prices'] ?? [] as $row) {
            $dayPrices = $this->dayPrices($row['day_prices'] ?? null);

            UnitPrice::updateOrCreate(
                [
                    'unit_id' => $unit->id,
                    'unit_section_id' => $row['unit_section_id'] ?? null,
                    'period' => $row['period'],
                ],
                [
                    'weekday_price' => $row['weekday_price'] ?? 0,
                    'weekend_price' => $row['weekend_price'] ?? 0,
                    'day_prices' => $dayPrices,
                    'is_active' => true,
                ],
            );
        }

        return back()->with('success', 'تم حفظ أسعار الوحدة');
    }

    /**
     * تنقية خريطة أسعار الأيام: تُقبَل أيام الأسبوع السبعة فقط، ويُسقَط اليوم
     * المتروك فارغًا ليرجع إلى سعر أيام الأسبوع/نهايته بدل أن يُخزَّن صفرًا
     * فيبيع ليلةً بلا مقابل.
     *
     * الخريطة الفارغة تُخزَّن null لا [] — فتُقرأ لاحقًا «لا تسعير يومي هنا».
     *
     * @param  array<int|string, mixed>|null  $prices
     * @return array<int, float>|null
     */
    private function dayPrices(?array $prices): ?array
    {
        $clean = [];

        foreach (Weekdays::keys() as $day) {
            $value = $prices[$day] ?? null;

            if ($value === null || $value === '') {
                continue;
            }

            $clean[$day] = round((float) $value, 2);
        }

        return $clean === [] ? null : $clean;
    }
}
