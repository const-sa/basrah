<?php

namespace Tests\Unit;

use App\Support\Accounting\SectionShares;
use PHPUnit\Framework\TestCase;

/**
 * قسمة مبلغ الحجز على الأقسام التي حُجزت.
 *
 * القاعدة التي كانت تُسقِط قسمًا بلا سعر من الدفاتر: حصته تخرج صفرًا،
 * والسطر الصفري لا يُكتب، فيُقيَّد الحجز كله على القسم الذي يليه.
 */
class SectionSharesTest extends TestCase
{
    public function test_rooms_with_prices_are_weighted_by_them(): void
    {
        $this->assertSame([300.0, 100.0], array_values(SectionShares::split(400, [300, 100])));
    }

    public function test_a_room_the_price_list_forgot_still_gets_a_share(): void
    {
        $shares = array_values(SectionShares::split(400, [0, 400]));

        // Equally, not 0 and 400 — a zero share is never written as a line,
        // and the room would vanish from the books while its neighbour
        // carried the whole booking.
        $this->assertSame([200.0, 200.0], $shares);
    }

    public function test_rooms_with_no_prices_at_all_share_equally(): void
    {
        $this->assertSame([250.0, 250.0], array_values(SectionShares::split(500, [0, 0])));
    }

    public function test_the_last_room_takes_the_remainder_so_the_shares_add_up(): void
    {
        $shares = SectionShares::split(100, [1, 1, 1]);

        $this->assertSame([33.33, 33.33, 33.34], array_values($shares));
        $this->assertSame(100.0, round(array_sum($shares), 2));
    }

    public function test_the_keys_are_kept_so_a_share_can_be_read_back_per_room(): void
    {
        $this->assertSame([7 => 300.0, 4 => 100.0], SectionShares::split(400, [7 => 300, 4 => 100]));
    }

    public function test_one_room_takes_the_whole_amount(): void
    {
        $this->assertSame([900.0], array_values(SectionShares::split(900, [0])));
    }

    public function test_nothing_let_shares_nothing(): void
    {
        $this->assertSame([], SectionShares::split(500, []));
    }
}
