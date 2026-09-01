<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Item;
use App\Models\Purchase;
use App\Models\Role;
use App\Models\Supplier;
use App\Models\User;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\DepartmentsSeeder;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A purchase invoice is billed with tax or without it.
 *
 * The catalogue's rate is what tax would be due, not what the supplier
 * actually charged: the same item comes taxed from a registered supplier and
 * untaxed from one who is not. So the invoice answers for itself, and its
 * answer outranks every rate on its lines.
 */
class PurchaseTaxChoiceTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Supplier $supplier;

    private Item $item;

    private Department $department;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([RolesSeeder::class, DepartmentsSeeder::class, CatalogSeeder::class]);

        $this->admin = User::factory()->create([
            'role_id' => Role::where('slug', 'super-admin')->value('id'),
            'is_active' => true,
        ]);

        $this->supplier = Supplier::create(['name' => 'مؤسسة التوريد', 'is_active' => true]);
        $this->department = Department::firstWhere('code', 'POOLS') ?? Department::firstOrFail();

        $this->item = Item::where('type', 'stock')->firstOrFail();
        $this->item->update(['tax_rate' => 15]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(bool $taxable, array $overrides = []): array
    {
        return array_merge([
            'supplier_id' => $this->supplier->id,
            'department_id' => $this->department->id,
            'payment_method_id' => null,
            'is_taxable' => $taxable,
            'paid_amount' => 0,
            'discount_amount' => 0,
            'notes' => null,
            'items' => [[
                'item_id' => $this->item->id,
                'quantity' => 10,
                'unit_cost' => 35,
                // What the screen computed from the catalogue's rate — 350 × 15%
                'tax_amount' => 52.50,
            ]],
        ], $overrides);
    }

    public function test_an_invoice_billed_with_tax_carries_it(): void
    {
        $this->actingAs($this->admin)
            ->post('/admin/purchases', $this->payload(taxable: true))
            ->assertRedirect('/admin/purchases');

        $purchase = Purchase::latest('id')->firstOrFail();

        $this->assertTrue($purchase->is_taxable);
        $this->assertSame('350.00', $purchase->subtotal);
        $this->assertSame('52.50', $purchase->tax_amount);
        $this->assertSame('402.50', $purchase->total_amount);
    }

    public function test_an_invoice_billed_without_tax_carries_none_though_its_item_has_a_rate(): void
    {
        $this->actingAs($this->admin)
            ->post('/admin/purchases', $this->payload(taxable: false))
            ->assertRedirect('/admin/purchases');

        $purchase = Purchase::latest('id')->firstOrFail();

        $this->assertFalse($purchase->is_taxable);
        $this->assertSame('350.00', $purchase->subtotal);
        $this->assertSame('0.00', $purchase->tax_amount);
        $this->assertSame('350.00', $purchase->total_amount);
    }

    public function test_the_choice_is_answered_again_on_every_edit(): void
    {
        $this->actingAs($this->admin)->post('/admin/purchases', $this->payload(taxable: true));

        $purchase = Purchase::latest('id')->firstOrFail();

        $this->actingAs($this->admin)
            ->put("/admin/purchases/{$purchase->id}", $this->payload(taxable: false))
            ->assertSessionMissing('warning')
            ->assertRedirect('/admin/purchases');

        $purchase->refresh();

        $this->assertFalse($purchase->is_taxable);
        $this->assertSame('0.00', $purchase->tax_amount);
        $this->assertSame('350.00', $purchase->total_amount);
    }

    public function test_the_untaxed_sheet_shows_no_tax_on_its_lines(): void
    {
        $this->actingAs($this->admin)->post('/admin/purchases', $this->payload(taxable: false));

        $purchase = Purchase::latest('id')->firstOrFail();

        $this->actingAs($this->admin)
            ->getJson("/admin/purchases/{$purchase->id}")
            ->assertOk()
            ->assertJsonPath('purchase.is_taxable', false)
            ->assertJsonPath('purchase.tax_amount', 0)
            ->assertJsonPath('items.0.tax_amount', 0);
    }

    public function test_the_invoice_must_say_whether_it_is_taxed(): void
    {
        $payload = $this->payload(taxable: true);
        unset($payload['is_taxable']);

        $this->actingAs($this->admin)
            ->post('/admin/purchases', $payload)
            ->assertSessionHasErrors('is_taxable');
    }
}
