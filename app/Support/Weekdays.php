<?php

namespace App\Support;

use App\Services\BookingPricing;

/**
 * أيام الأسبوع بترقيم Carbon (0 الأحد … 6 السبت).
 *
 * وُضعت هنا لأن التسعيرة صارت تُدخَل يومًا يومًا، فاحتاجت الواجهة أسماء الأيام
 * ورقم كل يوم من مصدر واحد؛ وتكرارها في الواجهة يفتح باب اختلاف الترقيم بين
 * ما يُعرَض وما يُخزَّن، وهو خطأ صامت يظهر متأخرًا في فاتورة عميل.
 */
class Weekdays
{
    /** @var array<int, string> */
    public const NAMES = [
        0 => 'الأحد',
        1 => 'الاثنين',
        2 => 'الثلاثاء',
        3 => 'الأربعاء',
        4 => 'الخميس',
        5 => 'الجمعة',
        6 => 'السبت',
    ];

    /**
     * @return list<int>
     */
    public static function keys(): array
    {
        return array_keys(self::NAMES);
    }

    public static function label(int $day): string
    {
        return self::NAMES[$day] ?? (string) $day;
    }

    /**
     * تمثيل جاهز للواجهة — مع وسم أيام نهاية الأسبوع لتمييزها بصريًا في جدول
     * الأسعار، فيبقى تعريف نهاية الأسبوع في الإعداد لا في نسختين متباعدتين.
     *
     * @return list<array{key: int, label: string, is_weekend: bool}>
     */
    public static function forView(): array
    {
        $weekend = BookingPricing::weekendDays();

        return array_map(fn (int $day) => [
            'key' => $day,
            'label' => self::label($day),
            'is_weekend' => in_array($day, $weekend, true),
        ], self::keys());
    }
}
