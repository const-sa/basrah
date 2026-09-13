<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Sale;
use App\Models\Treasury;
use App\Models\Unit;
use App\Models\Voucher;
use App\Services\Accounting\ClientStatementService;
use App\Services\BookingService;
use Database\Seeders\AccountsSeeder;
use Database\Seeders\BookingSetupSeeder;
use Database\Seeders\RolesSeeder;
use Database\Seeders\UnitsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * كشف حساب العميل الموحَّد فوق الحجوزات والمبيعات والسندات.
 */
class ClientStatementServiceTest extends TestCase
{
    use RefreshDatabase;

    private ClientStatementService $statements;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        $this->seed([RolesSeeder::class, UnitsSeeder::class, BookingSetupSeeder::class, AccountsSeeder::class]);
        $this->statements = app(ClientStatementService::class);
    }

    public function test_outstanding_combines_bookings_and_sales(): void
    {
        $client = Client::create(['name' => 'عميل تجريبي']);

        $booking = app(BookingService::class)->create([
            'client_id' => $client->id,
            'unit_id' => Unit::where('code', 'HALL-01')->value('id'),
            'scope' => 'whole',
            'booking_date' => '2026-09-10',
            'period' => 'full_day',
            'status' => 'deposit_paid',
        ]);

        app(BookingService::class)->recordPayment($booking, [
            'type' => 'deposit',
            'payment_method_id' => $this->paymentMethodId('cash'),
            'amount' => 500,
        ]);

        Sale::create([
            'number' => 'S-TEST-1',
            'client_id' => $client->id,
            'type' => 'sale',
            'subtotal' => 200,
            'tax_amount' => 0,
            'total_amount' => 200,
            'paid_amount' => 50,
        ]);

        $expected = round($booking->fresh()->remainingAmount() + 150, 2);

        $this->assertSame($expected, $this->statements->outstandingFor($client->fresh()));
    }

    public function test_statement_orders_events_chronologically_and_accumulates_balance(): void
    {
        $client = Client::create(['name' => 'عميل كشف الحساب']);
        $treasury = Treasury::where('type', 'cash')->firstOrFail();

        $sale = Sale::create([
            'number' => 'S-TEST-2',
            'client_id' => $client->id,
            'type' => 'sale',
            'subtotal' => 1000,
            'tax_amount' => 0,
            'total_amount' => 1000,
            'paid_amount' => 0,
        ]);
        // created_at ليس من الحقول القابلة للتعبئة الجماعية في Sale، فيُضبَط
        // مباشرةً هنا لاختبار الترتيب الزمني بتاريخين مختلفين.
        $sale->forceFill(['created_at' => '2026-08-01 10:00:00'])->save();

        Voucher::create([
            'number' => 'RV-TEST-1',
            'type' => 'receipt',
            'voucher_date' => '2026-08-05',
            'amount' => 400,
            'treasury_id' => $treasury->id,
            'client_id' => $client->id,
            'sale_id' => $sale->id,
            'status' => 'posted',
        ]);

        $statement = $this->statements->statementFor($client->fresh());

        $this->assertSame(0.0, $statement['opening_balance']);
        $this->assertSame(1000.0, $statement['total_debit']);
        $this->assertSame(400.0, $statement['total_credit']);
        $this->assertSame(600.0, $statement['closing_balance']);
        $this->assertCount(2, $statement['rows']);
        $this->assertSame('sale', $statement['rows'][0]['type']);
        $this->assertSame('voucher', $statement['rows'][1]['type']);
        $this->assertSame(600.0, $statement['rows'][1]['balance']);
    }

    public function test_from_filter_carries_an_accurate_opening_balance(): void
    {
        $client = Client::create(['name' => 'عميل فلترة التاريخ']);
        $treasury = Treasury::where('type', 'cash')->firstOrFail();

        $older = Sale::create([
            'number' => 'S-TEST-3',
            'client_id' => $client->id,
            'type' => 'sale',
            'subtotal' => 300,
            'tax_amount' => 0,
            'total_amount' => 300,
            'paid_amount' => 0,
        ]);
        $older->forceFill(['created_at' => '2026-07-01 10:00:00'])->save();

        $newer = Sale::create([
            'number' => 'S-TEST-4',
            'client_id' => $client->id,
            'type' => 'sale',
            'subtotal' => 100,
            'tax_amount' => 0,
            'total_amount' => 100,
            'paid_amount' => 0,
        ]);
        $newer->forceFill(['created_at' => '2026-08-10 10:00:00'])->save();

        $statement = $this->statements->statementFor($client->fresh(), '2026-08-01');

        $this->assertSame(300.0, $statement['opening_balance']);
        $this->assertCount(1, $statement['rows']);
        $this->assertSame(400.0, $statement['closing_balance']);
    }
}
