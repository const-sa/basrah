<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Contract;
use App\Models\ContractTemplate;
use App\Models\Department;
use App\Models\Item;
use App\Models\Quotation;
use App\Models\QuotationItem;
use App\Models\Role;
use App\Models\User;
use App\Services\ContractPdf;
use App\Services\ContractService;
use App\Support\PoolMaintenanceContractTemplate;
use Database\Seeders\ContractTemplateSeeder;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * The pools' monthly-maintenance sheet — «عرض سعر الصيانة الشهرية».
 */
class PoolMaintenanceContractTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Quotation $quotation;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        $this->seed([RolesSeeder::class, ContractTemplateSeeder::class]);

        $this->owner = User::factory()->create([
            'role_id' => Role::where('slug', 'super-admin')->value('id'),
            'is_active' => true,
            'has_all_units' => true,
        ]);

        $this->quotation = $this->makeQuotation();
    }

    public function test_the_seeder_pins_the_maintenance_sheet(): void
    {
        $template = ContractTemplate::where('name', PoolMaintenanceContractTemplate::NAME)->first();

        $this->assertNotNull($template);
        $this->assertTrue($template->is_active);
        // The halls and chalets keep the default; this one is picked by name.
        $this->assertFalse((bool) $template->is_default);
        $this->assertStringContainsString('{{discount_amount}}', $template->body);
        $this->assertStringContainsString('بداية كل شهر ميلادي', $template->terms);
    }

    public function test_the_sheet_is_offered_on_the_pools_contracts_screen(): void
    {
        $this->actingAs($this->owner)->get('/admin/pools/contracts')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('admin/contracts/Index')
                ->where('scope', 'quotation')
                ->where(
                    'templates',
                    fn ($templates) => collect($templates)->pluck('name')
                        ->contains(PoolMaintenanceContractTemplate::NAME),
                ));
    }

    public function test_the_sheet_prints_the_priced_lines_and_the_discount_under_them(): void
    {
        $contract = $this->draw();

        $this->assertTrue($contract->isMaintenanceForm());
        // Not the installation pad — a different paper, and the layouts must
        // not be confused for one another.
        $this->assertFalse($contract->isInstallationForm());
        $this->assertSame(PoolMaintenanceContractTemplate::SUBJECT, $contract->subject());

        // The paper's own arithmetic: the lines total 5,000, less 500, due 4,500.
        $this->assertSame('5,000.00', $contract->data['subtotal']);
        $this->assertSame('500.00', $contract->data['discount_amount']);
        $this->assertSame('4,500.00', $contract->data['total_amount']);
        $this->assertStringContainsString('4,500.00 ريال', $contract->body);

        $this->actingAs($this->owner)->get("/admin/contracts/{$contract->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('admin/contracts/Show')
                ->where('contract.is_maintenance_form', true)
                ->where('contract.is_installation_form', false)
                ->where('contract.subject', PoolMaintenanceContractTemplate::SUBJECT)
                ->where('contract.subtotal', '5,000.00')
                ->where('contract.discount_amount', '500.00')
                ->has('contract.items', 1));

        $this->assertStringStartsWith('%PDF-', app(ContractPdf::class)->render($contract));
    }

    public function test_moving_a_draft_onto_the_sheet_retitles_and_relayouts_it(): void
    {
        $contract = app(ContractService::class)->generateFromQuotation($this->quotation);

        $this->assertFalse($contract->isMaintenanceForm());

        $this->actingAs($this->owner)->post("/admin/contracts/{$contract->id}/refresh", [
            'contract_template_id' => $this->form()->id,
        ]);

        $contract->refresh();

        $this->assertTrue($contract->isMaintenanceForm());
        $this->assertSame(PoolMaintenanceContractTemplate::SUBJECT, $contract->subject());
        $this->assertStringContainsString('المواد الكيماوية', (string) $contract->terms);
        // The priced snapshot underneath is untouched by the change of form.
        $this->assertCount(1, $contract->lines());
    }

    public function test_the_sheet_is_written_on_a_client_with_no_quotation(): void
    {
        $client = Client::create(['name' => 'المدرسة الرقمية', 'mobile' => '0545332149', 'type' => 'pool']);

        $this->actingAs($this->owner)->post('/admin/contracts/direct', [
            'client_id' => $client->id,
            'contract_template_id' => $this->form()->id,
            'total_amount' => 4500,
        ])->assertRedirect();

        $contract = Contract::latest('id')->first();

        $this->assertNotNull($contract);
        $this->assertTrue($contract->isMaintenanceForm());
        $this->assertSame($client->id, $contract->client_id);
        // No quotation behind it, so the table is ruled empty to be filled by
        // hand — and the sheet still prints.
        $this->assertSame([], $contract->lines());
        $this->assertSame('—', $contract->data['discount_amount']);
        $this->assertStringStartsWith('%PDF-', app(ContractPdf::class)->render($contract));

        $this->actingAs($this->owner)->get('/admin/pools/contracts')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('contracts.data', 1)
                ->where('contracts.data.0.number', $contract->number));
    }

    private function draw(): Contract
    {
        return app(ContractService::class)->generateFromQuotation($this->quotation, $this->form());
    }

    private function form(): ContractTemplate
    {
        return ContractTemplate::where('name', PoolMaintenanceContractTemplate::NAME)->firstOrFail();
    }

    /**
     * The paper's own example: two monthly visits at 2,500, less a 500 discount.
     */
    private function makeQuotation(): Quotation
    {
        $department = Department::firstOrCreate(
            ['code' => 'POOLS'],
            ['name' => 'المسابح', 'sells' => true, 'is_active' => true, 'sort_order' => 1],
        );

        $client = Client::create([
            'name' => 'المدرسة الرقمية',
            'mobile' => '0551508655',
            'city' => 'الرياض',
            'type' => 'pool',
        ]);

        $quotation = Quotation::create([
            'number' => 'QT-000020',
            'client_id' => $client->id,
            'user_id' => $this->owner->id,
            'department_id' => $department->id,
            'status' => 'pending',
            'subtotal' => 5000,
            'discount_amount' => 500,
            'tax_amount' => 0,
            'total_amount' => 4500,
            'valid_until' => '2026-10-01',
        ]);

        $item = Item::create([
            'department_id' => $department->id,
            'code' => 'IT-MAINT',
            'name' => 'تنظيف وصيانة المسبح شامل المواد',
            'price' => 2500,
            'is_active' => true,
        ]);

        QuotationItem::create([
            'quotation_id' => $quotation->id,
            'item_id' => $item->id,
            'quantity' => 2,
            'unit_price' => 2500,
            'total_price' => 5000,
        ]);

        return $quotation->fresh();
    }
}
