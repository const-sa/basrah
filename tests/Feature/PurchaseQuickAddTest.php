<?php

namespace Tests\Feature;

use App\Models\Item;
use App\Models\Role;
use App\Models\Supplier;
use App\Models\User;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\DepartmentsSeeder;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Quick add of a supplier or an item from inside the purchase invoice.
 *
 * The invoice form appends a line straight from these responses, so the payload
 * shape is part of the contract and not an implementation detail.
 */
class PurchaseQuickAddTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        // مبالغ هذا الاختبار شاملة للضريبة، فالمنشأة فيه مسجَّلة.
        $this->registerForVat();

        $this->seed([RolesSeeder::class, DepartmentsSeeder::class, CatalogSeeder::class]);

        $this->admin = User::factory()->create([
            'role_id' => Role::where('slug', 'super-admin')->firstOrFail()->id,
            'is_active' => true,
        ]);
    }

    public function test_a_supplier_is_created_from_name_and_mobile_alone(): void
    {
        $response = $this->actingAs($this->admin)
            ->postJson('/admin/suppliers/quick', ['name' => 'مؤسسة الخليج', 'mobile' => '0551234567']);

        $response->assertCreated()->assertJsonPath('supplier.name', 'مؤسسة الخليج');

        $supplier = Supplier::findOrFail($response->json('supplier.id'));

        $this->assertSame('0551234567', $supplier->mobile);
        $this->assertTrue($supplier->is_active);
    }

    public function test_a_supplier_needs_a_name(): void
    {
        $this->actingAs($this->admin)
            ->postJson('/admin/suppliers/quick', ['name' => ''])
            ->assertStatus(422)
            ->assertJsonValidationErrors('name');
    }

    public function test_an_item_is_created_with_the_shape_the_invoice_line_expects(): void
    {
        $response = $this->actingAs($this->admin)->postJson('/admin/items/quick', [
            'name' => 'كلور حبيبي',
            'code' => null,
            'type' => 'stock',
            'unit' => 'kg',
            'cost' => 32.5,
            'price' => 45,
            'tax_rate' => 15,
            'department_id' => null,
        ]);

        $response->assertCreated()
            ->assertJsonStructure(['item' => ['id', 'code', 'name', 'category', 'price', 'cost', 'tax_rate']])
            ->assertJsonPath('item.cost', 32.5)
            ->assertJsonPath('item.tax_rate', 15);

        $item = Item::findOrFail($response->json('item.id'));

        // The invoice is what puts stock on the shelf — the item starts empty.
        $this->assertSame(0.0, (float) $item->stock_qty);
        $this->assertTrue($item->is_active);
    }

    public function test_an_omitted_code_is_generated_and_stays_unique(): void
    {
        $codes = collect(range(1, 3))->map(function (int $n) {
            $response = $this->actingAs($this->admin)->postJson('/admin/items/quick', [
                'name' => "صنف {$n}",
                'type' => 'stock',
                'unit' => 'piece',
                'cost' => 0,
                'price' => 0,
                'tax_rate' => 0,
            ]);

            $response->assertCreated();

            return $response->json('item.code');
        });

        $this->assertCount(3, $codes->unique(), 'generated item codes collided');
        $this->assertNotContains(null, $codes->all());
    }

    public function test_a_duplicate_code_is_rejected(): void
    {
        $taken = Item::firstOrFail();

        $this->actingAs($this->admin)->postJson('/admin/items/quick', [
            'name' => 'مكرر',
            'code' => $taken->code,
            'type' => 'stock',
            'unit' => 'piece',
            'cost' => 0,
            'price' => 0,
            'tax_rate' => 0,
        ])->assertStatus(422)->assertJsonValidationErrors('code');
    }

    public function test_quick_add_is_closed_to_a_role_without_the_create_permission(): void
    {
        $viewer = User::factory()->create([
            'role_id' => Role::where('slug', 'cashier')->firstOrFail()->id,
            'is_active' => true,
        ]);

        $this->actingAs($viewer)
            ->postJson('/admin/suppliers/quick', ['name' => 'مورد'])
            ->assertForbidden();
    }
}
