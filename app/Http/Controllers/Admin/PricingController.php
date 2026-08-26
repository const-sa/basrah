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
     *
     * The deposit is edited here too, alongside the price it is charged on —
     * it was previously reachable only from seeders.
     */
    public function updatePrices(Request $request, Unit $unit): RedirectResponse
    {
        // تسعيرة الوحدة تعديلٌ عليها، فتلزمها صلاحية تعديل نوعها.
        $this->authorizeUnitAction($request, $unit, 'edit');

        // فترات الشاليه تختلف عن فترات القاعة، فيُقبَل من كل نوع ما يخصّه:
        // قبول «الليلة» على قاعة يخزّن صفًا لا يقرؤه أحد.
        // A chalet carries the night plus the three day periods, so the same
        // unit can be sold as a stay or for a morning/evening/full day.
        $periods = $unit->type === 'chalet'
            ? StayPeriod::pricingKeys()
            : BookingPeriod::hallKeys();

        $data = $request->validate([
            // The security deposit is one figure for the whole unit, not one
            // per period: it answers for damage to the chalet, and damage does
            // not care whether the guest came for a morning or a week.
            'security_deposit' => ['nullable', 'numeric', 'min:0', 'max:9999999999'],
            // ساعات كل فترة: دخولها وخروجها على هذه الوحدة بعينها. تُقبل
            // للفترات التي يُباع بها هذا النوع وحدها، وتُترك فارغة لترجع
            // الفترة إلى ساعات الإعدادات.
            'hours' => ['array'],
            'hours.*.start' => ['nullable', 'date_format:H:i', 'required_with:hours.*.end'],
            'hours.*.end' => ['nullable', 'date_format:H:i', 'required_with:hours.*.start'],
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
            // Deposit is per (section, period) like the price it belongs to.
            // Both may be blank, meaning this row asks for no deposit.
            'prices.*.deposit_amount' => ['nullable', 'numeric', 'min:0', 'max:9999999999'],
            'prices.*.deposit_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
        ]);

        $unit->fill(['period_hours' => $this->hours($data['hours'] ?? null, $periods)]);

        if (array_key_exists('security_deposit', $data)) {
            $unit->fill(['security_deposit' => $this->nullableDecimal($data['security_deposit'])]);
        }

        $unit->save();

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
                    // A blank box means "no deposit of this kind", which is
                    // null rather than zero: UnitPrice::depositFor() treats a
                    // set-but-zero amount as a deliberate "no deposit asked"
                    // and would stop falling through to the percentage.
                    'deposit_amount' => $this->nullableDecimal($row['deposit_amount'] ?? null),
                    'deposit_percent' => $this->nullableDecimal($row['deposit_percent'] ?? null),
                    'is_active' => true,
                ],
            );
        }

        return back()->with('success', 'تم حفظ أسعار الوحدة');
    }

    /**
     * ساعات الفترات بعد تنقيتها.
     *
     * تُقبل الفترات التي يُباع بها هذا النوع وحدها — ساعةُ «الليلة» على قاعة
     * لا تُقرأ أبدًا — وتُسقَط الفترة التي تُركت خانتاها فارغتين فترجع إلى
     * ساعات الإعدادات. والخريطة الفارغة تُخزَّن null لا [] فتُقرأ لاحقًا
     * «هذه الوحدة على ساعات النظام».
     *
     * ساعة الخروج قد تسبق ساعة الدخول ولا خطأ في ذلك: الفترة عندئذٍ تعبر
     * منتصف الليل — والمبيت كله كذلك — وBookingPeriod يستنتج العبور من
     * الساعتين نفسيهما.
     *
     * @param  array<string, mixed>|null  $hours
     * @param  list<string>  $periods
     * @return array<string, array{start: string, end: string}>|null
     */
    private function hours(?array $hours, array $periods): ?array
    {
        $clean = [];

        foreach ($periods as $period) {
            $start = $hours[$period]['start'] ?? null;
            $end = $hours[$period]['end'] ?? null;

            if (! is_string($start) || ! is_string($end) || $start === '' || $end === '') {
                continue;
            }

            $clean[$period] = ['start' => $start, 'end' => $end];
        }

        return $clean === [] ? null : $clean;
    }

    /**
     * An empty box is "not set" (null), not zero. Kept separate from the
     * price columns, which default to 0 because a price always exists.
     */
    private function nullableDecimal(mixed $value): ?float
    {
        return ($value === null || $value === '') ? null : round((float) $value, 2);
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
