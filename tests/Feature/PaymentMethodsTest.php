<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Booking;
use App\Models\Client;
use App\Models\Item;
use App\Models\PaymentMethod;
use App\Models\Role;
use App\Models\Unit;
use App\Models\User;
use App\Services\Accounting\Ledger;
use App\Services\BookingService;
use App\Services\SalesService;
use Database\Seeders\AccountsSeeder;
use Database\Seeders\BookingSetupSeeder;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\DepartmentsSeeder;
use Database\Seeders\RolesSeeder;
use Database\Seeders\UnitsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * طرق الدفع كجدول: إدارتها من الإعدادات، وأثرها المحاسبي، وحرمة المستعملة.
 */
class PaymentMethodsTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([
            RolesSeeder::class, DepartmentsSeeder::class, UnitsSeeder::class,
            BookingSetupSeeder::class, AccountsSeeder::class, CatalogSeeder::class,
        ]);

        $this->owner = User::factory()->create([
            'role_id' => Role::where('slug', 'super-admin')->value('id'),
            'is_active' => true,
            'has_all_units' => true,
        ]);
    }

    /**
     * الهجرة نفسها تُنشئ الطرق، فلا يبقى نظام بلا طريقة دفع بعد الترحيل.
     */
    public function test_the_migration_seeds_the_default_methods(): void
    {
        $this->assertSame(5, PaymentMethod::count());

        foreach (['cash', 'transfer', 'card', 'online', 'account'] as $code) {
            $this->assertTrue(PaymentMethod::where('code', $code)->exists(), "الطريقة {$code} مفقودة");
        }

        // النقد والآجل أساسيان: النظام يستدعيهما بكودهما ويبني عليهما سلوكًا.
        $this->assertTrue(PaymentMethod::where('code', 'cash')->value('is_system'));
        $this->assertTrue(PaymentMethod::where('code', 'account')->value('is_system'));
    }

    public function test_the_settings_screen_lists_the_methods_with_their_usage(): void
    {
        $this->actingAs($this->owner)
            ->get('/admin/settings/payment-methods')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('admin/settings/PaymentMethods')
                ->has('methods', 5)
                ->has('destinations', 2)
                ->where('stats.active', 5));
    }

    /**
     * الطرق مشتركة: الطريقة الواحدة تظهر في كل شاشات النظام بلا نطاق يحصرها.
     */
    public function test_a_new_method_is_added_and_appears_in_every_screen(): void
    {
        $this->actingAs($this->owner)->post('/admin/settings/payment-methods', [
            'code' => 'wallet',
            'name' => 'محفظة إلكترونية',
            'deposits_to' => 'bank',
            'is_active' => true,
            'sort_order' => 9,
        ])->assertSessionHasNoErrors();

        $wallet = PaymentMethod::where('code', 'wallet')->firstOrFail();
        $this->assertSame('bank', $wallet->deposits_to);

        $this->assertCount(6, PaymentMethod::options());

        $this->actingAs($this->owner)
            ->get('/admin/bookings/halls/create')
            ->assertInertia(fn ($page) => $page->has('meta.payment_methods', 6));

        $this->actingAs($this->owner)
            ->get('/admin/pos')
            ->assertInertia(fn ($page) => $page->has('methods', 6));

        $this->actingAs($this->owner)
            ->get('/admin/accounting/vouchers')
            ->assertInertia(fn ($page) => $page->has('methods', 6));
    }

    /**
     * أخطر ما كان في الثوابت: أي مفتاح غير معروف يهبط على الصندوق النقدي
     * صامتًا. الآن الطريقة تحمل حسابها، فالبنكية الجديدة تُقيَّد في البنك.
     */
    public function test_a_bank_method_posts_the_payment_to_the_bank_account(): void
    {
        $wallet = PaymentMethod::create([
            'code' => 'wallet', 'name' => 'محفظة إلكترونية', 'deposits_to' => 'bank',
            'is_active' => true, 'sort_order' => 9,
        ]);

        $booking = $this->booking();

        app(BookingService::class)->recordPayment($booking, [
            'type' => 'deposit', 'payment_method_id' => $wallet->id, 'amount' => 300,
        ], $this->owner->id);

        $this->assertSame(300.0, $this->balance(Ledger::BANK));
        $this->assertSame(0.0, $this->balance(Ledger::CASH));
    }

    public function test_a_disabled_method_is_rejected_and_hidden(): void
    {
        $card = PaymentMethod::where('code', 'card')->firstOrFail();
        $card->update(['is_active' => false]);

        $this->assertCount(4, PaymentMethod::options());

        $booking = $this->booking();

        $this->actingAs($this->owner)
            ->post("/admin/bookings/{$booking->id}/payments", [
                'type' => 'deposit',
                'payment_method_id' => $card->id,
                'amount' => 100,
                'paid_on' => '2026-11-01',
            ])->assertSessionHasErrors('payment_method_id');
    }

    /**
     * المستند القديم يبقى شاهدًا على طريقة قبضه، فالمستعملة تُعطَّل ولا تُحذف.
     */
    public function test_a_method_in_use_cannot_be_deleted(): void
    {
        $booking = $this->booking();
        $cash = PaymentMethod::where('code', 'cash')->firstOrFail();

        app(BookingService::class)->recordPayment($booking, [
            'type' => 'deposit', 'payment_method_id' => $cash->id, 'amount' => 100,
        ], $this->owner->id);

        $transfer = PaymentMethod::where('code', 'transfer')->firstOrFail();

        $this->actingAs($this->owner)
            ->delete("/admin/settings/payment-methods/{$transfer->id}")
            ->assertSessionHas('success');

        // الحذف أرشفة: الطريقة ترتفع من الاستعمال ويبقى صفّها للاسترجاع.
        $this->assertNull(PaymentMethod::find($transfer->id));
        $this->assertSoftDeleted('payment_methods', ['id' => $transfer->id]);

        // النقد أساسي ومستعمل معًا — يُرفض حذفه لكلا السببين.
        $this->actingAs($this->owner)
            ->delete("/admin/settings/payment-methods/{$cash->id}")
            ->assertSessionHas('error');

        $this->assertDatabaseHas('payment_methods', ['id' => $cash->id]);
    }

    public function test_a_system_method_cannot_be_disabled(): void
    {
        $cash = PaymentMethod::where('code', 'cash')->firstOrFail();

        $this->actingAs($this->owner)
            ->patch("/admin/settings/payment-methods/{$cash->id}/toggle")
            ->assertSessionHas('error');

        $this->assertTrue($cash->fresh()->is_active);
    }

    /**
     * الأساسية يُعدَّل اسمها وحساب إيداعها، ويبقى كودها وصفة الآجل ثابتَين
     * لأن النظام يستدعيها بالكود ويبني على الآجل سلوكًا.
     */
    public function test_editing_a_system_method_keeps_its_code_and_credit_flag(): void
    {
        $account = PaymentMethod::where('code', 'account')->firstOrFail();

        $this->actingAs($this->owner)->put("/admin/settings/payment-methods/{$account->id}", [
            'code' => 'deferred',
            'name' => 'بيع آجل',
            'deposits_to' => 'bank',
            'is_credit' => false,
            'is_active' => true,
            'sort_order' => 5,
        ])->assertSessionHasNoErrors();

        $account->refresh();

        $this->assertSame('بيع آجل', $account->name);
        $this->assertSame('bank', $account->deposits_to);
        $this->assertSame('account', $account->code);
        $this->assertTrue($account->is_credit);
    }

    public function test_the_code_must_be_a_latin_slug(): void
    {
        $this->actingAs($this->owner)->post('/admin/settings/payment-methods', [
            'code' => 'محفظة',
            'name' => 'محفظة',
            'deposits_to' => 'cash',
            'is_active' => true,
        ])->assertSessionHasErrors('code');
    }

    /**
     * صفة الآجل تحكم السلوك لا الكود: طريقة آجلة جديدة تبدأ بلا سداد
     * وتُقيَّد ذمّةً كما «على الحساب».
     */
    public function test_any_credit_method_leaves_the_invoice_unpaid(): void
    {
        $installments = PaymentMethod::create([
            'code' => 'installments', 'name' => 'تقسيط', 'deposits_to' => 'cash', 'is_credit' => true,
            'is_active' => true, 'sort_order' => 9,
        ]);

        $sale = app(SalesService::class)->checkout([
            'lines' => [['item_id' => Item::where('code', 'SPR-001')->firstOrFail()->id, 'quantity' => 2]],
            'payment_method_id' => $installments->id,
        ], $this->owner->id);

        $this->assertSame(0.0, (float) $sale->paid_amount);
        $this->assertTrue($sale->isCredit());
        $this->assertSame((float) $sale->total_amount, $this->balance(Ledger::RECEIVABLES));
    }

    private function booking(): Booking
    {
        return app(BookingService::class)->create([
            'unit_id' => Unit::where('code', 'HALL-01')->value('id'),
            'client_id' => Client::create(['name' => 'عميل', 'mobile' => '0551112233'])->id,
            'scope' => 'whole',
            'booking_date' => '2026-12-15',
            'period' => 'full_day',
            'status' => 'deposit_paid',
        ]);
    }

    private function balance(string $code): float
    {
        return Account::where('code', $code)->firstOrFail()->balance();
    }
}
