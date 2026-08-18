<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Client;
use App\Models\Setting;
use App\Models\Unit;
use App\Models\User;
use App\Models\WhatsappMessage;
use Database\Seeders\BookingSetupSeeder;
use Database\Seeders\RolesSeeder;
use Database\Seeders\UnitsSeeder;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * تذكيرات الحجوزات المجدولة (§14): تمشي وحدها كل صباح.
 */
class ScheduledRemindersTest extends TestCase
{
    use RefreshDatabase;

    private Unit $unit;

    private Client $client;

    private int $sequence = 0;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();

        $this->seed([RolesSeeder::class, UnitsSeeder::class, BookingSetupSeeder::class]);

        $this->unit = Unit::where('code', 'HALL-01')->firstOrFail();
        $this->client = Client::create(['name' => 'أبو محمد', 'mobile' => '0551112222', 'is_active' => true]);

        $settings = Setting::current();
        $settings->wa_enabled = true;
        $settings->save();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function booking(array $attributes = []): Booking
    {
        $date = $attributes['booking_date'] ?? now()->addDay()->toDateString();

        return Booking::create([
            'reference' => 'a-'.(++$this->sequence),
            'unit_id' => $this->unit->id,
            'client_id' => $this->client->id,
            'scope' => 'whole',
            'period' => 'evening',
            'booking_date' => $date,
            'starts_at' => $date.' 18:00:00',
            'ends_at' => $date.' 23:00:00',
            'status' => 'confirmed',
            'base_amount' => 1000,
            'total_amount' => 1000,
            'paid_amount' => 400,
            ...$attributes,
        ]);
    }

    public function test_only_bookings_of_the_target_day_are_reminded(): void
    {
        $tomorrow = $this->booking();
        $this->booking(['booking_date' => now()->addDays(5)->toDateString()]);

        $this->artisan('bookings:send-reminders')->assertSuccessful();

        $messages = WhatsappMessage::where('purpose', 'reminder')->get();

        $this->assertCount(1, $messages);
        $this->assertSame($tomorrow->id, $messages->first()->related_id);
    }

    /**
     * الحجز الملغى لا يُذكَّر صاحبه بموعدٍ لم يعد قائمًا.
     */
    public function test_cancelled_bookings_are_skipped(): void
    {
        $this->booking(['status' => 'cancelled']);

        $this->artisan('bookings:send-reminders')->assertSuccessful();

        $this->assertSame(0, WhatsappMessage::where('purpose', 'reminder')->count());
    }

    /**
     * تشغيل الأمر مرتين لا يُزعج العميل مرتين.
     */
    public function test_a_booking_is_not_reminded_twice(): void
    {
        $this->booking();

        $this->artisan('bookings:send-reminders')->assertSuccessful();
        $this->artisan('bookings:send-reminders')->assertSuccessful();

        $this->assertSame(1, WhatsappMessage::where('purpose', 'reminder')->count());
    }

    public function test_nothing_is_sent_when_the_integration_is_disabled(): void
    {
        $settings = Setting::current();
        $settings->wa_enabled = false;
        $settings->save();

        $this->booking();

        $this->artisan('bookings:send-reminders')->assertSuccessful();

        $this->assertSame(0, WhatsappMessage::count());
    }

    public function test_a_client_without_a_mobile_is_skipped(): void
    {
        $silent = Client::create(['name' => 'بلا جوال', 'is_active' => true]);
        $this->booking(['client_id' => $silent->id]);

        $this->artisan('bookings:send-reminders')->assertSuccessful();

        $this->assertSame(0, WhatsappMessage::count());
    }

    /**
     * المهمتان مسجَّلتان في المجدول — وبدونهما لا يمشي شيء ولو كان cron قائمًا.
     */
    public function test_the_scheduler_knows_both_nightly_tasks(): void
    {
        $commands = collect(app(Schedule::class)->events())
            ->map(fn ($event) => $event->command ?? '')
            ->implode(' | ');

        $this->assertStringContainsString('backup:run', $commands);
        $this->assertStringContainsString('bookings:send-reminders', $commands);
    }

    public function test_the_days_option_overrides_the_configured_window(): void
    {
        $this->booking(['booking_date' => now()->addDays(3)->toDateString()]);

        $this->artisan('bookings:send-reminders')->assertSuccessful();
        $this->assertSame(0, WhatsappMessage::count());

        $this->artisan('bookings:send-reminders --days=3')->assertSuccessful();
        $this->assertSame(1, WhatsappMessage::where('purpose', 'reminder')->count());
    }

    /**
     * الرسالة تُنسب إلى النظام لا إلى موظف — لا مستخدم يقف خلف المجدول.
     */
    public function test_the_scheduled_message_has_no_sender(): void
    {
        User::factory()->create();
        $this->booking();

        $this->artisan('bookings:send-reminders')->assertSuccessful();

        $this->assertNull(WhatsappMessage::where('purpose', 'reminder')->firstOrFail()->sent_by);
    }
}
