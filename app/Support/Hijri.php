<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use IntlDateFormatter;
use Throwable;

/**
 * The Umm al-Qura date printed beside the Gregorian one, as the rental form
 * asks for it. Degrades to an empty string: intl is not on every host.
 */
class Hijri
{
    /** Umm al-Qura with Latin digits — the form is filled in Latin numerals. */
    private const LOCALE = 'ar-SA-u-ca-islamic-umalqura-nu-latn';

    /** 1448/03/11 هـ */
    public static function short(?string $date): string
    {
        $formatted = self::format($date, 'yyyy/MM/dd');

        return $formatted === '' ? '' : $formatted.' هـ';
    }

    /** 11 ربيع الأول 1448 هـ */
    public static function long(?string $date): string
    {
        $formatted = self::format($date, 'd MMMM yyyy');

        return $formatted === '' ? '' : $formatted.' هـ';
    }

    private static function format(?string $date, string $pattern): string
    {
        if (blank($date) || ! class_exists(IntlDateFormatter::class)) {
            return '';
        }

        try {
            // Read at noon: a timezone shift at midnight rolls the Hijri day back
            // one, printing a date that contradicts the Gregorian beside it.
            $moment = CarbonImmutable::parse(substr($date, 0, 10).' 12:00:00', 'UTC');

            $formatter = new IntlDateFormatter(
                self::LOCALE,
                IntlDateFormatter::NONE,
                IntlDateFormatter::NONE,
                'UTC',
                IntlDateFormatter::TRADITIONAL,
                $pattern,
            );

            return (string) $formatter->format($moment);
        } catch (Throwable) {
            return '';
        }
    }
}
