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
    public const PERIODS = [
        'morning' => ['label' => 'صباحي', 'start' => '09:00', 'end' => '17:00', 'overnight' => false],
        'evening' => ['label' => 'مسائي', 'start' => '17:00', 'end' => '01:00', 'overnight' => true],
        'full_day' => ['label' => 'يوم كامل', 'start' => '09:00', 'end' => '01:00', 'overnight' => true],
    ];

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
        $meta = self::PERIODS[$period] ?? self::PERIODS['full_day'];

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
        return array_map(fn ($m) => $m['label'], self::PERIODS);
    }

    public static function label(string $period): string
    {
        return self::PERIODS[$period]['label'] ?? $period;
    }

    /**
     * @return list<string>
     */
    public static function keys(): array
    {
        return array_keys(self::PERIODS);
    }

    /**
     * تمثيل جاهز للواجهة.
     *
     * @return list<array{key: string, label: string, start: string, end: string}>
     */
    public static function forView(): array
    {
        return collect(self::PERIODS)->map(fn ($m, $key) => [
            'key' => $key,
            'label' => $m['label'],
            'start' => $m['start'],
            'end' => $m['end'],
        ])->values()->all();
    }
}
