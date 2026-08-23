<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Booking;
use App\Models\Client;
use App\Models\Role;
use App\Models\Unit;
use App\Models\User;
use App\Services\Accounting\Ledger;
use App\Services\BookingService;
use App\Support\StayPeriod;
use Database\Seeders\AccountsSeeder;
use Database\Seeders\BookingSetupSeeder;
use Database\Seeders\DepartmentsSeeder;
use Database\Seeders\FacilitiesSeeder;
use Database\Seeders\RolesSeeder;
use Database\Seeders\UnitsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * The refundable security deposit, and the line it must never cross.
 *
 * A booking deposit is part of the price; a security deposit is the guest's
 * own money held against damage. Everything here exists to keep the two
 * apart: the total, what is still owed, and the accounts the money lands in.
 */
class SecurityDepositTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

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

        $this->chalet = Unit::where('code', 'CH-BSR1')->firstOrFail();
        $this->chalet->update(['security_deposit' => 500]);
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function payload(array $extra = []): array
    {
        return [
            'unit_id' => $this->chalet->id,
            'client_id' => Client::first()?->id,
            'scope' => 'whole',
            'booking_date' => '2027-02-10',
            'check_out_date' => '2027-02-12',
            ...$extra,
        ];
    }

    private function balance(string $code): float
    {
        return Account::where('code', $code)->firstOrFail()->balance();
    }

    // ── The amount itself ────────────────────────────────────

    public function test_the_pricing_screen_stores_the_unit_deposit(): void
    {
        $this->actingAs($this->owner)
            ->put("/admin/units/{$this->chalet->id}/prices", [
                'security_deposit' => 750,
                'prices' => [[
                    'unit_section_id' => null,
                    'period' => StayPeriod::PERIOD,
                    'weekday_price' => 400,
                    'weekend_price' => 500,
                ]],
            ])
            ->assertRedirect();

        $this->assertEqualsWithDelta(750, $this->chalet->fresh()->securityDeposit(), 0.01);
    }

    public function test_an_empty_box_means_the_unit_takes_no_deposit(): void
    {
        $this->actingAs($this->owner)
            ->put("/admin/units/{$this->chalet->id}/prices", [
                'security_deposit' => '',
                'prices' => [],
            ])
            ->assertRedirect();

        $this->assertNull($this->chalet->fresh()->security_deposit);
        $this->assertSame(0.0, $this->chalet->fresh()->securityDeposit());
    }

    public function test_a_new_booking_takes_the_chalet_usual_amount(): void
    {
        $this->actingAs($this->owner)->post('/admin/bookings/chalets', $this->payload())->assertRedirect();

        $this->assertEqualsWithDelta(500, (float) Booking::latest('id')->firstOrFail()->security_deposit_amount, 0.01);
    }

    /**
     * The default is a suggestion, not a rule — a waived deposit must survive
     * the save, which a plain "??" fallback would quietly overwrite with 500.
     */
    public function test_the_form_may_waive_the_deposit(): void
    {
        $this->actingAs($this->owner)
            ->post('/admin/bookings/chalets', $this->payload(['security_deposit_amount' => 0]))
            ->assertRedirect();

        $this->assertSame(0.0, (float) Booking::latest('id')->firstOrFail()->security_deposit_amount);
    }

    public function test_editing_the_dates_does_not_reset_a_waived_deposit(): void
    {
        $this->actingAs($this->owner)
            ->post('/admin/bookings/chalets', $this->payload(['security_deposit_amount' => 0]))
            ->assertRedirect();

        $booking = Booking::latest('id')->firstOrFail();

        $this->actingAs($this->owner)
            ->put("/admin/bookings/chalets/{$booking->id}", $this->payload([
                'check_out_date' => '2027-02-13',
            ]))
            ->assertRedirect();

        $this->assertSame(0.0, (float) $booking->fresh()->security_deposit_amount);
    }

    // ── Taking it, and keeping it out of the price ───────────

    public function test_it_is_collected_with_the_booking_when_asked(): void
    {
        $this->actingAs($this->owner)
            ->post('/admin/bookings/chalets', $this->payload([
                'security_collected' => true,
                'payment_method_id' => $this->paymentMethodId(),
            ]))
            ->assertRedirect();

        $booking = Booking::latest('id')->firstOrFail();

        $this->assertEqualsWithDelta(500, $booking->securityHeld(), 0.01);
        $this->assertSame(1, $booking->payments()->where('type', 'security_deposit')->count());
    }

    public function test_it_is_recorded_but_not_collected_when_not_asked(): void
    {
        $this->actingAs($this->owner)->post('/admin/bookings/chalets', $this->payload())->assertRedirect();

        $booking = Booking::latest('id')->firstOrFail();

        $this->assertEqualsWithDelta(500, (float) $booking->security_deposit_amount, 0.01);
        $this->assertSame(0.0, $booking->securityHeld(), 'nothing is in hand until it is taken');
    }

    /**
     * The whole point. Held money is not paid money: counting it would show a
     * stay as settled while its price is still owed.
     */
    public function test_the_deposit_never_counts_as_payment(): void
    {
        $this->actingAs($this->owner)
            ->post('/admin/bookings/chalets', $this->payload([
                'security_collected' => true,
                'payment_method_id' => $this->paymentMethodId(),
            ]))
            ->assertRedirect();

        $booking = Booking::latest('id')->firstOrFail();

        $this->assertSame(0.0, (float) $booking->paid_amount);
        $this->assertEqualsWithDelta((float) $booking->total_amount, $booking->remainingAmount(), 0.01);
        $this->assertFalse($booking->isFullyPaid());
    }

    // ── Where the money lands ────────────────────────────────

    public function test_it_is_held_in_its_own_liability_account(): void
    {
        $booking = $this->bookingWithDeposit();

        $this->assertSame(500.0, $this->balance(Ledger::REFUNDABLE_DEPOSITS));
        $this->assertSame(500.0, $this->balance(Ledger::CASH));
        $this->assertSame(0.0, $this->balance(Ledger::UNEARNED_REVENUE), 'it is not a deposit on the price');
        $this->assertSame(0.0, $this->balance(Ledger::BOOKING_REVENUE));
        $this->assertSame(0.0, (float) $booking->fresh()->paid_amount);
    }

    public function test_refunding_it_empties_the_account_and_the_till(): void
    {
        $booking = $this->bookingWithDeposit();

        app(BookingService::class)->recordPayment($booking, [
            'type' => 'security_refund',
            'payment_method_id' => $this->paymentMethodId(),
            'amount' => 500,
        ]);

        $this->assertSame(0.0, $this->balance(Ledger::REFUNDABLE_DEPOSITS));
        $this->assertSame(0.0, $this->balance(Ledger::CASH));
        $this->assertSame(0.0, $booking->fresh()->securityHeld());
    }

    /**
     * Damage taken out of the deposit is the only part that becomes income,
     * and no cash moves: the money is already in the till, the guest's claim
     * on it simply ends.
     */
    public function test_a_forfeit_turns_into_revenue_without_touching_the_till(): void
    {
        $booking = $this->bookingWithDeposit();
        $service = app(BookingService::class);

        $service->recordPayment($booking, ['type' => 'security_forfeit', 'amount' => 120]);
        $service->recordPayment($booking, [
            'type' => 'security_refund',
            'payment_method_id' => $this->paymentMethodId(),
            'amount' => 380,
        ]);

        $this->assertSame(0.0, $this->balance(Ledger::REFUNDABLE_DEPOSITS), 'the liability is fully resolved');
        $this->assertSame(120.0, $this->balance(Ledger::BOOKING_REVENUE));
        $this->assertSame(120.0, $this->balance(Ledger::CASH), 'only the refunded part left the till');
        $this->assertSame(0.0, (float) $booking->fresh()->paid_amount, 'none of it was ever a payment');
    }

    public function test_a_forfeit_carries_no_payment_method(): void
    {
        $booking = $this->bookingWithDeposit();

        $payment = app(BookingService::class)->recordPayment($booking, [
            'type' => 'security_forfeit',
            'payment_method_id' => $this->paymentMethodId(),
            'amount' => 100,
        ]);

        $this->assertNull($payment->payment_method_id, 'no money moved, so no till is named');
    }

    // ── Guards ───────────────────────────────────────────────

    public function test_more_cannot_be_released_than_is_held(): void
    {
        $booking = $this->bookingWithDeposit();

        $this->expectException(ValidationException::class);

        app(BookingService::class)->recordPayment($booking, [
            'type' => 'security_refund',
            'payment_method_id' => $this->paymentMethodId(),
            'amount' => 600,
        ]);
    }

    public function test_releases_are_counted_together(): void
    {
        $booking = $this->bookingWithDeposit();
        $service = app(BookingService::class);

        $service->recordPayment($booking, ['type' => 'security_forfeit', 'amount' => 400]);

        $this->expectException(ValidationException::class);

        $service->recordPayment($booking, [
            'type' => 'security_refund',
            'payment_method_id' => $this->paymentMethodId(),
            'amount' => 200,
        ]);
    }

    /**
     * A price refund is bounded by what was paid, and a security refund by
     * what is held. Holding a deposit must not open the door to refunding
     * money the guest never put towards the price.
     */
    public function test_a_held_deposit_does_not_fund_a_price_refund(): void
    {
        $booking = $this->bookingWithDeposit();

        $this->expectException(ValidationException::class);

        app(BookingService::class)->recordPayment($booking, [
            'type' => 'refund',
            'payment_method_id' => $this->paymentMethodId(),
            'amount' => 100,
        ]);
    }

    // ── What the screens read ────────────────────────────────

    public function test_the_payments_panel_reports_agreed_and_held(): void
    {
        $booking = $this->bookingWithDeposit();

        app(BookingService::class)->recordPayment($booking, ['type' => 'security_forfeit', 'amount' => 200]);

        $summary = $this->actingAs($this->owner)
            ->getJson("/admin/bookings/{$booking->id}/payments")
            ->assertOk()
            ->json('summary');

        $this->assertEqualsWithDelta(500, $summary['security_deposit_amount'], 0.01);
        $this->assertEqualsWithDelta(300, $summary['security_held'], 0.01);
        $this->assertSame(0.0, (float) $summary['paid_amount']);
    }

    public function test_the_booking_form_offers_the_unit_amount(): void
    {
        $this->actingAs($this->owner)
            ->get('/admin/bookings/chalets/create')
            // 500, not 500.0: a whole amount comes back through JSON as an int
            ->assertInertia(fn ($page) => $page->where(
                'units.'.$this->unitIndexInForm().'.security_deposit',
                500,
            ));
    }

    /**
     * The types the desk may pick when creating a booking are the price ones.
     * A security movement smuggled in as payment_type would land in unearned
     * revenue and count against the total.
     */
    public function test_a_security_type_cannot_be_smuggled_into_the_create_form(): void
    {
        $this->actingAs($this->owner)
            ->post('/admin/bookings/chalets', $this->payload([
                'payment_amount' => 100,
                'payment_type' => 'security_deposit',
                'payment_method_id' => $this->paymentMethodId(),
            ]))
            ->assertSessionHasErrors('payment_type');
    }

    private function bookingWithDeposit(): Booking
    {
        $this->actingAs($this->owner)
            ->post('/admin/bookings/chalets', $this->payload([
                'security_collected' => true,
                'payment_method_id' => $this->paymentMethodId(),
            ]))
            ->assertRedirect();

        return Booking::latest('id')->firstOrFail();
    }

    /**
     * The create screen lists every chalet the user may reach; find this one
     * rather than assuming it is first, so seeding order cannot break the test.
     */
    private function unitIndexInForm(): int
    {
        $ids = Unit::where('type', 'chalet')->where('is_active', true)
            ->orderBy('sort_order')->pluck('id')->all();

        return (int) array_search($this->chalet->id, $ids, true);
    }
}
