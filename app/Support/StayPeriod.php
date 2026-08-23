<?php

namespace App\Support;

use Carbon\CarbonImmutable;

/**
 * إقامة الشاليه: تاريخ دخول وتاريخ خروج وعدد ليالٍ.
 *
 * لماذا فئة مستقلة عن BookingPeriod؟ لأن القاعة تُحجز داخل يوم واحد وفترته
 * معلومة مسبقًا (صباحي/مسائي/يوم كامل)، بينما الشاليه يمتد ليالي متتالية
 * بمدى مفتوح. حشر الحالتين في فئة واحدة يجعل «الفترة» تعني شيئين مختلفين،
 * وهو أصل الالتباس الذي دفع إلى فصل الشاشتين أصلًا.
 *
 * المدى الزمني الناتج (starts_at → ends_at) هو ما يُفحص عليه التعارض، تمامًا
 * كما في القاعة، فيبقى كشف التعارض عملية واحدة لا اثنتين.
 */
class StayPeriod
{
    /**
     * الفترة المخزَّنة في عمود period لكل حجز شاليه.
     * قيمة موجودة أصلًا في enum الجدول وفي تسعيرة الوحدات.
     */
    public const PERIOD = 'overnight';

    /** Label for the overnight row in the pricing screen. */
    public const LABEL = 'الليلة';

    public static function checkInTime(): string
    {
        return app(BookingTimes::class)->stay()['check_in'];
    }

    public static function checkOutTime(): string
    {
        return app(BookingTimes::class)->stay()['check_out'];
    }

    public static function maxNights(): int
    {
        return app(BookingTimes::class)->stay()['max_nights'];
    }

    /**
     * عدد الليالي بين تاريخين — الخروج غير محسوب ليلةً.
     */
    public static function nights(string $checkIn, string $checkOut): int
    {
        return CarbonImmutable::parse($checkIn)->startOfDay()
            ->diffInDays(CarbonImmutable::parse($checkOut)->startOfDay());
    }

    /**
     * المدى الزمني الفعلي للإقامة: من ساعة الدخول يوم الوصول
     * إلى ساعة الخروج يوم المغادرة.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public static function range(string $checkIn, string $checkOut): array
    {
        $starts = CarbonImmutable::parse($checkIn)->startOfDay()
            ->setTimeFromTimeString(self::checkInTime());

        $ends = CarbonImmutable::parse($checkOut)->startOfDay()
            ->setTimeFromTimeString(self::checkOutTime());

        return [$starts, $ends];
    }

    /**
     * ليالي الإقامة كتواريخ — كل ليلة تُسعَّر على حدة لأن نهاية الأسبوع
     * والموسم قد يقعان داخل الإقامة لا على طرفها.
     *
     * @return list<CarbonImmutable>
     */
    public static function nightDates(string $checkIn, string $checkOut): array
    {
        $start = CarbonImmutable::parse($checkIn)->startOfDay();
        $count = self::nights($checkIn, $checkOut);

        $dates = [];
        for ($i = 0; $i < $count; $i++) {
            $dates[] = $start->addDays($i);
        }

        return $dates;
    }

    /**
     * تمثيل جاهز للواجهة.
     *
     * @return array{check_in_time: string, check_out_time: string, max_nights: int}
     */
    public static function forView(): array
    {
        return [
            'check_in_time' => self::checkInTime(),
            'check_out_time' => self::checkOutTime(),
            'max_nights' => self::maxNights(),
        ];
    }

    /**
     * Pricing periods a chalet may carry, in the same shape as
     * BookingPeriod::forView() so the pricing screen reads both unit types
     * without branching.
     *
     * The night comes first because it is what a chalet is normally sold as.
     * The three day periods follow so the same chalet can also be sold for a
     * morning, an evening, or a full day without an overnight stay — pricing
     * a period here is what makes it bookable, see Unit::dayUsePeriods().
     *
     * @return list<array{key: string, label: string, start: string, end: string}>
     */
    public static function pricingPeriods(): array
    {
        return [
            [
                'key' => self::PERIOD,
                'label' => self::LABEL,
                'start' => self::checkInTime(),
                'end' => self::checkOutTime(),
            ],
            ...BookingPeriod::forView(),
        ];
    }

    /**
     * Keys of the periods above — what the pricing endpoint accepts.
     *
     * @return list<string>
     */
    public static function pricingKeys(): array
    {
        return array_column(self::pricingPeriods(), 'key');
    }
}
