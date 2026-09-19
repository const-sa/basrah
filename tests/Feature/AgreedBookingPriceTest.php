<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Client;
use App\Models\EventType;
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
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * A booking is let at the price agreed with its client.
 *
 * The table and the event type set what a unit is normally sold for, but one
 * client takes the hall at one price and the next at another. The clerk used
 * to have no way to say so: the amount only moved by adding an event type, or
 * by faking it with a discount that then printed on the contract as a discount.
 *
 * So the amount can be written on the booking itself. Written, it is the price
 * — for the hall, for the stay and for the day — and left blank the booking is
 * priced the way it always was.
 */
class AgreedBookingPriceTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Unit $hall;

    private Unit $chalet;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();

        $this->seed([
            RolesSeeder::class, FacilitiesSeeder::class, DepartmentsSeeder::class,
            UnitsSeeder::class, BookingSetupSeeder::class, AccountsSeeder::class,
        ]);

        $this->owner = User::factory()->create([
            'role_id' => Role::where('slug', 'super-admin')->value('id'),
            'is_active' => true,
            'has_all_units' => true,
        ]);

        $this->hall = Unit::where('type', 'hall')->firstOrFail();
        $this->chalet = $this->chaletLetWhole();
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function hallPayload(array $overrides = []): array
    {
        return [
            'unit_id' => $this->hall->id,
            'client_id' => Client::first()?->id,
            'scope' => 'whole',
            'booking_date' => '2026-11-05',
            'period' => 'full_day',
            'status' => 'deposit_paid',
            ...$overrides,
        ];
    }

    // ── القاعات ──────────────────────────────────────────────

    public function test_a_hall_is_let_at_the_agreed_price_with_no_event_type_chosen(): void
    {
        $this->actingAs($this->owner)
            ->post('/admin/bookings/halls', $this->hallPayload(['agreed_amount' => 3500]))
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $booking = Booking::latest('id')->firstOrFail();

        $this->assertEqualsWithDelta(3500, (float) $booking->agreed_amount, 0.01);
        $this->assertEqualsWithDelta(3500, (float) $booking->base_amount, 0.01);
        $this->assertEqualsWithDelta(3500, (float) $booking->total_amount, 0.01);
        $this->assertNull($booking->event_type_id);
    }

    /** The agreed price outranks the event type's — it is the price. */
    public function test_the_agreed_price_stands_in_for_the_event_types_price(): void
    {
        $type = EventType::create(['unit_id' => $this->hall->id, 'name' => 'زواج', 'color' => 'rose', 'price' => 15000]);

        $this->actingAs($this->owner)
            ->post('/admin/bookings/halls', $this->hallPayload([
                'event_type_id' => $type->id,
                'agreed_amount' => 9000,
            ]))
            ->assertSessionHasNoErrors();

        $booking = Booking::latest('id')->firstOrFail();

        $this->assertEqualsWithDelta(9000, (float) $booking->base_amount, 0.01);
        $this->assertSame($type->id, $booking->event_type_id, 'the occasion is still recorded, only its price is not used');
    }

    public function test_a_booking_with_no_agreed_price_is_still_priced_from_the_table(): void
    {
        $quoted = $this->actingAs($this->owner)
            ->postJson('/admin/bookings/halls/quote', $this->hallPayload())
            ->assertOk()
            ->json('pricing');

        $this->actingAs($this->owner)->post('/admin/bookings/halls', $this->hallPayload());

        $booking = Booking::latest('id')->firstOrFail();

        $this->assertFalse($quoted['priced_by_agreement']);
        $this->assertNull($booking->agreed_amount);
        $this->assertEqualsWithDelta((float) $quoted['base_amount'], (float) $booking->base_amount, 0.01);
    }

    public function test_the_screen_is_quoted_the_agreed_price_before_it_is_saved(): void
    {
        $quoted = $this->actingAs($this->owner)
            ->postJson('/admin/bookings/halls/quote', $this->hallPayload(['agreed_amount' => 2750]))
            ->assertOk()
            ->json('pricing');

        $this->assertTrue($quoted['priced_by_agreement']);
        $this->assertFalse($quoted['priced_by_event']);
        $this->assertEqualsWithDelta(2750, (float) $quoted['base_amount'], 0.01);
        $this->assertEqualsWithDelta(2750, (float) $quoted['total_amount'], 0.01);
    }

    /** The agreed price is the booking's, not one day's of it. */
    public function test_the_agreed_price_covers_every_day_of_the_occasion(): void
    {
        $this->actingAs($this->owner)
            ->post('/admin/bookings/halls', $this->hallPayload([
                'days_count' => 3,
                'agreed_amount' => 6000,
            ]))
            ->assertSessionHasNoErrors();

        $booking = Booking::latest('id')->firstOrFail();

        $this->assertSame(3, $booking->days_count);
        $this->assertEqualsWithDelta(6000, (float) $booking->base_amount, 0.01);
    }

    /**
     * An edit keeps the agreement unless it is being changed: moving a date
     * must not put back the table price the clerk had overridden.
     */
    public function test_an_edit_that_says_nothing_about_the_price_keeps_the_agreement(): void
    {
        $this->actingAs($this->owner)
            ->post('/admin/bookings/halls', $this->hallPayload(['agreed_amount' => 3500]));

        $booking = Booking::latest('id')->firstOrFail();

        $this->actingAs($this->owner)
            ->put("/admin/bookings/halls/{$booking->id}", $this->hallPayload(['booking_date' => '2026-11-09']))
            ->assertSessionHasNoErrors();

        $booking->refresh();

        $this->assertEqualsWithDelta(3500, (float) $booking->agreed_amount, 0.01);
        $this->assertEqualsWithDelta(3500, (float) $booking->base_amount, 0.01);
    }

    public function test_clearing_the_agreement_prices_the_booking_from_the_table_again(): void
    {
        $this->actingAs($this->owner)
            ->post('/admin/bookings/halls', $this->hallPayload(['agreed_amount' => 3500]));

        $booking = Booking::latest('id')->firstOrFail();

        $this->actingAs($this->owner)
            ->put("/admin/bookings/halls/{$booking->id}", $this->hallPayload(['agreed_amount' => null]))
            ->assertSessionHasNoErrors();

        $booking->refresh();

        $this->assertNull($booking->agreed_amount);
        $this->assertNotEqualsWithDelta(3500, (float) $booking->base_amount, 0.01);
    }

    /** The edit screen opens on the agreement instead of asking for it again. */
    public function test_the_edit_screen_carries_the_agreed_price(): void
    {
        $this->actingAs($this->owner)
            ->post('/admin/bookings/halls', $this->hallPayload(['agreed_amount' => 3500]));

        $booking = Booking::latest('id')->firstOrFail();

        $this->actingAs($this->owner)
            ->get("/admin/bookings/halls/{$booking->id}/edit")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('admin/bookings/halls/Form')
                ->where('booking.agreed_amount', fn ($v) => (float) $v === 3500.0)
                ->etc());
    }

    /**
     * The lines are what links a booking to the rooms it took and prices each
     * one, so an agreed price is split across them rather than replacing them.
     */
    public function test_the_agreed_price_is_split_across_the_booked_sections(): void
    {
        $hall = Unit::where('type', 'hall')->whereHas('sections')->firstOrFail();
        $sections = $hall->sections()->orderBy('id')->take(2)->get();

        $this->assertCount(2, $sections, 'the fixture hall holds at least two sections');

        $this->actingAs($this->owner)
            ->post('/admin/bookings/halls', $this->hallPayload([
                'unit_id' => $hall->id,
                'scope' => 'sections',
                'section_ids' => $sections->pluck('id')->all(),
                'agreed_amount' => 1000,
            ]))
            ->assertSessionHasNoErrors();

        $booking = Booking::latest('id')->firstOrFail();

        $this->assertEqualsWithDelta(1000, (float) $booking->base_amount, 0.01);
        $this->assertCount(2, $booking->sections);
        $this->assertEqualsWithDelta(
            1000,
            $booking->sections->sum(fn ($s) => (float) $s->pivot->price),
            0.01,
            'what each section was let at still adds up to what was agreed',
        );
    }

    // ── الشاليهات ────────────────────────────────────────────

    /** The agreed price is the stay's, however many nights it runs. */
    public function test_a_chalet_stay_is_let_at_the_agreed_price(): void
    {
        $this->actingAs($this->owner)
            ->post('/admin/bookings/chalets', [
                'unit_id' => $this->chalet->id,
                'client_id' => Client::first()?->id,
                'scope' => 'whole',
                'booking_date' => '2026-11-10',
                'check_out_date' => '2026-11-13',
                'agreed_amount' => 900,
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $booking = Booking::latest('id')->firstOrFail();

        $this->assertSame(3, $booking->nights);
        $this->assertEqualsWithDelta(900, (float) $booking->agreed_amount, 0.01);
        $this->assertEqualsWithDelta(900, (float) $booking->base_amount, 0.01);
        $this->assertEqualsWithDelta(900, (float) $booking->total_amount, 0.01);
    }

    public function test_a_chalet_day_use_booking_is_let_at_the_agreed_price(): void
    {
        UnitPrice::updateOrCreate(
            ['unit_id' => $this->chalet->id, 'unit_section_id' => null, 'period' => 'full_day'],
            ['weekday_price' => 400, 'weekend_price' => 400, 'day_prices' => null, 'is_active' => true],
        );

        $this->actingAs($this->owner)
            ->post('/admin/bookings/chalets', [
                'unit_id' => $this->chalet->id,
                'client_id' => Client::first()?->id,
                'scope' => 'whole',
                'booking_date' => '2026-11-20',
                'period' => 'full_day',
                'days_count' => 1,
                'agreed_amount' => 250,
            ])
            ->assertSessionHasNoErrors();

        $booking = Booking::latest('id')->firstOrFail();

        $this->assertSame('full_day', $booking->period);
        $this->assertEqualsWithDelta(250, (float) $booking->base_amount, 0.01);
    }

    /** The stay quote answers with the agreed price, as the screen shows it. */
    public function test_the_stay_quote_answers_with_the_agreed_price(): void
    {
        $quoted = $this->actingAs($this->owner)
            ->postJson('/admin/bookings/chalets/quote', [
                'unit_id' => $this->chalet->id,
                'scope' => 'whole',
                'booking_date' => '2026-11-10',
                'check_out_date' => '2026-11-13',
                'period' => StayPeriod::PERIOD,
                'agreed_amount' => 900,
            ])
            ->assertOk()
            ->json('pricing');

        $this->assertTrue($quoted['priced_by_agreement']);
        $this->assertEqualsWithDelta(900, (float) $quoted['base_amount'], 0.01);
        $this->assertEqualsWithDelta(900, (float) $quoted['total_amount'], 0.01);
    }

    /** A stay left without an agreement is still summed night by night. */
    public function test_a_stay_with_no_agreement_is_still_priced_night_by_night(): void
    {
        $quoted = $this->actingAs($this->owner)
            ->postJson('/admin/bookings/chalets/quote', [
                'unit_id' => $this->chalet->id,
                'scope' => 'whole',
                'booking_date' => '2026-11-10',
                'check_out_date' => '2026-11-13',
                'period' => StayPeriod::PERIOD,
            ])
            ->assertOk()
            ->json('pricing');

        $this->assertFalse($quoted['priced_by_agreement']);
        $this->assertSame(3, $quoted['nights']);
    }
}
