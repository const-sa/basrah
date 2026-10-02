<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Item;
use App\Models\Role;
use App\Models\Sale;
use App\Models\User;
use App\Services\Accounting\Ledger;
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
 * الدفع المقسَّم: جزء نقدًا وجزء شبكة — كل جزء يدخل حسابه، ويُرَدّ منه.
 */
class SplitPaymentTest extends TestCase
{
    use RefreshDatabase;

    private SalesService $sales;

    private User $cashier;

    protected function setUp(): void
    {
        parent::setUp();

        // 10 × 65 + 15% = 747.50 — the same invoice as PosTest.
        $this->registerForVat();

        $this->seed([RolesSeeder::class, DepartmentsSeeder::class, UnitsSeeder::class, BookingSetupSeeder::class, AccountsSeeder::class, CatalogSeeder::class]);

        $this->sales = app(SalesService::class);

        $this->cashier = User::factory()->create([
            'role_id' => Role::where('slug', 'cashier')->firstOrFail()->id,
            'is_active' => true,
        ]);
    }

    private function balance(string $code): float
    {
        return Account::where('code', $code)->first()->balance();
    }

    /**
     * @param  array<int, array{0: string, 1: float}>  $parts  [method code, amount]
     */
    private function sell(array $parts): Sale
    {
        return $this->sales->checkout([
            'lines' => [['item_id' => Item::where('code', 'SPR-001')->firstOrFail()->id, 'quantity' => 10]],
            'payments' => array_map(fn (array $p) => ['payment_method_id' => $this->paymentMethodId($p[0]), 'amount' => $p[1]], $parts),
        ], $this->cashier->id);
    }

    public function test_each_part_lands_in_its_own_account(): void
    {
        $sale = $this->sell([['cash', 300], ['card', 447.5]]);

        $this->assertSame(747.5, (float) $sale->paid_amount);
        $this->assertSame('paid', $sale->paymentStatus());
        $this->assertCount(2, $sale->payments);

        $this->assertSame(300.0, $this->balance(Ledger::CASH));
        $this->assertSame(447.5, $this->balance(Ledger::BANK));
        $this->assertSame(0.0, $this->balance(Ledger::RECEIVABLES));
        $this->assertSame(747.5, $this->balance(Ledger::SALES_REVENUE));

        $this->assertSame('نقدًا 300.00 + شبكة 447.50', $sale->fresh()->methodLabel());
    }

    public function test_what_the_parts_leave_unpaid_is_owed_by_the_client(): void
    {
        $sale = $this->sell([['cash', 200], ['card', 300]]);

        $this->assertSame(500.0, (float) $sale->paid_amount);
        $this->assertSame(247.5, $sale->remainingAmount());
        $this->assertSame(247.5, $this->balance(Ledger::RECEIVABLES));
    }

    public function test_parts_beyond_the_total_are_refused(): void
    {
        $this->expectException(ValidationException::class);

        $this->sell([['cash', 500], ['card', 500]]);
    }

    public function test_a_refund_goes_back_through_each_method_in_its_share(): void
    {
        $sale = $this->sell([['cash', 300], ['card', 447.5]]);

        // Half the goods back: 373.75, split 150 cash / 223.75 card.
        $return = $this->sales->refund($sale, [Item::where('code', 'SPR-001')->value('id') => 5], $this->cashier->id);

        $this->assertSame(373.75, (float) $return->total_amount);
        $this->assertSame([150.0, 223.75], $return->payments()->orderBy('id')->pluck('amount')->map(fn ($a) => (float) $a)->all());

        $this->assertSame(150.0, $this->balance(Ledger::CASH));
        $this->assertSame(223.75, $this->balance(Ledger::BANK));
    }

    public function test_one_part_is_a_plain_single_method_sale(): void
    {
        $sale = $this->sell([['card', 747.5]]);

        $this->assertFalse($sale->fresh()->isSplit());
        $this->assertSame($this->paymentMethodId('card'), (int) $sale->payment_method_id);
        $this->assertSame(747.5, $this->balance(Ledger::BANK));
    }

    public function test_the_pos_screen_accepts_a_split_payment(): void
    {
        $owner = User::factory()->create([
            'role_id' => Role::where('slug', 'super-admin')->firstOrFail()->id,
            'is_active' => true,
            'has_all_units' => true,
        ]);

        $this->actingAs($owner)->post('/admin/pos/checkout', [
            'lines' => [['item_id' => Item::where('code', 'SPR-001')->value('id'), 'quantity' => 10]],
            'payment_method_id' => $this->paymentMethodId('cash'),
            'is_taxable' => true,
            'payments' => [
                ['payment_method_id' => $this->paymentMethodId('cash'), 'amount' => 47.5],
                ['payment_method_id' => $this->paymentMethodId('card'), 'amount' => 700],
            ],
        ])->assertSessionHas('success');

        $this->assertSame(47.5, $this->balance(Ledger::CASH));
        $this->assertSame(700.0, $this->balance(Ledger::BANK));
    }
}
