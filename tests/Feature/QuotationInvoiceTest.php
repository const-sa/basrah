<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Department;
use App\Models\Item;
use App\Models\PaymentMethod;
use App\Models\Quotation;
use App\Models\QuotationItem;
use App\Models\Role;
use App\Models\Sale;
use App\Models\User;
use Database\Seeders\AccountsSeeder;
use Database\Seeders\BookingSetupSeeder;
use Database\Seeders\RolesSeeder;
use Database\Seeders\UnitsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class QuotationInvoiceTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Quotation $quotation;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        $this->seed([RolesSeeder::class, UnitsSeeder::class, BookingSetupSeeder::class, AccountsSeeder::class]);

        $this->owner = User::factory()->create([
            'role_id' => Role::where('slug', 'super-admin')->value('id'),
            'is_active' => true,
            'has_all_units' => true,
        ]);

        $this->quotation = $this->makeQuotation();
    }

    private function cashMethodId(): int
    {
        return PaymentMethod::where('code', 'cash')->value('id')
            ?? PaymentMethod::create(PaymentMethod::defaults()[0])->id;
    }

    private function convert(array $payload = []): TestResponse
    {
        return $this->actingAs($this->owner)->post(
            "/admin/quotations/{$this->quotation->id}/invoice",
            $payload + ['payment_method_id' => $this->cashMethodId()],
        );
    }

    /**
     * التحويل كان يقصد `sales.show`، وهي نقطة JSON تخدم النافذة المنبثقة ولا
     * صفحة خلفها — فتسقط Inertia على «All Inertia requests must receive a
     * valid Inertia response». الفاتورة تُصدَر ثم لا يرى المستخدم إلا الخطأ.
     */
    public function test_the_invoice_lands_on_a_renderable_page(): void
    {
        $response = $this->convert();

        $sale = Sale::latest('id')->first();

        $response->assertRedirect(route('sales.index', ['invoice' => $sale->id]));

        // ومتابعة التحويل تُجيب بصفحة Inertia لا بـ JSON خام.
        $this->actingAs($this->owner)
            ->get(route('sales.index', ['invoice' => $sale->id]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('admin/sales/Index')
                ->where('openSale.id', $sale->id)
                ->where('openSale.number', $sale->number));
    }

    /**
     * صفحة العرض تحمل زرّ الإصدار — هي الصفحة التي تُفتح عند موافقة العميل.
     */
    public function test_the_quotation_page_can_issue_the_invoice(): void
    {
        $this->actingAs($this->owner)
            ->get("/admin/quotations/{$this->quotation->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('admin/quotations/Show')
                ->where('quotation.invoice', null)
                ->has('methods'));

        $this->convert();

        $sale = Sale::latest('id')->firstOrFail();

        // وبعد الإصدار يصير الزرّ إحالةً إلى الفاتورة، فلا تُصدَر مرتين.
        $this->actingAs($this->owner)
            ->get("/admin/quotations/{$this->quotation->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('quotation.invoice.id', $sale->id)
                ->where('quotation.invoice.number', $sale->number));
    }

    public function test_an_invoice_is_issued_from_the_quotation(): void
    {
        $this->convert()->assertRedirect();

        $sale = Sale::latest('id')->first();

        $this->assertNotNull($sale);
        $this->assertSame($this->quotation->id, $sale->quotation_id);
        $this->assertSame($this->quotation->client_id, $sale->client_id);
        $this->assertSame($this->quotation->department_id, $sale->department_id);
        $this->assertSame('sale', $sale->type);
    }

    public function test_the_invoice_carries_the_same_lines_prices_and_totals(): void
    {
        $this->convert();

        $sale = Sale::latest('id')->first()->load('lines');
        $quotation = $this->quotation->fresh()->load('items');

        $this->assertCount($quotation->items->count(), $sale->lines);

        foreach ($quotation->items as $line) {
            $saleLine = $sale->lines->firstWhere('item_id', $line->item_id);

            $this->assertNotNull($saleLine);
            $this->assertEquals((float) $line->quantity, (float) $saleLine->quantity);
            $this->assertEquals((float) $line->unit_price, (float) $saleLine->unit_price);
            $this->assertEquals((float) $line->total_price, (float) $saleLine->total);
        }

        $this->assertEquals((float) $quotation->subtotal, (float) $sale->subtotal);
        $this->assertEquals((float) $quotation->discount_amount, (float) $sale->discount_amount);
        $this->assertEquals((float) $quotation->tax_amount, (float) $sale->tax_amount);
        $this->assertEquals((float) $quotation->total_amount, (float) $sale->total_amount);
    }

    public function test_issuing_the_invoice_accepts_the_quotation(): void
    {
        $this->assertSame('pending', $this->quotation->status);

        $this->convert();

        $this->assertSame('accepted', $this->quotation->fresh()->status);
    }

    public function test_a_quotation_is_invoiced_only_once(): void
    {
        $this->convert();

        $this->convert()->assertSessionHas('warning');

        $this->assertSame(1, Sale::where('quotation_id', $this->quotation->id)->count());
    }

    public function test_a_rejected_quotation_is_not_invoiced(): void
    {
        $this->quotation->update(['status' => 'rejected']);

        $this->convert()->assertSessionHas('warning');

        $this->assertSame(0, Sale::count());
    }

    public function test_the_partial_payment_is_recorded_and_leaves_a_balance(): void
    {
        $this->convert(['paid_amount' => 100]);

        $sale = Sale::latest('id')->first();

        $this->assertEquals(100.0, (float) $sale->paid_amount);
        $this->assertEqualsWithDelta((float) $this->quotation->total_amount - 100, $sale->remainingAmount(), 0.01);
        $this->assertSame('partial', $sale->paymentStatus());
    }

    public function test_the_quotation_links_back_to_its_invoice(): void
    {
        $this->convert();

        $quotation = $this->quotation->fresh();

        $this->assertTrue($quotation->isInvoiced());
        $this->assertSame(Sale::latest('id')->value('number'), $quotation->invoice->number);
    }

    private function makeQuotation(): Quotation
    {
        $department = Department::firstOrCreate(
            ['code' => 'POOLS'],
            ['name' => 'المسابح', 'sells' => true, 'is_active' => true, 'sort_order' => 1],
        );

        $client = Client::create(['name' => 'شركة أكسو للمقاولات', 'mobile' => '0551234567']);

        $quotation = Quotation::create([
            'number' => 'QT-000001',
            'client_id' => $client->id,
            'user_id' => $this->owner->id,
            'department_id' => $department->id,
            'status' => 'pending',
            'discount_amount' => 50,
            'valid_until' => '2026-10-01',
        ]);

        $subtotal = 0.0;
        $tax = 0.0;

        $lines = [
            ['فلتر رملي هايورد 18 بوصة', 1, 500, 15, 'stock', 40],
            ['صيانة شهرية', 2, 100, 15, 'service', 0],
        ];

        foreach ($lines as [$name, $qty, $price, $rate, $type, $stock]) {
            $item = Item::create([
                'department_id' => $department->id,
                'code' => 'IT-'.$name,
                'name' => $name,
                'type' => $type,
                'price' => $price,
                'cost' => $price / 2,
                'tax_rate' => $rate,
                'stock_qty' => $stock,
                'is_active' => true,
            ]);

            QuotationItem::create([
                'quotation_id' => $quotation->id,
                'item_id' => $item->id,
                'quantity' => $qty,
                'unit_price' => $price,
                'total_price' => $qty * $price,
            ]);

            $subtotal += $qty * $price;
            $tax += round($qty * $price * $rate / 100, 2);
        }

        $quotation->update([
            'subtotal' => $subtotal,
            'tax_amount' => $tax,
            'total_amount' => round($subtotal - 50 + $tax, 2),
        ]);

        return $quotation->fresh();
    }
}
