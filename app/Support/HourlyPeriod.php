<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * حجز الشاليه بالساعات.
 *
 * الفترات الثلاث — صباحي ومسائي ويوم كامل — ساعاتها مكتوبة سلفًا وأسعارها
 * مسعَّرة في شاشة الأسعار. وهذا شكلٌ رابع: العميل يطلب من الرابعة إلى
 * التاسعة، ويُتَّفق معه على المبلغ في المكالمة نفسها. فالساعتان يكتبهما
 * الموظف لكل حجز على حدة، والمبلغ يُدخَل يدويًا لا يُقرأ من جدول.
 *
 * ولا عمود يحفظ عدد الساعات: هو الفرق بين starts_at وends_at، والمدى هو ما
 * يُبنى عليه كشف التعارض. وعمودٌ ثانٍ يُخزَّن بجواره يفتح بابًا لأن يقول
 * الحجز خمس ساعات ويقول مداه أربعًا.
 */
class HourlyPeriod
{
    /** القيمة المخزَّنة في عمود period. */
    public const PERIOD = 'hourly';

    public const LABEL = 'بالساعات';

    /**
     * أقصر حجز وأطوله.
     *
     * حارسا خطأ إدخال لا سياسة تسعير: ربع ساعة ليست حجزًا، وأربع وعشرون ساعة
     * فأكثر مبيتٌ يُباع بالليلة لا بالساعة.
     */
    public const MIN_MINUTES = 30;

    public const MAX_HOURS = 23;

    /**
     * المدى الزمني للحجز: من ساعة البداية إلى ساعة النهاية في يومه.
     *
     * والنهاية التي لا تتجاوز البداية تقع في الغد: حجزٌ من العاشرة مساءً إلى
     * الواحدة صباحًا ثلاث ساعات، لا واحدٌ وعشرون بالسالب. وهو الاستنتاج نفسه
     * الذي تجريه BookingPeriod على فتراتها، فلا تختلف قراءة الساعتين هنا عن
     * قراءتهما هناك.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public static function range(string $date, string $start, string $end): array
    {
        $day = CarbonImmutable::parse($date)->startOfDay();

        $starts = $day->setTimeFromTimeString($start);
        $ends = $day->setTimeFromTimeString($end);

        if ($ends->lessThanOrEqualTo($starts)) {
            $ends = $ends->addDay();
        }

        return [$starts, $ends];
    }

    /**
     * عدد ساعات المدى، مُقرَّبًا إلى ربع الساعة.
     *
     * الربع أدقّ وحدة تُكتب في الاتفاق: «ساعتان ونصف» تُقال، و«ساعتان وثلاث
     * دقائق» لا تُقال. والتقريب هنا للعرض والعقد؛ أما المدى المحفوظ فيبقى
     * بدقيقته كما أُدخل، وعليه وحده يُكشف التعارض.
     */
    public static function hours(CarbonInterface $starts, CarbonInterface $ends): float
    {
        $minutes = max(0, $starts->diffInMinutes($ends));

        return round($minutes / 60 * 4) / 4;
    }

    /**
     * عدد الساعات كما يُقرأ في الصف والعقد.
     *
     * العربية تصرّف العدد: ساعة، ساعتان، خمس ساعات. وكسر الساعة يُقال نصفًا
     * وربعًا لا عشريًّا — «ساعتان ونصف» لا «2.5 ساعة».
     */
    public static function label(float $hours): string
    {
        $whole = (int) floor($hours);
        $fraction = round($hours - $whole, 2);

        $fractionLabel = match ($fraction) {
            0.25 => 'ربع',
            0.5 => 'نصف',
            0.75 => 'ثلاثة أرباع',
            default => null,
        };

        $wholeLabel = match (true) {
            $whole === 0 => null,
            $whole === 1 => 'ساعة',
            $whole === 2 => 'ساعتان',
            $whole <= 10 => "{$whole} ساعات",
            default => "{$whole} ساعة",
        };

        // الكسر وحده: «نصف ساعة» لا «نصف» مبتورةً من موصوفها.
        if ($wholeLabel === null) {
            return $fractionLabel === null ? 'أقل من ساعة' : "{$fractionLabel} ساعة";
        }

        return $fractionLabel === null ? $wholeLabel : "{$wholeLabel} و{$fractionLabel}";
    }

    /**
     * الساعة بصيغة 12 ساعة كما تُقرأ عربيًّا — «٤:٠٠ م».
     */
    public static function time(CarbonInterface $at): string
    {
        $hour = (int) $at->format('G');
        $shown = $hour % 12 === 0 ? 12 : $hour % 12;

        return $shown.':'.$at->format('i').' '.($hour < 12 ? 'ص' : 'م');
    }
}
