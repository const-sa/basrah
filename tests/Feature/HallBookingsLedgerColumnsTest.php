<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Client;
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
 * أعمدة الدفتر في سجل حجوزات القاعات: من مبلغ الحجز إلى المسترجع،
 * ومجاميع الصفحة ومجاميع كل الصفحات المفلترة.
 */
class HallBookingsLedgerColumnsTest extends TestCase
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
            'discount_amount' => 100,
        ]);
    }

    public function test_every_ledger_column_reaches_the_row(): void
    {
        $this->actingAs($this->owner)
            ->get('/admin/bookings/halls')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('admin/bookings/halls/Index')
                ->has('bookings.data.0', fn ($row) => $row
                    ->where('reference', $this->booking->reference)
                    ->has('client.mobile')
                    ->has('event_type')
                    ->has('sections')
                    ->has('last_day_date')
                    ->has('subtotal_amount')
                    ->has('discount_amount')
                    ->has('deposit_amount')
                    ->has('tax_amount')
                    ->has('total_amount')
                    ->has('paid_amount')
                    ->has('paid_by_method')
                    ->has('remaining_amount')
                    ->has('refunded_amount')
                    ->has('payment_status')
                    ->etc()));
    }

    /**
     * مبلغ الحجز قبل الخصم، والإجمالي بعده — يُقرأ الصف أفقيًا كدفتر.
     */
    public function test_the_subtotal_is_the_total_before_the_discount(): void
    {
        $total = (float) $this->booking->total_amount;

        $this->actingAs($this->owner)
            ->get('/admin/bookings/halls')
            ->assertInertia(fn ($page) => $page
                ->where('bookings.data.0.discount_amount', fn ($v) => (float) $v === 100.0)
                ->where('bookings.data.0.subtotal_amount', fn ($v) => (float) $v === $total + 100.0)
                ->where('bookings.data.0.total_amount', fn ($v) => (float) $v === $total));
    }

    public function test_paid_amounts_are_split_over_their_methods(): void
    {
        app(BookingService::class)->recordPayment($this->booking, [
            'type' => 'deposit', 'payment_method_id' => $this->paymentMethodId('cash'), 'amount' => 200, 'paid_on' => '2026-09-01',
        ], $this->owner->id);

        app(BookingService::class)->recordPayment($this->booking, [
            'type' => 'payment', 'payment_method_id' => $this->paymentMethodId('transfer'), 'amount' => 150, 'paid_on' => '2026-09-02',
        ], $this->owner->id);

        // أعمدة طرق الدفع تُفتَّح بمعرّف الطريقة لا بكودها، فالعمود مرتبط
        // بالصف في الجدول ولا ينكسر بإعادة تسمية الكود من الإعدادات.
        $cash = $this->paymentMethodId('cash');
        $transfer = $this->paymentMethodId('transfer');
        $card = $this->paymentMethodId('card');

        $this->actingAs($this->owner)
            ->get('/admin/bookings/halls')
            ->assertInertia(fn ($page) => $page
                // الطرق مشتركة في النظام كله، فأعمدة السجل هي الطرق المفعّلة كلها
                ->has('methods', 5)
                ->where("bookings.data.0.paid_by_method.{$cash}", fn ($v) => (float) $v === 200.0)
                ->where("bookings.data.0.paid_by_method.{$transfer}", fn ($v) => (float) $v === 150.0)
                ->where("bookings.data.0.paid_by_method.{$card}", fn ($v) => (float) $v === 0.0)
                ->where('bookings.data.0.payment_status', 'مسدّدة جزئيًا'));
    }

    /**
     * المسترجع عمود مستقل: لا يُخصم من المقبوض في عرضه، فالمراجع
     * يحتاج أن يرى ما قُبض وما رُدّ كلًّا على حدة.
     */
    public function test_refunds_show_in_their_own_column(): void
    {
        app(BookingService::class)->recordPayment($this->booking, [
            'type' => 'payment', 'payment_method_id' => $this->paymentMethodId('cash'), 'amount' => 400, 'paid_on' => '2026-09-01',
        ], $this->owner->id);

        app(BookingService::class)->recordPayment($this->booking, [
            'type' => 'refund', 'payment_method_id' => $this->paymentMethodId('cash'), 'amount' => 100, 'paid_on' => '2026-09-03',
        ], $this->owner->id);

        $cash = $this->paymentMethodId('cash');

        $this->actingAs($this->owner)
            ->get('/admin/bookings/halls')
            ->assertInertia(fn ($page) => $page
                ->where('bookings.data.0.refunded_amount', fn ($v) => (float) $v === 100.0)
                ->where("bookings.data.0.paid_by_method.{$cash}", fn ($v) => (float) $v === 400.0));
    }

    public function test_page_and_filtered_totals_are_reported(): void
    {
        app(BookingService::class)->recordPayment($this->booking, [
            'type' => 'deposit', 'payment_method_id' => $this->paymentMethodId('cash'), 'amount' => 250, 'paid_on' => '2026-09-01',
        ], $this->owner->id);

        $total = (float) $this->booking->fresh()->total_amount;
        $cash = $this->paymentMethodId('cash');

        $this->actingAs($this->owner)
            ->get('/admin/bookings/halls')
            ->assertInertia(fn ($page) => $page
                ->where('totals.page.count', 1)
                ->where('totals.page.total', fn ($v) => (float) $v === $total)
                ->where('totals.page.paid', fn ($v) => (float) $v === 250.0)
                ->where("totals.page.paid_by_method.{$cash}", fn ($v) => (float) $v === 250.0)
                ->where('totals.page.remaining', fn ($v) => (float) $v === $total - 250.0)
                ->where('totals.all.count', 1)
                ->where('totals.all.total', fn ($v) => (float) $v === $total)
                ->where('totals.all.paid', fn ($v) => (float) $v === 250.0));
    }

    /**
     * مجاميع «كل الصفحات» تتبع الفلتر لا الصفحة: حجزٌ خارج الفلتر
     * لا يدخل المجموع، وإلا صار الرقم مجموع الجدول كله بلا معنى.
     */
    public function test_filtered_totals_exclude_what_the_filter_dropped(): void
    {
        $other = Client::create(['name' => 'سعد العتيبي', 'mobile' => '0559998888']);

        app(BookingService::class)->create([
            'unit_id' => Unit::where('type', 'hall')->value('id'),
            'client_id' => $other->id,
            'scope' => 'whole',
            'booking_date' => '2026-12-20',
            'period' => 'full_day',
            'status' => 'confirmed',
        ]);

        $this->actingAs($this->owner)
            ->get('/admin/bookings/halls?from=2026-09-01&to=2026-09-30')
            ->assertInertia(fn ($page) => $page
                ->where('totals.all.count', 1)
                ->where('totals.all.total', fn ($v) => (float) $v === (float) $this->booking->total_amount));
    }

    public function test_vat_column_stays_empty_until_tax_is_registered(): void
    {
        $this->actingAs($this->owner)
            ->get('/admin/bookings/halls')
            ->assertInertia(fn ($page) => $page->where('bookings.data.0.tax_amount', fn ($v) => (float) $v === 0.0));

        Setting::current()->update([
            'business_name' => 'ديوان البصرة',
            'tax_enabled' => true,
            'tax_number' => '300000000000003',
            'tax_rate' => 15,
        ]);

        $total = (float) $this->booking->total_amount;
        $expected = round($total - round($total / 1.15, 2), 2);

        $this->actingAs($this->owner)
            ->get('/admin/bookings/halls')
            ->assertInertia(fn ($page) => $page->where('bookings.data.0.tax_amount', fn ($v) => (float) $v === $expected));
    }
}
