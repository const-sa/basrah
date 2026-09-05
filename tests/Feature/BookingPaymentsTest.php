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
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
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
            'status' => 'deposit_paid',
        ]);
    }

    public function test_recording_a_deposit_through_the_route_posts_its_entry(): void
    {
        $this->actingAs($this->owner)
            ->post("/admin/bookings/{$this->booking->id}/payments", [
                'type' => 'deposit', 'payment_method_id' => $this->paymentMethodId('cash'),
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
                'type' => 'deposit', 'payment_method_id' => $this->paymentMethodId('cash'),
                'amount' => 500, 'paid_on' => '2026-11-01', 'notify' => true,
            ])->assertSessionHasNoErrors();

        $this->assertSame(1, WhatsappMessage::where('purpose', 'payment')->count());
    }

    public function test_completing_a_booking_through_the_route_recognizes_revenue(): void
    {
        $this->actingAs($this->owner)->post("/admin/bookings/{$this->booking->id}/payments", [
            'type' => 'deposit', 'payment_method_id' => $this->paymentMethodId('cash'), 'amount' => 650, 'paid_on' => '2026-11-01', 'notify' => false,
        ]);

        // الإقفال مسدَّدًا من الواجهة يجب أن يمرّ بالخدمة لا بتحديث مباشر،
        // وإلا بقي الإيراد غير مثبت في الدفاتر.
        $this->actingAs($this->owner)
            ->patch("/admin/bookings/{$this->booking->id}/status", ['status' => 'paid_in_full'])
            ->assertSessionHasNoErrors();

        $total = (float) $this->booking->fresh()->total_amount;

        $this->assertSame('paid_in_full', $this->booking->fresh()->status);
        $this->assertSame($total, Account::where('code', Ledger::BOOKING_REVENUE)->first()->balance());
        $this->assertSame(0.0, Account::where('code', Ledger::UNEARNED_REVENUE)->first()->balance());
    }

    public function test_payments_endpoint_returns_the_ledger_and_summary(): void
    {
        $this->actingAs($this->owner)->post("/admin/bookings/{$this->booking->id}/payments", [
            'type' => 'deposit', 'payment_method_id' => $this->paymentMethodId('cash'), 'amount' => 400, 'paid_on' => '2026-11-01', 'notify' => false,
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
            'type' => 'deposit', 'payment_method_id' => $this->paymentMethodId('cash'), 'amount' => 200, 'paid_on' => '2026-11-01', 'notify' => false,
        ]);

        $this->actingAs($this->owner)
            ->post("/admin/bookings/{$this->booking->id}/payments", [
                'type' => 'refund', 'payment_method_id' => $this->paymentMethodId('cash'), 'amount' => 900, 'paid_on' => '2026-11-02', 'notify' => false,
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
                'type' => 'deposit', 'payment_method_id' => $this->paymentMethodId('cash'), 'amount' => 100, 'paid_on' => '2026-11-01',
            ])->assertForbidden();
    }

    public function test_a_transfer_receipt_can_be_attached_to_a_payment(): void
    {
        Storage::fake('public');

        $this->actingAs($this->owner)
            ->post("/admin/bookings/{$this->booking->id}/payments", [
                'type' => 'payment', 'payment_method_id' => $this->paymentMethodId('cash'),
                'amount' => 300, 'paid_on' => '2026-11-01', 'reference' => 'TRX-9', 'notify' => false,
                'attachment' => UploadedFile::fake()->image('receipt.jpg'),
            ])->assertSessionHasNoErrors();

        $payment = $this->booking->payments()->latest('id')->first();

        $this->assertNotNull($payment->attachment_path);
        Storage::disk('public')->assertExists($payment->attachment_path);

        // اللوحة تقرأ المرفق من نفس المسار الذي يعرض الدفعات.
        $this->actingAs($this->owner)
            ->getJson("/admin/bookings/{$this->booking->id}/payments")
            ->assertOk()
            ->assertJsonPath('payments.0.attachment_url', Storage::url($payment->attachment_path));
    }

    public function test_an_executable_receipt_is_refused(): void
    {
        Storage::fake('public');

        $this->actingAs($this->owner)
            ->post("/admin/bookings/{$this->booking->id}/payments", [
                'type' => 'payment', 'payment_method_id' => $this->paymentMethodId('cash'),
                'amount' => 300, 'paid_on' => '2026-11-01', 'notify' => false,
                'attachment' => UploadedFile::fake()->create('receipt.php', 10, 'text/x-php'),
            ])->assertSessionHasErrors('attachment');

        $this->assertSame(0.0, (float) $this->booking->fresh()->paid_amount);
    }

    public function test_the_invoice_lists_the_attached_receipts(): void
    {
        Storage::fake('public');

        $this->actingAs($this->owner)->post("/admin/bookings/{$this->booking->id}/payments", [
            'type' => 'deposit', 'payment_method_id' => $this->paymentMethodId('cash'),
            'amount' => 250, 'paid_on' => '2026-11-01', 'notify' => false,
            'attachment' => UploadedFile::fake()->image('transfer.png'),
        ]);

        $this->actingAs($this->owner)
            ->get("/admin/bookings/{$this->booking->id}/invoice")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('invoice.payments', 1)
                ->where('invoice.payments.0.amount', 250)
                ->whereNot('invoice.payments.0.url', null));
    }

    public function test_a_payment_without_a_receipt_still_appears_on_the_invoice(): void
    {
        $this->recordPayment();

        // الصفّ الخالي من إيصال هو موضع زرّ الإرفاق — إخفاؤه يُخفي الباب نفسه.
        $this->actingAs($this->owner)
            ->get("/admin/bookings/{$this->booking->id}/invoice")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('invoice.payments', 1)
                ->where('invoice.payments.0.url', null));
    }

    public function test_a_receipt_can_be_attached_from_the_invoice_page(): void
    {
        Storage::fake('public');

        $payment = $this->recordPayment();

        $this->actingAs($this->owner)
            ->post("/admin/bookings/{$this->booking->id}/payments/{$payment->id}/receipt", [
                'receipt' => UploadedFile::fake()->create('transfer.pdf', 40, 'application/pdf'),
            ])->assertSessionHasNoErrors();

        Storage::disk('public')->assertExists($payment->fresh()->attachment_path);
    }

    public function test_replacing_a_receipt_drops_the_previous_file(): void
    {
        Storage::fake('public');

        $payment = $this->recordPayment();

        $this->actingAs($this->owner)->post("/admin/bookings/{$this->booking->id}/payments/{$payment->id}/receipt", [
            'receipt' => UploadedFile::fake()->image('first.png'),
        ]);

        $first = $payment->fresh()->attachment_path;

        $this->actingAs($this->owner)->post("/admin/bookings/{$this->booking->id}/payments/{$payment->id}/receipt", [
            'receipt' => UploadedFile::fake()->image('second.png'),
        ])->assertSessionHasNoErrors();

        $second = $payment->fresh()->attachment_path;

        $this->assertNotSame($first, $second);
        Storage::disk('public')->assertMissing($first);
        Storage::disk('public')->assertExists($second);
    }

    public function test_a_receipt_can_be_removed_from_the_invoice_page(): void
    {
        Storage::fake('public');

        $payment = $this->recordPayment();

        $this->actingAs($this->owner)->post("/admin/bookings/{$this->booking->id}/payments/{$payment->id}/receipt", [
            'receipt' => UploadedFile::fake()->image('transfer.png'),
        ]);

        $stored = $payment->fresh()->attachment_path;

        $this->actingAs($this->owner)
            ->delete("/admin/bookings/{$this->booking->id}/payments/{$payment->id}/receipt")
            ->assertSessionHasNoErrors();

        $this->assertNull($payment->fresh()->attachment_path);
        Storage::disk('public')->assertMissing($stored);
    }

    public function test_a_receipt_cannot_be_attached_through_another_bookings_id(): void
    {
        Storage::fake('public');

        $payment = $this->recordPayment();

        $other = app(BookingService::class)->create([
            'unit_id' => Unit::where('code', 'HALL-01')->value('id'),
            'client_id' => $this->booking->client_id,
            'scope' => 'whole',
            'booking_date' => '2026-12-20',
            'period' => 'full_day',
            'status' => 'deposit_paid',
        ]);

        // رقم الدفعة في الرابط لا يفتحها إلا تحت حجزها.
        $this->actingAs($this->owner)
            ->post("/admin/bookings/{$other->id}/payments/{$payment->id}/receipt", [
                'receipt' => UploadedFile::fake()->image('transfer.png'),
            ])->assertNotFound();

        $this->assertNull($payment->fresh()->attachment_path);
    }

    public function test_a_scoped_user_cannot_attach_a_receipt_on_another_unit(): void
    {
        Storage::fake('public');

        $payment = $this->recordPayment();

        $other = User::factory()->create([
            'role_id' => Role::where('slug', 'unit-supervisor')->value('id'),
            'is_active' => true,
            'has_all_units' => false,
        ]);
        $other->units()->sync([Unit::where('code', 'HALL-02')->value('id')]);

        $this->actingAs($other)
            ->post("/admin/bookings/{$this->booking->id}/payments/{$payment->id}/receipt", [
                'receipt' => UploadedFile::fake()->image('transfer.png'),
            ])->assertForbidden();
    }

    /** دفعة بسيطة بلا مرفق — نقطة البدء لاختبارات الإرفاق من الفاتورة. */
    private function recordPayment(): \App\Models\BookingPayment
    {
        $this->actingAs($this->owner)->post("/admin/bookings/{$this->booking->id}/payments", [
            'type' => 'deposit', 'payment_method_id' => $this->paymentMethodId('cash'),
            'amount' => 250, 'paid_on' => '2026-11-01', 'notify' => false,
        ]);

        return $this->booking->payments()->latest('id')->firstOrFail();
    }

    public function test_reminder_route_sends_a_whatsapp_message(): void
    {
        $this->actingAs($this->owner)
            ->post("/admin/bookings/{$this->booking->id}/remind")
            ->assertSessionHasNoErrors();

        $this->assertSame(1, WhatsappMessage::where('purpose', 'reminder')->count());
    }
}
