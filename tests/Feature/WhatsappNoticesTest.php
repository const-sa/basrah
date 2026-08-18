<?php

namespace Tests\Feature;

use App\Jobs\SendWhatsappMessage;
use App\Models\Booking;
use App\Models\Client;
use App\Models\Role;
use App\Models\Unit;
use App\Models\User;
use App\Models\WhatsappMessage;
use Database\Seeders\BookingSetupSeeder;
use Database\Seeders\RolesSeeder;
use Database\Seeders\UnitsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * رسائل الواتساب المستحدثة (§14): الفاتورة، إشعار الإلغاء، تذكير المتبقي.
 */
class WhatsappNoticesTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Client $client;

    private Unit $unit;

    private int $sequence = 0;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();

        $this->seed([RolesSeeder::class, UnitsSeeder::class, BookingSetupSeeder::class]);

        $this->owner = User::factory()->create([
            'name' => 'مالك النظام',
            'role_id' => Role::where('slug', 'super-admin')->firstOrFail()->id,
            'is_active' => true,
            'has_all_units' => true,
        ]);

        $this->unit = Unit::where('code', 'HALL-01')->firstOrFail();
        $this->client = Client::create(['name' => 'أبو محمد', 'mobile' => '0551112222', 'is_active' => true]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function booking(array $attributes = []): Booking
    {
        return Booking::create([
            'reference' => 'a-'.(++$this->sequence),
            'unit_id' => $this->unit->id,
            'client_id' => $this->client->id,
            'created_by' => $this->owner->id,
            'scope' => 'whole',
            'period' => 'evening',
            'booking_date' => '2026-08-20',
            'starts_at' => '2026-08-20 18:00:00',
            'ends_at' => '2026-08-20 23:00:00',
            'status' => 'confirmed',
            'base_amount' => 1000,
            'total_amount' => 1000,
            'paid_amount' => 400,
            ...$attributes,
        ]);
    }

    public function test_the_invoice_is_sent_and_logged(): void
    {
        $booking = $this->booking();

        $this->actingAs($this->owner)
            ->post("/admin/bookings/{$booking->id}/invoice/send")
            ->assertRedirect()
            ->assertSessionHas('success');

        $message = WhatsappMessage::where('purpose', 'invoice')->firstOrFail();

        $this->assertSame('966551112222', $message->to_number);
        $this->assertStringContainsString($booking->reference, $message->body);
        $this->assertStringContainsString('600', $message->body);
        $this->assertSame(Booking::class, $message->related_type);

        Queue::assertPushed(SendWhatsappMessage::class);
    }

    public function test_the_balance_reminder_states_what_is_left(): void
    {
        $booking = $this->booking();

        $this->actingAs($this->owner)
            ->post("/admin/bookings/{$booking->id}/remind-balance")
            ->assertRedirect()
            ->assertSessionHas('success');

        $message = WhatsappMessage::where('purpose', 'balance_reminder')->firstOrFail();

        $this->assertStringContainsString('المتبقي', $message->body);
        $this->assertStringContainsString('600', $message->body);
    }

    /**
     * من سدَّد لا يُطالَب — ولا تُستهلك محادثة في رسالة بلا معنى.
     */
    public function test_no_balance_reminder_for_a_settled_booking(): void
    {
        $booking = $this->booking(['paid_amount' => 1000]);

        $this->actingAs($this->owner)
            ->post("/admin/bookings/{$booking->id}/remind-balance")
            ->assertSessionHas('warning');

        $this->assertSame(0, WhatsappMessage::where('purpose', 'balance_reminder')->count());
    }

    public function test_a_client_without_a_mobile_gets_no_message(): void
    {
        $silent = Client::create(['name' => 'بلا جوال', 'is_active' => true]);
        $booking = $this->booking(['client_id' => $silent->id]);

        $this->actingAs($this->owner)
            ->post("/admin/bookings/{$booking->id}/invoice/send")
            ->assertSessionHas('warning');

        $this->assertSame(0, WhatsappMessage::count());
    }

    /**
     * إشعار الإلغاء يُرسل بطلبٍ صريح، ويذكر ما دُفع لأنه أول ما يُسأل عنه.
     */
    public function test_the_cancellation_notice_is_sent_only_when_asked(): void
    {
        $booking = $this->booking();

        $this->actingAs($this->owner)
            ->patch("/admin/bookings/{$booking->id}/status", [
                'status' => 'cancelled',
                'reason' => 'ظرف طارئ',
                'notify' => false,
            ])
            ->assertRedirect();

        $this->assertSame(0, WhatsappMessage::where('purpose', 'cancellation')->count());

        $second = $this->booking();

        $this->patch("/admin/bookings/{$second->id}/status", [
            'status' => 'cancelled',
            'reason' => 'ظرف طارئ',
            'notify' => true,
        ])->assertRedirect();

        $message = WhatsappMessage::where('purpose', 'cancellation')->firstOrFail();

        $this->assertStringContainsString($second->reference, $message->body);
        $this->assertStringContainsString('ظرف طارئ', $message->body);
        $this->assertStringContainsString('400', $message->body);
    }

    /**
     * الإرسال بابٌ محروس بصلاحية الواتساب لا بصلاحية الحجوزات.
     */
    public function test_sending_requires_the_whatsapp_permission(): void
    {
        $role = Role::create(['name' => 'حجوزات بلا واتساب', 'slug' => 'no-wa', 'permissions' => ['bookings.view', 'bookings.edit']]);

        $staff = User::factory()->create(['role_id' => $role->id, 'is_active' => true, 'has_all_units' => true]);
        $booking = $this->booking();

        $this->actingAs($staff)->post("/admin/bookings/{$booking->id}/invoice/send")->assertForbidden();
        $this->actingAs($staff)->post("/admin/bookings/{$booking->id}/remind-balance")->assertForbidden();
    }

    public function test_the_log_screen_knows_the_new_purposes(): void
    {
        $this->assertArrayHasKey('invoice', WhatsappMessage::PURPOSES);
        $this->assertArrayHasKey('cancellation', WhatsappMessage::PURPOSES);
        $this->assertArrayHasKey('balance_reminder', WhatsappMessage::PURPOSES);
    }
}
