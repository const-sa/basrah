<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Booking;
use App\Models\Client;
use App\Models\CostCenter;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\Role;
use App\Models\Unit;
use App\Models\UnitPrice;
use App\Models\User;
use App\Services\Accounting\Ledger;
use App\Services\BookingService;
use App\Services\ChaletBookingService;
use App\Support\Accounting\SectionRevenueAttribution;
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
 * إيراد الشاليه المقسَّم يُنسب إلى الغرفة التي كسبته.
 *
 * قبل هذا كان إيراد غرف الشاليه كلها سطرًا واحدًا على مركز الشاليه، فسؤال
 * «كم دخل من شاليه ٢» لا جواب له في الدفاتر — الرقم موجود لكنه غير مفصول.
 */
class SectionRevenueTest extends TestCase
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
            'role_id' => Role::where('slug', 'super-admin')->firstOrFail()->id,
            'is_active' => true,
            'has_all_units' => true,
        ]);

        $this->chalet = Unit::where('type', 'chalet')->whereHas('sections')->firstOrFail();
    }

    private function priceSection(int $sectionId, float $amount): void
    {
        UnitPrice::updateOrCreate(
            ['unit_id' => $this->chalet->id, 'unit_section_id' => $sectionId, 'period' => StayPeriod::PERIOD],
            // day_prices outranks the weekday/weekend pair, so a seeded day
            // rate would price the room over this one.
            ['weekday_price' => $amount, 'weekend_price' => $amount, 'day_prices' => null, 'is_active' => true],
        );
    }

    private function stayInRoom(int $sectionId, string $from = '2027-02-10', string $to = '2027-02-11'): Booking
    {
        return app(ChaletBookingService::class)->create([
            'unit_id' => $this->chalet->id,
            'client_id' => Client::first()?->id,
            'scope' => 'sections',
            'section_ids' => [$sectionId],
            'booking_date' => $from,
            'check_out_date' => $to,
            'status' => 'deposit_paid',
        ]);
    }

    /**
     * @return array<int, float> مركز التكلفة ← ما قُيّد عليه من إيراد الحجوزات
     */
    private function revenueByCenter(): array
    {
        $account = Account::where('code', Ledger::BOOKING_REVENUE)->value('id');

        return JournalLine::where('account_id', $account)
            ->get()
            ->groupBy('cost_center_id')
            ->map(fn ($lines) => round((float) $lines->sum('credit') - (float) $lines->sum('debit'), 2))
            ->all();
    }

    public function test_every_section_is_a_cost_centre_of_its_own(): void
    {
        $section = $this->chalet->sections()->firstOrFail();
        $centre = CostCenter::where('unit_section_id', $section->id)->firstOrFail();

        // The unit's name travels with it — «شاليه ١» alone names nothing on a
        // screen listing every unit's rooms together.
        $this->assertSame($this->chalet->name.' — '.$section->name, $centre->name);
        $this->assertNull($centre->unit_id);
    }

    public function test_a_room_let_alone_credits_its_own_centre(): void
    {
        $section = $this->chalet->sections()->firstOrFail();
        $this->priceSection($section->id, 500);

        $booking = $this->stayInRoom($section->id);
        app(BookingService::class)->settleInFull($booking);

        $centre = CostCenter::where('unit_section_id', $section->id)->value('id');
        $unitCentre = CostCenter::where('unit_id', $this->chalet->id)->value('id');
        $byCentre = $this->revenueByCenter();

        $this->assertSame(round((float) $booking->fresh()->total_amount, 2), $byCentre[$centre] ?? 0.0);
        $this->assertArrayNotHasKey($unitCentre, $byCentre, 'the chalet no longer carries what one room earned');
    }

    public function test_two_rooms_split_the_revenue_by_what_each_was_let_at(): void
    {
        $sections = $this->chalet->sections()->orderBy('id')->take(2)->get();
        $this->assertCount(2, $sections, 'the fixture chalet holds at least two rooms');

        $this->priceSection($sections[0]->id, 300);
        $this->priceSection($sections[1]->id, 100);

        $booking = app(ChaletBookingService::class)->create([
            'unit_id' => $this->chalet->id,
            'client_id' => Client::first()?->id,
            'scope' => 'sections',
            'section_ids' => $sections->pluck('id')->all(),
            'booking_date' => '2027-03-10',
            'check_out_date' => '2027-03-11',
            'status' => 'deposit_paid',
        ]);

        app(BookingService::class)->settleInFull($booking);

        $total = round((float) $booking->fresh()->total_amount, 2);
        $byCentre = $this->revenueByCenter();

        $first = CostCenter::where('unit_section_id', $sections[0]->id)->value('id');
        $second = CostCenter::where('unit_section_id', $sections[1]->id)->value('id');

        $this->assertEqualsWithDelta($total * 0.75, $byCentre[$first] ?? 0.0, 0.01);
        $this->assertEqualsWithDelta($total * 0.25, $byCentre[$second] ?? 0.0, 0.01);

        // Split or not, the books add up to the same total.
        $this->assertEqualsWithDelta($total, array_sum($byCentre), 0.01);
    }

    public function test_a_chalet_taken_whole_still_credits_the_chalet(): void
    {
        $whole = $this->chaletLetWhole();

        $booking = app(ChaletBookingService::class)->create([
            'unit_id' => $whole->id,
            'client_id' => Client::first()?->id,
            'scope' => 'whole',
            'booking_date' => '2027-04-10',
            'check_out_date' => '2027-04-11',
            'status' => 'deposit_paid',
        ]);

        app(BookingService::class)->settleInFull($booking);

        $unitCentre = CostCenter::where('unit_id', $whole->id)->value('id');

        $this->assertSame(
            round((float) $booking->fresh()->total_amount, 2),
            $this->revenueByCenter()[$unitCentre] ?? 0.0,
            'a unit sold whole earned it whole',
        );
    }

    public function test_the_revenues_screen_lists_the_room_and_filters_to_it(): void
    {
        $section = $this->chalet->sections()->firstOrFail();
        $this->priceSection($section->id, 400);

        $booking = $this->stayInRoom($section->id);
        app(BookingService::class)->settleInFull($booking);

        $centre = CostCenter::where('unit_section_id', $section->id)->value('id');
        $total = round((float) $booking->fresh()->total_amount, 2);
        $name = $this->chalet->name.' — '.$section->name;

        $this->actingAs($this->owner)
            ->get('/admin/accounting/revenues?from=2020-01-01&to=2035-12-31')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('admin/accounting/Revenues')
                // The room reads on «من أيّ وحدة؟» exactly as a unit does...
                ->where('byCenter.0.name', $name)
                ->where('byCenter.0.amount', fn ($amount) => (float) $amount === $total)
                // ...and counts towards the chalets, not «إيرادات أخرى».
                ->where('byCenter.0.segment', 'chalets')
                ->etc(),
            );

        $this->actingAs($this->owner)
            ->get("/admin/accounting/revenues?from=2020-01-01&to=2035-12-31&cost_center_id={$centre}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('stats.total', fn ($sum) => (float) $sum === $total)->etc());
    }

    /**
     * الإقامة في غرفةٍ واحدة هي عين ما يُسأل عنه، فلا يجوز أن تبقى على
     * الشاليه بحجّة أنّ سطرها لم ينقسم.
     */
    public function test_a_single_room_stay_is_moved_off_the_chalet_too(): void
    {
        $section = $this->chalet->sections()->firstOrFail();
        $this->priceSection($section->id, 500);

        $booking = $this->stayInRoom($section->id, '2027-08-10', '2027-08-11');
        app(BookingService::class)->settleInFull($booking);

        $account = Account::where('code', Ledger::BOOKING_REVENUE)->value('id');
        $entry = JournalEntry::where('reference_type', Booking::class)
            ->where('reference_id', $booking->id)
            ->where('source', 'booking')
            ->firstOrFail();

        // Back to how the ledger held it before rooms had centres.
        $unitCentre = CostCenter::where('unit_id', $this->chalet->id)->value('id');
        $total = round((float) $entry->lines()->where('account_id', $account)->sum('credit'), 2);
        $entry->lines()->where('account_id', $account)->delete();
        $entry->lines()->create([
            'account_id' => $account, 'cost_center_id' => $unitCentre, 'debit' => 0, 'credit' => $total,
        ]);

        $moved = app(SectionRevenueAttribution::class)->apply();

        $this->assertSame(['entries' => 1, 'lines' => 1], $moved);

        $centre = CostCenter::where('unit_section_id', $section->id)->value('id');
        $byCentre = $this->revenueByCenter();

        $this->assertSame($total, $byCentre[$centre] ?? 0.0);
        $this->assertArrayNotHasKey($unitCentre, $byCentre);

        // Already where it belongs — a second run leaves it be.
        $this->assertSame(['entries' => 0, 'lines' => 0], app(SectionRevenueAttribution::class)->apply());
    }

    public function test_revenue_posted_before_the_split_is_moved_onto_its_rooms(): void
    {
        $sections = $this->chalet->sections()->orderBy('id')->take(2)->get();
        $this->priceSection($sections[0]->id, 300);
        $this->priceSection($sections[1]->id, 100);

        $booking = app(ChaletBookingService::class)->create([
            'unit_id' => $this->chalet->id,
            'client_id' => Client::first()?->id,
            'scope' => 'sections',
            'section_ids' => $sections->pluck('id')->all(),
            'booking_date' => '2027-05-10',
            'check_out_date' => '2027-05-11',
            'status' => 'deposit_paid',
        ]);

        app(BookingService::class)->settleInFull($booking);

        $account = Account::where('code', Ledger::BOOKING_REVENUE)->value('id');
        $entry = JournalEntry::where('reference_type', Booking::class)
            ->where('reference_id', $booking->id)
            ->where('source', 'booking')
            ->firstOrFail();

        // Put the entry back the way the ledger held it before rooms had
        // centres of their own: the whole credit on the chalet, in one line.
        $unitCentre = CostCenter::where('unit_id', $this->chalet->id)->value('id');
        $total = round((float) $entry->lines()->where('account_id', $account)->sum('credit'), 2);
        $entry->lines()->where('account_id', $account)->delete();
        $entry->lines()->create([
            'account_id' => $account, 'cost_center_id' => $unitCentre, 'debit' => 0, 'credit' => $total,
        ]);

        $before = ['debit' => (float) $entry->total_debit, 'credit' => (float) $entry->total_credit];

        $moved = app(SectionRevenueAttribution::class)->apply();

        $this->assertSame(1, $moved['entries']);
        $this->assertSame(2, $moved['lines']);

        $byCentre = $this->revenueByCenter();
        $first = CostCenter::where('unit_section_id', $sections[0]->id)->value('id');

        $this->assertEqualsWithDelta($total * 0.75, $byCentre[$first] ?? 0.0, 0.01);
        $this->assertArrayNotHasKey($unitCentre, $byCentre);

        // Nothing about the entry itself moved — same account, same total.
        $entry->refresh();
        $this->assertSame($before['debit'], (float) $entry->total_debit);
        $this->assertSame($before['credit'], (float) $entry->total_credit);
        $this->assertEqualsWithDelta($total, $entry->lines()->where('account_id', $account)->sum('credit'), 0.01);

        // And a second run splits nothing further.
        $this->assertSame(['entries' => 0, 'lines' => 0], app(SectionRevenueAttribution::class)->apply());
    }
}
