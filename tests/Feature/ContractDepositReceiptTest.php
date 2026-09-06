<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Client;
use App\Models\Contract;
use App\Models\ContractTemplate;
use App\Models\Department;
use App\Models\JournalEntry;
use App\Models\PaymentMethod;
use App\Models\Quotation;
use App\Models\Role;
use App\Models\Treasury;
use App\Models\User;
use App\Models\Voucher;
use App\Services\Accounting\Ledger;
use App\Services\Accounting\VoucherService;
use App\Services\ContractService;
use App\Support\PoolInstallationContractTemplate;
use App\Support\PoolMaintenanceContractTemplate;
use Database\Seeders\AccountsSeeder;
use Database\Seeders\ContractTemplateSeeder;
use Database\Seeders\PaymentMethodsSeeder;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * العربون المقبوض وقت تحرير عقد التركيب أو الصيانة — سند قبض تلقائي.
 *
 * The sheet used to print «المدفوع 0.00» against the whole total for the life
 * of the contract, however much the client had handed over at signing. These
 * cover the till: the deposit is a posted receipt voucher tied to the
 * contract, and the paid and remaining boxes are what its vouchers add up to.
 */
class ContractDepositReceiptTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Client $client;

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

        $this->client = Client::create(['name' => 'عبدالله الشمري', 'mobile' => '0551508655', 'type' => 'pool']);
    }

    public function test_the_deposit_paid_at_signing_becomes_a_posted_receipt_voucher(): void
    {
        $this->actingAs($this->owner)->post('/admin/contracts/direct', [
            'client_id' => $this->client->id,
            'contract_template_id' => $this->installationForm()->id,
            'total_amount' => 12000,
            'deposit_amount' => 4000,
        ])->assertRedirect();

        $contract = Contract::latest('id')->firstOrFail();
        $voucher = Voucher::where('contract_id', $contract->id)->firstOrFail();

        $this->assertSame('receipt', $voucher->type);
        $this->assertSame('posted', $voucher->status);
        $this->assertStringStartsWith('RV-', $voucher->number);
        $this->assertSame(4000.0, (float) $voucher->amount);
        $this->assertSame($this->client->id, $voucher->client_id);
        $this->assertSame("عربون عقد {$contract->number}", $voucher->description);

        // ...and the contract now knows what it has been paid.
        $this->assertSame(4000.0, $contract->fresh()->paidAmount());
        $this->assertSame(8000.0, $contract->fresh()->remainingAmount());
    }

    public function test_the_receipt_debits_the_till_and_credits_unearned_revenue(): void
    {
        $contract = $this->drawWithDeposit(12000, 4000);
        $voucher = Voucher::where('contract_id', $contract->id)->firstOrFail();

        $entry = JournalEntry::findOrFail($voucher->journal_entry_id);
        $lines = $entry->lines()->with('account')->get()->keyBy(fn ($l) => $l->account->code);

        $this->assertSame('posted', $entry->status);
        $this->assertSame(4000.0, (float) $lines[Ledger::CASH]->debit);
        // The pool is not dug yet, so the money is a liability and not income:
        // booking it as revenue on the day of signing would put the earnings
        // in the wrong month.
        $this->assertSame(4000.0, (float) $lines[Ledger::UNEARNED_REVENUE]->credit);

        // The till it landed in actually holds it.
        $this->assertSame(4000.0, Treasury::where('type', 'cash')->firstOrFail()->balance());
    }

    public function test_the_deposit_is_posted_to_the_pools_cost_centre(): void
    {
        $pools = Department::firstOrCreate(
            ['code' => 'POOLS'],
            ['name' => 'المسابح', 'sells' => true, 'is_active' => true, 'sort_order' => 1],
        );

        $contract = $this->drawWithDeposit(10000, 2500);
        $voucher = Voucher::where('contract_id', $contract->id)->firstOrFail();

        $this->assertSame($pools->id, $voucher->costCenter?->department_id);
    }

    public function test_the_sheet_prints_what_was_paid_and_what_is_left(): void
    {
        $contract = $this->drawWithDeposit(12000, 4000);

        $this->actingAs($this->owner)->get("/admin/contracts/{$contract->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('admin/contracts/Show')
                ->where('contract.is_installation_form', true)
                ->where('contract.total_amount', '12,000.00')
                // Not the frozen «0.00» the snapshot was drawn with.
                ->where('contract.deposit_amount', '4,000.00')
                ->where('contract.remaining_amount', '8,000.00')
                ->where('contract.takes_receipts', true)
                ->where('contract.accepts_receipt', true)
                ->has('contract.receipts', 1)
                ->where('contract.receipts.0.amount', 4000));
    }

    public function test_a_later_payment_is_a_second_receipt_on_the_same_contract(): void
    {
        $contract = $this->drawWithDeposit(12000, 4000);

        $this->actingAs($this->owner)->post("/admin/contracts/{$contract->id}/receipt", [
            'amount' => 8000,
            'payment_method_id' => PaymentMethod::where('code', 'transfer')->value('id'),
        ])->assertRedirect();

        $contract->refresh();

        $this->assertSame(12000.0, $contract->paidAmount());
        $this->assertSame(0.0, $contract->remainingAmount());
        $this->assertCount(2, $contract->vouchers()->get());
        // Only the first receipt is the عربون; the rest are payments on it.
        $this->assertSame(
            "دفعة على العقد {$contract->number}",
            $contract->vouchers()->latest('id')->first()->description,
        );

        // Nothing is left to collect, so the screen stops offering to.
        $this->actingAs($this->owner)->get("/admin/contracts/{$contract->id}")
            ->assertInertia(fn ($page) => $page->where('contract.accepts_receipt', false));
    }

    public function test_more_than_the_contract_is_worth_cannot_be_collected_on_it(): void
    {
        $contract = $this->drawWithDeposit(12000, 4000);

        $this->actingAs($this->owner)->post("/admin/contracts/{$contract->id}/receipt", [
            'amount' => 9000,
        ])->assertSessionHas('warning');

        $this->assertSame(4000.0, $contract->fresh()->paidAmount());
        $this->assertCount(1, $contract->vouchers()->get());
    }

    public function test_a_contract_priced_on_the_paper_still_takes_its_deposit(): void
    {
        // The pools write the pad at the client's house and price it there, so
        // the sheet can carry no value — the money still changes hands.
        $this->actingAs($this->owner)->post('/admin/contracts/direct', [
            'client_id' => $this->client->id,
            'contract_template_id' => $this->installationForm()->id,
            'deposit_amount' => 1500,
        ])->assertRedirect();

        $contract = Contract::latest('id')->firstOrFail();

        $this->assertSame('—', $contract->data['total_amount']);
        $this->assertSame(1500.0, $contract->paidAmount());
        $this->assertNull($contract->remainingAmount());

        $this->actingAs($this->owner)->get("/admin/contracts/{$contract->id}")
            ->assertInertia(fn ($page) => $page
                ->where('contract.deposit_amount', '1,500.00')
                // Nothing to subtract from — the box stays for the paper.
                ->where('contract.remaining_amount', null));
    }

    public function test_the_maintenance_sheet_takes_a_deposit_too(): void
    {
        $this->actingAs($this->owner)->post('/admin/contracts/direct', [
            'client_id' => $this->client->id,
            'contract_template_id' => $this->maintenanceForm()->id,
            'total_amount' => 900,
            'deposit_amount' => 300,
        ])->assertRedirect();

        $contract = Contract::latest('id')->firstOrFail();

        $this->assertTrue($contract->isMaintenanceForm());
        $this->assertSame(300.0, $contract->paidAmount());
        $this->assertSame(600.0, $contract->remainingAmount());
    }

    public function test_a_standard_sheet_keeps_its_frozen_boxes_and_takes_no_receipt(): void
    {
        $contract = app(ContractService::class)->generateDirect(
            $this->client,
            ContractTemplate::where('is_default', true)->firstOrFail(),
            5000,
        );

        $this->assertFalse($contract->isPoolsForm());

        // A receipt against it is refused outright — a sheet with no receipt
        // book has nowhere to put the money.
        $this->actingAs($this->owner)->post("/admin/contracts/{$contract->id}/receipt", ['amount' => 100])
            ->assertNotFound();

        $this->actingAs($this->owner)->get("/admin/contracts/{$contract->id}")
            ->assertInertia(fn ($page) => $page
                ->where('contract.deposit_amount', '0.00')
                ->where('contract.remaining_amount', '5,000.00')
                ->where('contract.takes_receipts', false));
    }

    public function test_the_deposit_follows_the_quotation_a_contract_is_drawn_from(): void
    {
        $department = Department::firstOrCreate(
            ['code' => 'POOLS'],
            ['name' => 'المسابح', 'sells' => true, 'is_active' => true, 'sort_order' => 1],
        );

        $quotation = Quotation::create([
            'number' => 'QT-000010',
            'client_id' => $this->client->id,
            'user_id' => $this->owner->id,
            'department_id' => $department->id,
            'status' => 'pending',
            'subtotal' => 20000,
            'discount_amount' => 0,
            'tax_amount' => 0,
            'total_amount' => 20000,
        ]);

        $this->actingAs($this->owner)->post('/admin/contracts/from-quotation', [
            'quotation_id' => $quotation->id,
            'contract_template_id' => $this->installationForm()->id,
            'deposit_amount' => 10000,
            'payment_method_id' => PaymentMethod::where('code', 'transfer')->value('id'),
        ])->assertRedirect();

        $contract = Contract::latest('id')->firstOrFail();
        $voucher = Voucher::where('contract_id', $contract->id)->firstOrFail();

        $this->assertSame(10000.0, $contract->paidAmount());
        $this->assertSame(10000.0, $contract->remainingAmount());
        // A transfer lands in the bank, not the cash box.
        $this->assertSame('bank', $voucher->treasury->type);
        $this->assertSame($department->id, $voucher->costCenter?->department_id);
    }

    public function test_cancelling_the_receipt_gives_the_contract_back_what_it_added(): void
    {
        $contract = $this->drawWithDeposit(12000, 4000);
        $voucher = Voucher::where('contract_id', $contract->id)->firstOrFail();

        app(VoucherService::class)->cancel($voucher, 'سند خاطئ', $this->owner->id);

        $this->assertSame(0.0, $contract->fresh()->paidAmount());
        $this->assertSame(12000.0, $contract->fresh()->remainingAmount());
    }

    public function test_a_contract_is_still_drawn_when_the_receipt_cannot_be_written(): void
    {
        // No unearned-revenue account, so the voucher cannot be posted. The
        // contract is already signed by then — losing it would be worse than
        // reporting the deposit as unrecorded.
        Account::where('code', Ledger::UNEARNED_REVENUE)->delete();

        $this->actingAs($this->owner)->post('/admin/contracts/direct', [
            'client_id' => $this->client->id,
            'contract_template_id' => $this->installationForm()->id,
            'total_amount' => 12000,
            'deposit_amount' => 4000,
        ])->assertSessionHas('warning');

        $contract = Contract::latest('id')->firstOrFail();

        $this->assertSame(0.0, $contract->paidAmount());
        $this->assertCount(0, $contract->vouchers()->get());
    }

    public function test_a_cancelled_contract_takes_no_further_receipt(): void
    {
        $contract = $this->drawWithDeposit(12000, 4000);

        $this->actingAs($this->owner)->patch("/admin/contracts/{$contract->id}/status", ['status' => 'cancelled'])
            ->assertRedirect();

        $this->actingAs($this->owner)->post("/admin/contracts/{$contract->id}/receipt", ['amount' => 1000])
            ->assertSessionHas('warning');

        // ...and what was already collected stays: the money reached the till,
        // and giving it back is a سند صرف, not the receipt being erased.
        $this->assertSame(4000.0, $contract->fresh()->paidAmount());
        $this->assertCount(1, $contract->vouchers()->get());

        $this->actingAs($this->owner)->get("/admin/contracts/{$contract->id}")
            ->assertInertia(fn ($page) => $page
                ->where('contract.accepts_receipt', false)
                ->where('contract.deposit_amount', '4,000.00'));
    }

    private function drawWithDeposit(float $total, float $deposit): Contract
    {
        $this->actingAs($this->owner)->post('/admin/contracts/direct', [
            'client_id' => $this->client->id,
            'contract_template_id' => $this->installationForm()->id,
            'total_amount' => $total,
            'deposit_amount' => $deposit,
        ])->assertRedirect();

        return Contract::latest('id')->firstOrFail();
    }

    private function installationForm(): ContractTemplate
    {
        return ContractTemplate::where('name', PoolInstallationContractTemplate::NAME)->firstOrFail();
    }

    private function maintenanceForm(): ContractTemplate
    {
        return ContractTemplate::where('name', PoolMaintenanceContractTemplate::NAME)->firstOrFail();
    }
}
