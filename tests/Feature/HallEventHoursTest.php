<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Client;
use App\Models\Unit;
use App\Services\BookingService;
use Database\Seeders\RolesSeeder;
use Database\Seeders\UnitsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * المناسبة تأخذ القاعة بالساعات التي اتُّفق عليها.
 *
 * القاعة تُباع يوماً كاملاً بساعات الإعدادات، لكن المناسبة قد تُتفق على
 * غيرها: من الرابعة عصراً إلى العاشرة مساءً. فالساعتان تُكتبان على الحجز،
 * ومنهما يُبنى مداه الذي يُكشف به التعارض — والسعر يبقى سعر اليوم الكامل.
 */
class HallEventHoursTest extends TestCase
{
    use RefreshDatabase;

    private BookingService $service;

    private Unit $unit;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([RolesSeeder::class, UnitsSeeder::class]);
        $this->service = app(BookingService::class);
        $this->unit = Unit::where('code', 'HALL-02')->firstOrFail();
    }

    public function test_the_event_is_reserved_for_the_hours_it_was_given(): void
    {
        $booking = $this->book(['start_time' => '16:00', 'end_time' => '22:00']);

        $this->assertSame('2026-11-10 16:00', $booking->starts_at->format('Y-m-d H:i'));
        $this->assertSame('2026-11-10 22:00', $booking->ends_at->format('Y-m-d H:i'));
        $this->assertTrue($booking->hasCustomHours());
        $this->assertSame('يوم كامل — من 4:00 م إلى 10:00 م', $booking->scheduleLabel());
    }

    /** النهاية التي لا تتجاوز البداية تقع في الغد، كما تفعل الفترة العابرة للمنتصف. */
    public function test_an_event_running_past_midnight_ends_the_next_day(): void
    {
        $booking = $this->book(['start_time' => '21:00', 'end_time' => '02:00']);

        $this->assertSame('2026-11-10 21:00', $booking->starts_at->format('Y-m-d H:i'));
        $this->assertSame('2026-11-11 02:00', $booking->ends_at->format('Y-m-d H:i'));
    }

    /** الساعات تُضيّق المدى، فما خارجها يبقى متاحاً للقاعة نفسها. */
    public function test_another_event_may_take_the_hall_outside_those_hours(): void
    {
        $this->book(['start_time' => '10:00', 'end_time' => '14:00']);

        $second = $this->book(['start_time' => '16:00', 'end_time' => '22:00'], 'عميل ثانٍ');

        $this->assertSame('2026-11-10 16:00', $second->starts_at->format('Y-m-d H:i'));
    }

    public function test_an_overlapping_hour_is_still_refused(): void
    {
        $this->book(['start_time' => '16:00', 'end_time' => '22:00']);

        $this->expectException(ValidationException::class);

        $this->book(['start_time' => '20:00', 'end_time' => '23:00'], 'عميل ثانٍ');
    }

    /** بلا ساعتين تُقرأ ساعات الفترة من الإعدادات كما كانت — لا يتغيّر شيء. */
    public function test_an_event_without_hours_keeps_the_periods_own(): void
    {
        $booking = $this->book([]);

        $this->assertNull($booking->start_time);
        $this->assertFalse($booking->hasCustomHours());
        $this->assertSame('09:00', $booking->starts_at->format('H:i'));
    }

    /** مناسبةٌ بساعاتٍ خاصّة تقع في يومها مهما كُتب في عدد الأيام. */
    public function test_custom_hours_hold_the_event_to_one_day(): void
    {
        $booking = $this->book(['start_time' => '16:00', 'end_time' => '22:00', 'days_count' => 5]);

        $this->assertSame(1, $booking->days_count);
        $this->assertSame('2026-11-10 22:00', $booking->ends_at->format('Y-m-d H:i'));
    }

    /** السعر سعر اليوم الكامل: الساعات تحدّد المدى المحجوز لا المبلغ. */
    public function test_the_hours_do_not_change_the_price(): void
    {
        $full = $this->book([]);
        $hourly = $this->book(['start_time' => '16:00', 'end_time' => '22:00'], 'عميل ثانٍ', '2026-11-12');

        $this->assertSame((float) $full->base_amount, (float) $hourly->base_amount);
    }

    /** رسالة العميل تقول متى يدخل ومتى يخرج، لا اسم الفترة وحده. */
    public function test_the_period_variable_carries_its_hours(): void
    {
        $plain = $this->book([]);
        $custom = $this->book(['start_time' => '16:00', 'end_time' => '22:00'], 'عميل ثانٍ', '2026-11-14');

        $this->assertSame('يوم كامل — من 9:00 ص إلى 1:00 ص', $plain->periodWithHours());
        $this->assertSame('يوم كامل — من 4:00 م إلى 10:00 م', $custom->periodWithHours());
    }

    private function book(array $hours, string $client = 'عميل المناسبة', string $date = '2026-11-10'): Booking
    {
        return $this->service->create([
            'unit_id' => $this->unit->id,
            'client_id' => Client::create(['name' => $client, 'mobile' => '05'.random_int(10000000, 99999999)])->id,
            'scope' => 'whole',
            'booking_date' => $date,
            'period' => 'full_day',
            'status' => 'deposit_paid',
            ...$hours,
        ]);
    }
}
