<?php

namespace App\Support;

/**
 * شروط العقد مقسومةً بنودًا — مقدّمةٌ ثم «1 ـ … 2 ـ …».
 *
 * الشروط تُحفظ نصًّا واحدًا متصلًا في أغلب القوالب، فتُطبع كتلةً لا يُعرف
 * فيها أين ينتهي بندٌ ويبدأ غيره. والتقسيم يتبع الترقيم بالتسلسل: يُطلب
 * «1» ثم «2» بعده ثم «3»، فلا يُحسب رقمٌ داخل البند — «300.00 ريال» أو
 * «الساعة 12» — بدايةَ بندٍ جديد.
 */
class TermsClauses
{
    /**
     * @return array{intro: ?string, clauses: list<array{number: int, text: string}>}
     */
    public static function split(?string $terms): array
    {
        $text = trim((string) $terms);
        $empty = ['intro' => $text === '' ? null : $text, 'clauses' => []];

        if ($text === '') {
            return $empty;
        }

        $marks = [];
        $offset = 0;

        for ($n = 1; $n <= 99; $n++) {
            // The number, then its separator: «1 ـ»، «1.»، «1-»، «1)».
            if (! preg_match('/(?<=^|\s)'.$n.'\s*[.ـ\-–)]\s*(?!\d)/u', $text, $m, PREG_OFFSET_CAPTURE, $offset)) {
                break;
            }

            $marks[] = ['number' => $n, 'at' => $m[0][1], 'body' => $m[0][1] + strlen($m[0][0])];
            $offset = $m[0][1] + strlen($m[0][0]);
        }

        // One number is not a list — «1» may be a count in the wording.
        if (count($marks) < 2) {
            return $empty;
        }

        $clauses = [];

        foreach ($marks as $i => $mark) {
            $end = $marks[$i + 1]['at'] ?? strlen($text);
            $clauses[] = [
                'number' => $mark['number'],
                'text' => self::clean(substr($text, $mark['body'], $end - $mark['body'])),
            ];
        }

        return [
            'intro' => self::clean(substr($text, 0, $marks[0]['at'])) ?: null,
            'clauses' => $clauses,
        ];
    }

    private static function clean(string $text): string
    {
        return trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
    }
}
