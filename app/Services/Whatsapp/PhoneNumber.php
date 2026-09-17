<?php

namespace App\Services\Whatsapp;

/**
 * Normalises phone numbers to the international format WhatsApp gateways expect:
 * digits only, country code included, no "+" and no "00" prefix.
 */
final class PhoneNumber
{
    /**
     * Longest national (country-code-less) number we still consider "local".
     */
    private const NATIONAL_MAX_LENGTH = 9;

    /** Shorter than this and it is not a number anyone can be reached on. */
    private const MIN_LENGTH = 10;

    /** Null rather than a number too short to dial — the caller drops the send. */
    public static function normalizeOrNull($phone, ?string $countryCode = '966'): ?string
    {
        $digits = self::normalize($phone, $countryCode);

        return strlen($digits) >= self::MIN_LENGTH ? $digits : null;
    }

    /**
     * @param  string|int|null  $phone
     * @param  string|null  $countryCode  Default country code, e.g. "966". Null/empty leaves the number untouched.
     */
    public static function normalize($phone, ?string $countryCode = '966'): string
    {
        $digits = preg_replace('/\D+/', '', (string) $phone) ?? '';

        if ($digits === '') {
            return '';
        }

        // 00966... -> 966...
        if (str_starts_with($digits, '00')) {
            $digits = substr($digits, 2);
        }

        $code = preg_replace('/\D+/', '', (string) $countryCode) ?? '';

        if ($code === '') {
            return $digits;
        }

        // Already prefixed: drop a stray trunk zero, e.g. 9660512345678 -> 966512345678
        if (str_starts_with($digits, $code)) {
            $national = substr($digits, strlen($code));

            if (str_starts_with($national, '0')) {
                $national = substr($national, 1);
            }

            return $code.$national;
        }

        // Local format with trunk prefix: 0512345678 -> 966512345678
        if (str_starts_with($digits, '0')) {
            return $code.substr($digits, 1);
        }

        // Bare national number: 512345678 -> 966512345678
        if (strlen($digits) <= self::NATIONAL_MAX_LENGTH) {
            return $code.$digits;
        }

        // Long enough to already carry a foreign country code, leave it alone.
        return $digits;
    }
}
