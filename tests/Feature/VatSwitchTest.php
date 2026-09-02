<?php

namespace Tests\Feature;

use App\Http\Resources\ItemOptionResource;
use App\Models\Item;
use App\Models\Purchase;
use App\Models\Quotation;
use App\Models\Role;
use App\Models\Sale;
use App\Models\Setting;
use App\Models\Supplier;
use App\Models\User;
use App\Services\SalesService;
use App\Support\Vat;
use Database\Seeders\AccountsSeeder;
use Database\Seeders\BookingSetupSeeder;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\DepartmentsSeeder;
use Database\Seeders\RolesSeeder;
use Database\Seeders\UnitsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * مفتاح الضريبة يسري على النظام كله.
 *
 * الأصناف تحمل نسبها في جدولها، وكانت المشتريات والمبيعات ونقطة البيع وعروض
 * الأسعار تقرؤها منه مباشرةً — فيُطفأ المفتاح في الإعدادات وتبقى الضريبة
 * تُحتسب وتُطبع. فهذه الاختبارات تمسك الطرفين: ما يخرج للشاشة، وما يُحفظ حين
 * تُرسل الشاشة ضريبةً بعد الإطفاء.
 */
class VatSwitchTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([
            RolesSeeder::class, DepartmentsSeeder::class, UnitsSeeder::class,
            BookingSetupSeeder::class, AccountsSeeder::class, CatalogSeeder::class,
        ]);

        $this->admin = User::factory()->create([
            'role_id' => Role::where('slug', 'super-admin')->value('id'),
            'is_active' => true,
            'has_all_units' => true,
        ]);

        $this->enableVat();
    }

    private function enableVat(bool $on = true): void
    {
        Setting::current()->update([
            'tax_enabled' => $on,
            'tax_number' => '300000000000003',
            'tax_rate' => 15,
        ]);

        Vat::forget();
    }

    private function taxedItem(): Item
    {
        $item = Item::where('is_active', true)->firstOrFail();
        $item->update(['tax_rate' => 15]);

        return $item->fresh();
    }

    public function test_the_switch_gates_the_rate_an_item_ships_with(): void
    {
        $item = $this->taxedItem();

        $this->assertSame(15.0, (new ItemOptionResource($item))->resolve()['tax_rate']);

        $this->enableVat(false);

        // النسبة محفوظة على الصنف كما هي، لكنها لا تخرج للشاشة.
        $this->assertSame(15.0, (float) $item->fresh()->tax_rate);
        $this->assertSame(0.0, (new ItemOptionResource($item->fresh()))->resolve()['tax_rate']);
    }

    public function test_a_registration_without_a_rate_does_not_count_as_taxable(): void
    {
        Setting::current()->update(['tax_enabled' => true, 'tax_number' => null, 'tax_rate' => 15]);
        Vat::forget();

        $this->assertFalse(Vat::applies());
        $this->assertSame(0.0, Vat::rateOf(15));
    }

    public function test_a_sale_carries_no_tax_once_the_switch_is_off(): void
    {
        $item = $this->taxedItem();

        $this->enableVat(false);

        $sale = app(SalesService::class)->checkout([
            'lines' => [['item_id' => $item->id, 'quantity' => 2, 'unit_price' => 100]],
            'payment_method_id' => $this->paymentMethodId('cash'),
        ], $this->admin->id);

        $this->assertSame(0.0, (float) $sale->tax_amount);
        $this->assertSame(200.0, (float) $sale->total_amount);
    }

    public function test_a_credit_note_mirrors_the_tax_the_original_sale_charged(): void
    {
        $item = $this->taxedItem();

        $sale = app(SalesService::class)->checkout([
            'lines' => [['item_id' => $item->id, 'quantity' => 2, 'unit_price' => 100]],
            'payment_method_id' => $this->paymentMethodId('cash'),
        ], $this->admin->id);

        $this->assertSame(30.0, (float) $sale->tax_amount);

        // إطفاء المفتاح بعد البيع لا يُسقط ضريبةً حُصّلت فعلًا عند ردّها.
        $this->enableVat(false);

        $return = app(SalesService::class)->refund($sale, [$item->id => 1], $this->admin->id, 'تالف');

        $this->assertSame(15.0, (float) $return->tax_amount);
    }

    public function test_a_purchase_posted_after_the_switch_is_off_stores_no_tax(): void
    {
        $item = $this->taxedItem();
        $supplier = Supplier::firstOrCreate(['name' => 'مورد الاختبار']);

        $this->enableVat(false);

        // الشاشة قد تكون فُتحت قبل الإطفاء فترسل ضريبةً محسوبة — تُرفض هنا.
        $this->actingAs($this->admin)->post('/admin/purchases', [
            'supplier_id' => $supplier->id,
            'department_id' => \App\Models\Department::first()->id,
            'payment_method_id' => $this->paymentMethodId('cash'),
            'is_taxable' => true,
            'paid_amount' => 0,
            'discount_amount' => 0,
            'notes' => null,
            'items' => [[
                'item_id' => $item->id, 'quantity' => 2, 'unit_cost' => 100, 'tax_amount' => 30,
            ]],
        ])->assertSessionHasNoErrors()->assertRedirect();

        $purchase = Purchase::latest('id')->firstOrFail();

        $this->assertSame(0.0, (float) $purchase->tax_amount);
        $this->assertSame(200.0, (float) $purchase->total_amount);
    }

    public function test_a_quotation_posted_after_the_switch_is_off_stores_no_tax(): void
    {
        $item = $this->taxedItem();

        $this->enableVat(false);

        $this->actingAs($this->admin)->post('/admin/quotations', [
            'client_id' => \App\Models\Client::firstOrCreate(['name' => 'عميل الاختبار'])->id,
            'department_id' => \App\Models\Department::first()->id,
            'discount_amount' => 0,
            'notes' => null,
            'valid_until' => null,
            'items' => [[
                'item_id' => $item->id, 'quantity' => 2, 'unit_price' => 100,
                'is_taxable' => true, 'tax_amount' => 30,
            ]],
        ])->assertSessionHasNoErrors()->assertRedirect();

        $quotation = Quotation::latest('id')->firstOrFail();

        $this->assertSame(0.0, (float) $quotation->tax_amount);
        $this->assertSame(200.0, (float) $quotation->total_amount);
    }

    public function test_the_switch_reaches_every_page_as_a_shared_prop(): void
    {
        $this->enableVat(false);

        $this->actingAs($this->admin)
            ->get('/admin/purchases')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('vat.applies', false)->where('vat.rate', 0));

        $this->enableVat();

        $this->actingAs($this->admin)
            ->get('/admin/purchases')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('vat.applies', true)->where('vat.rate', 15));
    }

    public function test_the_lines_of_a_printed_purchase_add_up_to_its_stored_tax(): void
    {
        $item = $this->taxedItem();
        $supplier = Supplier::firstOrCreate(['name' => 'مورد الاختبار']);

        $this->actingAs($this->admin)->post('/admin/purchases', [
            'supplier_id' => $supplier->id,
            'department_id' => \App\Models\Department::first()->id,
            'payment_method_id' => $this->paymentMethodId('cash'),
            'is_taxable' => true,
            'paid_amount' => 0,
            'discount_amount' => 0,
            'notes' => null,
            'items' => [[
                'item_id' => $item->id, 'quantity' => 2, 'unit_cost' => 100, 'tax_amount' => 30,
            ]],
        ])->assertSessionHasNoErrors()->assertRedirect();

        $purchase = Purchase::latest('id')->firstOrFail();

        // إطفاء المفتاح بعد التحرير لا يمحو ضريبةً على ورقةٍ صدرت بها.
        $this->enableVat(false);

        $response = $this->actingAs($this->admin)
            ->getJson("/admin/purchases/{$purchase->id}")
            ->assertOk();

        $this->assertEqualsWithDelta(30.0, collect($response->json('items'))->sum('tax_amount'), 0.01);
        $this->assertEqualsWithDelta(30.0, $response->json('purchase.tax_amount'), 0.01);
    }

    public function test_a_sale_issued_while_taxed_keeps_its_zatca_code_after_the_switch_is_off(): void
    {
        $item = $this->taxedItem();

        $sale = app(SalesService::class)->checkout([
            'lines' => [['item_id' => $item->id, 'quantity' => 1, 'unit_price' => 100]],
            'payment_method_id' => $this->paymentMethodId('cash'),
        ], $this->admin->id);

        $this->assertGreaterThan(0, (float) $sale->tax_amount);

        $this->enableVat(false);

        $this->actingAs($this->admin)
            ->getJson("/admin/sales/{$sale->id}")
            ->assertOk()
            ->assertJsonPath('issuer.tax_number', '300000000000003');
    }

    public function test_a_sale_made_after_the_switch_is_off_gets_no_zatca_code(): void
    {
        $item = $this->taxedItem();

        $this->enableVat(false);

        $sale = app(SalesService::class)->checkout([
            'lines' => [['item_id' => $item->id, 'quantity' => 1, 'unit_price' => 100]],
            'payment_method_id' => $this->paymentMethodId('cash'),
        ], $this->admin->id);

        $this->assertSame(0.0, (float) Sale::find($sale->id)->tax_amount);

        $this->actingAs($this->admin)
            ->getJson("/admin/sales/{$sale->id}")
            ->assertOk()
            ->assertJsonPath('issuer.tax_number', null)
            ->assertJsonPath('issuer.qr', null);
    }
}
