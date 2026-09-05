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

    public function test_the_form_is_written_on_a_client_with_no_quotation(): void
    {
        $client = Client::create(['name' => 'سعد القحطاني', 'mobile' => '0555555555', 'type' => 'pool']);

        $this->actingAs($this->owner)->post('/admin/contracts/direct', [
            'client_id' => $client->id,
            'contract_template_id' => $this->form()->id,
            'total_amount' => 12000,
        ])->assertRedirect();

        $contract = Contract::latest('id')->first();

        $this->assertNotNull($contract);
        $this->assertNull($contract->quotation_id);
        $this->assertNull($contract->booking_id);
        $this->assertSame($client->id, $contract->client_id);
        $this->assertTrue($contract->isInstallationForm());
        $this->assertSame('6,000.00', $contract->data['first_installment']);
        $this->assertSame([], $contract->lines());

        // It belongs to the register that drew it, and it prints.
        $this->actingAs($this->owner)->get('/admin/pools/contracts')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('contracts.data', 1)
                ->where('contracts.data.0.number', $contract->number));

        $this->assertStringStartsWith('%PDF-', app(ContractPdf::class)->render($contract));
    }

    public function test_the_value_may_be_left_for_the_paper_to_carry(): void
    {
        $client = Client::create(['name' => 'مؤسسة النخبة', 'mobile' => '0533333333', 'type' => 'pool']);

        $this->actingAs($this->owner)->post('/admin/contracts/direct', [
            'client_id' => $client->id,
            'contract_template_id' => $this->form()->id,
        ])->assertRedirect();

        $contract = Contract::latest('id')->first();

        // «—» is what the page and the PDF print as an empty fill-in run.
        $this->assertSame('—', $contract->data['total_amount']);
        $this->assertSame('—', $contract->data['first_installment']);

        $this->actingAs($this->owner)->get("/admin/contracts/{$contract->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('contract.is_installation_form', true)
                ->where('contract.total_amount', null)
                ->where('contract.first_installment', null));
    }

    public function test_a_draft_written_on_a_client_is_edited_in_place(): void
    {
        $client = Client::create(['name' => 'فهد الدوسري', 'mobile' => '0544444444', 'type' => 'pool']);
        $other = Client::create(['name' => 'ناصر العتيبي', 'mobile' => '0522222222', 'type' => 'pool']);

        $contract = app(ContractService::class)->generateDirect($client, $this->form(), 1000);

        $this->actingAs($this->owner)->put("/admin/contracts/{$contract->id}", [
            'client_id' => $other->id,
            'fields' => [
                'total_amount' => '8000',
                'pool_width' => '4',
                'pool_length' => '8',
                'pool_min_depth' => '1.2',
                'pool_max_depth' => '1.8',
                // The number identifies the contract and is never written over.
                'contract_number' => 'CT-9999-9999',
            ],
            'items' => [
                ['name' => 'فلتر', 'quantity' => 1],
                ['name' => 'مضخة', 'quantity' => 2],
                // A row with no description is dropped, not signed against.
                ['name' => '', 'quantity' => 3],
            ],
            'body' => $contract->body,
            'terms' => $contract->terms,
        ])->assertRedirect("/admin/contracts/{$contract->id}");

        $number = $contract->number;
        $contract->refresh();

        $this->assertSame($number, $contract->number);
        $this->assertSame($other->id, $contract->client_id);
        $this->assertSame('ناصر العتيبي', $contract->data['client_name']);
        $this->assertSame('8,000.00', $contract->data['total_amount']);
        // The words and the payments follow the corrected value on their own.
        $this->assertSame('4,000.00', $contract->data['first_installment']);
        $this->assertStringContainsString('ثمانية آلاف', $contract->data['total_amount_words']);
        $this->assertSame('8', $contract->data['pool_length']);
        $this->assertCount(2, $contract->lines());
        // Terms left as they were are re-rendered, so the payments quoted
        // inside them follow the corrected value.
        $this->assertStringContainsString('4,000.00 ريال', (string) $contract->terms);
        $this->assertStringContainsString('8,000.00', $contract->body);
    }

    public function test_every_printed_field_is_editable(): void
    {
        $client = Client::create(['name' => 'راكان الشهري', 'mobile' => '0577777777', 'type' => 'pool']);
        $contract = app(ContractService::class)->generateDirect($client, $this->form(), 1000);

        $this->actingAs($this->owner)->put("/admin/contracts/{$contract->id}", [
            'fields' => [
                'contract_date' => '2026-01-15',
                'contract_date_hijri' => '1447/07/26',
                'client_name' => 'راكان الشهري وشركاه',
                'client_address' => 'الرياض — حي الملقا',
                'subject' => 'التمديد والتركيب',
                // Written by hand over what the value would have derived.
                'first_installment' => '700.00',
                'second_installment' => '300.00',
            ],
            'body' => $contract->body,
            'terms' => $contract->terms,
        ])->assertRedirect();

        $data = $contract->fresh()->data;

        $this->assertSame('2026-01-15', $data['contract_date']);
        $this->assertSame('1447/07/26', $data['contract_date_hijri']);
        $this->assertSame('راكان الشهري وشركاه', $data['client_name']);
        $this->assertSame('الرياض — حي الملقا', $data['client_address']);
        $this->assertSame('700.00', $data['first_installment']);
    }

    public function test_a_cleared_field_prints_as_a_blank_run(): void
    {
        $client = Client::create(['name' => 'وليد الزهراني', 'mobile' => '0588888888', 'type' => 'pool']);
        $contract = app(ContractService::class)->generateDirect($client, $this->form(), 1000);

        $this->actingAs($this->owner)->put("/admin/contracts/{$contract->id}", [
            'fields' => ['total_amount' => ''],
            'body' => $contract->body,
            'terms' => $contract->terms,
        ]);

        $this->assertSame('—', $contract->fresh()->data['total_amount']);

        $this->actingAs($this->owner)->get("/admin/contracts/{$contract->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('contract.total_amount', null));
    }

    public function test_the_frozen_text_is_rewritten_as_typed(): void
    {
        $client = Client::create(['name' => 'تركي المالكي', 'mobile' => '0599999999', 'type' => 'pool']);
        $contract = app(ContractService::class)->generateDirect($client, $this->form(), 1000);

        $this->actingAs($this->owner)->put("/admin/contracts/{$contract->id}", [
            'body' => 'عقد تمديد وتركيب — نصٌّ كتبه الموظف بنفسه.',
            'terms' => $contract->terms,
        ]);

        $this->assertSame('عقد تمديد وتركيب — نصٌّ كتبه الموظف بنفسه.', $contract->fresh()->body);
    }

    public function test_rewritten_terms_are_kept_word_for_word(): void
    {
        $client = Client::create(['name' => 'ماجد الحربي', 'mobile' => '0511111111', 'type' => 'pool']);
        $contract = app(ContractService::class)->generateDirect($client, $this->form(), 1000);

        $this->actingAs($this->owner)->put("/admin/contracts/{$contract->id}", [
            'client_id' => $client->id,
            'body' => $contract->body,
            'terms' => 'ضمان سنتين على الفلتر فقط.',
        ]);

        $this->assertSame('ضمان سنتين على الفلتر فقط.', $contract->fresh()->terms);
    }

    public function test_a_quotation_contract_is_edited_without_touching_its_quotation(): void
    {
        $contract = app(ContractService::class)->generateFromQuotation($this->quotation, $this->form());

        $this->actingAs($this->owner)->put("/admin/contracts/{$contract->id}", [
            'fields' => ['total_amount' => '800', 'pool_width' => '5'],
            'items' => [['name' => 'فلتر رملي', 'quantity' => 1, 'unit_price' => 800, 'total_price' => 800]],
            'body' => $contract->body,
            'terms' => $contract->terms,
        ])->assertRedirect();

        $contract->refresh();

        // The contract is the paper; the quotation it was drawn from is not
        // rewritten by correcting the paper.
        $this->assertSame('800.00', $contract->data['total_amount']);
        $this->assertSame('5', $contract->data['pool_width']);
        $this->assertCount(1, $contract->lines());
        $this->assertSame('800.00', $contract->lines()[0]['unit_price']);
        $this->assertSame('747.50', number_format((float) $this->quotation->fresh()->total_amount, 2));
    }

    public function test_a_sent_contract_is_never_edited(): void
    {
        $client = Client::create(['name' => 'بدر السالم', 'mobile' => '0566666666', 'type' => 'pool']);
        $contract = app(ContractService::class)->generateDirect($client, $this->form(), 1000);
        $contract->update(['status' => 'sent', 'sent_at' => now()]);

        $this->actingAs($this->owner)->get("/admin/contracts/{$contract->id}/edit")
            ->assertRedirect("/admin/contracts/{$contract->id}");

        $this->actingAs($this->owner)->put("/admin/contracts/{$contract->id}", [
            'fields' => ['total_amount' => '5'],
            'body' => 'محاولة',
        ])->assertSessionHas('warning');

        $this->assertSame('1,000.00', $contract->fresh()->data['total_amount']);
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
