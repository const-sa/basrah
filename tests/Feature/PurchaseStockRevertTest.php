<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Item;
use App\Models\Purchase;
use App\Models\Role;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\User;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\DepartmentsSeeder;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Editing or deleting a purchase invoice takes back what it brought in.
 *
 * The correction is written as its own movement rather than by erasing the
 * first one: the ledger is the record of what was believed at the time, and an
 * invoice corrected an hour after receiving is not an invoice that never was.
 */
class PurchaseStockRevertTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Supplier $supplier;

    private Item $item;

    private Department $department;

    private float $opening;

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
        $this->opening = (float) $this->item->stock_qty;
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(float $quantity): array
    {
        return [
            'supplier_id' => $this->supplier->id,
            'department_id' => $this->department->id,
            'payment_method_id' => null,
            'is_taxable' => false,
            'paid_amount' => 0,
            'discount_amount' => 0,
            'notes' => null,
            'items' => [[
                'item_id' => $this->item->id,
                'quantity' => $quantity,
                'unit_cost' => 35,
                'tax_amount' => 0,
            ]],
        ];
    }

    private function stock(): float
    {
        return (float) $this->item->fresh()->stock_qty;
    }

    public function test_a_purchase_adds_its_quantity_to_stock(): void
    {
        $this->actingAs($this->admin)->post('/admin/purchases', $this->payload(10));

        $this->assertSame($this->opening + 10, $this->stock());
    }

    public function test_editing_the_quantity_leaves_only_the_new_one_in_stock(): void
    {
        $this->actingAs($this->admin)->post('/admin/purchases', $this->payload(10));

        $purchase = Purchase::latest('id')->firstOrFail();

        $this->actingAs($this->admin)
            ->put("/admin/purchases/{$purchase->id}", $this->payload(4))
            ->assertSessionMissing('warning')
            ->assertRedirect('/admin/purchases');

        $this->assertSame($this->opening + 4, $this->stock());
        $this->assertSame(1, StockMovement::where('type', 'purchase_revert')->count());
    }

    public function test_deleting_the_invoice_returns_stock_to_where_it_started(): void
    {
        $this->actingAs($this->admin)->post('/admin/purchases', $this->payload(10));

        $purchase = Purchase::latest('id')->firstOrFail();

        $this->actingAs($this->admin)
            ->delete("/admin/purchases/{$purchase->id}")
            ->assertSessionMissing('warning')
            ->assertRedirect('/admin/purchases');

        $this->assertSame($this->opening, $this->stock());
        $this->assertSoftDeleted('purchases', ['id' => $purchase->id]);
    }

    public function test_the_reversal_is_named_in_the_movements_ledger(): void
    {
        $this->actingAs($this->admin)->post('/admin/purchases', $this->payload(10));

        $purchase = Purchase::latest('id')->firstOrFail();

        $this->actingAs($this->admin)->delete("/admin/purchases/{$purchase->id}");

        $this->assertArrayHasKey('purchase_revert', StockMovement::TYPES);

        $reversal = StockMovement::where('type', 'purchase_revert')->firstOrFail();

        $this->assertSame(-10.0, (float) $reversal->quantity);
        $this->assertSame($this->opening, (float) $reversal->balance_after);
    }
}
