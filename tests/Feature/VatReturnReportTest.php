<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Purchase;
use App\Models\Role;
use App\Models\Sale;
use App\Models\Supplier;
use App\Models\User;
use Database\Seeders\AccountsSeeder;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * تقرير الإقرار الضريبي: مبيعات ومشتريات خاضعة من المستندات مباشرة.
 */
class VatReturnReportTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([RolesSeeder::class, AccountsSeeder::class]);
        $this->registerForVat(15);

        $this->owner = User::factory()->create([
            'role_id' => Role::where('slug', 'super-admin')->firstOrFail()->id,
            'is_active' => true,
            'has_all_units' => true,
        ]);
    }

    private function purchase(array $attributes): Purchase
    {
        return Purchase::create([
            'supplier_id' => Supplier::create(['name' => 'مورّد تجريبي'])->id,
            'user_id' => $this->owner->id,
            'department_id' => Department::create(['name' => 'قسم تجريبي'])->id,
            ...$attributes,
        ]);
    }

    public function test_output_and_input_vat_are_summed_from_sales_and_purchases(): void
    {
        Sale::create([
            'number' => 'S-VAT-1', 'type' => 'sale', 'is_taxable' => true,
            'subtotal' => 1000, 'tax_amount' => 150, 'total_amount' => 1150, 'paid_amount' => 0,
        ]);

        $this->purchase([
            'number' => 'P-VAT-1', 'is_taxable' => true,
            'subtotal' => 400, 'tax_amount' => 60, 'total_amount' => 460, 'paid_amount' => 0,
        ]);

        $this->actingAs($this->owner)
            ->get('/admin/accounting/reports')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('vatReturn.output.tax', 150)
                ->where('vatReturn.input.tax', 60)
                ->where('vatReturn.net_due', 90),
            );
    }

    public function test_a_sale_return_reduces_output_vat(): void
    {
        $sale = Sale::create([
            'number' => 'S-VAT-2', 'type' => 'sale', 'is_taxable' => true,
            'subtotal' => 1000, 'tax_amount' => 150, 'total_amount' => 1150, 'paid_amount' => 1150,
        ]);

        Sale::create([
            'number' => 'S-VAT-2-R', 'type' => 'return', 'is_taxable' => true, 'original_sale_id' => $sale->id,
            'subtotal' => 200, 'tax_amount' => 30, 'total_amount' => 230, 'paid_amount' => 0,
        ]);

        $this->actingAs($this->owner)
            ->get('/admin/accounting/reports')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('vatReturn.output.tax', 120));
    }

    public function test_non_taxable_documents_are_excluded(): void
    {
        Sale::create([
            'number' => 'S-VAT-3', 'type' => 'sale', 'is_taxable' => false,
            'subtotal' => 500, 'tax_amount' => 0, 'total_amount' => 500, 'paid_amount' => 0,
        ]);

        $this->actingAs($this->owner)
            ->get('/admin/accounting/reports')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('vatReturn.output.tax', 0)
                ->where('vatReturn.net_due', 0),
            );
    }

    public function test_vat_export_streams_a_csv(): void
    {
        $this->actingAs($this->owner)
            ->get('/admin/accounting/reports/vat/export')
            ->assertOk()
            ->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
    }
}
