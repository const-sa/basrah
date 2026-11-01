<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Client;
use App\Models\Contract;
use App\Models\ContractTemplate;
use App\Models\CostCenter;
use App\Models\Department;
use App\Models\JournalEntry;
use App\Models\Role;
use App\Models\User;
use App\Services\Accounting\ContractAccounting;
use App\Services\Accounting\Ledger;
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

    public function test_an_installation_signed_but_not_handed_over_earns_nothing(): void
    {
        $contract = $this->installation(12000, 4000);

        $this->assertSame(0.0, $this->balanceOf(Ledger::SALES_REVENUE));
        $this->assertSame(4000.0, $this->balanceOf(Ledger::UNEARNED_REVENUE));
        $this->assertSame('لم يُسجَّل تنفيذ العقد بعد.', app(ContractAccounting::class)->refusal($contract));
    }

    public function test_handing_over_an_installation_recognizes_its_revenue(): void
    {
        $contract = $this->installation(12000, 4000);

        $this->actingAs($this->owner)
            ->post("/admin/contracts/{$contract->id}/complete")
            ->assertSessionHas('success');

        // The whole value is earned; what was collected clears the liability
        // and the rest stands as a receivable on the client.
        $this->assertSame(12000.0, $this->balanceOf(Ledger::SALES_REVENUE));
        $this->assertSame(0.0, $this->balanceOf(Ledger::UNEARNED_REVENUE));
        $this->assertSame(8000.0, $this->balanceOf(Ledger::RECEIVABLES));
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

        $this->assertSame(1, JournalEntry::where('source', 'contract')->count());
        $this->assertSame(12000.0, $this->balanceOf(Ledger::SALES_REVENUE));
    }

    public function test_undoing_a_handover_reverses_the_revenue(): void
    {
        $contract = $this->installation(12000, 4000);

        $this->actingAs($this->owner)->post("/admin/contracts/{$contract->id}/complete");
        $this->actingAs($this->owner)->delete("/admin/contracts/{$contract->id}/complete");

        $this->assertNull($contract->fresh()->completed_at);
        $this->assertSame(0.0, $this->balanceOf(Ledger::SALES_REVENUE));
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

        $this->assertSame(800.0, $this->balanceOf(Ledger::SALES_REVENUE));
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
        $this->assertSame(1600.0, $this->balanceOf(Ledger::SALES_REVENUE));
    }

    public function test_a_maintenance_sheet_is_never_recognized_on_handover(): void
    {
        $contract = $this->maintenance(800, 800);

        $this->assertSame(
            'عقد الصيانة الشهرية يُثبت إيراده مع كل سند قبض.',
            app(ContractAccounting::class)->refusal($contract),
        );
    }

    public function test_cancelling_a_recognized_contract_reverses_it(): void
    {
        $contract = $this->installation(12000, 4000);

        $this->actingAs($this->owner)->post("/admin/contracts/{$contract->id}/complete");

        $this->actingAs($this->owner)
            ->patch("/admin/contracts/{$contract->id}/status", ['status' => 'cancelled'])
            ->assertSessionHas('success');

        $this->assertSame(0.0, $this->balanceOf(Ledger::SALES_REVENUE));
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
