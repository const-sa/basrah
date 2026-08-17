<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Department;
use App\Models\Item;
use App\Models\Role;
use App\Models\Sale;
use App\Models\Setting;
use App\Models\Treasury;
use App\Models\User;
use App\Models\Voucher;
use App\Services\Accounting\Ledger;
use App\Services\Accounting\VoucherService;
use App\Services\SalesService;
use App\Services\ZatcaQr;
use Database\Seeders\AccountsSeeder;
use Database\Seeders\BookingSetupSeeder;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\DepartmentsSeeder;
use Database\Seeders\RolesSeeder;
use Database\Seeders\UnitsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * سجل فواتير المسابح: استعراضها وحدها، ومرتجعاتها، وسندات قبضها.
 */
class SalesRegisterTest extends TestCase
{
    use RefreshDatabase;

    private SalesService $sales;

    private User $cashier;

    private Department $pools;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([RolesSeeder::class, DepartmentsSeeder::class, UnitsSeeder::class, BookingSetupSeeder::class, AccountsSeeder::class, CatalogSeeder::class]);

        $this->sales = app(SalesService::class);
        $this->pools = Department::where('code', 'POOLS')->firstOrFail();

        $this->cashier = User::factory()->create([
            'role_id' => Role::where('slug', 'cashier')->firstOrFail()->id,
            'is_active' => true,
        ]);
    }

    /**
     * فاتورة على الحساب — هي وحدها ما يقبل السداد الجزئي.
     */
    private function accountSale(float $quantity = 10, ?int $departmentId = null): Sale
    {
        return $this->sales->checkout([
            'lines' => [['item_id' => Item::where('code', 'SPR-001')->firstOrFail()->id, 'quantity' => $quantity]],
            'department_id' => $departmentId ?? $this->pools->id,
            'payment_method_id' => $this->paymentMethodId('account'),
        ], $this->cashier->id);
    }

    private function settle(Sale $sale, float $amount): Voucher
    {
        $vouchers = app(VoucherService::class);

        $voucher = $vouchers->create([
            'type' => 'receipt',
            'voucher_date' => now()->toDateString(),
            'amount' => $amount,
            'treasury_id' => Treasury::firstOrFail()->id,
            'account_id' => Account::where('code', Ledger::RECEIVABLES)->value('id'),
            'sale_id' => $sale->id,
            'payment_method_id' => $this->paymentMethodId('cash'),
        ], $this->cashier->id);

        return $vouchers->post($voucher, $this->cashier->id);
    }

    public function test_register_lists_only_the_selected_departments_invoices(): void
    {
        $venues = Department::where('code', 'VENUES')->firstOrFail();

        $poolsSale = $this->accountSale();
        $otherSale = $this->accountSale(2, $venues->id);

        $response = $this->actingAs($this->cashier)->get('/admin/sales');

        $response->assertOk();

        $numbers = collect($response->viewData('page')['props']['sales']['data'])->pluck('number');

        $this->assertTrue($numbers->contains($poolsSale->number));
        $this->assertFalse($numbers->contains($otherSale->number));
    }

    public function test_a_receipt_voucher_moves_the_invoice_to_partially_paid(): void
    {
        $sale = $this->accountSale(); // 747.50

        $this->assertSame('unpaid', $sale->paymentStatus());

        $this->actingAs($this->cashier)
            ->post("/admin/sales/{$sale->id}/settle", [
                'amount' => 300,
                'treasury_id' => Treasury::firstOrFail()->id,
                'payment_method_id' => $this->paymentMethodId('cash'),
                'voucher_date' => now()->toDateString(),
            ])
            ->assertRedirect();

        $sale = $sale->fresh();

        $this->assertSame(300.0, (float) $sale->paid_amount);
        $this->assertSame(447.5, $sale->remainingAmount());
        $this->assertSame('partial', $sale->paymentStatus());
        $this->assertDatabaseHas('vouchers', ['sale_id' => $sale->id, 'amount' => 300.0, 'status' => 'posted']);
    }

    public function test_settling_the_remainder_marks_the_invoice_paid(): void
    {
        $sale = $this->accountSale();

        $this->settle($sale, 300);
        $this->settle($sale->fresh(), 447.5);

        $sale = $sale->fresh();

        $this->assertSame(0.0, $sale->remainingAmount());
        $this->assertSame('paid', $sale->paymentStatus());
    }

    public function test_settlement_cannot_exceed_the_remaining_amount(): void
    {
        $sale = $this->accountSale();

        $this->actingAs($this->cashier)
            ->post("/admin/sales/{$sale->id}/settle", [
                'amount' => 1000,
                'treasury_id' => Treasury::firstOrFail()->id,
                'payment_method_id' => $this->paymentMethodId('cash'),
                'voucher_date' => now()->toDateString(),
            ])
            ->assertSessionHasErrors('amount');

        $this->assertSame(0.0, (float) $sale->fresh()->paid_amount);
    }

    public function test_cancelling_a_posted_voucher_restores_the_remaining_amount(): void
    {
        $sale = $this->accountSale();
        $voucher = $this->settle($sale, 300);

        app(VoucherService::class)->cancel($voucher, 'سند خاطئ', $this->cashier->id);

        $sale = $sale->fresh();

        $this->assertSame(0.0, (float) $sale->paid_amount);
        $this->assertSame(747.5, $sale->remainingAmount());
        $this->assertSame('unpaid', $sale->paymentStatus());
    }

    public function test_a_return_reduces_what_is_still_due_on_the_invoice(): void
    {
        $sale = $this->accountSale(); // 10 × 65 + ضريبة = 747.50
        $this->settle($sale, 300);

        $this->actingAs($this->cashier)
            ->post("/admin/sales/{$sale->id}/refund", [
                'quantities' => [Item::where('code', 'SPR-001')->firstOrFail()->id => 4],
                'reason' => 'صنف غير مطابق',
            ])
            ->assertRedirect();

        $sale = $sale->fresh();

        // 4 × 65 = 260 + ضريبة 39 = 299 مرتجعًا
        $this->assertSame(299.0, $sale->returnedAmount());
        $this->assertSame(448.5, $sale->netTotal());
        $this->assertSame(148.5, $sale->remainingAmount());
        $this->assertSame('partial', $sale->paymentStatus());
    }

    public function test_payment_status_filter_isolates_partially_paid_invoices(): void
    {
        $partial = $this->accountSale();
        $this->settle($partial, 100);

        $unpaid = $this->accountSale(2);

        $numbers = collect(
            $this->actingAs($this->cashier)
                ->get('/admin/sales?payment_status=partial')
                ->viewData('page')['props']['sales']['data']
        )->pluck('number');

        $this->assertTrue($numbers->contains($partial->number));
        $this->assertFalse($numbers->contains($unpaid->number));
    }

    public function test_invoice_details_expose_its_returns_and_vouchers(): void
    {
        $sale = $this->accountSale();
        $this->settle($sale, 200);
        $this->sales->refund($sale, [Item::where('code', 'SPR-001')->firstOrFail()->id => 2], $this->cashier->id);

        $payload = $this->actingAs($this->cashier)
            ->getJson("/admin/sales/{$sale->id}")
            ->assertOk()
            ->json();

        $this->assertCount(1, $payload['vouchers']);
        $this->assertCount(1, $payload['returns']);
        $this->assertEqualsWithDelta(200.0, $payload['vouchers'][0]['amount'], 0.001);
        $this->assertEqualsWithDelta(8.0, $payload['lines'][0]['returnable_quantity'], 0.001);
    }

    public function test_register_reports_tax_per_invoice_and_net_of_returns(): void
    {
        $sale = $this->accountSale(); // 650 + ضريبة 97.50

        $this->sales->refund($sale, [Item::where('code', 'SPR-001')->firstOrFail()->id => 4], $this->cashier->id);

        $props = $this->actingAs($this->cashier)
            ->get('/admin/sales')
            ->viewData('page')['props'];

        $row = collect($props['sales']['data'])->firstWhere('number', $sale->number);

        $this->assertSame(97.5, $row['tax_amount']);
        // أعمدة القائمة: السعر + الضريبة = الإجمالي
        $this->assertSame(650.0, $row['amount_before_tax']);
        $this->assertSame(round($row['amount_before_tax'] + $row['tax_amount'], 2), $row['total']);
        // ضريبة المرتجع 4 × 65 × 15% = 39 ⇒ الصافي 58.50
        $this->assertSame(58.5, $props['stats']['tax_total']);
    }

    public function test_printable_invoice_carries_the_business_identity(): void
    {
        $settings = Setting::current();
        $settings->update([
            'business_name' => 'مؤسسة ديوان البصرة',
            'logo_path' => 'storage/branding/logo.png',
            'address' => 'البصرة',
            'tax_enabled' => false,
        ]);

        $sale = $this->accountSale();

        $issuer = $this->actingAs($this->cashier)
            ->getJson("/admin/sales/{$sale->id}")
            ->assertOk()
            ->json('issuer');

        $this->assertSame('مؤسسة ديوان البصرة', $issuer['business_name']);
        $this->assertSame('المسابح', $issuer['activity']);
        $this->assertStringContainsString('branding/logo.png', $issuer['logo_url']);
        // بلا تسجيل ضريبي لا رمز — رمزٌ برقم فارغ لا يقرؤه تطبيق الهيئة.
        $this->assertNull($issuer['qr']);
    }

    public function test_qr_encodes_the_zatca_fields_when_a_tax_number_exists(): void
    {
        Setting::current()->update([
            'business_name' => 'مؤسسة ديوان البصرة',
            'tax_enabled' => true,
            'tax_number' => '300000000000003',
        ]);

        $sale = $this->accountSale(); // 747.50 منها 97.50 ضريبة

        $issuer = $this->actingAs($this->cashier)
            ->getJson("/admin/sales/{$sale->id}")
            ->json('issuer');

        $this->assertStringStartsWith('data:image/svg+xml;base64,', $issuer['qr']);

        // الرمز نفسه TLV: يُبنى بالخدمة ذاتها فيُتحقق من حقوله مباشرة.
        $tlv = base64_decode(app(ZatcaQr::class)->payload(
            'مؤسسة ديوان البصرة',
            '300000000000003',
            $sale->created_at->toIso8601String(),
            747.5,
            97.5,
        ));

        $this->assertSame(1, ord($tlv[0]));
        $this->assertStringContainsString('300000000000003', $tlv);
        $this->assertStringContainsString('747.50', $tlv);
        $this->assertStringContainsString('97.50', $tlv);
    }
}
