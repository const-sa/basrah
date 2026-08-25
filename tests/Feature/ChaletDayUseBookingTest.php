<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Client;
use App\Models\Role;
use App\Models\Unit;
use App\Models\UnitPrice;
use App\Models\User;
use App\Support\BookingPeriod;
use App\Support\StayPeriod;
use Database\Seeders\AccountsSeeder;
use Database\Seeders\BookingSetupSeeder;
use Database\Seeders\DepartmentsSeeder;
use Database\Seeders\FacilitiesSeeder;
use Database\Seeders\RolesSeeder;
use Database\Seeders\UnitsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Selling a chalet by the day rather than the night.
 *
 * A chalet stays a night-by-night stay by default. Pricing a day period is
 * what opens it for a morning, an evening or a full day — and both shapes
 * reduce to the same time range, so they block each other.
 */
class ChaletDayUseBookingTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Unit $chalet;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([
            RolesSeeder::class, FacilitiesSeeder::class, DepartmentsSeeder::class,
            UnitsSeeder::class, BookingSetupSeeder::class, AccountsSeeder::class,
        ]);

        $this->owner = User::factory()->create([
            'role_id' => Role::where('slug', 'super-admin')->value('id'),
            'is_active' => true,
            'has_all_units' => true,
        ]);

        $this->chalet = $this->chaletLetWhole();
    }

    private function priceDayPeriod(string $period, float $amount = 400): void
    {
        UnitPrice::updateOrCreate(
            ['unit_id' => $this->chalet->id, 'unit_section_id' => null, 'period' => $period],
            ['weekday_price' => $amount, 'weekend_price' => $amount, 'is_active' => true],
        );
    }

    /** @return array<string, mixed> */
    private function dayUsePayload(string $period, string $date, int $days = 1): array
    {
        return [
            'unit_id' => $this->chalet->id,
            'client_id' => Client::first()?->id,
            'scope' => 'whole',
            'booking_date' => $date,
            'period' => $period,
            'days_count' => $days,
        ];
    }

    // ── التسعير ──────────────────────────────────────────────

    public function test_the_pricing_screen_accepts_day_periods_for_a_chalet(): void
    {
        $this->actingAs($this->owner)
            ->put("/admin/units/{$this->chalet->id}/prices", [
                'prices' => [
                    ['unit_section_id' => null, 'period' => 'full_day', 'weekday_price' => 500, 'weekend_price' => 700],
                    ['unit_section_id' => null, 'period' => 'morning', 'weekday_price' => 250, 'weekend_price' => 300],
                ],
            ])
            ->assertRedirect();

        $this->assertSame(2, UnitPrice::where('unit_id', $this->chalet->id)
            ->whereIn('period', ['full_day', 'morning'])->count());
    }

    public function test_the_pricing_screen_stores_the_deposit(): void
    {
        $this->actingAs($this->owner)
            ->put("/admin/units/{$this->chalet->id}/prices", [
                'prices' => [[
                    'unit_section_id' => null,
                    'period' => StayPeriod::PERIOD,
                    'weekday_price' => 600,
                    'weekend_price' => 800,
                    'deposit_percent' => 30,
                    'deposit_amount' => '',
                ]],
            ])
            ->assertRedirect();

        $price = UnitPrice::where('unit_id', $this->chalet->id)
            ->where('period', StayPeriod::PERIOD)->whereNull('unit_section_id')->firstOrFail();

        $this->assertNull($price->deposit_amount, 'an empty box must stay unset, not become zero');
        $this->assertEqualsWithDelta(30, (float) $price->deposit_percent, 0.01);
        $this->assertEqualsWithDelta(300, $price->depositFor(1000), 0.01);
    }

    // ── الحجز النهاري ────────────────────────────────────────

    public function test_a_priced_day_period_is_offered_and_bookable(): void
    {
        $this->priceDayPeriod('full_day', 450);

        $this->assertSame(['full_day'], $this->chalet->fresh()->dayUsePeriods());

        $this->actingAs($this->owner)
            ->post('/admin/bookings/chalets', $this->dayUsePayload('full_day', '2026-10-05'))
            ->assertRedirect();

        $booking = Booking::latest('id')->firstOrFail();

        $this->assertSame('full_day', $booking->period);
        $this->assertSame(1, $booking->days_count);
        $this->assertNull($booking->nights, 'a day-use booking is not measured in nights');
        $this->assertNull($booking->check_out_date, 'a day-use booking ends inside its own day');
        $this->assertEqualsWithDelta(450, (float) $booking->total_amount, 0.01);
    }

    /**
     * The form keeps a check-out date in state while the day-use fields are
     * shown, so one can arrive stale. It must be ignored, not validated —
     * failing here blocks the save against a field the form does not render,
     * which reads to the user as the save button doing nothing.
     */
    public function test_a_stale_check_out_date_is_ignored_on_a_day_use_booking(): void
    {
        $this->priceDayPeriod('full_day', 450);

        $this->actingAs($this->owner)
            ->post('/admin/bookings/chalets', [
                ...$this->dayUsePayload('full_day', '2026-10-20'),
                // Before the booking date — would fail "after:booking_date".
                'check_out_date' => '2026-10-19',
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $booking = Booking::latest('id')->firstOrFail();

        $this->assertSame('full_day', $booking->period);
        $this->assertNull($booking->check_out_date);
    }

    public function test_a_stay_still_requires_a_valid_check_out_date(): void
    {
        $this->actingAs($this->owner)
            ->post('/admin/bookings/chalets', [
                'unit_id' => $this->chalet->id,
                'scope' => 'whole',
                'booking_date' => '2026-10-20',
                'check_out_date' => '2026-10-19',
            ])
            ->assertSessionHasErrors('check_out_date');
    }

    public function test_an_unpriced_period_is_refused(): void
    {
        // Only full_day is priced, so evening must not be sellable.
        $this->priceDayPeriod('full_day', 450);

        $this->actingAs($this->owner)
            ->post('/admin/bookings/chalets', $this->dayUsePayload('evening', '2026-10-06'))
            ->assertSessionHasErrors('period');

        $this->assertSame(0, Booking::where('period', 'evening')->count());
    }

    public function test_a_multi_day_day_use_booking_prices_every_day(): void
    {
        $this->priceDayPeriod('morning', 200);

        $this->actingAs($this->owner)
            ->post('/admin/bookings/chalets', $this->dayUsePayload('morning', '2026-10-12', 3))
            ->assertRedirect();

        $booking = Booking::latest('id')->firstOrFail();

        $this->assertSame(3, $booking->days_count);
        $this->assertEqualsWithDelta(600, (float) $booking->total_amount, 0.01);
    }

    // ── التعارض بين الشكلين ──────────────────────────────────

    public function test_a_day_use_booking_blocks_a_stay_that_covers_it(): void
    {
        $this->priceDayPeriod('full_day', 450);

        $this->actingAs($this->owner)
            ->post('/admin/bookings/chalets', $this->dayUsePayload('full_day', '2026-11-10'))
            ->assertRedirect();

        // The stay covers the night of the 10th, which the full-day booking holds.
        $this->actingAs($this->owner)
            ->post('/admin/bookings/chalets', [
                'unit_id' => $this->chalet->id,
                'scope' => 'whole',
                'booking_date' => '2026-11-10',
                'check_out_date' => '2026-11-12',
            ])
            ->assertSessionHasErrors('availability');

        $this->assertSame(1, Booking::where('unit_id', $this->chalet->id)->count());
    }

    public function test_a_stay_blocks_a_day_use_booking_inside_it(): void
    {
        $this->priceDayPeriod('full_day', 450);

        $this->actingAs($this->owner)
            ->post('/admin/bookings/chalets', [
                'unit_id' => $this->chalet->id,
                'scope' => 'whole',
                'booking_date' => '2026-12-01',
                'check_out_date' => '2026-12-04',
            ])
            ->assertRedirect();

        $this->actingAs($this->owner)
            ->post('/admin/bookings/chalets', $this->dayUsePayload('full_day', '2026-12-02'))
            ->assertSessionHasErrors('availability');
    }

    public function test_the_stay_path_is_untouched(): void
    {
        $this->actingAs($this->owner)
            ->post('/admin/bookings/chalets', [
                'unit_id' => $this->chalet->id,
                'scope' => 'whole',
                'booking_date' => '2027-01-05',
                'check_out_date' => '2027-01-08',
            ])
            ->assertRedirect();

        $booking = Booking::latest('id')->firstOrFail();

        $this->assertSame(StayPeriod::PERIOD, $booking->period);
        $this->assertSame(3, $booking->nights);
        $this->assertNull($booking->days_count, 'nights and days_count never combine');
    }

    // ── الظهور في الشاشات ────────────────────────────────────

    /**
     * A day-use chalet booking must appear on the chalets list. It used to be
     * filtered out by stays() while the halls list excluded it by unit type,
     * so it was visible on no screen at all.
     */
    public function test_a_day_use_booking_appears_in_the_chalet_list(): void
    {
        $this->priceDayPeriod('evening', 300);

        $this->actingAs($this->owner)
            ->post('/admin/bookings/chalets', $this->dayUsePayload('evening', '2026-09-15'))
            ->assertRedirect();

        $reference = Booking::latest('id')->value('reference');

        $this->actingAs($this->owner)
            ->get('/admin/bookings/chalets')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('bookings.data.0.reference', $reference)
                ->where('bookings.data.0.period', 'evening')
                ->where('bookings.data.0.schedule_label', 'مسائي'));
    }

    public function test_a_day_use_booking_does_not_leak_into_the_hall_list(): void
    {
        $this->priceDayPeriod('evening', 300);

        $this->actingAs($this->owner)
            ->post('/admin/bookings/chalets', $this->dayUsePayload('evening', '2026-09-16'))
            ->assertRedirect();

        $this->actingAs($this->owner)
            ->get('/admin/bookings/halls')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('bookings.data', []));
    }

    /**
     * A morning period closes at 17:00 the same day, so deriving its last day
     * from ends_at the way a stay does would place it before its own start
     * and drop the bar from the calendar entirely.
     */
    public function test_a_morning_booking_is_drawn_on_the_chalet_calendar(): void
    {
        $this->priceDayPeriod('morning', 200);

        $this->actingAs($this->owner)
            ->post('/admin/bookings/chalets', $this->dayUsePayload('morning', '2026-09-17'))
            ->assertRedirect();

        $this->actingAs($this->owner)
            ->get('/admin/calendar/chalets?month=2026-09')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('bookings.0.check_in', '2026-09-17')
                ->where('bookings.0.check_out', '2026-09-17')
                ->where('bookings.0.span', 1));
    }

    // ── نقطة الاقتباس ────────────────────────────────────────

    public function test_the_quote_endpoint_prices_a_day_use_booking(): void
    {
        $this->priceDayPeriod('evening', 320);

        $this->actingAs($this->owner)
            ->postJson('/admin/bookings/chalets/quote', [
                'unit_id' => $this->chalet->id,
                'scope' => 'whole',
                'booking_date' => '2027-02-02',
                'period' => 'evening',
                'days_count' => 2,
            ])
            ->assertOk()
            ->assertJsonPath('availability.ok', true)
            ->assertJsonPath('pricing.total_amount', 640);
    }

    public function test_the_quote_endpoint_explains_an_unpriced_period(): void
    {
        $this->actingAs($this->owner)
            ->postJson('/admin/bookings/chalets/quote', [
                'unit_id' => $this->chalet->id,
                'scope' => 'whole',
                'booking_date' => '2027-02-02',
                'period' => 'morning',
            ])
            ->assertOk()
            ->assertJsonPath('availability.ok', false)
            ->assertJsonPath('pricing', null);
    }

    public function test_the_booking_form_says_which_periods_a_chalet_offers(): void
    {
        $this->priceDayPeriod('full_day', 450);

        $this->actingAs($this->owner)
            ->get('/admin/bookings/chalets/create')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where(
                'units.'.$this->chaletIndexInOptions().'.day_use_periods',
                ['full_day'],
            ));
    }

    /** Position of the chalet under test inside the units option list. */
    private function chaletIndexInOptions(): int
    {
        $ids = Unit::where('is_active', true)->where('type', 'chalet')
            ->orderBy('sort_order')->pluck('id')->all();

        return (int) array_search($this->chalet->id, $ids, true);
    }

    public function test_max_days_is_enforced(): void
    {
        $this->priceDayPeriod('full_day', 450);

        $this->actingAs($this->owner)
            ->post('/admin/bookings/chalets', $this->dayUsePayload('full_day', '2027-03-01', BookingPeriod::MAX_DAYS + 1))
            ->assertSessionHasErrors('days_count');
    }
}
