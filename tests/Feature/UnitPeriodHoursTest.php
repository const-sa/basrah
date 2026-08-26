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
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * ساعات الفترة على الوحدة نفسها.
 *
 * ساعات الحجز إعدادٌ عام، والوحدة تكتب ساعاتها فوقه من شاشة أسعارها. وليست
 * الساعة هنا نصًّا يُعرض: عليها يُبنى مدى الحجز (starts_at → ends_at) وبه
 * يُكشف التعارض، فتُفحص النتيجة في المدى لا في العمود المحفوظ وحده.
 */
class UnitPeriodHoursTest extends TestCase
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

    /**
     * @param  array<string, array{start: string, end: string}>  $hours
     * @param  list<array<string, mixed>>  $prices
     */
    private function savePrices(array $hours, array $prices = []): TestResponse
    {
        return $this->actingAs($this->owner)->put("/admin/units/{$this->chalet->id}/prices", [
            'hours' => $hours,
            'prices' => $prices ?: [
                ['unit_section_id' => null, 'period' => StayPeriod::PERIOD, 'weekday_price' => 600, 'weekend_price' => 900],
            ],
        ]);
    }

    public function test_the_pricing_screen_saves_the_hours_of_a_period(): void
    {
        $this->savePrices([StayPeriod::PERIOD => ['start' => '15:00', 'end' => '11:00']])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->chalet->refresh();

        $this->assertSame(['start' => '15:00', 'end' => '11:00'], $this->chalet->periodHours(StayPeriod::PERIOD));
        $this->assertSame('15:00', StayPeriod::checkInTime($this->chalet));
        $this->assertSame('11:00', StayPeriod::checkOutTime($this->chalet));
    }

    /**
     * الوحدة التي لم تُكتب لها ساعة تبقى على ساعة الإعدادات — الوحدات القائمة
     * تعمل بعد هذا العمود كما كانت قبله.
     */
    public function test_a_unit_without_hours_reads_the_client_wide_ones(): void
    {
        $this->assertNull($this->chalet->periodHours(StayPeriod::PERIOD));
        $this->assertSame('16:00', StayPeriod::checkInTime($this->chalet));
        $this->assertSame('09:00', BookingPeriod::periodsFor($this->chalet)['morning']['start']);
    }

    /**
     * الساعة تصل إلى مدى الحجز المحفوظ، وهو ما يُقاس عليه التعارض.
     */
    public function test_the_hours_build_the_saved_stay_range(): void
    {
        $this->savePrices([StayPeriod::PERIOD => ['start' => '14:30', 'end' => '10:00']])
            ->assertSessionHasNoErrors();

        $this->actingAs($this->owner)->post('/admin/bookings/chalets', [
            'unit_id' => $this->chalet->id,
            'client_id' => Client::first()?->id,
            'scope' => 'whole',
            'period' => StayPeriod::PERIOD,
            'booking_date' => '2026-10-05',
            'check_out_date' => '2026-10-07',
        ])->assertSessionHasNoErrors();

        $booking = Booking::latest('id')->firstOrFail();

        $this->assertSame('2026-10-05 14:30:00', $booking->starts_at->toDateTimeString());
        $this->assertSame('2026-10-07 10:00:00', $booking->ends_at->toDateTimeString());
    }

    /**
     * ساعة الوحدة تحكم فترات اليوم كذلك، وعبور منتصف الليل يُستنتج من
     * الساعتين لا من علم مخزَّن: مسائيٌّ يُقفل الحادية عشرة لا يغلق صباح الغد.
     */
    public function test_a_day_period_takes_the_unit_hours_and_re_derives_the_midnight_crossing(): void
    {
        UnitPrice::updateOrCreate(
            ['unit_id' => $this->chalet->id, 'unit_section_id' => null, 'period' => 'evening'],
            ['weekday_price' => 400, 'weekend_price' => 400, 'is_active' => true],
        );

        [, $shared] = BookingPeriod::range('2026-10-05', 'evening');
        $this->assertSame('2026-10-06 01:00:00', $shared->toDateTimeString());

        $this->chalet->update(['period_hours' => ['evening' => ['start' => '18:00', 'end' => '23:00']]]);

        [$starts, $ends] = BookingPeriod::range('2026-10-05', 'evening', 1, $this->chalet->fresh());

        $this->assertSame('2026-10-05 18:00:00', $starts->toDateTimeString());
        $this->assertSame('2026-10-05 23:00:00', $ends->toDateTimeString());
        $this->assertFalse(BookingPeriod::periodsFor($this->chalet->fresh())['evening']['overnight']);
    }

    /**
     * الساعتان تُكتبان معًا: نصف مدًى لا يُبنى منه حجز، فيُرفض عند الحفظ بدل
     * أن يُخزَّن نصفه ويُقرأ نصفه الآخر من مكان آخر.
     */
    public function test_half_a_pair_of_hours_is_rejected(): void
    {
        $this->savePrices([StayPeriod::PERIOD => ['start' => '15:00', 'end' => '']])
            ->assertSessionHasErrors('hours.'.StayPeriod::PERIOD.'.end');

        $this->savePrices([StayPeriod::PERIOD => ['start' => '25:61', 'end' => '11:00']])
            ->assertSessionHasErrors('hours.'.StayPeriod::PERIOD.'.start');
    }

    /**
     * تفريغ الخانتين إرجاعٌ إلى ساعات الإعدادات لا حفظُ فراغ.
     */
    public function test_clearing_both_boxes_returns_the_period_to_the_client_wide_hours(): void
    {
        $this->savePrices([StayPeriod::PERIOD => ['start' => '15:00', 'end' => '11:00']])
            ->assertSessionHasNoErrors();

        $this->savePrices([StayPeriod::PERIOD => ['start' => '', 'end' => '']])
            ->assertSessionHasNoErrors();

        $this->chalet->refresh();

        $this->assertNull($this->chalet->period_hours);
        $this->assertSame('16:00', StayPeriod::checkInTime($this->chalet));
    }

    /**
     * القاعة تُباع باليوم الكامل وحده، فساعة «الليلة» عليها لا يقرؤها أحد
     * ولا تُخزَّن.
     */
    public function test_a_hall_may_only_write_hours_for_the_periods_it_is_sold_in(): void
    {
        $hall = Unit::where('type', 'hall')->firstOrFail();

        $this->actingAs($this->owner)->put("/admin/units/{$hall->id}/prices", [
            'hours' => [
                'full_day' => ['start' => '10:00', 'end' => '02:00'],
                StayPeriod::PERIOD => ['start' => '15:00', 'end' => '11:00'],
            ],
            'prices' => [
                ['unit_section_id' => null, 'period' => 'full_day', 'weekday_price' => 5000, 'weekend_price' => 7000],
            ],
        ])->assertSessionHasNoErrors();

        $hall->refresh();

        $this->assertSame(['full_day' => ['start' => '10:00', 'end' => '02:00']], $hall->period_hours);
    }

    /**
     * الساعة تصل إلى شاشة الحجز مع الوحدة نفسها، فتُعرض ساعتها لا ساعة النظام.
     */
    public function test_the_booking_form_carries_each_unit_hours(): void
    {
        $this->chalet->update(['period_hours' => [StayPeriod::PERIOD => ['start' => '13:00', 'end' => '09:00']]]);

        $this->actingAs($this->owner)
            ->get('/admin/bookings/chalets/create')
            ->assertInertia(fn ($page) => $page
                ->where(
                    'units.'.$this->unitIndex().'.hours.'.StayPeriod::PERIOD,
                    ['start' => '13:00', 'end' => '09:00'],
                ));
    }

    /**
     * موضع الشاليه في قائمة وحدات الشاشة — مرتبةً بـ sort_order كما ترسلها.
     */
    private function unitIndex(): int
    {
        return Unit::where('type', 'chalet')
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->pluck('id')
            ->search($this->chalet->id);
    }
}
