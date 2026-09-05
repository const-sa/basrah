<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\ContractTemplate;
use App\Models\Department;
use App\Models\Item;
use App\Models\Quotation;
use App\Models\QuotationItem;
use App\Models\Role;
use App\Models\User;
use App\Services\ContractPdf;
use App\Services\ContractService;
use App\Support\PoolInstallationContractTemplate;
use Database\Seeders\ContractTemplateSeeder;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * The pools' piping-and-installation form — «عقد التمديد والتركيب».
 */
class PoolInstallationContractTest extends TestCase
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

    public function test_the_seeder_pins_the_installation_form(): void
    {
        $template = ContractTemplate::where('name', PoolInstallationContractTemplate::NAME)->first();

        $this->assertNotNull($template);
        $this->assertTrue($template->is_active);
        // The halls and chalets keep the default; this one is picked by name.
        $this->assertFalse((bool) $template->is_default);
        $this->assertStringContainsString('{{first_installment}}', $template->body);
        $this->assertStringContainsString('جرم الفلتر', $template->terms);
    }

    public function test_the_form_is_offered_on_the_pools_contracts_screen(): void
    {
        $this->actingAs($this->owner)->get('/admin/pools/contracts')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('admin/contracts/Index')
                ->where('scope', 'quotation')
                ->where(
                    'templates',
                    fn ($templates) => collect($templates)->pluck('name')
                        ->contains(PoolInstallationContractTemplate::NAME),
                ));
    }

    public function test_the_value_is_split_into_two_payments_that_add_up_to_it(): void
    {
        $contract = $this->draw();

        $this->assertSame('373.75', $contract->data['first_installment']);
        $this->assertSame('373.75', $contract->data['second_installment']);
        $this->assertStringContainsString('373.75 ريال', $contract->body);

        // An odd value is split without inventing or losing a halala.
        $this->quotation->update(['total_amount' => 1000.01]);
        $data = app(ContractService::class)->buildQuotationData($this->quotation->fresh(), 'CT-2026-0002');

        $this->assertSame('500.01', $data['first_installment']);
        $this->assertSame('500.00', $data['second_installment']);
    }

    public function test_the_contract_is_titled_and_laid_out_by_the_form_it_is_drawn_on(): void
    {
        $contract = $this->draw();

        $this->assertTrue($contract->isInstallationForm());
        $this->assertSame(PoolInstallationContractTemplate::SUBJECT, $contract->subject());

        $this->actingAs($this->owner)->get("/admin/contracts/{$contract->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('admin/contracts/Show')
                ->where('contract.is_installation_form', true)
                ->where('contract.subject', PoolInstallationContractTemplate::SUBJECT)
                ->where('contract.first_installment', '373.75')
                // The equipment grid is the quotation's own lines.
                ->has('contract.items', 2));

        $this->assertStringStartsWith('%PDF-', app(ContractPdf::class)->render($contract));
    }

    public function test_a_contract_on_another_template_keeps_the_standard_sheet(): void
    {
        $contract = app(ContractService::class)->generateFromQuotation($this->quotation);

        $this->assertFalse($contract->isInstallationForm());
        $this->assertSame('المسابح', $contract->subject());
    }

    public function test_moving_a_draft_onto_the_form_retitles_and_relayouts_it(): void
    {
        $contract = app(ContractService::class)->generateFromQuotation($this->quotation);

        $this->actingAs($this->owner)->post("/admin/contracts/{$contract->id}/refresh", [
            'contract_template_id' => $this->form()->id,
        ]);

        $contract->refresh();

        $this->assertTrue($contract->isInstallationForm());
        $this->assertSame(PoolInstallationContractTemplate::SUBJECT, $contract->subject());
        $this->assertStringContainsString('جرم الفلتر', (string) $contract->terms);
        // The priced snapshot underneath is untouched by the change of form.
        $this->assertCount(2, $contract->lines());
    }

    private function draw()
    {
        return app(ContractService::class)->generateFromQuotation($this->quotation, $this->form());
    }

    private function form(): ContractTemplate
    {
        return ContractTemplate::where('name', PoolInstallationContractTemplate::NAME)->firstOrFail();
    }

    private function makeQuotation(): Quotation
    {
        $department = Department::firstOrCreate(
            ['code' => 'POOLS'],
            ['name' => 'المسابح', 'sells' => true, 'is_active' => true, 'sort_order' => 1],
        );

        $client = Client::create([
            'name' => 'عبدالله الشمري',
            'mobile' => '0551508655',
            'city' => 'الرياض — حي الدار البيضاء',
        ]);

        $quotation = Quotation::create([
            'number' => 'QT-000010',
            'client_id' => $client->id,
            'user_id' => $this->owner->id,
            'department_id' => $department->id,
            'status' => 'pending',
            'subtotal' => 650,
            'discount_amount' => 0,
            'tax_amount' => 97.50,
            'total_amount' => 747.50,
            'valid_until' => '2026-10-01',
        ]);

        foreach ([['فلتر', 1, 450], ['مضخة', 1, 200]] as [$name, $qty, $price]) {
            $item = Item::create([
                'department_id' => $department->id,
                'code' => 'IT-'.$name,
                'name' => $name,
                'price' => $price,
                'is_active' => true,
            ]);

            QuotationItem::create([
                'quotation_id' => $quotation->id,
                'item_id' => $item->id,
                'quantity' => $qty,
                'unit_price' => $price,
                'total_price' => $qty * $price,
            ]);
        }

        return $quotation->fresh();
    }
}
