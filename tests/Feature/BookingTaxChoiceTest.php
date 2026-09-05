<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Client;
use App\Models\Role;
use App\Models\Unit;
use App\Models\User;
use App\Support\HourlyPeriod;
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
 * A booking is invoiced with tax or without it.
 *
 * Tax used to follow the VAT switch alone: register the business and every
 * booking was priced with it, whoever the guest was. But a hall let to a
 * government body or a charity is invoiced without tax while the one beside it
 * is not, and the operator had no way to say so except to fake it with a
 * discount — which then printed on the contract as a discount.
 *
 * So the booking answers for itself, the answer is stored with it, and the
 * invoice reads it back instead of asking the settings.
 */
class BookingTaxChoiceTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Unit $hall;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();

        // The business is registered so the booking's own answer is what is under
        // test, not the system-wide switch.
        $this->registerForVat();

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
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function hallPayload(bool $taxable, array $overrides = []): array
    {
        return [
            'unit_id' => $this->hall->id,
            'client_id' => Client::first()?->id,
            'scope' => 'whole',
            'booking_date' => '2026-10-07',
            'period' => 'full_day',
            'status' => 'deposit_paid',
            'discount_amount' => 0,
            'is_taxable' => $taxable,
            ...$overrides,
        ];
    }

    public function test_a_hall_booked_with_tax_carries_it_over_the_priced_amount(): void
    {
        $this->actingAs($this->owner)
            ->post('/admin/bookings/halls', $this->hallPayload(taxable: true))
            ->assertSessionMissing('warning');

        $booking = Booking::latest('id')->firstOrFail();

        $this->assertTrue($booking->is_taxable);
        $this->assertSame(
            round($booking->netAmount() * 1.15, 2),
            (float) $booking->total_amount,
        );
        $this->assertGreaterThan(0, $booking->taxAmount());
    }

    public function test_a_hall_booked_without_tax_is_totalled_at_its_net(): void
    {
        $this->actingAs($this->owner)
            ->post('/admin/bookings/halls', $this->hallPayload(taxable: false))
            ->assertSessionMissing('warning');

        $booking = Booking::latest('id')->firstOrFail();

        $this->assertFalse($booking->is_taxable);
        $this->assertSame($booking->netAmount(), (float) $booking->total_amount);
        $this->assertSame(0.0, $booking->taxAmount());
    }

    /**
     * An edit keeps what was agreed unless it is being changed: moving a date
     * must not put back tax waived for an exempt body.
     */
    public function test_an_edit_that_says_nothing_about_tax_keeps_the_answer(): void
    {
        $this->actingAs($this->owner)
            ->post('/admin/bookings/halls', $this->hallPayload(taxable: false));

        $booking = Booking::latest('id')->firstOrFail();
        $payload = $this->hallPayload(taxable: false, overrides: ['booking_date' => '2026-10-09']);
        unset($payload['is_taxable']);

        $this->actingAs($this->owner)
            ->put("/admin/bookings/halls/{$booking->id}", $payload)
            ->assertSessionMissing('warning');

        $booking->refresh();

        $this->assertFalse($booking->is_taxable);
        $this->assertSame(0.0, $booking->taxAmount());
    }

    public function test_the_answer_is_asked_again_on_every_edit(): void
    {
        $this->actingAs($this->owner)
            ->post('/admin/bookings/halls', $this->hallPayload(taxable: false));

        $booking = Booking::latest('id')->firstOrFail();
        $net = $booking->netAmount();

        $this->actingAs($this->owner)
            ->put("/admin/bookings/halls/{$booking->id}", $this->hallPayload(taxable: true))
            ->assertSessionMissing('warning');

        $booking->refresh();

        $this->assertTrue($booking->is_taxable);
        $this->assertSame(round($net * 1.15, 2), (float) $booking->total_amount);
    }

    /**
     * The invoice is read by its booking's answer: without tax there is no row,
     * no tax number and no QR, and the sheet goes out as a booking invoice rather
     * than a simplified tax invoice.
     */
    public function test_the_untaxed_booking_prints_a_sheet_with_no_vat_and_no_qr(): void
    {
        $this->actingAs($this->owner)
            ->post('/admin/bookings/halls', $this->hallPayload(taxable: false));

        $booking = Booking::latest('id')->firstOrFail();

        $this->actingAs($this->owner)
            ->get("/admin/bookings/{$booking->id}/invoice")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('invoice.is_taxable', false)
                ->where('invoice.tax_amount', fn ($v) => (float) $v === 0.0)
                ->where('invoice.net_amount', fn ($v) => (float) $v === (float) $booking->total_amount)
                ->where('issuer.qr', null));
    }

    public function test_the_screen_is_quoted_the_total_the_save_will_store(): void
    {
        $quoted = $this->actingAs($this->owner)
            ->postJson('/admin/bookings/halls/quote', [
                'unit_id' => $this->hall->id,
                'scope' => 'whole',
                'booking_date' => '2026-10-07',
                'period' => 'full_day',
                'is_taxable' => false,
            ])
            ->assertOk()
            ->json('pricing');

        $this->assertFalse($quoted['is_taxable']);
        $this->assertSame(0.0, (float) $quoted['tax_amount']);
        $this->assertSame((float) $quoted['net_amount'], (float) $quoted['total_amount']);

        $this->actingAs($this->owner)
            ->post('/admin/bookings/halls', $this->hallPayload(taxable: false));

        $this->assertSame(
            (float) $quoted['total_amount'],
            (float) Booking::latest('id')->firstOrFail()->total_amount,
        );
    }

    /**
     * A chalet is like a hall across all three of its shapes: a stay by the
     * night, a day-use period, and a booking by the hour — one answer, passed
     * through each of their quotes.
     */
    public function test_a_chalet_stay_booked_without_tax_is_totalled_at_its_net(): void
    {
        $chalet = $this->chaletLetWhole();

        $this->actingAs($this->owner)
            ->post('/admin/bookings/chalets', [
                'unit_id' => $chalet->id,
                'client_id' => Client::first()?->id,
                'scope' => 'whole',
                'period' => StayPeriod::PERIOD,
                'booking_date' => '2026-10-07',
                'check_out_date' => '2026-10-09',
                'is_taxable' => false,
            ])
            ->assertSessionMissing('warning');

        $booking = Booking::latest('id')->firstOrFail();

        $this->assertFalse($booking->is_taxable);
        $this->assertSame($booking->netAmount(), (float) $booking->total_amount);
    }

    public function test_a_chalet_booked_by_the_hour_without_tax_is_totalled_at_its_net(): void
    {
        $chalet = $this->chaletLetWhole();

        $this->actingAs($this->owner)
            ->post('/admin/bookings/chalets', [
                'unit_id' => $chalet->id,
                'client_id' => Client::first()?->id,
                'scope' => 'whole',
                'period' => HourlyPeriod::PERIOD,
                'booking_date' => '2026-10-07',
                'start_time' => '16:00',
                'end_time' => '21:00',
                'hourly_amount' => 750,
                'is_taxable' => false,
            ])
            ->assertSessionMissing('warning');

        $booking = Booking::latest('id')->firstOrFail();

        $this->assertFalse($booking->is_taxable);
        $this->assertSame('750.00', $booking->total_amount);
    }

    /**
     * And a booking that was never asked — one coming from the public site —
     * stays on the common case.
     */
    public function test_a_booking_that_was_never_asked_is_taxed_as_before(): void
    {
        $payload = $this->hallPayload(taxable: true);
        unset($payload['is_taxable']);

        $this->actingAs($this->owner)
            ->post('/admin/bookings/halls', $payload)
            ->assertSessionMissing('warning');

        $booking = Booking::latest('id')->firstOrFail();

        $this->assertTrue($booking->is_taxable);
        $this->assertGreaterThan(0, $booking->taxAmount());
    }
}
