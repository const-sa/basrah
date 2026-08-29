<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Client;
use App\Models\Role;
use App\Models\Unit;
use App\Models\UnitPrice;
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
use Tests\TestCase;

/**
 * حجز الشاليه بالساعات.
 *
 * شكلٌ ثالث إلى جانب الإقامة بالليالي والفترة النهارية: ساعتاه يكتبهما
 * الموظف لكل حجز على حدة، ومبلغه يُتَّفق عليه ولا يُقرأ من جدول أسعار. وهو
 * كغيره يقفل الشاليه في مداه، فيمنع ويُمنع.
 */
class ChaletHourlyBookingTest extends TestCase
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

    /** @return array<string, mixed> */
    private function payload(array $overrides = []): array
    {
        return [
            'unit_id' => $this->chalet->id,
            'client_id' => Client::first()?->id,
            'scope' => 'whole',
            'period' => HourlyPeriod::PERIOD,
            'booking_date' => '2026-10-05',
            'start_time' => '16:00',
            'end_time' => '21:00',
            'hourly_amount' => 750,
            ...$overrides,
        ];
    }

    public function test_a_chalet_is_sold_by_the_hour_at_the_price_that_was_agreed(): void
    {
        $this->actingAs($this->owner)
            ->post('/admin/bookings/chalets', $this->payload())
            ->assertSessionHasNoErrors();

        $booking = Booking::latest('id')->firstOrFail();

        $this->assertSame(HourlyPeriod::PERIOD, $booking->period);
        $this->assertSame('2026-10-05 16:00:00', $booking->starts_at->toDateTimeString());
        $this->assertSame('2026-10-05 21:00:00', $booking->ends_at->toDateTimeString());
        $this->assertSame(750.0, (float) $booking->base_amount);
        $this->assertSame(5.0, $booking->hoursCount());

        // لا ليالٍ ولا أيام: الحجز يقع في مداه وحده.
        $this->assertNull($booking->nights);
        $this->assertNull($booking->days_count);
        $this->assertNull($booking->check_out_date);
    }

    /**
     * الشاليه غير المسعَّر لأي فترة يُباع بالساعة: المبلغ متَّفق عليه، ولا
     * تسعيرة تُقرأ حتى يُشترط وجودها.
     */
    public function test_the_hour_needs_no_priced_period_behind_it(): void
    {
        $this->assertSame([], $this->chalet->load('prices')->dayUsePeriods());

        $this->actingAs($this->owner)
            ->post('/admin/bookings/chalets', $this->payload())
            ->assertSessionHasNoErrors();

        $this->assertSame(1, Booking::where('period', HourlyPeriod::PERIOD)->count());
    }

    /**
     * النهاية التي لا تتجاوز البداية تقع في الغد — لا مدًى بالسالب.
     */
    public function test_an_evening_that_runs_past_midnight_ends_the_next_day(): void
    {
        $this->actingAs($this->owner)
            ->post('/admin/bookings/chalets', $this->payload([
                'start_time' => '22:00',
                'end_time' => '01:00',
            ]))
            ->assertSessionHasNoErrors();

        $booking = Booking::latest('id')->firstOrFail();

        $this->assertSame('2026-10-05 22:00:00', $booking->starts_at->toDateTimeString());
        $this->assertSame('2026-10-06 01:00:00', $booking->ends_at->toDateTimeString());
        $this->assertSame(3.0, $booking->hoursCount());
    }

    /**
     * الساعات تقفل الشاليه في مداها كما تقفله الإقامة: حجزٌ بالساعة يمنع
     * إقامةً تتقاطع معه، والعكس.
     */
    public function test_the_hours_block_a_stay_that_overlaps_them(): void
    {
        $this->actingAs($this->owner)
            ->post('/admin/bookings/chalets', $this->payload())
            ->assertSessionHasNoErrors();

        UnitPrice::updateOrCreate(
            ['unit_id' => $this->chalet->id, 'unit_section_id' => null, 'period' => StayPeriod::PERIOD],
            ['weekday_price' => 900, 'weekend_price' => 900, 'is_active' => true],
        );

        $this->actingAs($this->owner)
            ->post('/admin/bookings/chalets', [
                'unit_id' => $this->chalet->id,
                'client_id' => Client::first()?->id,
                'scope' => 'whole',
                'period' => StayPeriod::PERIOD,
                'booking_date' => '2026-10-05',
                'check_out_date' => '2026-10-07',
            ])
            ->assertSessionHasErrors('availability');

        $this->assertSame(1, Booking::count());
    }

    /**
     * حارسا المدى: نصف ساعة أدنى، وما بلغ اليوم يُباع ليلةً لا ساعات.
     */
    public function test_a_span_shorter_or_longer_than_the_hour_is_refused(): void
    {
        $this->actingAs($this->owner)
            ->post('/admin/bookings/chalets', $this->payload(['start_time' => '16:00', 'end_time' => '16:10']))
            ->assertSessionHasErrors('end_time');

        $this->actingAs($this->owner)
            ->post('/admin/bookings/chalets', $this->payload(['start_time' => '16:00', 'end_time' => '15:30']))
            ->assertSessionHasErrors('end_time');

        $this->assertSame(0, Booking::count());
    }

    public function test_the_hours_are_required_when_the_booking_is_sold_by_the_hour(): void
    {
        $payload = $this->payload();
        unset($payload['start_time'], $payload['hourly_amount']);

        $this->actingAs($this->owner)
            ->post('/admin/bookings/chalets', $payload)
            ->assertSessionHasErrors(['start_time', 'hourly_amount']);
    }

    /**
     * الساعتان تُتركان في حالة الشاشة بعد التبديل إلى شكلٍ آخر، فلا يجوز أن
     * يردّ التحقق عليهما حجزًا لا يعرضهما.
     */
    public function test_a_stay_ignores_hours_left_behind_by_the_form(): void
    {
        UnitPrice::updateOrCreate(
            ['unit_id' => $this->chalet->id, 'unit_section_id' => null, 'period' => StayPeriod::PERIOD],
            ['weekday_price' => 900, 'weekend_price' => 900, 'is_active' => true],
        );

        $this->actingAs($this->owner)
            ->post('/admin/bookings/chalets', [
                'unit_id' => $this->chalet->id,
                'client_id' => Client::first()?->id,
                'scope' => 'whole',
                'period' => StayPeriod::PERIOD,
                'booking_date' => '2026-10-05',
                'check_out_date' => '2026-10-07',
                'start_time' => 'ليس وقتًا',
                'end_time' => '',
                'hourly_amount' => 'مبلغ',
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(StayPeriod::PERIOD, Booking::latest('id')->firstOrFail()->period);
    }

    /**
     * عدد الساعات ومداها يظهران في الصف وفي العقد: هما ما يُراجَع عند
     * التسليم، فلا يُقرآن من ذاكرة الموظف.
     */
    public function test_the_row_and_the_contract_read_the_hours(): void
    {
        $this->actingAs($this->owner)
            ->post('/admin/bookings/chalets', $this->payload(['start_time' => '16:00', 'end_time' => '20:30']))
            ->assertSessionHasNoErrors();

        $booking = Booking::latest('id')->firstOrFail();

        $this->assertSame('بالساعات', $booking->periodLabel());
        $this->assertSame('4 ساعات ونصف — من 4:00 م إلى 8:30 م', $booking->scheduleLabel());

        $this->actingAs($this->owner)
            ->get('/admin/bookings/chalets')
            ->assertInertia(fn ($page) => $page
                ->where('bookings.data.0.schedule_label', $booking->scheduleLabel())
                ->where('bookings.data.0.period_label', 'بالساعات'));
    }

    /**
     * عرض السعر يردّ المبلغ كما أُدخل: لا جدول يُقرأ منه، فالمبلغ هو الأساس
     * وعليه تُحسب الإضافات والخصم والضريبة.
     */
    public function test_the_quote_returns_the_entered_amount(): void
    {
        $this->actingAs($this->owner)
            ->postJson('/admin/bookings/chalets/quote', $this->payload(['hourly_amount' => 640]))
            ->assertOk()
            ->assertJsonPath('availability.ok', true)
            ->assertJsonPath('pricing.base_amount', 640)
            ->assertJsonPath('pricing.hours', 5);
    }

    /**
     * تعديل الحجز لا يُعيد المبلغ صفرًا ولا يُسقط ساعتيه: ما لم يُرسل يُقرأ
     * من الحجز نفسه.
     */
    public function test_an_edit_that_does_not_touch_the_terms_keeps_them(): void
    {
        $this->actingAs($this->owner)
            ->post('/admin/bookings/chalets', $this->payload())
            ->assertSessionHasNoErrors();

        $booking = Booking::latest('id')->firstOrFail();

        $this->actingAs($this->owner)
            ->put("/admin/bookings/chalets/{$booking->id}", [
                'unit_id' => $this->chalet->id,
                'client_id' => $booking->client_id,
                'scope' => 'whole',
                'period' => HourlyPeriod::PERIOD,
                'booking_date' => '2026-10-05',
                'start_time' => '16:00',
                'end_time' => '21:00',
                'hourly_amount' => 750,
                'notes' => 'تأخّر الوصول نصف ساعة',
            ])
            ->assertSessionHasNoErrors();

        $booking->refresh();

        $this->assertSame(750.0, (float) $booking->base_amount);
        $this->assertSame(5.0, $booking->hoursCount());
        $this->assertSame('تأخّر الوصول نصف ساعة', $booking->notes);
    }
}
