<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Client;
use App\Models\Contract;
use App\Models\Department;
use App\Models\Item;
use App\Models\Quotation;
use App\Models\QuotationItem;
use App\Models\Role;
use App\Models\User;
use App\Services\BookingService;
use App\Services\ContractPdf;
use App\Services\ContractService;
use Database\Seeders\AccountsSeeder;
use Database\Seeders\BookingSetupSeeder;
use Database\Seeders\ContractTemplateSeeder;
use Database\Seeders\RolesSeeder;
use Database\Seeders\UnitsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Contracts drawn from a quotation — the pools path (sales and maintenance).
 */
class QuotationContractTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Quotation $quotation;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        $this->seed([RolesSeeder::class, UnitsSeeder::class, BookingSetupSeeder::class, AccountsSeeder::class, ContractTemplateSeeder::class]);

        $this->owner = User::factory()->create([
            'role_id' => Role::where('slug', 'super-admin')->value('id'),
            'is_active' => true,
            'has_all_units' => true,
        ]);

        $this->quotation = $this->makeQuotation();
    }

    public function test_a_contract_is_drawn_from_the_quotation(): void
    {
        $this->actingAs($this->owner)
            ->post('/admin/contracts/from-quotation', ['quotation_id' => $this->quotation->id])
            ->assertRedirect();

        $contract = Contract::latest('id')->first();

        $this->assertNotNull($contract);
        $this->assertSame($this->quotation->id, $contract->quotation_id);
        $this->assertNull($contract->booking_id);
        $this->assertSame($this->quotation->client_id, $contract->client_id);
        $this->assertTrue($contract->fromQuotation());
    }

    public function test_the_priced_lines_and_total_are_frozen_onto_the_contract(): void
    {
        $contract = app(ContractService::class)->generateFromQuotation($this->quotation);

        $this->assertCount(2, $contract->lines());
        $this->assertSame('مضخة مسبح', $contract->lines()[0]['name']);
        // The contracted price is the quoted price by construction, not retyped.
        $this->assertSame(
            number_format((float) $this->quotation->total_amount, 2),
            $contract->data['total_amount'],
        );
        $this->assertSame($this->quotation->number, $contract->data['quotation_number']);
    }

    public function test_editing_the_quotation_afterwards_does_not_rewrite_the_contract(): void
    {
        $contract = app(ContractService::class)->generateFromQuotation($this->quotation);
        $frozenTotal = $contract->data['total_amount'];

        $this->quotation->update(['total_amount' => 99999]);
        $this->quotation->items()->delete();

        // The snapshot is the contract: the quotation stays editable after a
        // contract is drawn, and a later price change must not reach it.
        $contract->refresh();

        $this->assertSame($frozenTotal, $contract->data['total_amount']);
        $this->assertCount(2, $contract->lines());
    }

    public function test_drawing_the_contract_marks_the_quotation_accepted(): void
    {
        $this->assertSame('pending', $this->quotation->status);

        $this->actingAs($this->owner)
            ->post('/admin/contracts/from-quotation', ['quotation_id' => $this->quotation->id]);

        $this->assertSame('accepted', $this->quotation->fresh()->status);
    }

    public function test_one_quotation_yields_only_one_contract(): void
    {
        $this->actingAs($this->owner)
            ->post('/admin/contracts/from-quotation', ['quotation_id' => $this->quotation->id]);

        $this->actingAs($this->owner)
            ->post('/admin/contracts/from-quotation', ['quotation_id' => $this->quotation->id])
            ->assertSessionHas('warning');

        $this->assertSame(1, Contract::where('quotation_id', $this->quotation->id)->count());
    }

    public function test_a_rejected_quotation_yields_no_contract(): void
    {
        $this->quotation->update(['status' => 'rejected']);

        $this->actingAs($this->owner)
            ->post('/admin/contracts/from-quotation', ['quotation_id' => $this->quotation->id])
            ->assertSessionHas('warning');

        $this->assertSame(0, Contract::where('quotation_id', $this->quotation->id)->count());
    }

    public function test_the_pools_register_shows_only_its_own_contracts(): void
    {
        app(ContractService::class)->generateFromQuotation($this->quotation);
        // A booking draws its own contract as it is created — no second call.
        $this->makeBooking();

        $this->actingAs($this->owner)->get('/admin/pools/contracts')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('admin/contracts/Index')
                ->where('scope', 'quotation')
                ->has('contracts.data', 1)
                ->where('contracts.data.0.from_quotation', true)
                // No booking source is offered where a booking contract would
                // not show up afterwards.
                ->has('bookings', 0));

        // The overseeing register still sees both.
        $this->actingAs($this->owner)->get('/admin/contracts')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('scope', 'all')->has('contracts.data', 2));
    }

    public function test_the_contract_page_and_pdf_render_the_quotation_lines(): void
    {
        $contract = app(ContractService::class)->generateFromQuotation($this->quotation);

        $this->actingAs($this->owner)->get("/admin/contracts/{$contract->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('admin/contracts/Show')
                ->where('contract.from_quotation', true)
                ->where('contract.subject', 'المسابح')
                ->has('contract.items', 2));

        $pdf = app(ContractPdf::class)->render($contract);

        $this->assertStringStartsWith('%PDF-', $pdf);
    }

    public function test_a_template_placeholder_carrying_the_lines_is_left_standing(): void
    {
        // `items` is an array, not a scalar: substituting it into a sentence
        // would be a type error, so an unknown-shaped key stays as written.
        $contract = app(ContractService::class)->generateFromQuotation($this->quotation);
        $contract->template->update(['terms' => 'البنود: {{items}} — الإجمالي {{total_amount}}']);

        $this->actingAs($this->owner)->post("/admin/contracts/{$contract->id}/refresh");

        $terms = (string) $contract->fresh()->terms;

        $this->assertStringContainsString('{{items}}', $terms);
        $this->assertStringContainsString($contract->data['total_amount'], $terms);
    }

    private function makeQuotation(): Quotation
    {
        $department = Department::firstOrCreate(
            ['code' => 'POOLS'],
            ['name' => 'المسابح', 'sells' => true, 'is_active' => true, 'sort_order' => 1],
        );

        $client = Client::create(['name' => 'شركة الأفق', 'mobile' => '0551234567']);

        $quotation = Quotation::create([
            'number' => 'QT-000001',
            'client_id' => $client->id,
            'user_id' => $this->owner->id,
            'department_id' => $department->id,
            'status' => 'pending',
            'subtotal' => 700,
            'discount_amount' => 50,
            'tax_amount' => 97.50,
            'total_amount' => 747.50,
            'valid_until' => '2026-10-01',
        ]);

        foreach ([['مضخة مسبح', 1, 500], ['صيانة شهرية', 2, 100]] as [$name, $qty, $price]) {
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

    private function makeBooking(): Booking
    {
        return app(BookingService::class)->create([
            'unit_id' => $this->chaletLetWhole()->id,
            'client_id' => Client::create(['name' => 'خالد المطيري', 'mobile' => '0559876543'])->id,
            'scope' => 'whole',
            'booking_date' => '2026-09-10',
            'period' => 'full_day',
            'status' => 'confirmed',
            'guests_count' => 30,
        ]);
    }
}
