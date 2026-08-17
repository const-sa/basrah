<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Booking;
use App\Models\Client;
use App\Models\Role;
use App\Models\Unit;
use App\Models\User;
use App\Models\WhatsappMessage;
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
 * تسجيل الدفعات عبر المسارات، والاعتراف بالإيراد عند الإنهاء.
 */
class BookingPaymentsTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Booking $booking;

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

        $client = Client::create(['name' => 'عميل', 'mobile' => '0551112233']);

        $this->booking = app(BookingService::class)->create([
            'unit_id' => Unit::where('code', 'HALL-01')->value('id'),
            'client_id' => $client->id,
            'scope' => 'whole',
            'booking_date' => '2026-12-15',
            'period' => 'full_day',
            'status' => 'confirmed',
        ]);
    }

    public function test_recording_a_deposit_through_the_route_posts_its_entry(): void
    {
        $this->actingAs($this->owner)
            ->post("/admin/bookings/{$this->booking->id}/payments", [
                'type' => 'deposit', 'method' => 'cash',
                'amount' => 650, 'paid_on' => '2026-11-01', 'notify' => false,
            ])->assertSessionHasNoErrors();

        $this->assertSame(650.0, (float) $this->booking->fresh()->paid_amount);
        $this->assertSame(650.0, Account::where('code', Ledger::CASH)->first()->balance());
        $this->assertSame(650.0, Account::where('code', Ledger::UNEARNED_REVENUE)->first()->balance());
    }

    public function test_payment_can_notify_the_client_on_whatsapp(): void
    {
        $this->actingAs($this->owner)
            ->post("/admin/bookings/{$this->booking->id}/payments", [
                'type' => 'deposit', 'method' => 'cash',
                'amount' => 500, 'paid_on' => '2026-11-01', 'notify' => true,
            ])->assertSessionHasNoErrors();

        $this->assertSame(1, WhatsappMessage::where('purpose', 'payment')->count());
    }

    public function test_completing_a_booking_through_the_route_recognizes_revenue(): void
    {
        $this->actingAs($this->owner)->post("/admin/bookings/{$this->booking->id}/payments", [
            'type' => 'deposit', 'method' => 'cash', 'amount' => 650, 'paid_on' => '2026-11-01', 'notify' => false,
        ]);

        // الإنهاء من الواجهة يجب أن يمرّ بالخدمة لا بتحديث مباشر،
        // وإلا بقي الإيراد غير مثبت في الدفاتر.
        $this->actingAs($this->owner)
            ->patch("/admin/bookings/{$this->booking->id}/status", ['status' => 'completed'])
            ->assertSessionHasNoErrors();

        $total = (float) $this->booking->fresh()->total_amount;

        $this->assertSame('completed', $this->booking->fresh()->status);
        $this->assertSame($total, Account::where('code', Ledger::BOOKING_REVENUE)->first()->balance());
        $this->assertSame(0.0, Account::where('code', Ledger::UNEARNED_REVENUE)->first()->balance());
    }

    public function test_payments_endpoint_returns_the_ledger_and_summary(): void
    {
        $this->actingAs($this->owner)->post("/admin/bookings/{$this->booking->id}/payments", [
            'type' => 'deposit', 'method' => 'cash', 'amount' => 400, 'paid_on' => '2026-11-01', 'notify' => false,
        ]);

        $response = $this->actingAs($this->owner)
            ->getJson("/admin/bookings/{$this->booking->id}/payments")
            ->assertOk();

        // JSON يُسلسل 400.0 كعدد صحيح، فالمقارنة بالقيمة لا بالنوع
        $this->assertCount(1, $response->json('payments'));
        $this->assertEqualsWithDelta(400.0, $response->json('summary.paid_amount'), 0.01);
        $this->assertEqualsWithDelta(
            (float) $this->booking->total_amount - 400,
            $response->json('summary.remaining_amount'),
            0.01,
        );
    }

    public function test_refund_beyond_the_paid_amount_is_rejected(): void
    {
        $this->actingAs($this->owner)->post("/admin/bookings/{$this->booking->id}/payments", [
            'type' => 'deposit', 'method' => 'cash', 'amount' => 200, 'paid_on' => '2026-11-01', 'notify' => false,
        ]);

        $this->actingAs($this->owner)
            ->post("/admin/bookings/{$this->booking->id}/payments", [
                'type' => 'refund', 'method' => 'cash', 'amount' => 900, 'paid_on' => '2026-11-02', 'notify' => false,
            ])->assertSessionHasErrors('amount');

        $this->assertSame(200.0, (float) $this->booking->fresh()->paid_amount);
    }

    public function test_a_scoped_user_cannot_record_a_payment_on_another_unit(): void
    {
        $other = User::factory()->create([
            'role_id' => Role::where('slug', 'unit-supervisor')->value('id'),
            'is_active' => true,
            'has_all_units' => false,
        ]);
        $other->units()->sync([Unit::where('code', 'HALL-02')->value('id')]);

        $this->actingAs($other)
            ->post("/admin/bookings/{$this->booking->id}/payments", [
                'type' => 'deposit', 'method' => 'cash', 'amount' => 100, 'paid_on' => '2026-11-01',
            ])->assertForbidden();
    }

    public function test_reminder_route_sends_a_whatsapp_message(): void
    {
        $this->actingAs($this->owner)
            ->post("/admin/bookings/{$this->booking->id}/remind")
            ->assertSessionHasNoErrors();

        $this->assertSame(1, WhatsappMessage::where('purpose', 'reminder')->count());
    }
}
