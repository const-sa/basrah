<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Department;
use App\Models\Item;
use App\Models\Quotation;
use App\Models\QuotationItem;
use App\Models\Role;
use App\Models\Sale;
use App\Models\User;
use Database\Seeders\AccountsSeeder;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\DepartmentsSeeder;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A sales invoice is issued with tax or without it.
 *
 * The catalogue's rate says what tax would be due on an item, not whether this
 * sale is taxed: the same bottle is sold with VAT over the counter and without
 * it to an exempt buyer. So the invoice answers for itself, and its answer
 * outranks every rate on its lines — on the sheet, in the register, and in the
 * QR code the tax authority reads.
 */
class SaleTaxChoiceTest extends TestCase
{
    use RefreshDatabase;

    private User $cashier;

    private Department $department;

    private Item $item;

    protected function setUp(): void
    {
        parent::setUp();

        // The invoice's answer sits under the business-wide switch, so the
        // business is registered here to test the answer itself, not the switch.
        $this->registerForVat();

        $this->seed([RolesSeeder::class, DepartmentsSeeder::class, CatalogSeeder::class, AccountsSeeder::class]);

        $this->cashier = User::factory()->create([
            'role_id' => Role::where('slug', 'super-admin')->value('id'),
            'is_active' => true,
            'has_all_units' => true,
        ]);

        $this->department = Department::selling()->firstOrFail();

        $this->item = Item::where('department_id', $this->department->id)
            ->where('type', 'service')
            ->firstOr(fn () => Item::where('department_id', $this->department->id)->firstOrFail());

        $this->item->update(['tax_rate' => 15, 'price' => 100]);
        $this->item->refresh();
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(bool $taxable, array $overrides = []): array
    {
        return array_merge([
            'lines' => [[
                'item_id' => $this->item->id,
                'quantity' => 2,
                'unit_price' => 100,
                'discount_amount' => 0,
            ]],
            'client_id' => Client::walkIn()->id,
            'department_id' => $this->department->id,
            'payment_method_id' => $this->paymentMethodId(),
            'discount_amount' => 0,
            'is_taxable' => $taxable,
            'notes' => null,
        ], $overrides);
    }

    public function test_an_invoice_issued_with_tax_carries_it(): void
    {
        $this->actingAs($this->cashier)
            ->post('/admin/pos/checkout', $this->payload(taxable: true))
            ->assertSessionMissing('warning');

        $sale = Sale::latest('id')->firstOrFail();

        $this->assertTrue($sale->is_taxable);
        $this->assertSame('200.00', $sale->subtotal);
        $this->assertSame('30.00', $sale->tax_amount);
        $this->assertSame('230.00', $sale->total_amount);
    }

    public function test_an_invoice_issued_without_tax_carries_none_though_its_item_has_a_rate(): void
    {
        $this->actingAs($this->cashier)
            ->post('/admin/pos/checkout', $this->payload(taxable: false))
            ->assertSessionMissing('warning');

        $sale = Sale::latest('id')->firstOrFail();

        $this->assertFalse($sale->is_taxable);
        $this->assertSame('200.00', $sale->subtotal);
        $this->assertSame('0.00', $sale->tax_amount);
        $this->assertSame('200.00', $sale->total_amount);
        $this->assertSame('0.00', $sale->lines()->firstOrFail()->tax_amount);
    }

    public function test_the_invoice_must_say_whether_it_is_taxed(): void
    {
        $payload = $this->payload(taxable: true);
        unset($payload['is_taxable']);

        $this->actingAs($this->cashier)
            ->post('/admin/pos/checkout', $payload)
            ->assertSessionHasErrors('is_taxable');

        $this->assertSame(0, Sale::count());
    }

    /**
     * The printed sheet is read by its invoice's answer: without tax there is no
     * row, no tax number and no QR — a code carrying zero tax is rejected by the
     * authority's app.
     */
    public function test_the_untaxed_sheet_carries_no_tax_number_and_no_qr(): void
    {
        $this->actingAs($this->cashier)->post('/admin/pos/checkout', $this->payload(taxable: false));

        $sale = Sale::latest('id')->firstOrFail();

        $this->actingAs($this->cashier)
            ->getJson("/admin/sales/{$sale->id}")
            ->assertOk()
            ->assertJsonPath('sale.is_taxable', false)
            ->assertJsonPath('sale.tax_amount', 0)
            ->assertJsonPath('issuer.tax_number', null)
            ->assertJsonPath('issuer.qr', null);
    }

    public function test_the_taxed_sheet_keeps_its_tax_number_and_qr(): void
    {
        $this->actingAs($this->cashier)->post('/admin/pos/checkout', $this->payload(taxable: true));

        $sale = Sale::latest('id')->firstOrFail();

        $this->actingAs($this->cashier)
            ->getJson("/admin/sales/{$sale->id}")
            ->assertOk()
            ->assertJsonPath('sale.is_taxable', true)
            ->assertJsonPath('sale.tax_amount', 30)
            ->assertJsonPath('issuer.tax_number', '300000000000003');
    }

    /**
     * A return is written with the answer of the invoice it reverses: what was
     * sold untaxed is refunded untaxed, so the system never hands back tax it
     * never took.
     */
    public function test_a_return_inherits_the_answer_of_the_invoice_it_reverses(): void
    {
        $this->actingAs($this->cashier)->post('/admin/pos/checkout', $this->payload(taxable: false));

        $sale = Sale::latest('id')->firstOrFail();

        $this->actingAs($this->cashier)
            ->post("/admin/sales/{$sale->id}/refund", [
                'quantities' => [$this->item->id => 2],
            ])
            ->assertSessionMissing('warning');

        $return = Sale::where('original_sale_id', $sale->id)->firstOrFail();

        $this->assertFalse($return->is_taxable);
        $this->assertSame('0.00', $return->tax_amount);
        $this->assertSame('200.00', $return->total_amount);
    }

    /**
     * The invoice is written with the answer of the offer behind it: an offer
     * whose every line is exempt becomes an untaxed invoice, printing neither a
     * zero tax row nor a QR.
     */
    public function test_an_invoice_from_an_all_exempt_quotation_is_issued_without_tax(): void
    {
        $sale = $this->convertQuotationWithExemptLines(allExempt: true);

        $this->assertFalse($sale->is_taxable);
        $this->assertSame('0.00', $sale->tax_amount);
    }

    /**
     * And an offer with a single taxed line yields a tax invoice: the answer does
     * not drop what is due on the lines that were never exempt.
     */
    public function test_an_invoice_from_a_partly_taxed_quotation_stays_taxed(): void
    {
        $sale = $this->convertQuotationWithExemptLines(allExempt: false);

        $this->assertTrue($sale->is_taxable);
        $this->assertSame('15.00', $sale->tax_amount);
    }

    private function convertQuotationWithExemptLines(bool $allExempt): Sale
    {
        $second = Item::where('department_id', $this->department->id)
            ->where('id', '!=', $this->item->id)
            ->firstOrFail();

        $second->update(['tax_rate' => 15, 'price' => 100]);

        $quotation = Quotation::create([
            'number' => 'QT-000001',
            'client_id' => Client::walkIn()->id,
            'department_id' => $this->department->id,
            'user_id' => $this->cashier->id,
            'quotation_date' => now()->toDateString(),
            'subtotal' => 200,
            'discount_amount' => 0,
            'tax_amount' => $allExempt ? 0 : 15,
            'total_amount' => $allExempt ? 200 : 215,
            'status' => 'pending',
        ]);

        foreach ([$this->item, $second] as $index => $item) {
            QuotationItem::create([
                'quotation_id' => $quotation->id,
                'item_id' => $item->id,
                'quantity' => 1,
                'unit_price' => 100,
                'total_price' => 100,
                'is_taxable' => ! $allExempt && $index === 0,
                'tax_amount' => ! $allExempt && $index === 0 ? 15 : 0,
            ]);
        }

        $this->actingAs($this->cashier)
            ->post("/admin/quotations/{$quotation->id}/invoice", [
                'payment_method_id' => $this->paymentMethodId(),
            ])
            ->assertSessionMissing('warning');

        return Sale::latest('id')->firstOrFail();
    }
}
