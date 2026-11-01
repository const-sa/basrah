<?php

namespace App\Support\Accounting;

/**
 * How one amount is shared between the sections that were let.
 *
 * Kept in one place because three callers have to agree to the halala: the
 * revenue credit as it is posted, the backfill that re-attributes what was
 * posted before, and the agreed price as it is frozen onto the booking.
 */
final class SectionShares
{
    /**
     * Weighted by price only when every section carries one — otherwise shared
     * equally.
     *
     * A section let at no price is a gap in the price list, not an agreement.
     * Weighting by it gives that section a zero share, and a zero line is never
     * written — so the section vanishes from the books while its neighbour is
     * credited the whole booking.
     *
     * The last key takes the remainder rather than its own rounded share, so
     * the shares add up to the amount exactly and the entry stays balanced.
     *
     * @param  array<array-key, float>  $weights  section key => the price it was let at
     * @return array<array-key, float> the same keys, in the same order
     */
    public static function split(float $amount, array $weights): array
    {
        if ($weights === []) {
            return [];
        }

        $amount = round($amount, 2);
        $count = count($weights);
        $prices = array_map(fn ($weight) => round((float) $weight, 2), $weights);
        $weighted = min($prices) > 0;
        $base = array_sum($prices);

        $shares = [];
        $left = $amount;
        $seen = 0;

        foreach ($weights as $key => $ignored) {
            $shares[$key] = ++$seen === $count
                ? round($left, 2)
                : round($weighted ? $amount * $prices[$key] / $base : $amount / $count, 2);

            $left = round($left - $shares[$key], 2);
        }

        return $shares;
    }
}
