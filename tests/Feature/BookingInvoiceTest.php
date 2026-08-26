<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Client;
use App\Models\Package;
use App\Models\Role;
use App\Models\Setting;
use App\Models\Unit;
use App\Models\User;
use App\Services\BookingService;
use Database\Seeders\AccountsSeeder;
use Database\Seeders\BookingSetupSeeder;
use Database\Seeders\RolesSeeder;
use Database\Seeders\UnitsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * فاتورة حجز القاعة — الفاتورة الضريبية المبسّطة التي تُطبع للعميل.
 */
class BookingInvoiceTest extends TestCase
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
            'role_id' => Role::where('slug', 'super-admin')->firstOrFail()->id,
            'is_active' => true,
            'has_all_units' => true,
        ]);

        $client = Client::create(['name' => 'خالد المطيري', 'mobile' => '0551234567']);

        $this->booking = app(BookingService::class)->create([
            'unit_id' => Unit::where('type', 'hall')->value('id'),
            'client_id' => $client->id,
            'scope' => 'whole',
            'booking_date' => '2026-09-10',
            'period' => 'full_day',
            'status' => 'confirmed',
            'guests_count' => 30,
        ]);
    }

    public function test_the_invoice_renders_with_the_bookings_own_amounts(): void
    {
        $this->actingAs($this->owner)
            ->get("/admin/bookings/{$this->booking->id}/invoice")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('admin/bookings/Invoice')
                ->where('invoice.number', $this->booking->reference)
                // JSON يُسقط الكسر الصفري، فتُقارن المبالغ عدديًا لا بنوعها.
                ->where('invoice.total_amount', fn ($v) => (float) $v === (float) $this->booking->total_amount)
                ->where('invoice.client_name', 'خالد المطيري')
                ->has('invoice.lines'));
    }

    /**
     * بلا تسجيل ضريبي تخرج الورقة فاتورةَ حجز بلا ضريبة ولا رمز:
     * فاتورة ضريبية برقم ضريبي فارغ لا يقرؤها تطبيق الهيئة.
     */
    public function test_without_a_tax_registration_there_is_no_vat_and_no_qr(): void
    {
        $this->actingAs($this->owner)
            ->get("/admin/bookings/{$this->booking->id}/invoice")
            ->assertInertia(fn ($page) => $page
                ->where('invoice.is_taxable', false)
                ->where('invoice.tax_amount', fn ($v) => (float) $v === 0.0)
                ->where('invoice.net_amount', fn ($v) => (float) $v === (float) $this->booking->total_amount)
                ->where('issuer.qr', null));
    }

    /**
     * الضريبة تُضاف فوق ما سُعِّر به الحجز، فيصير إجماليه شاملًا لها —
     * وهي نفسها التي عرضتها شاشة الحجز قبل الحفظ، فلا تفاجئ العميل.
     */
    public function test_vat_is_added_on_top_of_the_priced_amount(): void
    {
        $this->enableTax();

        $booking = $this->bookingWithTax();

        $net = $booking->netAmount();

        $this->actingAs($this->owner)
            ->get("/admin/bookings/{$booking->id}/invoice")
            ->assertInertia(fn ($page) => $page
                ->where('invoice.is_taxable', true)
                ->where('invoice.tax_rate', fn ($v) => (float) $v === 15.0)
                ->where('invoice.net_amount', fn ($v) => (float) $v === $net)
                ->where('invoice.tax_amount', fn ($v) => (float) $v === round($net * 0.15, 2))
                ->where('invoice.total_amount', fn ($v) => (float) $v === round($net * 1.15, 2))
                ->has('issuer.qr'));
    }

    /**
     * حجزٌ سُجِّل والضريبة معطّلة يبقى بلا ضريبة وإن فُعِّلت بعده: الضريبة
     * حُسبت يوم إنشائه ودخلت إجماليه، وتفعيلٌ لاحق لا يرفع ورقةً وُقّعت.
     */
    public function test_a_booking_priced_before_the_tax_was_enabled_stays_untaxed(): void
    {
        $this->enableTax();

        $this->actingAs($this->owner)
            ->get("/admin/bookings/{$this->booking->id}/invoice")
            ->assertInertia(fn ($page) => $page
                ->where('invoice.is_taxable', false)
                ->where('invoice.tax_amount', fn ($v) => (float) $v === 0.0)
                ->where('invoice.total_amount', fn ($v) => (float) $v === (float) $this->booking->total_amount));
    }

    public function test_the_package_appears_as_its_own_line(): void
    {
        $package = Package::create([
            'name' => 'باقة الضيافة',
            'unit_id' => $this->booking->unit_id,
            'price' => 300,
            'is_active' => true,
        ]);

        app(BookingService::class)->update($this->booking, [
            'unit_id' => $this->booking->unit_id,
            'client_id' => $this->booking->client_id,
            'scope' => 'whole',
            'booking_date' => $this->booking->booking_date->toDateString(),
            'period' => $this->booking->period,
            'package_id' => $package->id,
        ]);

        $this->actingAs($this->owner)
            ->get("/admin/bookings/{$this->booking->id}/invoice")
            ->assertInertia(fn ($page) => $page
                ->where('invoice.lines.1.name', 'باقة باقة الضيافة')
                ->where('invoice.lines.1.amount', fn ($v) => (float) $v === 300.0));
    }

    /**
     * المقبوض يظهر موزّعًا على طرقه — كما في فاتورة عامرة.
     */
    public function test_payments_are_grouped_by_their_method(): void
    {
        app(BookingService::class)->recordPayment($this->booking, [
            'type' => 'deposit', 'payment_method_id' => $this->paymentMethodId('cash'), 'amount' => 200, 'paid_on' => '2026-09-01',
        ], $this->owner->id);

        app(BookingService::class)->recordPayment($this->booking, [
            'type' => 'payment', 'payment_method_id' => $this->paymentMethodId('transfer'), 'amount' => 150, 'paid_on' => '2026-09-02',
        ], $this->owner->id);

        $this->actingAs($this->owner)
            ->get("/admin/bookings/{$this->booking->id}/invoice")
            ->assertInertia(fn ($page) => $page
                ->where('invoice.paid_amount', fn ($v) => (float) $v === 350.0)
                ->where('invoice.payment_status', 'مسدّدة جزئيًا')
                ->has('invoice.methods', 2));
    }

    public function test_a_scoped_user_cannot_open_another_units_invoice(): void
    {
        // مشرف وحدة لا مالكًا: مدير النظام يرى الوحدات كلها بحكم دوره.
        $other = User::factory()->create([
            'role_id' => Role::where('slug', 'unit-supervisor')->firstOrFail()->id,
            'is_active' => true,
            'has_all_units' => false,
        ]);

        $other->units()->sync([Unit::where('id', '!=', $this->booking->unit_id)->value('id')]);

        $this->actingAs($other)
            ->get("/admin/bookings/{$this->booking->id}/invoice")
            ->assertForbidden();
    }

    /**
     * حجزٌ سُعِّر والضريبة مفعّلة — الضريبة تدخل إجماليه عند إنشائه.
     */
    private function bookingWithTax(): Booking
    {
        return app(BookingService::class)->create([
            'unit_id' => $this->booking->unit_id,
            'client_id' => $this->booking->client_id,
            'scope' => 'whole',
            'booking_date' => '2026-10-15',
            'period' => 'full_day',
            'status' => 'confirmed',
            'guests_count' => 30,
        ]);
    }

    private function enableTax(): void
    {
        $settings = Setting::current();
        $settings->update([
            'business_name' => 'ديوان البصرة',
            'tax_enabled' => true,
            'tax_number' => '300000000000003',
            'tax_rate' => 15,
        ]);
    }
}
