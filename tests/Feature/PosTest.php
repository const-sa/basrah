<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Client;
use App\Models\CostCenter;
use App\Models\Item;
use App\Models\Role;
use App\Models\Sale;
use App\Models\StockMovement;
use App\Models\Unit;
use App\Models\User;
use App\Services\Accounting\Ledger;
use App\Services\InventoryService;
use App\Services\SalesService;
use Database\Seeders\AccountsSeeder;
use Database\Seeders\BookingSetupSeeder;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\DepartmentsSeeder;
use Database\Seeders\RolesSeeder;
use Database\Seeders\UnitsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * نقطة البيع والمخزون: الأنواع الأربعة، المرتجعات، الجرد.
 */
class PosTest extends TestCase
{
    use RefreshDatabase;

    private SalesService $sales;

    private InventoryService $inventory;

    private User $cashier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([RolesSeeder::class, DepartmentsSeeder::class, UnitsSeeder::class, BookingSetupSeeder::class, AccountsSeeder::class, CatalogSeeder::class]);

        $this->sales = app(SalesService::class);
        $this->inventory = app(InventoryService::class);

        $this->cashier = User::factory()->create([
            'role_id' => Role::where('slug', 'cashier')->firstOrFail()->id,
            'is_active' => true,
        ]);
    }

    private function item(string $code): Item
    {
        return Item::where('code', $code)->firstOrFail();
    }

    public function test_selling_a_stock_item_reduces_its_balance_and_logs_a_movement(): void
    {
        $part = $this->item('SPR-001');
        $before = (float) $part->stock_qty;

        $sale = $this->sales->checkout([
            'lines' => [['item_id' => $part->id, 'quantity' => 10]],
            'payment_method_id' => $this->paymentMethodId('cash'),
        ], $this->cashier->id);

        $this->assertSame($before - 10, (float) $part->fresh()->stock_qty);
        $this->assertDatabaseHas('stock_movements', [
            'item_id' => $part->id,
            'type' => 'sale',
            'quantity' => -10,
        ]);
        $this->assertSame(650.0, (float) $sale->subtotal);      // 10 × 65
        $this->assertSame(97.5, (float) $sale->tax_amount);     // 15%
        $this->assertSame(747.5, (float) $sale->total_amount);
    }

    public function test_service_item_has_no_stock_movement(): void
    {
        $service = $this->item('SRV-001');

        $this->sales->checkout([
            'lines' => [['item_id' => $service->id, 'quantity' => 1]],
            'payment_method_id' => $this->paymentMethodId('cash'),
        ], $this->cashier->id);

        $this->assertSame(0, StockMovement::where('item_id', $service->id)->count());
    }

    public function test_selling_a_bundle_consumes_its_components(): void
    {
        $bundle = $this->item('PRJ-001');
        $pump = $this->item('PMP-002');
        $light = $this->item('LGT-001');

        $pumpBefore = (float) $pump->stock_qty;
        $lightBefore = (float) $light->stock_qty;

        $this->sales->checkout([
            'lines' => [['item_id' => $bundle->id, 'quantity' => 2]],
            'payment_method_id' => $this->paymentMethodId('cash'),
        ], $this->cashier->id);

        // المشروع يحوي مضخة و4 كشافات لكل وحدة، وبيعت وحدتان
        $this->assertSame($pumpBefore - 2, (float) $pump->fresh()->stock_qty);
        $this->assertSame($lightBefore - 8, (float) $light->fresh()->stock_qty);
        // الحزمة نفسها بلا رصيد
        $this->assertSame(0, StockMovement::where('item_id', $bundle->id)->count());
    }

    public function test_measured_item_accepts_fractional_quantity(): void
    {
        $insulation = $this->item('MSR-001');

        $sale = $this->sales->checkout([
            'lines' => [['item_id' => $insulation->id, 'quantity' => 12.5]],
            'payment_method_id' => $this->paymentMethodId('cash'),
        ], $this->cashier->id);

        $this->assertSame(1125.0, (float) $sale->subtotal); // 12.5 × 90
        $this->assertSame(987.5, (float) $insulation->fresh()->stock_qty);
    }

    public function test_non_measured_item_rejects_fractional_quantity(): void
    {
        $this->expectException(ValidationException::class);

        $this->sales->checkout([
            'lines' => [['item_id' => $this->item('SPR-001')->id, 'quantity' => 2.5]],
            'payment_method_id' => $this->paymentMethodId('cash'),
        ], $this->cashier->id);
    }

    public function test_selling_more_than_available_stock_is_rejected(): void
    {
        $pump = $this->item('PMP-001'); // رصيد 12

        try {
            $this->sales->checkout([
                'lines' => [['item_id' => $pump->id, 'quantity' => 100]],
                'payment_method_id' => $this->paymentMethodId('cash'),
            ], $this->cashier->id);
            $this->fail('كان يجب رفض البيع لنفاد الرصيد.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('غير كافٍ', $e->errors()['lines'][0]);
        }

        $this->assertSame(12.0, (float) $pump->fresh()->stock_qty);
        $this->assertSame(0, Sale::count());
    }

    public function test_bundle_is_rejected_when_a_component_runs_out(): void
    {
        // الكشافات 25 والمشروع يحتاج 4 لكل وحدة ⇒ 25 مشروعًا تحتاج 100
        $this->expectException(ValidationException::class);

        $this->sales->checkout([
            'lines' => [['item_id' => $this->item('PRJ-001')->id, 'quantity' => 25]],
            'payment_method_id' => $this->paymentMethodId('cash'),
        ], $this->cashier->id);
    }

    public function test_sale_posts_revenue_and_cost_of_goods_entries(): void
    {
        $part = $this->item('SPR-001');

        $this->sales->checkout([
            'lines' => [['item_id' => $part->id, 'quantity' => 10]],
            'payment_method_id' => $this->paymentMethodId('cash'),
        ], $this->cashier->id);

        // 10 × 65 = 650 + ضريبة 97.5 = 747.5 · التكلفة 10 × 35 = 350
        $this->assertSame(747.5, Account::where('code', Ledger::CASH)->first()->balance());
        $this->assertSame(747.5, Account::where('code', Ledger::SALES_REVENUE)->first()->balance());
        $this->assertSame(350.0, Account::where('code', Ledger::COGS)->first()->balance());
        $this->assertSame(-350.0, Account::where('code', Ledger::INVENTORY)->first()->balance());
    }

    public function test_card_sale_debits_the_bank_not_the_cash(): void
    {
        $this->sales->checkout([
            'lines' => [['item_id' => $this->item('SPR-001')->id, 'quantity' => 10]],
            'payment_method_id' => $this->paymentMethodId('card'),
        ], $this->cashier->id);

        $this->assertSame(0.0, Account::where('code', Ledger::CASH)->first()->balance());
        $this->assertSame(747.5, Account::where('code', Ledger::BANK)->first()->balance());
    }

    public function test_a_sale_without_a_client_lands_on_the_walk_in_client(): void
    {
        $sale = $this->sales->checkout([
            'lines' => [['item_id' => $this->item('SPR-001')->id, 'quantity' => 1]],
            'payment_method_id' => $this->paymentMethodId('cash'),
        ], $this->cashier->id);

        $this->assertSame(Client::walkIn()->id, $sale->client_id);
        $this->assertTrue($sale->client->isWalkIn());
        $this->assertSame(Client::WALK_IN_NAME, $sale->client->name);
    }

    public function test_the_walk_in_client_is_never_duplicated(): void
    {
        Client::walkIn();
        Client::walkIn();

        $this->assertSame(1, Client::where('is_walk_in', true)->count());
    }

    public function test_a_chosen_client_overrides_the_walk_in_default(): void
    {
        $client = Client::create(['name' => 'شركة الأفق', 'is_active' => true]);

        $sale = $this->sales->checkout([
            'lines' => [['item_id' => $this->item('SPR-001')->id, 'quantity' => 1]],
            'client_id' => $client->id,
            'payment_method_id' => $this->paymentMethodId('cash'),
        ], $this->cashier->id);

        $this->assertSame($client->id, $sale->client_id);
    }

    public function test_a_partially_paid_sale_reports_the_remaining_amount(): void
    {
        $sale = $this->sales->checkout([
            'lines' => [['item_id' => $this->item('SPR-001')->id, 'quantity' => 10]], // 747.50
            'payment_method_id' => $this->paymentMethodId('cash'),
            'paid_amount' => 500,
        ], $this->cashier->id);

        $this->assertSame(500.0, (float) $sale->paid_amount);
        $this->assertSame(247.5, $sale->remainingAmount());
        $this->assertSame('partial', $sale->paymentStatus());

        // المقبوض في الصندوق والباقي دَين على العميل — لا يختلطان.
        $this->assertSame(500.0, Account::where('code', Ledger::CASH)->first()->balance());
        $this->assertSame(247.5, Account::where('code', Ledger::RECEIVABLES)->first()->balance());
        $this->assertSame(747.5, Account::where('code', Ledger::SALES_REVENUE)->first()->balance());
    }

    public function test_paid_amount_cannot_exceed_the_invoice_total(): void
    {
        $sale = $this->sales->checkout([
            'lines' => [['item_id' => $this->item('SPR-001')->id, 'quantity' => 10]], // 747.50
            'payment_method_id' => $this->paymentMethodId('cash'),
            'paid_amount' => 5000,
        ], $this->cashier->id);

        $this->assertSame(747.5, (float) $sale->paid_amount);
        $this->assertSame(0.0, $sale->remainingAmount());
        $this->assertSame('paid', $sale->paymentStatus());
    }

    public function test_an_account_sale_accepts_a_down_payment(): void
    {
        $sale = $this->sales->checkout([
            'lines' => [['item_id' => $this->item('SPR-001')->id, 'quantity' => 10]], // 747.50
            'payment_method_id' => $this->paymentMethodId('account'),
            'paid_amount' => 200,
        ], $this->cashier->id);

        $this->assertSame('partial', $sale->paymentStatus());
        $this->assertSame(200.0, Account::where('code', Ledger::CASH)->first()->balance());
        $this->assertSame(547.5, Account::where('code', Ledger::RECEIVABLES)->first()->balance());
    }

    public function test_an_account_sale_without_a_down_payment_stays_unpaid(): void
    {
        $sale = $this->sales->checkout([
            'lines' => [['item_id' => $this->item('SPR-001')->id, 'quantity' => 10]],
            'payment_method_id' => $this->paymentMethodId('account'),
        ], $this->cashier->id);

        $this->assertSame(0.0, (float) $sale->paid_amount);
        $this->assertSame('unpaid', $sale->paymentStatus());
        $this->assertSame(747.5, Account::where('code', Ledger::RECEIVABLES)->first()->balance());
        $this->assertSame(0.0, Account::where('code', Ledger::CASH)->first()->balance());
    }

    public function test_the_pos_screen_checkout_stores_the_paid_amount(): void
    {
        $this->actingAs($this->cashier)
            ->post('/admin/pos/checkout', [
                'lines' => [['item_id' => $this->item('SPR-001')->id, 'quantity' => 10]],
                'payment_method_id' => $this->paymentMethodId('cash'),
                'paid_amount' => 300,
            ])
            ->assertRedirect();

        $sale = Sale::latest('id')->firstOrFail();

        $this->assertSame(300.0, (float) $sale->paid_amount);
        $this->assertSame(447.5, $sale->remainingAmount());
        $this->assertSame(Client::walkIn()->id, $sale->client_id);
    }

    public function test_the_walk_in_client_cannot_be_deleted_or_disabled(): void
    {
        $admin = User::factory()->create([
            'role_id' => Role::where('slug', 'super-admin')->firstOrFail()->id,
            'is_active' => true,
        ]);

        $walkIn = Client::walkIn();

        $this->actingAs($admin)->delete("/admin/clients/{$walkIn->id}")->assertRedirect();
        $this->actingAs($admin)->patch("/admin/clients/{$walkIn->id}/toggle")->assertRedirect();

        $this->assertDatabaseHas('clients', ['id' => $walkIn->id, 'is_active' => true]);
    }

    public function test_partial_return_restores_stock_and_reverses_the_revenue(): void
    {
        $part = $this->item('SPR-001');
        $before = (float) $part->stock_qty;

        $sale = $this->sales->checkout([
            'lines' => [['item_id' => $part->id, 'quantity' => 20]],
            'payment_method_id' => $this->paymentMethodId('cash'),
        ], $this->cashier->id);

        $return = $this->sales->refund($sale, [$part->id => 5], $this->cashier->id, 'صنف تالف');

        $this->assertSame('return', $return->type);
        $this->assertSame($before - 15, (float) $part->fresh()->stock_qty);
        // 20 بيعت (1495.00) ثم 5 رُدّت (373.75) ⇒ صافي الإيراد 1121.25
        $this->assertSame(1121.25, Account::where('code', Ledger::SALES_REVENUE)->first()->balance());
    }

    public function test_returning_more_than_sold_is_rejected(): void
    {
        $part = $this->item('SPR-001');

        $sale = $this->sales->checkout([
            'lines' => [['item_id' => $part->id, 'quantity' => 5]],
            'payment_method_id' => $this->paymentMethodId('cash'),
        ], $this->cashier->id);

        $this->expectException(ValidationException::class);
        $this->sales->refund($sale, [$part->id => 9], $this->cashier->id);
    }

    public function test_returning_twice_cannot_exceed_the_original_quantity(): void
    {
        $part = $this->item('SPR-001');

        $sale = $this->sales->checkout([
            'lines' => [['item_id' => $part->id, 'quantity' => 10]],
            'payment_method_id' => $this->paymentMethodId('cash'),
        ], $this->cashier->id);

        $this->sales->refund($sale, [$part->id => 6], $this->cashier->id);

        $this->expectException(ValidationException::class);
        $this->sales->refund($sale->fresh(), [$part->id => 6], $this->cashier->id);
    }

    public function test_stock_adjustment_records_the_difference(): void
    {
        $part = $this->item('SPR-001'); // 40

        $movement = $this->inventory->adjust($part, 30, $this->cashier->id, 'جرد شهري');

        $this->assertNotNull($movement);
        $this->assertSame(-10.0, (float) $movement->quantity);
        $this->assertSame('adjustment', $movement->type);
        $this->assertSame(30.0, (float) $part->fresh()->stock_qty);
    }

    public function test_low_stock_items_are_detected(): void
    {
        $pump = $this->item('PMP-001'); // رصيد 12 وحد الطلب 3

        $this->assertFalse($pump->isBelowReorderPoint());

        $this->inventory->adjust($pump, 2, $this->cashier->id);

        $this->assertTrue($pump->fresh()->isBelowReorderPoint());
        $this->assertTrue(Item::lowStock()->pluck('id')->contains($pump->id));
    }

    public function test_sale_linked_to_a_unit_lands_on_that_units_cost_center(): void
    {
        $unit = Unit::where('code', 'CH-BSR1')->firstOrFail();

        $this->sales->checkout([
            'lines' => [['item_id' => $this->item('SPR-001')->id, 'quantity' => 10]],
            'unit_id' => $unit->id,
            'payment_method_id' => $this->paymentMethodId('cash'),
        ], $this->cashier->id);

        $profit = CostCenter::forUnit($unit)->profitability();

        $this->assertSame(747.5, $profit['revenue']);
        $this->assertSame(350.0, $profit['expense']);   // تكلفة 10 × 35
        $this->assertSame(397.5, $profit['profit']);
    }
}
