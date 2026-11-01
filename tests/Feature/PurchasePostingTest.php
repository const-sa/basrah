<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\CostCenter;
use App\Models\Department;
use App\Models\Item;
use App\Models\JournalEntry;
use App\Models\Purchase;
use App\Models\Role;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Accounting\Ledger;
use Database\Seeders\AccountsSeeder;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\DepartmentsSeeder;
use Database\Seeders\PaymentMethodsSeeder;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A purchase invoice reaches the books.
 *
 * Purchases only ever moved stock. Inventory was credited by the cost of goods
 * sold on every invoice and never debited by what was bought, so the account
 * drifted further below zero with each sale; supplier payables did not exist
 * at all, and money handed over never left the treasury in the books.
 */
class PurchasePostingTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Supplier $supplier;

    private Item $item;

    private Department $department;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([
            RolesSeeder::class, DepartmentsSeeder::class, CatalogSeeder::class,
            AccountsSeeder::class, PaymentMethodsSeeder::class,
        ]);

        $this->admin = User::factory()->create([
            'role_id' => Role::where('slug', 'super-admin')->value('id'),
            'is_active' => true,
            'has_all_units' => true,
        ]);

        $this->supplier = Supplier::create(['name' => 'مؤسسة التوريد', 'is_active' => true]);
        $this->department = Department::firstWhere('code', 'POOLS') ?? Department::firstOrFail();
        $this->item = Item::where('type', 'stock')->firstOrFail();
    }

    public function test_a_cash_purchase_debits_the_stock_and_credits_the_till(): void
    {
        $this->buy(quantity: 10, unitCost: 35, paid: 350);

        $this->assertSame(350.0, $this->balanceOf(Ledger::INVENTORY));
        $this->assertSame(-350.0, $this->balanceOf(Ledger::CASH));
        $this->assertSame(0.0, $this->balanceOf(Ledger::PAYABLES));
    }

    public function test_an_unpaid_purchase_credits_the_supplier(): void
    {
        $this->buy(quantity: 10, unitCost: 35, paid: 0);

        $this->assertSame(350.0, $this->balanceOf(Ledger::INVENTORY));
        $this->assertSame(350.0, $this->balanceOf(Ledger::PAYABLES));
    }

    public function test_a_part_paid_purchase_splits_between_the_till_and_the_supplier(): void
    {
        $this->buy(quantity: 10, unitCost: 35, paid: 200);

        $this->assertSame(350.0, $this->balanceOf(Ledger::INVENTORY));
        $this->assertSame(150.0, $this->balanceOf(Ledger::PAYABLES));
        $this->assertSame(-200.0, $this->balanceOf(Ledger::CASH));
    }

    public function test_a_discount_is_earned_rather_than_taken_off_the_stock(): void
    {
        // Stock is valued in at its unit cost, so netting the discount into
        // inventory here would leave the account below the stock report.
        $this->buy(quantity: 10, unitCost: 35, paid: 0, discount: 50);

        $this->assertSame(350.0, $this->balanceOf(Ledger::INVENTORY));
        $this->assertSame(50.0, $this->balanceOf(Ledger::PURCHASE_DISCOUNT));
        $this->assertSame(300.0, $this->balanceOf(Ledger::PAYABLES));
    }

    public function test_the_entry_is_balanced_and_sourced_as_a_purchase(): void
    {
        $this->buy(quantity: 10, unitCost: 35, paid: 350);

        $purchase = Purchase::latest('id')->firstOrFail();
        $entry = JournalEntry::findOrFail($purchase->journal_entry_id);

        $this->assertSame('purchase', $entry->source);
        $this->assertSame('posted', $entry->status);
        $this->assertSame((float) $entry->total_debit, (float) $entry->total_credit);
    }

    public function test_the_entry_lands_on_the_department_cost_centre(): void
    {
        $this->buy(quantity: 10, unitCost: 35, paid: 350);

        $centre = CostCenter::forDepartment($this->department)->id;
        $purchase = Purchase::latest('id')->firstOrFail();
        $entry = JournalEntry::findOrFail($purchase->journal_entry_id);

        $this->assertTrue($entry->lines->every(fn ($line) => (int) $line->cost_center_id === $centre));
    }

    public function test_editing_an_invoice_reverses_the_old_entry_and_posts_the_new(): void
    {
        $this->buy(quantity: 10, unitCost: 35, paid: 350);
        $purchase = Purchase::latest('id')->firstOrFail();
        $first = $purchase->journal_entry_id;

        $this->actingAs($this->admin)
            ->put("/admin/purchases/{$purchase->id}", $this->payload(20, 35, 350));

        $this->assertSame('reversed', JournalEntry::findOrFail($first)->status);
        $this->assertNotSame($first, $purchase->fresh()->journal_entry_id);

        // The correction is written, not erased — and only the corrected
        // invoice is left standing in the stock.
        $this->assertSame(700.0, $this->balanceOf(Ledger::INVENTORY));
    }

    public function test_deleting_an_invoice_takes_its_entry_back_out(): void
    {
        $this->buy(quantity: 10, unitCost: 35, paid: 350);
        $purchase = Purchase::latest('id')->firstOrFail();

        $this->actingAs($this->admin)->delete("/admin/purchases/{$purchase->id}");

        // Reversed, not erased: the entry and its counter-entry both stand.
        $this->assertSame(0.0, $this->balanceOf(Ledger::INVENTORY));
        $this->assertSame(2, JournalEntry::where('source', 'purchase')->count());
    }

    public function test_an_untaxed_purchase_writes_no_input_vat_line(): void
    {
        $this->buy(quantity: 10, unitCost: 35, paid: 350);

        $this->assertSame(0.0, $this->balanceOf(Ledger::INPUT_VAT));
    }

    private function buy(float $quantity, float $unitCost, float $paid, float $discount = 0): void
    {
        $this->actingAs($this->admin)
            ->post('/admin/purchases', $this->payload($quantity, $unitCost, $paid, $discount))
            ->assertSessionHasNoErrors();
    }

    /** @return array<string, mixed> */
    private function payload(float $quantity, float $unitCost, float $paid, float $discount = 0): array
    {
        return [
            'supplier_id' => $this->supplier->id,
            'department_id' => $this->department->id,
            'payment_method_id' => $this->paymentMethodId(),
            'is_taxable' => false,
            'paid_amount' => $paid,
            'discount_amount' => $discount,
            'notes' => null,
            'items' => [[
                'item_id' => $this->item->id,
                'quantity' => $quantity,
                'unit_cost' => $unitCost,
                'tax_amount' => 0,
            ]],
        ];
    }

    private function balanceOf(string $code): float
    {
        return (float) Account::where('code', $code)->firstOrFail()->balance();
    }
}
