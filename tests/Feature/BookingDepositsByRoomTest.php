<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Booking;
use App\Models\CostCenter;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\Role;
use App\Models\Unit;
use App\Models\User;
use App\Services\Accounting\BookingAccounting;
use App\Services\Accounting\CostCenterStatementService;
use App\Services\Accounting\Ledger;
use App\Services\BookingService;
use Database\Seeders\AccountsSeeder;
use Database\Seeders\BookingSetupSeeder;
use Database\Seeders\RolesSeeder;
use Database\Seeders\UnitsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * A hall let by the side holds its deposits on that side, and earns on its date.
 *
 * «حجوزات قسم الرجال مش ظاهره ف مراكز التكلفة»: a men's-side booking still
 * ahead of its date has nothing but a deposit, and the deposit sat on the
 * hall — so the men's row read zero on every column.
 */
class BookingDepositsByRoomTest extends TestCase
{
    use RefreshDatabase;

    private Unit $hall;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        $this->seed([RolesSeeder::class, UnitsSeeder::class, BookingSetupSeeder::class, AccountsSeeder::class]);

        $this->hall = Unit::where('type', 'hall')->whereHas('sections')->firstOrFail();
    }

    private function bookSides(array $sectionIds, float $agreed = 10000): Booking
    {
        return app(BookingService::class)->create([
            'unit_id' => $this->hall->id,
            'scope' => 'sections',
            'section_ids' => $sectionIds,
            'booking_date' => '2026-09-10',
            'period' => 'full_day',
            'status' => 'deposit_paid',
            'agreed_amount' => $agreed,
        ]);
    }

    private function pay(Booking $booking, float $amount, string $type = 'deposit'): void
    {
        app(BookingService::class)->recordPayment($booking->fresh(), [
            'type' => $type,
            'payment_method_id' => $this->paymentMethodId('cash'),
            'amount' => $amount,
        ]);
    }

    private function centreOf(int $sectionId): int
    {
        return (int) CostCenter::where('unit_section_id', $sectionId)->value('id');
    }

    /** @return array<int, float> */
    private function held(array $centres): array
    {
        return app(CostCenterStatementService::class)->depositsHeldForMany($centres);
    }

    public function test_a_mens_side_deposit_is_held_on_the_mens_side(): void
    {
        $men = $this->hall->sections()->orderBy('sort_order')->firstOrFail();
        $booking = $this->bookSides([$men->id]);

        $this->pay($booking, 3000);

        $unitCentre = CostCenter::forUnit($this->hall)->id;
        $held = $this->held([$this->centreOf($men->id), $unitCentre]);

        $this->assertSame(3000.0, $held[$this->centreOf($men->id)]);
        $this->assertSame(0.0, $held[$unitCentre], 'the hall holds nothing it was not let as');

        // The hall still reads it, through its rooms.
        $this->assertSame(3000.0, app(CostCenterStatementService::class)->depositsHeldRolledUp([$unitCentre])[$unitCentre]);
    }

    public function test_earning_clears_each_side_to_zero(): void
    {
        $sections = $this->hall->sections()->orderBy('sort_order')->take(2)->get();
        $booking = $this->bookSides($sections->pluck('id')->all(), 10001);

        // Odd amounts, each split on its own — the halalas must still clear.
        $this->pay($booking, 1000.01);
        $this->pay($booking, 2000.03, 'payment');

        $this->settleAsDelivered($booking);

        $centres = $sections->map(fn ($s) => $this->centreOf($s->id))->all();

        foreach ($this->held($centres) as $balance) {
            $this->assertSame(0.0, $balance);
        }

        $revenue = app(CostCenterStatementService::class)->profitabilityForMany($centres);
        $this->assertEqualsWithDelta(10001, array_sum(array_column($revenue, 'revenue')), 0.001);
    }

    public function test_moving_a_booking_to_the_other_side_moves_its_deposit(): void
    {
        [$men, $women] = $this->hall->sections()->orderBy('sort_order')->take(2)->get()->all();
        $booking = $this->bookSides([$men->id]);
        $this->pay($booking, 2500);

        app(BookingService::class)->update($booking->fresh(), ['scope' => 'sections', 'section_ids' => [$women->id]]);

        $held = $this->held([$this->centreOf($men->id), $this->centreOf($women->id)]);

        $this->assertSame(0.0, $held[$this->centreOf($men->id)]);
        $this->assertSame(2500.0, $held[$this->centreOf($women->id)]);
    }

    /**
     * Revenue the old rule posted on full payment, months before the event,
     * is taken back and earned again on the booking's own date.
     */
    public function test_revenue_posted_before_the_event_waits_for_its_date(): void
    {
        $men = $this->hall->sections()->orderBy('sort_order')->firstOrFail();
        $booking = $this->bookSides([$men->id], 8000);
        $this->pay($booking, 8000, 'payment');

        $accounting = app(BookingAccounting::class);

        // What the old rule wrote: recognized the day it was paid.
        $accounting->recognizeRevenue($booking->fresh(), null, '2026-08-16');
        $revenue = Account::where('code', Ledger::BOOKING_REVENUE)->first();
        $this->assertSame(8000.0, $revenue->balance());

        $migration = require database_path('migrations/2026_11_02_100000_hold_the_revenue_of_bookings_not_yet_served.php');
        $migration->up();

        $this->assertSame(0.0, $revenue->balance());
        $this->assertSame(8000.0, $this->held([$this->centreOf($men->id)])[$this->centreOf($men->id)]);

        $this->travelTo($booking->fresh()->ends_at->copy()->addDay());
        $this->artisan('accounting:recognize-delivered')->assertSuccessful();

        $this->assertSame(8000.0, $revenue->balance());
        $this->assertSame(0.0, $this->held([$this->centreOf($men->id)])[$this->centreOf($men->id)]);
    }

    /** Deposits already posted on the hall are moved onto the side that was let. */
    public function test_old_deposits_on_the_hall_move_onto_the_side(): void
    {
        $men = $this->hall->sections()->orderBy('sort_order')->firstOrFail();
        $booking = $this->bookSides([$men->id]);
        $this->pay($booking, 4000);

        // Put it back the way it was posted before: on the hall.
        $unitCentre = CostCenter::forUnit($this->hall)->id;
        JournalLine::where('cost_center_id', $this->centreOf($men->id))->update(['cost_center_id' => $unitCentre]);

        $migration = require database_path('migrations/2026_11_02_100001_hold_each_bookings_deposits_on_the_rooms_it_let.php');
        $migration->up();

        $held = $this->held([$this->centreOf($men->id), $unitCentre]);
        $this->assertSame(4000.0, $held[$this->centreOf($men->id)]);
        $this->assertSame(0.0, $held[$unitCentre]);

        // Nothing else moved, and a second run is a no-op.
        $this->assertSame(0, app(BookingAccounting::class)->realignHeld($booking->fresh()));
        $this->assertSame(0, JournalEntry::where('status', 'reversed')->count());
    }

    /** Money on unearned revenue that no booking or contract holds is named by its entry. */
    public function test_the_audit_names_the_entry_behind_unexplained_deposits(): void
    {
        $entry = app(Ledger::class)->post('2026-08-16', 'عربون مُدخل يدويًا', [
            ['account' => Ledger::CASH, 'debit' => 1000],
            ['account' => Ledger::UNEARNED_REVENUE, 'credit' => 1000],
        ], 'manual');

        $this->artisan('accounting:audit --errors')
            ->expectsOutputToContain('1,000.00 belongs to no document')
            ->expectsOutputToContain("entry {$entry->number}")
            ->assertFailed();
    }

    /** Refunded to zero still leaves entries behind — such a booking is cancelled, not deleted. */
    public function test_a_booking_with_any_payment_cannot_be_deleted(): void
    {
        $men = $this->hall->sections()->orderBy('sort_order')->firstOrFail();
        $booking = $this->bookSides([$men->id]);
        $this->pay($booking, 2000);
        $this->pay($booking, 2000, 'refund');
        $this->assertSame(0.0, (float) $booking->fresh()->paid_amount);

        $owner = User::factory()->create([
            'role_id' => Role::where('slug', 'super-admin')->firstOrFail()->id,
            'is_active' => true,
            'has_all_units' => true,
        ]);

        $this->actingAs($owner)
            ->delete("/admin/bookings/{$booking->id}")
            ->assertSessionHas('warning');

        $this->assertFalse($booking->fresh()->trashed());
    }

    /** A deleted booking still holding a deposit is named, not lost in a total. */
    public function test_the_audit_names_a_deleted_booking_still_holding_money(): void
    {
        $men = $this->hall->sections()->orderBy('sort_order')->firstOrFail();
        $booking = $this->bookSides([$men->id]);
        $this->pay($booking, 3000);
        $this->pay($booking, 2000, 'refund');

        // How the live books came to hold one: deleted past the old guard.
        $booking->delete();

        $this->artisan('accounting:audit --errors')
            ->expectsOutputToContain("booking {$booking->reference} | deleted with money held")
            ->doesntExpectOutputToContain('belongs to no document')
            ->assertFailed();
    }

    /** A booking entered by mistake and deleted, whose money never came in, leaves the books clean. */
    public function test_voiding_a_deleted_bookings_payments_takes_them_out_of_the_books(): void
    {
        $men = $this->hall->sections()->orderBy('sort_order')->firstOrFail();
        $booking = $this->bookSides([$men->id]);
        $this->pay($booking, 3000);
        $this->pay($booking, 2000, 'refund');
        $booking->delete();

        // A cancelled one, entered by mistake the same way.
        $cancelled = $this->bookSides([$men->id]);
        $this->pay($cancelled, 2000);
        app(BookingService::class)->cancel($cancelled->fresh(), 'أُدخل خطأً');

        // Pretend first: it lists, and changes nothing.
        $this->artisan('accounting:void-mistaken-payments', ['references' => [$booking->reference, $cancelled->reference], '--pretend' => true])
            ->expectsOutputToContain('Total: 3,000.00')
            ->assertSuccessful();
        $this->assertSame(3000.0, Account::where('code', Ledger::UNEARNED_REVENUE)->first()->balance());

        $this->artisan('accounting:void-mistaken-payments', ['references' => [$booking->reference, $cancelled->reference]])
            ->assertSuccessful();

        $this->assertSame(0.0, Account::where('code', Ledger::CASH)->first()->balance());
        $this->assertSame(0.0, Account::where('code', Ledger::UNEARNED_REVENUE)->first()->balance());
        $this->assertSame(0, $booking->payments()->count());
        $this->assertSame(0.0, (float) $cancelled->fresh()->paid_amount);
        $this->artisan('accounting:audit')->assertSuccessful()->doesntExpectOutputToContain('money held');

        // A live booking is corrected on the booking, never from here.
        $live = $this->bookSides([$men->id]);
        $this->pay($live, 500);

        $this->artisan('accounting:void-mistaken-payments', ['references' => [$live->reference]])
            ->assertFailed();
        $this->assertSame(1, $live->payments()->count());
    }

    public function test_the_audit_passes_on_clean_books_and_names_a_broken_booking(): void
    {
        $men = $this->hall->sections()->orderBy('sort_order')->firstOrFail();
        $booking = $this->bookSides([$men->id]);
        $this->pay($booking, 1500);

        $this->artisan('accounting:audit')->assertSuccessful();

        $booking->update(['paid_amount' => 999]);

        $this->artisan('accounting:audit --errors')
            ->expectsOutputToContain("booking {$booking->reference}")
            ->assertFailed();
    }
}
