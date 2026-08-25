<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Client;
use App\Models\PaymentMethod;
use App\Models\Role;
use App\Models\User;
use App\Services\BookingService;
use App\Services\ChaletBookingService;
use Database\Seeders\AccountsSeeder;
use Database\Seeders\BookingSetupSeeder;
use Database\Seeders\RolesSeeder;
use Database\Seeders\UnitsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * سجل حجوزات الشاليهات يحمل ما يحمله سجل القاعات من أعمدة المال، لتفتح
 * المعاينة والفاتورة والسند منه كما تفتح من هناك.
 */
class ChaletBookingsLedgerColumnsTest extends TestCase
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

        $client = Client::create(['name' => 'نزيل الشاليه', 'mobile' => '0559876543']);

        $this->booking = app(ChaletBookingService::class)->create([
            'unit_id' => $this->chaletLetWhole()->id,
            'client_id' => $client->id,
            'scope' => 'whole',
            'booking_date' => '2026-10-05',
            'check_out_date' => '2026-10-08',
            'status' => 'confirmed',
            'discount_amount' => 50,
        ]);
    }

    public function test_the_stay_row_carries_the_same_ledger_columns_as_an_event_row(): void
    {
        $this->actingAs($this->owner)
            ->get('/admin/bookings/chalets')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('admin/bookings/chalets/Index')
                ->has('bookings.data.0', fn ($row) => $row
                    ->where('reference', $this->booking->reference)
                    // The preview modal and the invoice/bond buttons read these.
                    ->has('subtotal_amount')
                    ->has('discount_amount')
                    ->has('addons_amount')
                    ->has('tax_amount')
                    ->has('refunded_amount')
                    ->has('payment_status')
                    ->has('paid_by_method')
                    ->has('has_payments')
                    ->has('contract')
                    ->etc(),
                ),
            );
    }

    public function test_the_row_reports_no_payments_until_one_is_taken(): void
    {
        $this->actingAs($this->owner)
            ->get('/admin/bookings/chalets')
            ->assertInertia(fn ($page) => $page
                ->where('bookings.data.0.has_payments', false)
                ->where('bookings.data.0.payment_status', 'غير مسدّدة'),
            );

        $cash = PaymentMethod::where('is_active', true)->value('id');

        app(BookingService::class)->recordPayment($this->booking, [
            'type' => 'deposit',
            'payment_method_id' => $cash,
            'amount' => 200,
            'paid_on' => '2026-10-01',
        ], $this->owner->id);

        // The bond button turns on with the first receipt, and the amount is
        // reported under the method it was taken by.
        $this->actingAs($this->owner)
            ->get('/admin/bookings/chalets')
            ->assertInertia(fn ($page) => $page
                ->where('bookings.data.0.has_payments', true)
                ->where('bookings.data.0.payment_status', 'مسدّدة جزئيًا')
                ->where("bookings.data.0.paid_by_method.{$cash}", fn ($v) => (float) $v === 200.0),
            );
    }

    public function test_the_invoice_and_bond_open_for_a_stay(): void
    {
        app(BookingService::class)->recordPayment($this->booking, [
            'type' => 'deposit',
            'payment_method_id' => PaymentMethod::where('is_active', true)->value('id'),
            'amount' => 150,
            'paid_on' => '2026-10-01',
        ], $this->owner->id);

        $this->actingAs($this->owner)->get("/admin/bookings/{$this->booking->id}/invoice")->assertOk();
        $this->actingAs($this->owner)->get("/admin/bookings/{$this->booking->id}/bond")->assertOk();
    }
}
