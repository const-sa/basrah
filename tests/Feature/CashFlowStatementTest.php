<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Client;
use App\Models\ContractTemplate;
use App\Models\Role;
use App\Models\User;
use App\Services\Accounting\Ledger;
use App\Support\PoolInstallationContractTemplate;
use Database\Seeders\AccountsSeeder;
use Database\Seeders\ContractTemplateSeeder;
use Database\Seeders\PaymentMethodsSeeder;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * قائمة التدفقات النقدية — ما دخل الخزائن وما خرج منها، من الدفاتر.
 *
 * The case that asked for it: a pools contract's deposit, taken on a receipt
 * voucher, showed on the client's statement and nowhere a treasurer would
 * look for money that reached the till.
 */
class CashFlowStatementTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Ledger $ledger;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        $this->seed([RolesSeeder::class, ContractTemplateSeeder::class, AccountsSeeder::class, PaymentMethodsSeeder::class]);

        $this->owner = User::factory()->create([
            'role_id' => Role::where('slug', 'super-admin')->value('id'),
            'is_active' => true,
            'has_all_units' => true,
        ]);

        $this->ledger = app(Ledger::class);
    }

    public function test_a_contract_deposit_shows_as_cash_in_named_as_a_contract_receipt(): void
    {
        $client = Client::create(['name' => 'عبدالله الشمري', 'mobile' => '0551508655', 'type' => 'pool']);

        $this->actingAs($this->owner)->post('/admin/contracts/direct', [
            'client_id' => $client->id,
            'contract_template_id' => ContractTemplate::where('name', PoolInstallationContractTemplate::NAME)->value('id'),
            'total_amount' => 12000,
            'deposit_amount' => 4000,
        ])->assertRedirect();

        $this->actingAs($this->owner)
            ->get('/admin/accounting/reports?tab=cashflow')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('cashFlow.totals.inflow', 4000)
                ->where('cashFlow.totals.outflow', 0)
                ->where('cashFlow.totals.closing', 4000)
                ->where('cashFlow.sources.0.key', 'contract_receipt')
                ->where('cashFlow.sources.0.inflow', 4000)
                ->where('cashFlow.sections.0.key', 'operating')
                ->where('cashFlow.sections.0.inflows.0.code', Ledger::UNEARNED_REVENUE)
                ->where('cashFlow.sections.0.inflows.0.amount', 4000)
                ->has('cashFlow.movements', 1),
            );
    }

    public function test_the_statement_reconciles_to_the_cash_accounts_and_skips_transfers(): void
    {
        // Before the period: owner puts in capital — the opening balance.
        $this->ledger->post('2026-01-15', 'رأس مال', [
            ['account' => Ledger::CASH, 'debit' => 5000],
            ['account' => '3100', 'credit' => 5000],
        ]);

        // In the period: a booking payment with VAT, an expense, a bank deposit
        // of cash, and a fixed asset bought from the bank.
        $this->ledger->post('2026-03-01', 'دفعة حجز', [
            ['account' => Ledger::CASH, 'debit' => 1150],
            ['account' => Ledger::BOOKING_REVENUE, 'credit' => 1000],
            ['account' => Ledger::UNEARNED_REVENUE, 'credit' => 150],
        ], 'payment');

        $this->ledger->post('2026-03-02', 'كهرباء', [
            ['account' => '5320', 'debit' => 300],
            ['account' => Ledger::CASH, 'credit' => 300],
        ], 'expense');

        $this->ledger->post('2026-03-03', 'إيداع في البنك', [
            ['account' => Ledger::BANK, 'debit' => 2000],
            ['account' => Ledger::CASH, 'credit' => 2000],
        ]);

        $this->ledger->post('2026-03-04', 'شراء مكيّف', [
            ['account' => Ledger::FIXED_ASSETS, 'debit' => 800],
            ['account' => Ledger::BANK, 'credit' => 800],
        ]);

        $this->actingAs($this->owner)
            ->get('/admin/accounting/reports?from=2026-02-01&to=2026-03-31')
            ->assertOk()
            ->assertInertia(function ($page) {
                $flow = $page->toArray()['props']['cashFlow'];

                $this->assertEquals(5000, $flow['totals']['opening']);
                $this->assertEquals(1150, $flow['totals']['inflow']);
                $this->assertEquals(1100, $flow['totals']['outflow']);
                $this->assertEquals(5050, $flow['totals']['closing']);
                $this->assertEquals(2000, $flow['transfers']);

                $sections = collect($flow['sections'])->keyBy('key');
                $this->assertEquals(850, $sections['operating']['net']);
                $this->assertEquals(-800, $sections['investing']['net']);
                $this->assertEquals(0, $sections['financing']['net']);

                // Each treasury's closing is its balance in the chart of accounts.
                foreach ($flow['accounts'] as $row) {
                    $this->assertEquals(Account::find($row['id'])->balance('2026-03-31'), $row['closing'], $row['name']);
                }
            });
    }

    public function test_the_cash_flow_exports_as_csv(): void
    {
        $this->ledger->post(now()->toDateString(), 'دفعة', [
            ['account' => Ledger::CASH, 'debit' => 250],
            ['account' => Ledger::SALES_REVENUE, 'credit' => 250],
        ], 'sale');

        $response = $this->actingAs($this->owner)->get('/admin/accounting/reports/cash-flow/export');

        $response->assertOk();
        $this->assertStringContainsString('إجمالي الداخل', $response->streamedContent());
    }
}
