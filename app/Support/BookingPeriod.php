<?php

namespace App\Support;

use Carbon\CarbonImmutable;

/**
 * فترات الحجز وتحويلها إلى مدى زمني صريح.
 *
 * سبب التحويل: «المبيت» و«المسائي» يمتدان إلى ما بعد منتصف الليل، فمقارنة
 * الفترات بالاسم تُخطئ في كشف التعارض. المدى الزمني (starts_at → ends_at)
 * يجعل الكشف مسألة تقاطع فترتين، وهي عملية دقيقة لا تحتمل الالتباس.
 */
class BookingPeriod
{
    /**
     * Shipped hours, used until the client sets their own from the booking
     * times screen. Read periods() rather than this constant — it is only the
     * fallback, and the overnight flag here is documentation: the live one is
     * derived from the hours in BookingTimes.
     */
    public const DEFAULTS = [
        'morning' => ['label' => 'صباحي', 'start' => '09:00', 'end' => '17:00', 'overnight' => false],
        'evening' => ['label' => 'مسائي', 'start' => '17:00', 'end' => '01:00', 'overnight' => true],
        'full_day' => ['label' => 'يوم كامل', 'start' => '09:00', 'end' => '01:00', 'overnight' => true],
    ];

    /**
     * The periods as configured, keyed by period.
     *
     * The full catalogue — every period whose hours the client may set. What
     * a given unit type is actually sold in is narrower; see hallKeys().
     *
     * @return array<string, array{label: string, start: string, end: string, overnight: bool}>
     */
    public static function periods(): array
    {
        return app(BookingTimes::class)->periods();
    }

    /**
     * الفترة التي تُباع بها القاعة — يومٌ كامل لا غير.
     *
     * The morning/evening split is day-use, and day-use is a chalet
     * arrangement: the same chalet is let for a few hours, then again in the
     * evening, then overnight. A hall goes out for the occasion, which
     * occupies the day. Both halves of that split still exist as periods —
     * StayPeriod::pricingPeriods() offers them to chalets, and the booking
     * times screen sets their hours — they are simply not on a hall's menu.
     *
     * @var list<string>
     */
    public const HALL_KEYS = ['full_day'];

    /**
     * @return list<string>
     */
    public static function hallKeys(): array
    {
        return self::HALL_KEYS;
    }

    /**
     * فترات القاعة جاهزةً للواجهة — بنفس شكل forView حتى تقرأها الشاشات بلا تفريق.
     *
     * @return list<array{key: string, label: string, start: string, end: string}>
     */
    public static function hallPeriods(): array
    {
        return array_values(array_filter(
            self::forView(),
            fn (array $p) => in_array($p['key'], self::HALL_KEYS, true),
        ));
    }

    /**
     * أقصى عدد أيام لمناسبة واحدة — حارس خطأ إدخال لا سياسة تسعير.
     */
    public const MAX_DAYS = 30;

    /**
     * حدود الفترة كمدى زمني فعلي.
     *
     * المناسبة قد تمتد أيامًا متتالية: تبدأ بفترة يومها الأول وتنتهي بفترة
     * يومها الأخير، فيغطي المدى الواحد أيامها كلها ويقفلها جميعًا في كشف
     * التعارض. اليوم الأخير هو البداية + (عدد الأيام − 1): يومان يعنيان اليوم
     * وتاليه لا اليوم وما بعد غد.
     *
     * @param  int  $days  عدد أيام المناسبة — يوم واحد ما لم يُذكر غيره
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public static function range(string $date, string $period, int $days = 1): array
    {
        $periods = self::periods();
        $meta = $periods[$period] ?? $periods['full_day'];

        $day = CarbonImmutable::parse($date)->startOfDay();
        $starts = $day->setTimeFromTimeString($meta['start']);
        $ends = $day->addDays(self::days($days) - 1)->setTimeFromTimeString($meta['end']);

        // الفترة التي تعبر منتصف الليل تنتهي في اليوم التالي.
        if ($meta['overnight']) {
            $ends = $ends->addDay();
        }

        return [$starts, $ends];
    }

    /**
     * أيام المناسبة كتواريخ — كل يوم يُسعَّر بيومه لأن نهاية الأسبوع قد تقع
     * داخل المناسبة لا على طرفها.
     *
     * @return list<CarbonImmutable>
     */
    public static function dayDates(string $date, int $days = 1): array
    {
        $start = CarbonImmutable::parse($date)->startOfDay();

        return array_map(fn (int $i) => $start->addDays($i), range(0, self::days($days) - 1));
    }

    /**
     * عدد الأيام بعد تشذيبه: يوم واحد على الأقل، ولا يتجاوز الحد الأعلى.
     */
    public static function days(?int $days): int
    {
        return max(1, min(self::MAX_DAYS, (int) ($days ?: 1)));
    }

    /**
     * تاريخ آخر يوم في المناسبة.
     */
    public static function lastDay(string $date, int $days = 1): string
    {
        return CarbonImmutable::parse($date)->startOfDay()
            ->addDays(self::days($days) - 1)
            ->toDateString();
    }

    /**
     * @return array<string, string>
     */
    public static function labels(): array
    {
        return array_map(fn ($m) => $m['label'], self::periods());
    }

    public static function label(string $period): string
    {
        return self::periods()[$period]['label'] ?? $period;
    }

    /**
     * @return list<string>
     */
    public static function keys(): array
    {
        return array_keys(self::periods());
    }

    /**
     * تمثيل جاهز للواجهة.
     *
     * @return list<array{key: string, label: string, start: string, end: string}>
     */
    public static function forView(): array
    {
        return collect(self::periods())->map(fn ($m, $key) => [
            'key' => $key,
            'label' => $m['label'],
            'start' => $m['start'],
            'end' => $m['end'],
        ])->values()->all();
    }
}
