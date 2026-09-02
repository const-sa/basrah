<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Department;
use App\Models\Item;
use App\Models\PaymentMethod;
use App\Models\Quotation;
use App\Models\Role;
use App\Models\Setting;
use App\Models\Sale;
use App\Models\User;
use App\Support\Vat;
use Database\Seeders\AccountsSeeder;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\DepartmentsSeeder;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Each line of a quotation is offered with tax or exempt from it.
 *
 * A quotation mixes what is taxed with what is not — a supplied item beside a
 * government fee passed on at cost — and the catalogue's rate cannot tell them
 * apart. So the line answers for itself, and its answer outranks its item's
 * rate, on the offer and on the invoice drawn from it.
 */
class QuotationLineTaxChoiceTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Client $client;

    private Department $department;

    private Item $taxed;

    private Item $exempt;

    protected function setUp(): void
    {
        parent::setUp();

        $this->registerForVat();

        $this->seed([RolesSeeder::class, DepartmentsSeeder::class, CatalogSeeder::class, AccountsSeeder::class]);

        $this->admin = User::factory()->create([
            'role_id' => Role::where('slug', 'super-admin')->value('id'),
            'is_active' => true,
            'has_all_units' => true,
        ]);

        $this->client = Client::walkIn();
        $this->department = Department::selling()->firstOrFail();

        $items = Item::where('type', 'service')->take(2)->get();

        if ($items->count() < 2) {
            $items = Item::take(2)->get();
        }

        [$this->taxed, $this->exempt] = [$items[0], $items[1]];

        Item::whereIn('id', [$this->taxed->id, $this->exempt->id])->update(['tax_rate' => 15]);

        $this->taxed->refresh();
        $this->exempt->refresh();
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(bool $secondLineTaxable, array $overrides = []): array
    {
        return array_merge([
            'client_id' => $this->client->id,
            'department_id' => $this->department->id,
            'discount_amount' => 0,
            'notes' => null,
            'valid_until' => null,
            'items' => [
                [
                    'item_id' => $this->taxed->id,
                    'quantity' => 2,
                    'unit_price' => 100,
                    'is_taxable' => true,
                    'tax_amount' => 30,
                ],
                [
                    'item_id' => $this->exempt->id,
                    'quantity' => 1,
                    'unit_price' => 400,
                    'is_taxable' => $secondLineTaxable,
                    'tax_amount' => 60,
                ],
            ],
        ], $overrides);
    }

    public function test_a_line_marked_exempt_adds_no_tax_though_its_item_has_a_rate(): void
    {
        $this->actingAs($this->admin)
            ->post('/admin/quotations', $this->payload(secondLineTaxable: false))
            ->assertSessionMissing('warning')
            ->assertRedirect('/admin/quotations');

        $quotation = Quotation::latest('id')->firstOrFail();

        $this->assertSame('600.00', $quotation->subtotal);
        $this->assertSame('30.00', $quotation->tax_amount);
        $this->assertSame('630.00', $quotation->total_amount);

        $lines = $quotation->items()->orderBy('id')->get();

        $this->assertTrue($lines[0]->is_taxable);
        $this->assertSame('30.00', $lines[0]->tax_amount);
        $this->assertFalse($lines[1]->is_taxable);
        $this->assertSame('0.00', $lines[1]->tax_amount);
    }

    public function test_every_taxed_line_still_carries_its_share(): void
    {
        $this->actingAs($this->admin)
            ->post('/admin/quotations', $this->payload(secondLineTaxable: true))
            ->assertRedirect('/admin/quotations');

        $quotation = Quotation::latest('id')->firstOrFail();

        $this->assertSame('90.00', $quotation->tax_amount);
        $this->assertSame('690.00', $quotation->total_amount);
    }

    public function test_the_choice_is_answered_again_on_every_edit(): void
    {
        $this->actingAs($this->admin)->post('/admin/quotations', $this->payload(secondLineTaxable: true));

        $quotation = Quotation::latest('id')->firstOrFail();

        $this->actingAs($this->admin)
            ->put("/admin/quotations/{$quotation->id}", $this->payload(secondLineTaxable: false))
            ->assertSessionMissing('warning')
            ->assertRedirect('/admin/quotations');

        $quotation->refresh();

        $this->assertSame('30.00', $quotation->tax_amount);
        $this->assertSame('630.00', $quotation->total_amount);
        $this->assertFalse($quotation->items()->orderBy('id')->get()[1]->is_taxable);
    }

    /**
     * The printed sheet reads the line's own tax, so an exempt line shows none
     * instead of a share of the offer's tax spread across every line.
     */
    public function test_the_sheet_reads_each_line_by_its_own_answer(): void
    {
        $this->actingAs($this->admin)->post('/admin/quotations', $this->payload(secondLineTaxable: false));

        $quotation = Quotation::latest('id')->firstOrFail();

        $this->actingAs($this->admin)
            ->getJson("/admin/quotations/{$quotation->id}")
            ->assertOk()
            ->assertJsonPath('items.0.is_taxable', true)
            ->assertJsonPath('items.0.tax_amount', 30)
            ->assertJsonPath('items.1.is_taxable', false)
            ->assertJsonPath('items.1.tax_amount', 0);
    }

    /**
     * What was offered is what is billed: an exempt line must not gain tax on
     * the way to the invoice, or the client pays more than the offer said.
     */
    public function test_the_invoice_drawn_from_the_offer_keeps_the_exemption(): void
    {
        $this->actingAs($this->admin)->post('/admin/quotations', $this->payload(secondLineTaxable: false));

        $quotation = Quotation::latest('id')->firstOrFail();

        $this->actingAs($this->admin)->post("/admin/quotations/{$quotation->id}/invoice", [
            'payment_method_id' => PaymentMethod::where('code', 'cash')->value('id')
                ?? PaymentMethod::create(PaymentMethod::defaults()[0])->id,
        ])->assertSessionMissing('warning');

        $sale = Sale::latest('id')->firstOrFail();

        $this->assertSame('30.00', $sale->tax_amount);

        $lines = $sale->lines()->orderBy('id')->get();

        $this->assertSame('30.00', $lines[0]->tax_amount);
        $this->assertSame('0.00', $lines[1]->tax_amount);
    }

    public function test_every_line_must_say_whether_it_is_taxed(): void
    {
        $payload = $this->payload(secondLineTaxable: true);
        unset($payload['items'][1]['is_taxable']);

        $this->actingAs($this->admin)
            ->post('/admin/quotations', $payload)
            ->assertSessionHasErrors('items.1.is_taxable');
    }

    /**
     * The switch in settings still outranks the line: a business that is not
     * registered issues no tax, whatever its lines were marked.
     */
    public function test_the_settings_switch_still_silences_a_taxed_line(): void
    {
        Setting::current()->update(['tax_enabled' => false]);
        Vat::forget();

        $this->actingAs($this->admin)->post('/admin/quotations', $this->payload(secondLineTaxable: true));

        $quotation = Quotation::latest('id')->firstOrFail();

        $this->assertSame('0.00', $quotation->tax_amount);
        $this->assertSame('600.00', $quotation->total_amount);
    }
}
