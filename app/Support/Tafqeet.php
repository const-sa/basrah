<?php

namespace App\Support;

/**
 * تفقيط المبالغ — كتابة الرقم حروفًا بالعربية.
 *
 * سند القبض يكتب المبلغ رقمًا وحروفًا معًا: الحروف هي ما يمنع تعديل الرقم
 * بعد التوقيع، ولذلك يظلّ التفقيط جزءًا من السند لا زينةً فيه.
 */
class Tafqeet
{
    /** من صفر إلى تسعة عشر — ما دون العشرين لفظٌ واحد لا يُركَّب. */
    private const ONES = [
        '', 'واحد', 'اثنان', 'ثلاثة', 'أربعة', 'خمسة', 'ستة', 'سبعة', 'ثمانية', 'تسعة',
        'عشرة', 'أحد عشر', 'اثنا عشر', 'ثلاثة عشر', 'أربعة عشر', 'خمسة عشر',
        'ستة عشر', 'سبعة عشر', 'ثمانية عشر', 'تسعة عشر',
    ];

    private const TENS = ['', '', 'عشرون', 'ثلاثون', 'أربعون', 'خمسون', 'ستون', 'سبعون', 'ثمانون', 'تسعون'];

    private const HUNDREDS = [
        '', 'مائة', 'مائتان', 'ثلاثمائة', 'أربعمائة', 'خمسمائة',
        'ستمائة', 'سبعمائة', 'ثمانمائة', 'تسعمائة',
    ];

    /**
     * مراتب الآلاف: لكل مرتبة مفرد ومثنى وجمع، لأن العربية تُغيّر اللفظ
     * بالعدد لا بالموضع — «ألف» و«ألفان» و«ثلاثة آلاف».
     */
    private const SCALES = [
        1 => ['ألف', 'ألفان', 'آلاف'],
        2 => ['مليون', 'مليونان', 'ملايين'],
        3 => ['مليار', 'ملياران', 'مليارات'],
    ];

    /**
     * تفقيط مبلغ بعملته وكسوره: «فقط ألف ومائتان ريال سعودي لا غير».
     */
    public static function money(float $amount, string $currency = 'ريال سعودي', string $fraction = 'هللة'): string
    {
        $amount = round(abs($amount), 2);
        $whole = (int) floor($amount);
        $cents = (int) round(($amount - $whole) * 100);

        $words = self::integer($whole).' '.$currency;

        if ($cents > 0) {
            $words .= ' و'.self::integer($cents).' '.$fraction;
        }

        return 'فقط '.$words.' لا غير';
    }

    /**
     * تفقيط عدد صحيح بلا عملة.
     */
    public static function integer(int $number): string
    {
        if ($number <= 0) {
            return 'صفر';
        }

        // تُقسَّم الأرقام إلى مجموعات ثلاثية من اليمين، فكل مرتبة آلاف
        // تُقرأ وحدها ثم تُعطف على ما قبلها.
        $groups = [];

        while ($number > 0) {
            $groups[] = $number % 1000;
            $number = intdiv($number, 1000);
        }

        $parts = [];

        for ($scale = count($groups) - 1; $scale >= 0; $scale--) {
            if ($groups[$scale] === 0) {
                continue;
            }

            $parts[] = $scale === 0
                ? self::threeDigits($groups[$scale])
                : self::scaled($groups[$scale], $scale);
        }

        return implode(' و', $parts);
    }

    /**
     * مجموعة ثلاثية واحدة (0-999).
     *
     * @param  bool  $beforeScale  هل يليها اسم مرتبة؟ «مائتا ألف» لا «مائتان ألف».
     */
    private static function threeDigits(int $number, bool $beforeScale = false): string
    {
        $parts = [];
        $hundreds = intdiv($number, 100);
        $rest = $number % 100;

        if ($hundreds > 0) {
            $parts[] = $beforeScale && $hundreds === 2 && $rest === 0
                ? 'مائتا'
                : self::HUNDREDS[$hundreds];
        }

        if ($rest > 0 && $rest < 20) {
            $parts[] = self::ONES[$rest];
        } elseif ($rest >= 20) {
            $units = $rest % 10;
            $tens = self::TENS[intdiv($rest, 10)];
            $parts[] = $units > 0 ? self::ONES[$units].' و'.$tens : $tens;
        }

        return implode(' و', $parts);
    }

    /**
     * مجموعة ثلاثية مع اسم مرتبتها — «ألفان»، «ثلاثة آلاف»، «خمسة عشر ألفًا».
     */
    private static function scaled(int $value, int $scale): string
    {
        if (! isset(self::SCALES[$scale])) {
            return self::threeDigits($value);
        }

        [$single, $double, $plural] = self::SCALES[$scale];

        // التمييز يتبع آخر جزء من العدد لا العدد كله: «مائة وخمسة آلاف»
        // لأن الخمسة هي التي تحكم، بينما «مائة ألف» و«خمسة وعشرون ألف».
        $governing = $value % 100;

        return match (true) {
            $value === 1 => $single,
            $value === 2 => $double,
            $governing >= 3 && $governing <= 10 => self::threeDigits($value).' '.$plural,
            default => self::threeDigits($value, true).' '.$single,
        };
    }
}
