<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Client;
use App\Models\Role;
use App\Models\Unit;
use App\Models\UnitPrice;
use App\Models\User;
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
 * The diary behind the booking form's calendar.
 *
 * The quote answers one chosen range; this answers a month at once so a taken
 * day can be struck out before it is picked. The two must agree — a day the
 * calendar leaves open and the quote then refuses is worse than no calendar.
 */
class ChaletAvailabilityCalendarTest extends TestCase
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

        $this->chalet = Unit::where('code', 'CH-BSR1')->firstOrFail();
    }

    private function priceDayPeriod(string $period, float $amount = 400): void
    {
        UnitPrice::updateOrCreate(
            ['unit_id' => $this->chalet->id, 'unit_section_id' => null, 'period' => $period],
            ['weekday_price' => $amount, 'weekend_price' => $amount, 'is_active' => true],
        );
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, array{stay: bool, periods: array<string, bool>}>
     */
    private function days(string $from, string $to, array $extra = []): array
    {
        $response = $this->actingAs($this->owner)->postJson('/admin/bookings/chalets/availability', [
            'unit_id' => $this->chalet->id,
            'scope' => 'whole',
            'from' => $from,
            'to' => $to,
            ...$extra,
        ]);

        $response->assertOk();

        return $response->json('days');
    }

    public function test_the_nights_a_stay_holds_come_back_closed(): void
    {
        $this->actingAs($this->owner)->post('/admin/bookings/chalets', [
            'unit_id' => $this->chalet->id,
            'scope' => 'whole',
            'booking_date' => '2027-03-10',
            'check_out_date' => '2027-03-13',
        ])->assertRedirect();

        $days = $this->days('2027-03-08', '2027-03-15');

        $this->assertTrue($days['2027-03-09']['stay'], 'the night before the arrival is untouched');
        $this->assertFalse($days['2027-03-10']['stay']);
        $this->assertFalse($days['2027-03-11']['stay']);
        $this->assertFalse($days['2027-03-12']['stay']);

        // The last day is a departure, not a night: whoever arrives that
        // afternoon finds their night free.
        $this->assertTrue(
            $days['2027-03-13']['stay'],
            'the departure day is a free night again — a stay ends at noon',
        );
    }

    public function test_a_day_use_booking_closes_its_own_period_and_leaves_the_rest(): void
    {
        $this->priceDayPeriod('morning');
        $this->priceDayPeriod('evening');

        $this->actingAs($this->owner)->post('/admin/bookings/chalets', [
            'unit_id' => $this->chalet->id,
            'client_id' => Client::first()?->id,
            'scope' => 'whole',
            'booking_date' => '2027-04-06',
            'period' => 'morning',
            'days_count' => 1,
        ])->assertRedirect();

        $days = $this->days('2027-04-05', '2027-04-07');

        $this->assertFalse($days['2027-04-06']['periods']['morning']);
        $this->assertTrue($days['2027-04-06']['periods']['evening'], 'the evening is still for sale');
        $this->assertTrue($days['2027-04-05']['periods']['morning'], 'the day before is untouched');

        // A morning runs to 17:00 and the night begins at 16:00, so the two
        // overlap by the hour that costs the night.
        $this->assertFalse(
            $days['2027-04-06']['stay'],
            'a morning booking still costs the night it sits inside',
        );
    }

    public function test_only_the_periods_this_chalet_is_priced_for_are_listed(): void
    {
        $this->priceDayPeriod('full_day');

        $days = $this->days('2027-05-01', '2027-05-02');

        $this->assertSame(['full_day'], array_keys($days['2027-05-01']['periods']));
    }

    public function test_a_chalet_that_is_sold_by_the_night_only_lists_no_periods(): void
    {
        $days = $this->days('2027-05-01', '2027-05-02');

        $this->assertSame([], $days['2027-05-01']['periods']);
        $this->assertTrue($days['2027-05-01']['stay']);
    }

    public function test_days_that_have_passed_are_closed(): void
    {
        $days = $this->days(now()->subDays(2)->toDateString(), now()->addDay()->toDateString());

        $this->assertFalse($days[now()->subDays(2)->toDateString()]['stay']);
        $this->assertFalse($days[now()->subDay()->toDateString()]['stay']);
        $this->assertTrue($days[now()->toDateString()]['stay'], 'tonight is still bookable');
    }

    /**
     * A booking being edited must not find its own nights struck out of the
     * calendar it is shown in — the form would then refuse the dates it is
     * already saved with.
     */
    public function test_the_booking_being_edited_does_not_close_its_own_nights(): void
    {
        $this->actingAs($this->owner)->post('/admin/bookings/chalets', [
            'unit_id' => $this->chalet->id,
            'scope' => 'whole',
            'booking_date' => '2027-06-10',
            'check_out_date' => '2027-06-12',
        ])->assertRedirect();

        $booking = Booking::latest('id')->firstOrFail();

        $days = $this->days('2027-06-09', '2027-06-13');
        $this->assertFalse($days['2027-06-10']['stay']);

        $ignored = $this->days('2027-06-09', '2027-06-13', ['ignore_booking_id' => $booking->id]);
        $this->assertTrue($ignored['2027-06-10']['stay']);
        $this->assertTrue($ignored['2027-06-11']['stay']);
    }

    public function test_a_stopped_chalet_closes_every_day(): void
    {
        $this->chalet->update(['is_active' => false]);

        $days = $this->days('2027-07-01', '2027-07-03');

        $this->assertFalse($days['2027-07-01']['stay']);
        $this->assertFalse($days['2027-07-02']['stay']);
    }

    /**
     * The window is capped so a hand-made request cannot ask for years of
     * diary in one go. Asking for more returns the cap, not an error.
     */
    public function test_the_window_is_capped(): void
    {
        $days = $this->days('2027-08-01', '2028-08-01');

        $this->assertCount(92, $days);
        $this->assertArrayHasKey('2027-10-31', $days);
        $this->assertArrayNotHasKey('2027-11-01', $days);
    }

    public function test_the_calendar_agrees_with_the_quote(): void
    {
        $this->actingAs($this->owner)->post('/admin/bookings/chalets', [
            'unit_id' => $this->chalet->id,
            'scope' => 'whole',
            'booking_date' => '2027-09-14',
            'check_out_date' => '2027-09-16',
        ])->assertRedirect();

        $days = $this->days('2027-09-13', '2027-09-17');

        foreach (['2027-09-13', '2027-09-14', '2027-09-15', '2027-09-16'] as $date) {
            $quote = $this->actingAs($this->owner)->postJson('/admin/bookings/chalets/quote', [
                'unit_id' => $this->chalet->id,
                'scope' => 'whole',
                'booking_date' => $date,
                'period' => StayPeriod::PERIOD,
                'check_out_date' => date('Y-m-d', strtotime($date.' +1 day')),
            ]);

            $this->assertSame(
                $days[$date]['stay'],
                $quote->json('availability.ok'),
                "the calendar and the quote disagree about {$date}",
            );
        }
    }
}
