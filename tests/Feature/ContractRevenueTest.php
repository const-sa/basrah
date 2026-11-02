<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Client;
use App\Models\Contract;
use App\Models\ContractTemplate;
use App\Models\CostCenter;
use App\Models\Department;
use App\Models\Item;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\Quotation;
use App\Models\QuotationItem;
use App\Models\Role;
use App\Models\User;
use App\Models\Voucher;
use App\Models\Sale;
use App\Services\Accounting\AccountingSettingsRemap;
use App\Services\Accounting\ContractAccounting;
use App\Services\Accounting\RevenueAccounts;
use App\Services\Accounting\Ledger;
use App\Services\Accounting\VoucherService;
use App\Support\PoolInstallationContractTemplate;
use App\Support\PoolMaintenanceContractTemplate;
use Database\Seeders\AccountsSeeder;
use Database\Seeders\ContractTemplateSeeder;
use Database\Seeders\DepartmentsSeeder;
use Database\Seeders\PaymentMethodsSeeder;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Contract revenue — when money collected on a contract becomes earned.
 *
 * A contract posted nothing at all: its receipts credited a liability and
 * nothing ever moved them out, so the pools could collect against signed
 * contracts and still read zero income beside their invoices.
 */
class ContractRevenueTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        $this->seed([
            RolesSeeder::class, DepartmentsSeeder::class, ContractTemplateSeeder::class,
            AccountsSeeder::class, PaymentMethodsSeeder::class,
        ]);

        $this->owner = User::factory()->create([
            'role_id' => Role::where('slug', 'super-admin')->value('id'),
            'is_active' => true,
            'has_all_units' => true,
        ]);

        $this->client = Client::create(['name' => 'عبدالله الشمري', 'mobile' => '0551508655', 'type' => 'pool']);
    }

    /**
     * The client's choice: each payment on an installation pays for the stage
     * of work done, so it is pools revenue the day it is received — not held
     * in deposits until handover while the costs of the work already count.
     */
    public function test_an_installation_earns_each_receipt_the_day_it_is_taken(): void
    {
        $contract = $this->installation(12000, 4000);

        $this->assertSame(4000.0, $this->balanceOf(Ledger::POOL_CONTRACTS_REVENUE));
        $this->assertSame(0.0, $this->balanceOf(Ledger::UNEARNED_REVENUE));
        $this->assertSame(0.0, $this->balanceOf(Ledger::RECEIVABLES), 'nothing is owed before handover');
        $this->assertSame('لم يُسجَّل تنفيذ العقد بعد.', app(ContractAccounting::class)->refusal($contract));
    }

    public function test_handing_over_an_installation_earns_what_the_receipts_did_not(): void
    {
        $contract = $this->installation(12000, 4000);

        $this->actingAs($this->owner)
            ->post("/admin/contracts/{$contract->id}/complete")
            ->assertSessionHas('success');

        // 4,000 earned at signing and 8,000 at handover — owed by the client.
        $this->assertSame(12000.0, $this->balanceOf(Ledger::POOL_CONTRACTS_REVENUE));
        $this->assertSame(0.0, $this->balanceOf(Ledger::UNEARNED_REVENUE));
        $this->assertSame(8000.0, $this->balanceOf(Ledger::RECEIVABLES));
    }

    /** Paid in full before handover: everything is already earned, and handover adds nothing. */
    public function test_an_installation_paid_in_full_before_handover_adds_nothing_at_handover(): void
    {
        $contract = $this->installation(12000, 12000);

        $this->actingAs($this->owner)
            ->post("/admin/contracts/{$contract->id}/complete")
            ->assertSessionHas('warning');

        $this->assertSame(12000.0, $this->balanceOf(Ledger::POOL_CONTRACTS_REVENUE));
        $this->assertSame(0.0, $this->balanceOf(Ledger::RECEIVABLES));
        $this->artisan('accounting:audit --errors')->assertSuccessful();
    }

    /**
     * Cancelled midway, the money already received is kept against the work
     * done: it stays revenue — no refund, nothing back in deposits.
     */
    public function test_cancelling_an_installation_midway_keeps_what_was_paid_as_revenue(): void
    {
        $contract = $this->installation(12000, 4000);

        $this->actingAs($this->owner)->post("/admin/contracts/{$contract->id}/receipt", [
            'amount' => 2000,
            'payment_method_id' => $this->paymentMethodId(),
        ])->assertSessionHasNoErrors();

        $this->actingAs($this->owner)
            ->patch("/admin/contracts/{$contract->id}/status", ['status' => 'cancelled'])
            ->assertSessionHas('success');

        $this->assertSame(6000.0, $this->balanceOf(Ledger::POOL_CONTRACTS_REVENUE));
        $this->assertSame(0.0, $this->balanceOf(Ledger::UNEARNED_REVENUE));
        $this->assertSame(0.0, $this->balanceOf(Ledger::RECEIVABLES));
        $this->assertSame(0, Voucher::where('contract_id', $contract->id)->where('type', 'payment')->count(), 'no refund');
        $this->assertSame(6000.0, $contract->fresh()->paidAmount());
        $this->artisan('accounting:audit --errors')->assertSuccessful();
    }

    /** Receipts taken before the change are earned on their own dates. */
    public function test_receipts_held_before_the_change_are_earned_on_their_dates(): void
    {
        $contract = $this->installation(12000, 4000);
        $taken = now()->toDateString();

        // Undo the earning, the way the books held it before.
        JournalEntry::where('source', 'contract')->where('reference_type', Voucher::class)->get()
            ->each(fn ($e) => app(Ledger::class)->reverse($e));
        $this->assertSame(4000.0, $this->balanceOf(Ledger::UNEARNED_REVENUE));

        $this->travelTo(now()->addMonth());
        $migration = require database_path('migrations/2026_11_02_100003_earn_installation_receipts_on_the_day_they_were_taken.php');
        $migration->up();
        $migration->up();

        $this->assertSame(4000.0, $this->balanceOf(Ledger::POOL_CONTRACTS_REVENUE), 'earned once, however often it runs');
        $this->assertSame(0.0, $this->balanceOf(Ledger::UNEARNED_REVENUE));
        $this->assertSame(4000.0, Account::where('code', Ledger::POOL_CONTRACTS_REVENUE)->first()->balance($taken));
    }

    /**
     * «لو عملت تم التنفيذ ولسه باقي دفعة عند التسليم اسجلها عادي؟» — yes:
     * the payment settles the receivable, and never lands back in deposits.
     */
    public function test_the_payment_taken_after_handover_settles_what_the_client_owes(): void
    {
        // Half at signing, handed over, and the rest paid six months on.
        $contract = $this->installation(12000, 6000);

        $this->actingAs($this->owner)->post("/admin/contracts/{$contract->id}/complete");
        $handover = now()->toDateString();

        $this->travelTo(now()->addMonths(6));

        $this->actingAs($this->owner)
            ->post("/admin/contracts/{$contract->id}/receipt", [
                'amount' => 6000,
                'payment_method_id' => $this->paymentMethodId(),
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(12000.0, $this->balanceOf(Ledger::POOL_CONTRACTS_REVENUE), 'earned once, not twice');
        $this->assertSame(0.0, $this->balanceOf(Ledger::UNEARNED_REVENUE), 'nothing back in deposits');
        $this->assertSame(0.0, $this->balanceOf(Ledger::RECEIVABLES), 'the client owes nothing');

        // Every month in between still reads the client as owing the rest,
        // and the revenue stays in the month of handover.
        $between = now()->subMonths(3)->toDateString();
        $this->assertSame(6000.0, Account::where('code', Ledger::RECEIVABLES)->first()->balance($between));
        $this->assertSame(0.0, Account::where('code', Ledger::UNEARNED_REVENUE)->first()->balance($between));
        $this->assertSame(12000.0, Account::where('code', Ledger::POOL_CONTRACTS_REVENUE)->first()->balance($handover));

        $this->artisan('accounting:audit --errors')->assertSuccessful();

        // A receipt cancelled after handover puts the debt back.
        $voucher = Voucher::where('contract_id', $contract->id)->latest('id')->firstOrFail();
        app(VoucherService::class)->cancel($voucher, 'خطأ في الإدخال');

        $this->assertSame(12000.0, $this->balanceOf(Ledger::POOL_CONTRACTS_REVENUE));
        $this->assertSame(0.0, $this->balanceOf(Ledger::UNEARNED_REVENUE));
        $this->assertSame(6000.0, $this->balanceOf(Ledger::RECEIVABLES));

        // Taken again, then the handover undone: what was paid stays earned,
        // and nothing is owed on a job not yet delivered.
        $this->actingAs($this->owner)->post("/admin/contracts/{$contract->id}/receipt", [
            'amount' => 6000,
            'payment_method_id' => $this->paymentMethodId(),
        ]);
        $this->actingAs($this->owner)->delete("/admin/contracts/{$contract->id}/complete");

        $this->assertSame(12000.0, $this->balanceOf(Ledger::POOL_CONTRACTS_REVENUE));
        $this->assertSame(0.0, $this->balanceOf(Ledger::UNEARNED_REVENUE));
        $this->assertSame(0.0, $this->balanceOf(Ledger::RECEIVABLES));
        $this->artisan('accounting:audit --errors')->assertSuccessful();
    }

    public function test_the_revenue_lands_on_the_pools_cost_centre(): void
    {
        $contract = $this->installation(10000, 2500);

        $this->actingAs($this->owner)->post("/admin/contracts/{$contract->id}/complete");

        $pools = CostCenter::forDepartment(Department::where('code', 'POOLS')->firstOrFail())->id;
        $entry = JournalEntry::where('source', 'contract')->latest('id')->firstOrFail();

        $this->assertTrue($entry->lines->every(fn ($line) => (int) $line->cost_center_id === $pools));
    }

    public function test_handing_over_twice_recognizes_once(): void
    {
        $contract = $this->installation(12000, 4000);

        $this->actingAs($this->owner)->post("/admin/contracts/{$contract->id}/complete");
        $this->actingAs($this->owner)
            ->post("/admin/contracts/{$contract->id}/complete")
            ->assertSessionHas('warning');

        $this->assertSame(1, JournalEntry::where('source', 'contract')->where('reference_type', Contract::class)->count());
        $this->assertSame(12000.0, $this->balanceOf(Ledger::POOL_CONTRACTS_REVENUE));
    }

    public function test_undoing_a_handover_reverses_what_it_earned_and_keeps_the_receipts(): void
    {
        $contract = $this->installation(12000, 4000);

        $this->actingAs($this->owner)->post("/admin/contracts/{$contract->id}/complete");
        $this->actingAs($this->owner)->delete("/admin/contracts/{$contract->id}/complete");

        $this->assertNull($contract->fresh()->completed_at);
        $this->assertSame(4000.0, $this->balanceOf(Ledger::POOL_CONTRACTS_REVENUE));
        $this->assertSame(0.0, $this->balanceOf(Ledger::RECEIVABLES));
    }

    public function test_a_contract_with_no_written_value_is_refused(): void
    {
        $this->actingAs($this->owner)->post('/admin/contracts/direct', [
            'client_id' => $this->client->id,
            'contract_template_id' => $this->form(PoolInstallationContractTemplate::NAME),
        ])->assertRedirect();

        $contract = Contract::latest('id')->firstOrFail();

        $this->actingAs($this->owner)
            ->post("/admin/contracts/{$contract->id}/complete")
            ->assertSessionHas('warning');

        $this->assertSame(0, JournalEntry::where('source', 'contract')->count());
    }

    /**
     * The monthly sheet earns as it collects: each receipt is that month's
     * fee, so it credits revenue outright instead of a liability.
     */
    public function test_a_maintenance_receipt_is_revenue_the_month_it_is_taken(): void
    {
        $contract = $this->maintenance(800, 800);

        $this->assertSame(800.0, $this->balanceOf(Ledger::POOL_CONTRACTS_REVENUE));
        $this->assertSame(0.0, $this->balanceOf(Ledger::UNEARNED_REVENUE));
    }

    public function test_a_maintenance_sheet_takes_a_second_month(): void
    {
        $contract = $this->maintenance(800, 800);

        // This used to be refused with «لا يتبقى عليه مبلغ»: one month's fee
        // was read as the contract's whole value, so it closed after month one.
        $this->actingAs($this->owner)
            ->post("/admin/contracts/{$contract->id}/receipt", [
                'amount' => 800,
                'payment_method_id' => $this->paymentMethodId(),
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(1600.0, $contract->fresh()->paidAmount());
        $this->assertSame(1600.0, $this->balanceOf(Ledger::POOL_CONTRACTS_REVENUE));
    }

    /** A fee taken before 1 November and parked in deposits is earned after all. */
    public function test_a_maintenance_receipt_left_in_deposits_is_moved_to_revenue(): void
    {
        $contract = $this->maintenance(800, 800);
        $voucher = Voucher::where('contract_id', $contract->id)->firstOrFail();

        // Put it back the way the old receipt was written: onto unearned revenue.
        $unearned = Account::where('code', Ledger::UNEARNED_REVENUE)->value('id');
        $revenue = $voucher->account_id;
        $voucher->update(['account_id' => $unearned]);
        JournalLine::where('journal_entry_id', $voucher->journal_entry_id)->where('account_id', $revenue)->update(['account_id' => $unearned]);
        $this->assertSame(800.0, $this->balanceOf(Ledger::UNEARNED_REVENUE));

        $migration = require database_path('migrations/2026_11_02_100002_earn_the_maintenance_receipts_left_in_deposits.php');
        $migration->up();

        $this->assertSame(800.0, $this->balanceOf(Ledger::POOL_CONTRACTS_REVENUE));
        $this->assertSame(0.0, $this->balanceOf(Ledger::UNEARNED_REVENUE));
        $this->assertSame($revenue, $voucher->fresh()->account_id);
    }

    /** A contract written by mistake and cancelled, whose deposit never came in, leaves the books clean. */
    public function test_a_cancelled_contracts_mistaken_receipts_are_voided(): void
    {
        $contract = $this->installation(12000, 500);
        $this->assertSame(500.0, $this->balanceOf(Ledger::POOL_CONTRACTS_REVENUE));

        // Still open: refused, untouched.
        $this->artisan('accounting:void-mistaken-payments', ['references' => [$contract->number]])->assertFailed();
        $this->assertSame(500.0, $this->balanceOf(Ledger::POOL_CONTRACTS_REVENUE));

        $contract->update(['status' => 'cancelled']);

        $this->artisan('accounting:void-mistaken-payments', ['references' => [$contract->number], '--pretend' => true])
            ->expectsOutputToContain('Total: 500.00')
            ->assertSuccessful();
        $this->assertSame(500.0, $this->balanceOf(Ledger::POOL_CONTRACTS_REVENUE));

        // Money never received is not kept: it leaves revenue and the till.
        $this->artisan('accounting:void-mistaken-payments', ['references' => [$contract->number]])->assertSuccessful();

        $this->assertSame(0.0, $this->balanceOf(Ledger::POOL_CONTRACTS_REVENUE));
        $this->assertSame(0.0, $this->balanceOf(Ledger::UNEARNED_REVENUE));
        $this->assertSame(0.0, $contract->fresh()->paidAmount());
        $this->assertSame(0, Voucher::where('contract_id', $contract->id)->where('status', 'posted')->count());
        $this->assertSame(1, Voucher::where('contract_id', $contract->id)->where('status', 'cancelled')->count());
    }

    public function test_a_maintenance_sheet_is_never_recognized_on_handover(): void
    {
        $contract = $this->maintenance(800, 800);

        $this->assertSame(
            'عقد الصيانة الشهرية يُثبت إيراده مع كل سند قبض.',
            app(ContractAccounting::class)->refusal($contract),
        );
    }

    /**
     * Cancelled after handover: the balance the client will never pay is
     * dropped, and what they paid stays revenue.
     */
    public function test_cancelling_a_handed_over_contract_drops_only_what_is_still_owed(): void
    {
        $contract = $this->installation(12000, 4000);

        $this->actingAs($this->owner)->post("/admin/contracts/{$contract->id}/complete");

        $this->actingAs($this->owner)
            ->patch("/admin/contracts/{$contract->id}/status", ['status' => 'cancelled'])
            ->assertSessionHas('success');

        $this->assertSame(4000.0, $this->balanceOf(Ledger::POOL_CONTRACTS_REVENUE));
        $this->assertSame(0.0, $this->balanceOf(Ledger::RECEIVABLES));
        $this->assertSame(0.0, $this->balanceOf(Ledger::UNEARNED_REVENUE));
    }

    /**
     * An invoice raised off a contract earns where the contract does.
     *
     * The contract's centre is frozen when it is signed, but a quotation's
     * department can still be edited after it. Re-deriving the invoice from
     * the quotation would then split one job's money between the centre its
     * receipts went to and another one.
     */
    public function test_an_invoice_from_a_contract_earns_on_the_contracts_centre(): void
    {
        $quotation = $this->quotationWithAnItem();

        $this->actingAs($this->owner)
            ->post('/admin/contracts/from-quotation', ['quotation_id' => $quotation->id])
            ->assertRedirect();

        $contract = Contract::latest('id')->firstOrFail();
        $pools = app(ContractAccounting::class)->bindCostCenter($contract)->cost_center_id;

        // The offer is re-pointed at another department after the contract.
        $other = Department::firstOrCreate(
            ['code' => 'VENUES'],
            ['name' => 'القاعات والشاليهات', 'sells' => true, 'is_active' => true, 'sort_order' => 2],
        );
        $quotation->update(['department_id' => $other->id]);

        $this->actingAs($this->owner)
            ->post("/admin/quotations/{$quotation->id}/invoice", [
                'payment_method_id' => $this->paymentMethodId(),
                'paid_amount' => 0,
            ]);

        $entry = JournalEntry::where('source', 'sale')->latest('id')->firstOrFail();

        $this->assertTrue(
            $entry->lines->every(fn ($line) => (int) $line->cost_center_id === (int) $pools),
            'the invoice follows the contract, not the offer that was moved',
        );
    }

    /**
     * The client asked to read the pools' contracts apart from the shop: a
     * counter sale stays on «إيرادات المبيعات», contract work goes to its own.
     */
    public function test_pools_contracts_and_shop_sales_earn_on_separate_accounts(): void
    {
        $pools = CostCenter::forDepartment(Department::where('code', 'POOLS')->firstOrFail())->id;
        $revenue = app(RevenueAccounts::class);

        $this->assertSame($this->accountId(Ledger::SALES_REVENUE), $revenue->forSale(new Sale, $pools));
        $this->assertSame(
            $this->accountId(Ledger::POOL_CONTRACTS_REVENUE),
            $revenue->forSale(new Sale(['quotation_id' => 1]), $pools),
            'an invoice raised off a quotation is contract work',
        );

        $this->installation(12000, 4000);

        $this->assertSame(4000.0, $this->balanceOf(Ledger::POOL_CONTRACTS_REVENUE));
        $this->assertSame(0.0, $this->balanceOf(Ledger::SALES_REVENUE));
    }

    /** Revenue posted on the shared account before the split moves onto the contracts'. */
    public function test_the_split_moves_contract_revenue_posted_before_it(): void
    {
        $this->installation(12000, 4000);
        $this->maintenance(800, 800);

        $contracts = $this->accountId(Ledger::POOL_CONTRACTS_REVENUE);
        JournalLine::where('account_id', $contracts)->update(['account_id' => $this->accountId(Ledger::SALES_REVENUE)]);

        (require database_path('migrations/2026_11_02_100005_split_the_pools_contract_revenue_from_its_shop_sales.php'))->up();

        $this->assertSame(4800.0, $this->balanceOf(Ledger::POOL_CONTRACTS_REVENUE));
        $this->assertSame(0.0, $this->balanceOf(Ledger::SALES_REVENUE));
    }

    /** The settings' «remap» button must not sweep the contracts back onto the shop's account. */
    public function test_remapping_the_pools_keeps_contracts_on_their_own_account(): void
    {
        $this->installation(12000, 4000);

        app(AccountingSettingsRemap::class)->remapAll();

        $this->assertSame(4000.0, $this->balanceOf(Ledger::POOL_CONTRACTS_REVENUE));
        $this->assertSame(0.0, $this->balanceOf(Ledger::SALES_REVENUE));
    }

    /** A job finished last month, marked done today, earns in last month. */
    public function test_a_handover_earns_on_the_day_chosen_for_it(): void
    {
        $contract = $this->installation(12000, 4000);
        $finished = now()->subMonth()->toDateString();

        $this->actingAs($this->owner)
            ->post("/admin/contracts/{$contract->id}/complete", ['completed_at' => $finished])
            ->assertSessionHas('success');

        $this->assertSame($finished, $contract->fresh()->completed_at->toDateString());
        $this->assertSame($finished, app(ContractAccounting::class)->standingEntry($contract)->entry_date->toDateString());
        $this->assertSame(12000.0, $this->balanceOf(Ledger::POOL_CONTRACTS_REVENUE));
    }

    public function test_a_handover_cannot_be_dated_in_the_future(): void
    {
        $contract = $this->installation(12000, 4000);

        $this->actingAs($this->owner)
            ->post("/admin/contracts/{$contract->id}/complete", ['completed_at' => now()->addDay()->toDateString()])
            ->assertSessionHasErrors('completed_at');

        $this->assertNull($contract->fresh()->completed_at);
    }

    private function accountId(string $code): int
    {
        return (int) Account::where('code', $code)->value('id');
    }

    private function quotationWithAnItem(): Quotation
    {
        $pools = Department::where('code', 'POOLS')->firstOrFail();

        $quotation = Quotation::create([
            'number' => 'QT-000001',
            'client_id' => $this->client->id,
            'user_id' => $this->owner->id,
            'department_id' => $pools->id,
            'status' => 'pending',
            'subtotal' => 500,
            'discount_amount' => 0,
            'tax_amount' => 0,
            'total_amount' => 500,
            'valid_until' => '2027-10-01',
        ]);

        $item = Item::create([
            'department_id' => $pools->id,
            'code' => 'IT-PUMP',
            'name' => 'مضخة مسبح',
            'price' => 500,
            'is_active' => true,
        ]);

        QuotationItem::create([
            'quotation_id' => $quotation->id,
            'item_id' => $item->id,
            'quantity' => 1,
            'unit_price' => 500,
            'total_price' => 500,
            'is_taxable' => false,
        ]);

        return $quotation->fresh();
    }

    private function installation(float $total, float $deposit): Contract
    {
        return $this->draw(PoolInstallationContractTemplate::NAME, $total, $deposit);
    }

    private function maintenance(float $total, float $deposit): Contract
    {
        return $this->draw(PoolMaintenanceContractTemplate::NAME, $total, $deposit);
    }

    private function draw(string $template, float $total, float $deposit): Contract
    {
        $this->actingAs($this->owner)->post('/admin/contracts/direct', [
            'client_id' => $this->client->id,
            'contract_template_id' => $this->form($template),
            'total_amount' => $total,
            'deposit_amount' => $deposit,
        ])->assertRedirect();

        return Contract::latest('id')->firstOrFail();
    }

    private function form(string $name): int
    {
        return (int) ContractTemplate::where('name', $name)->value('id');
    }

    private function balanceOf(string $code): float
    {
        return (float) Account::where('code', $code)->firstOrFail()->balance();
    }
}
