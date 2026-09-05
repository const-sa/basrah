<?php

namespace Tests\Feature;

use App\Jobs\SendWhatsappMessage;
use App\Models\Booking;
use App\Models\Client;
use App\Models\NotificationTemplate;
use App\Models\Role;
use App\Models\Setting;
use App\Models\Unit;
use App\Models\User;
use App\Models\WhatsappMessage;
use App\Services\WhatsappNotifier;
use Database\Seeders\BookingSetupSeeder;
use Database\Seeders\NotificationTemplatesSeeder;
use Database\Seeders\RolesSeeder;
use Database\Seeders\UnitsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * قوالب الإشعارات المنفصلة للشاليهات والقاعات.
 */
class NotificationTemplatesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();

        $this->seed([
            RolesSeeder::class,
            UnitsSeeder::class,
            BookingSetupSeeder::class,
            NotificationTemplatesSeeder::class,
        ]);
    }

    public function test_chalet_booking_confirmation_uses_chalet_template(): void
    {
        $booking = $this->bookingFor('CH-BSR1');

        app(WhatsappNotifier::class)->bookingConfirmed($booking);

        $message = WhatsappMessage::where('purpose', 'booking_confirm')->firstOrFail();

        $this->assertStringContainsString('تم تأكيد حجز الشاليه بنجاح', $message->body);
    }

    public function test_hall_booking_confirmation_uses_hall_template(): void
    {
        $booking = $this->bookingFor('HALL-01');

        app(WhatsappNotifier::class)->bookingConfirmed($booking);

        $message = WhatsappMessage::where('purpose', 'booking_confirm')->latest('id')->firstOrFail();

        $this->assertStringContainsString('تم تأكيد حجز القاعة بنجاح', $message->body);
    }

    public function test_hall_invoice_uses_hall_template(): void
    {
        $booking = $this->bookingFor('HALL-01');

        app(WhatsappNotifier::class)->invoice($booking);

        $this->assertStringContainsString('فاتورة حجز القاعة', NotificationTemplate::resolve('invoice', 'hall')?->body ?? '');
        $this->assertStringContainsString('فاتورة حجز القاعة', WhatsappMessage::where('purpose', 'invoice')->firstOrFail()->body);
    }

    public function test_quick_client_from_chalet_form_gets_chalet_welcome(): void
    {
        $owner = User::factory()->create([
            'role_id' => Role::where('slug', 'super-admin')->firstOrFail()->id,
            'is_active' => true,
            'has_all_units' => true,
        ]);

        $settings = Setting::current();
        $settings->wa_enabled = true;
        $settings->wa_welcome_enabled = true;
        $settings->save();

        $this->actingAs($owner)->postJson('/admin/clients/quick', [
            'name' => 'عميل شاليه',
            'mobile' => '0551113333',
            'type' => 'chalet',
        ])->assertCreated();

        Queue::assertPushed(SendWhatsappMessage::class, function (SendWhatsappMessage $job) {
            return str_contains($job->message, 'شاليهاتنا بمسابح خاصة');
        });
    }

    private function bookingFor(string $unitCode): Booking
    {
        $client = Client::create(['name' => 'اختبار', 'mobile' => '0559998888', 'is_active' => true]);
        $unit = Unit::where('code', $unitCode)->firstOrFail();

        return Booking::create([
            'reference' => 'T-'.uniqid(),
            'unit_id' => $unit->id,
            'client_id' => $client->id,
            'scope' => 'whole',
            'period' => 'evening',
            'booking_date' => '2026-08-20',
            'starts_at' => '2026-08-20 18:00:00',
            'ends_at' => '2026-08-20 23:00:00',
            'status' => 'deposit_paid',
            'base_amount' => 1000,
            'total_amount' => 1000,
            'paid_amount' => 400,
        ]);
    }
}
