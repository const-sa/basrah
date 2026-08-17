<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\EventType;
use App\Models\Role;
use App\Models\Unit;
use App\Models\User;
use App\Services\BookingPricing;
use Database\Seeders\AccountsSeeder;
use Database\Seeders\BookingSetupSeeder;
use Database\Seeders\RolesSeeder;
use Database\Seeders\UnitsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * المناسبة الممتدة أيامًا: حجز واحد يقفل أيامه كلها ويُسعَّر يومًا يومًا.
 *
 * كانت تُسجَّل حجزًا لكل يوم فيتفرّق عقدها وحسابها، وكان اليوم الثاني يبقى
 * مفتوحًا للحجز لأن المدى لم يتجاوز اليوم الأول.
 */
class MultiDayHallBookingTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Unit $hall;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        $this->seed([RolesSeeder::class, UnitsSeeder::class, BookingSetupSeeder::class, AccountsSeeder::class]);

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
    private function payload(array $overrides = []): array
    {
        return [
            'unit_id' => $this->hall->id,
            'scope' => 'whole',
            // 2026-10-14 أربعاء — المناسبة تمتد إلى الخميس والجمعة
            'booking_date' => '2026-10-14',
            'period' => 'evening',
            'status' => 'confirmed',
            ...$overrides,
        ];
    }

    public function test_a_day_is_the_default_when_no_count_is_sent(): void
    {
        $this->actingAs($this->owner)
            ->post('/admin/bookings/halls', $this->payload())
            ->assertSessionHasNoErrors();

        $booking = Booking::latest('id')->firstOrFail();

        $this->assertSame(1, $booking->days_count);
        $this->assertSame('2026-10-14', $booking->lastDayDate());
    }

    public function test_the_range_covers_every_day_of_the_event(): void
    {
        $this->actingAs($this->owner)
            ->post('/admin/bookings/halls', $this->payload(['days_count' => 3]))
            ->assertSessionHasNoErrors();

        $booking = Booking::latest('id')->firstOrFail();

        $this->assertSame(3, $booking->days_count);
        $this->assertSame('2026-10-16', $booking->lastDayDate());
        $this->assertSame(['2026-10-14', '2026-10-15', '2026-10-16'], $booking->dayDates());

        // المدى يبدأ بمساء اليوم الأول وينتهي بعد منتصف ليل اليوم الأخير،
        // لأن الفترة المسائية تعبر منتصف الليل.
        $this->assertSame('2026-10-14 17:00:00', $booking->starts_at->toDateTimeString());
        $this->assertSame('2026-10-17 01:00:00', $booking->ends_at->toDateTimeString());
    }

    public function test_a_middle_day_is_blocked_for_anyone_else(): void
    {
        $this->actingAs($this->owner)
            ->post('/admin/bookings/halls', $this->payload(['days_count' => 3]))
            ->assertSessionHasNoErrors();

        // اليوم الثاني من المناسبة: كان يمرّ قبل امتداد المدى.
        $this->actingAs($this->owner)
            ->post('/admin/bookings/halls', $this->payload(['booking_date' => '2026-10-15']))
            ->assertSessionHasErrors('availability');

        // واليوم التالي لآخر أيامها يبقى متاحًا.
        $this->actingAs($this->owner)
            ->post('/admin/bookings/halls', $this->payload(['booking_date' => '2026-10-17']))
            ->assertSessionHasNoErrors();

        $this->assertSame(2, Booking::count());
    }

    /**
     * كل يوم بسعره: المناسبة تبدأ الأربعاء وتنتهي الجمعة، فيدخل فيها يوم
     * نهاية أسبوع واحد بسعره الأعلى — لا ثلاثة أيام بسعر الأربعاء.
     */
    public function test_each_day_is_priced_by_its_own_rate(): void
    {
        $pricing = app(BookingPricing::class);

        $wednesday = $pricing->quote($this->hall, 'whole', '2026-10-14', 'evening');
        $thursday = $pricing->quote($this->hall, 'whole', '2026-10-15', 'evening');
        $friday = $pricing->quote($this->hall, 'whole', '2026-10-16', 'evening');

        $threeDays = $pricing->quote($this->hall, 'whole', '2026-10-14', 'evening', [], [], 0, null, null, 3);

        $this->assertTrue($friday['is_weekend']);
        $this->assertSame(3, $threeDays['days']);
        $this->assertSame(
            round($wednesday['base_amount'] + $thursday['base_amount'] + $friday['base_amount'], 2),
            $threeDays['base_amount'],
        );
        $this->assertTrue($threeDays['is_weekend']);
    }

    /**
     * سعر نوع المناسبة ثمن يوم في القاعة، فيُضرب في أيامها.
     */
    public function test_an_event_type_price_applies_to_every_day(): void
    {
        $type = EventType::create([
            'unit_id' => $this->hall->id,
            'name' => 'زواج',
            'color' => 'rose',
            'price' => 10000,
        ]);

        $quote = app(BookingPricing::class)
            ->quote($this->hall, 'whole', '2026-10-14', 'evening', [], [], 0, null, $type->id, 2);

        $this->assertTrue($quote['priced_by_event']);
        $this->assertSame(20000.0, $quote['base_amount']);
        // سطر واحد مدموج لا سطران متطابقان
        $this->assertCount(1, $quote['lines']);
        $this->assertSame(2, $quote['lines'][0]['days']);
    }

    public function test_the_package_is_charged_once_for_the_whole_event(): void
    {
        $package = \App\Models\Package::create([
            'unit_id' => $this->hall->id,
            'name' => 'باقة الضيافة',
            'price' => 3000,
            'is_active' => true,
        ]);

        $quote = app(BookingPricing::class)
            ->quote($this->hall, 'whole', '2026-10-14', 'evening', [], [], 0, $package->id, null, 3);

        $this->assertSame(3000.0, $quote['package_amount']);
    }

    public function test_a_count_outside_the_allowed_range_is_rejected(): void
    {
        $this->actingAs($this->owner)
            ->post('/admin/bookings/halls', $this->payload(['days_count' => 0]))
            ->assertSessionHasErrors('days_count');

        $this->actingAs($this->owner)
            ->post('/admin/bookings/halls', $this->payload(['days_count' => 31]))
            ->assertSessionHasErrors('days_count');

        $this->assertSame(0, Booking::count());
    }

    public function test_shortening_an_event_frees_the_days_it_gave_up(): void
    {
        $this->actingAs($this->owner)
            ->post('/admin/bookings/halls', $this->payload(['days_count' => 3]))
            ->assertSessionHasNoErrors();

        $booking = Booking::latest('id')->firstOrFail();

        $this->actingAs($this->owner)
            ->put("/admin/bookings/halls/{$booking->id}", $this->payload(['days_count' => 1]))
            ->assertSessionHasNoErrors();

        $this->assertSame(1, $booking->fresh()->days_count);

        $this->actingAs($this->owner)
            ->post('/admin/bookings/halls', $this->payload(['booking_date' => '2026-10-15']))
            ->assertSessionHasNoErrors();
    }

    public function test_the_calendar_shows_the_event_on_all_of_its_days(): void
    {
        $this->actingAs($this->owner)
            ->post('/admin/bookings/halls', $this->payload(['days_count' => 3]))
            ->assertSessionHasNoErrors();

        $this->actingAs($this->owner)
            ->get('/admin/calendar/halls?month=2026-10')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('admin/bookings/halls/Calendar')
                ->has('bookings', 1)
                ->where('bookings.0.days_count', 3)
                ->where('bookings.0.dates', ['2026-10-14', '2026-10-15', '2026-10-16']));
    }
}
