<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Booking;
use App\Models\Client;
use App\Models\JournalLine;
use App\Models\RevenueAccount;
use App\Models\Role;
use App\Models\Unit;
use App\Models\UnitPrice;
use App\Models\User;
use App\Services\Accounting\Ledger;
use App\Services\Accounting\RevenueAccounts;
use App\Services\BookingService;
use App\Services\ChaletBookingService;
use App\Support\StayPeriod;
use Database\Seeders\AccountsSeeder;
use Database\Seeders\BookingSetupSeeder;
use Database\Seeders\DepartmentsSeeder;
use Database\Seeders\FacilitiesSeeder;
use Database\Seeders\RolesSeeder;
use Database\Seeders\UnitsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * إعدادات حسابات الإيراد: كل مصدر دخل يُرحَّل على الحساب الذي يختاره المشغّل.
 *
 * الاختبار يقطع الطريق كاملة — يُحفظ الاختيار من الشاشة، ثم يُصنع حجز حقيقي
 * ويُسوّى، ثم يُقرأ سطر القيد. فالإعداد الذي يُحفظ ولا يُغيّر موضع الترحيل
 * إعدادٌ صوريّ.
 *
 * وللمصدر طرفان يُختاران هنا: حساب الإيراد الذي يُقيَّد دائنًا، وحساب الأصول
 * الذي يُودع فيه المقبوض مدينًا. فيُقرأ الطرفان من القيد نفسه.
 */
class RevenueAccountsSettingsTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Unit $chalet;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([
            RolesSeeder::class, FacilitiesSeeder::class, DepartmentsSeeder::class,
            UnitsSeeder::class, BookingSetupSeeder::class, AccountsSeeder::class,
        ]);

        $this->owner = User::factory()->create([
            'role_id' => Role::where('slug', 'super-admin')->firstOrFail()->id,
            'is_active' => true,
            'has_all_units' => true,
        ]);

        $this->chalet = Unit::where('type', 'chalet')->whereHas('sections')->firstOrFail();
    }

    /**
     * حساب إيرادي جديد بجانب الحساب الموحَّد — «إيرادات الشاليهات» وحدها.
     */
    private function chaletAccount(): Account
    {
        return Account::create([
            'code' => '4140',
            'name' => 'إيرادات الشاليهات',
            'parent_id' => Account::where('code', '4100')->value('id'),
            'type' => 'revenue',
            'is_group' => false,
            'is_active' => true,
        ]);
    }

    /**
     * حساب بنكي جديد بجانب «البنك» الموحَّد — بنك الشاليهات وحدها.
     */
    private function bankAccount(string $code = '1130', string $name = 'بنك الشاليهات'): Account
    {
        return Account::create([
            'code' => $code,
            'name' => $name,
            'parent_id' => Account::where('code', '1100')->value('id'),
            'type' => 'asset',
            'is_group' => false,
            'is_active' => true,
        ]);
    }

    private function balanceOf(string $code): float
    {
        return Account::where('code', $code)->firstOrFail()->balance();
    }

    private function stay(string $from = '2027-05-10', string $to = '2027-05-11'): Booking
    {
        $section = $this->chalet->sections()->firstOrFail();

        UnitPrice::updateOrCreate(
            ['unit_id' => $this->chalet->id, 'unit_section_id' => $section->id, 'period' => StayPeriod::PERIOD],
            ['weekday_price' => 500, 'weekend_price' => 500, 'day_prices' => null, 'is_active' => true],
        );

        return app(ChaletBookingService::class)->create([
            'unit_id' => $this->chalet->id,
            'client_id' => Client::first()?->id,
            'scope' => 'sections',
            'section_ids' => [$section->id],
            'booking_date' => $from,
            'check_out_date' => $to,
            'status' => 'deposit_paid',
        ]);
    }

    /**
     * @return array<string, float> كود الحساب ← صافي ما قُيّد عليه
     */
    private function revenueByAccount(): array
    {
        return JournalLine::query()
            ->join('accounts', 'accounts.id', '=', 'journal_lines.account_id')
            ->where('accounts.type', 'revenue')
            ->get(['accounts.code as code', 'journal_lines.debit', 'journal_lines.credit'])
            ->groupBy('code')
            ->map(fn ($lines) => round((float) $lines->sum('credit') - (float) $lines->sum('debit'), 2))
            ->all();
    }

    /**
     * الشاشة انتقلت من الإعدادات إلى المحاسبة، والرابط القديم يبقى يعمل:
     * مفضّلةٌ محفوظة لا تنتهي إلى صفحة مفقودة لأننا رتّبنا القائمة.
     */
    public function test_the_old_settings_url_still_leads_to_the_screen(): void
    {
        $this->actingAs($this->owner)
            ->get('/admin/settings/revenue-accounts')
            ->assertRedirect('/admin/accounting/revenue-accounts');
    }

    public function test_screen_lists_every_stream_with_the_account_it_posts_to(): void
    {
        $this->actingAs($this->owner)
            ->get('/admin/accounting/revenue-accounts')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('admin/accounting/RevenueAccounts')
                ->has('streams', count(RevenueAccounts::STREAMS))
                ->where('streams.0.key', 'hall_bookings')
                ->where('streams.0.default_code', Ledger::BOOKING_REVENUE)
                ->has('accounts'));
    }

    /**
     * ما يُختار هنا هو ما يُرحَّل عليه الحجز بعد ذلك — لا حساب مثبَّت في الكود.
     */
    public function test_a_chalet_stay_credits_the_account_chosen_for_chalets(): void
    {
        $account = $this->chaletAccount();

        $this->actingAs($this->owner)
            ->post('/admin/accounting/revenue-accounts', [
                'streams' => [
                    ['key' => 'hall_bookings', 'account_id' => null],
                    ['key' => 'chalet_bookings', 'account_id' => $account->id],
                ],
            ])
            ->assertSessionHasNoErrors();

        app(BookingService::class)->settleInFull($this->stay());

        $byAccount = $this->revenueByAccount();

        $this->assertArrayHasKey('4140', $byAccount);
        $this->assertGreaterThan(0, $byAccount['4140']);
        $this->assertArrayNotHasKey(Ledger::BOOKING_REVENUE, $byAccount, 'إيراد الشاليه لم يعد على الحساب الموحَّد');
    }

    /**
     * ما رُحِّل قبل التغيير يبقى مكانه — القيد المرحَّل لا يُعاد كتابته.
     */
    public function test_changing_the_account_leaves_posted_entries_alone(): void
    {
        app(BookingService::class)->settleInFull($this->stay('2027-06-10', '2027-06-11'));

        $before = $this->revenueByAccount();
        $this->assertArrayHasKey(Ledger::BOOKING_REVENUE, $before);

        $account = $this->chaletAccount();

        $this->actingAs($this->owner)
            ->post('/admin/accounting/revenue-accounts', [
                'streams' => [['key' => 'chalet_bookings', 'account_id' => $account->id]],
            ])
            ->assertRedirect();

        $this->assertSame($before, $this->revenueByAccount());
    }

    public function test_a_group_account_is_refused(): void
    {
        $group = Account::where('code', '4100')->firstOrFail();
        $this->assertTrue((bool) $group->is_group);

        $this->actingAs($this->owner)
            ->post('/admin/accounting/revenue-accounts', [
                'streams' => [['key' => 'sales', 'account_id' => $group->id]],
            ])
            ->assertSessionHasErrors('streams.0.account_id');
    }

    public function test_an_expense_account_is_refused(): void
    {
        $expense = Account::where('code', Ledger::GENERAL_EXPENSE)->firstOrFail();

        $this->actingAs($this->owner)
            ->post('/admin/accounting/revenue-accounts', [
                'streams' => [['key' => 'sales', 'account_id' => $expense->id]],
            ])
            ->assertSessionHasErrors('streams.0.account_id');
    }

    /**
     * حسابٌ اختير ثم أُوقف لا يوقف البيع: يعود المصدر إلى حسابه الافتراضي.
     *
     * تعطيل حساب في شجرة الحسابات لا علاقة له بمن يقف عند الكاشير، فلا يجوز
     * أن يمنع تحرير الفاتورة.
     */
    public function test_a_deactivated_account_falls_back_to_the_default(): void
    {
        $account = $this->chaletAccount();
        RevenueAccount::updateOrCreate(['stream' => 'chalet_bookings'], ['account_id' => $account->id]);

        $account->update(['is_active' => false]);

        $resolver = app(RevenueAccounts::class);
        $resolver->forget();

        $this->assertSame(
            (int) Account::where('code', Ledger::BOOKING_REVENUE)->value('id'),
            $resolver->idFor('chalet_bookings'),
        );
    }

    // ── حساب الإيداع: الطرف المدين من القيد ───────────────────────

    public function test_screen_offers_the_asset_accounts_money_may_be_deposited_into(): void
    {
        $this->actingAs($this->owner)
            ->get('/admin/accounting/revenue-accounts')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('deposit_accounts')
                // الصندوق أول القائمة، ومعلَّمٌ أنه من النقدية وما في حكمها
                // فتُعرض الخزائن والبنوك في مجموعتها قبل بقية الأصول.
                ->where('deposit_accounts.0.code', Ledger::CASH)
                ->where('deposit_accounts.0.is_cash_family', true)
                ->where('streams.0.deposit_account_id', null));
    }

    /**
     * المقبوض يهبط حيث قرّر المشغّل لا حيث قرّرت طريقة الدفع.
     *
     * والدفعة نقدية عمدًا: الاختيار هنا أقوى من الطريقة، وإلا لم يكن اختيارًا.
     */
    public function test_a_chalet_deposit_lands_in_the_account_chosen_for_chalets(): void
    {
        $bank = $this->bankAccount();

        $this->actingAs($this->owner)
            ->post('/admin/accounting/revenue-accounts', [
                'streams' => [['key' => 'chalet_bookings', 'account_id' => null, 'deposit_account_id' => $bank->id]],
            ])
            ->assertSessionHasNoErrors();

        app(BookingService::class)->recordPayment($this->stay(), [
            'type' => 'deposit',
            'payment_method_id' => $this->paymentMethodId(),
            'amount' => 500,
        ]);

        $this->assertSame(500.0, $this->balanceOf('1130'));
        $this->assertSame(0.0, $this->balanceOf(Ledger::CASH), 'لم يبقَ من مقبوض الشاليهات شيء في الصندوق');
    }

    /**
     * ما لم يُختر له حساب إيداع يبقى على طريقة الدفع — اختيار الشاليهات لا
     * يجرّ معه القاعات.
     */
    public function test_a_stream_with_no_deposit_account_still_follows_the_payment_method(): void
    {
        $bank = $this->bankAccount();

        $this->actingAs($this->owner)->post('/admin/accounting/revenue-accounts', [
            'streams' => [
                ['key' => 'chalet_bookings', 'account_id' => null, 'deposit_account_id' => $bank->id],
                ['key' => 'hall_bookings', 'account_id' => null, 'deposit_account_id' => null],
            ],
        ])->assertSessionHasNoErrors();

        $hall = app(BookingService::class)->create([
            'unit_id' => Unit::where('type', 'hall')->value('id'),
            'scope' => 'whole',
            'booking_date' => '2027-07-10',
            'period' => 'full_day',
            'status' => 'deposit_paid',
        ]);

        app(BookingService::class)->recordPayment($hall, [
            'type' => 'deposit',
            'payment_method_id' => $this->paymentMethodId(),
            'amount' => 300,
        ]);

        $this->assertSame(300.0, $this->balanceOf(Ledger::CASH));
        $this->assertSame(0.0, $this->balanceOf('1130'));
    }

    /**
     * المال يخرج من حيث دخل: استردادُ دفعةٍ أُودعت في بنك الشاليهات يُخصم
     * منه هو، وإلا بقي في دفاتره رصيدٌ دُفع منذ زمن.
     */
    public function test_a_refund_leaves_from_the_account_the_money_was_deposited_into(): void
    {
        $bank = $this->bankAccount();

        $this->actingAs($this->owner)->post('/admin/accounting/revenue-accounts', [
            'streams' => [['key' => 'chalet_bookings', 'account_id' => null, 'deposit_account_id' => $bank->id]],
        ])->assertSessionHasNoErrors();

        $service = app(BookingService::class);
        $booking = $this->stay('2027-08-10', '2027-08-11');

        $service->recordPayment($booking, [
            'type' => 'deposit', 'payment_method_id' => $this->paymentMethodId(), 'amount' => 500,
        ]);
        $service->recordPayment($booking->fresh(), [
            'type' => 'refund', 'payment_method_id' => $this->paymentMethodId(), 'amount' => 200,
        ]);

        $this->assertSame(300.0, $this->balanceOf('1130'));
        $this->assertSame(0.0, $this->balanceOf(Ledger::CASH));
    }

    public function test_a_revenue_account_is_refused_as_a_deposit_account(): void
    {
        $revenue = Account::where('code', Ledger::SALES_REVENUE)->firstOrFail();

        $this->actingAs($this->owner)
            ->post('/admin/accounting/revenue-accounts', [
                'streams' => [['key' => 'sales', 'account_id' => null, 'deposit_account_id' => $revenue->id]],
            ])
            ->assertSessionHasErrors('streams.0.deposit_account_id');
    }

    public function test_a_group_asset_account_is_refused_as_a_deposit_account(): void
    {
        $group = Account::where('code', '1100')->firstOrFail();
        $this->assertTrue((bool) $group->is_group);

        $this->actingAs($this->owner)
            ->post('/admin/accounting/revenue-accounts', [
                'streams' => [['key' => 'sales', 'account_id' => null, 'deposit_account_id' => $group->id]],
            ])
            ->assertSessionHasErrors('streams.0.deposit_account_id');
    }

    /**
     * حساب إيداع أُوقف لا يوقف القبض: يعود المصدر إلى طريقة الدفع، فالدفعة
     * تُقبض ويُحرَّر سندها بدل أن يقف الضيف عند الصندوق بلا إيصال.
     */
    public function test_a_deactivated_deposit_account_falls_back_to_the_payment_method(): void
    {
        $bank = $this->bankAccount();
        RevenueAccount::updateOrCreate(['stream' => 'chalet_bookings'], ['deposit_account_id' => $bank->id]);

        $bank->update(['is_active' => false]);

        $resolver = app(RevenueAccounts::class);
        $resolver->forget();

        $this->assertNull($resolver->depositIdFor('chalet_bookings'));

        app(BookingService::class)->recordPayment($this->stay('2027-09-10', '2027-09-11'), [
            'type' => 'deposit',
            'payment_method_id' => $this->paymentMethodId(),
            'amount' => 400,
        ]);

        $this->assertSame(400.0, $this->balanceOf(Ledger::CASH));
    }

    /**
     * الطرفان في صفٍّ واحد، وحفظُ أحدهما لا يمسح الآخر — وإلا ضاع حساب
     * الإيداع كلما عُدِّل حساب الإيراد من شاشةٍ أو طلبٍ أقدم.
     */
    public function test_saving_the_revenue_side_alone_leaves_the_deposit_account_standing(): void
    {
        $bank = $this->bankAccount();
        $revenue = $this->chaletAccount();

        $this->actingAs($this->owner)->post('/admin/accounting/revenue-accounts', [
            'streams' => [['key' => 'chalet_bookings', 'account_id' => null, 'deposit_account_id' => $bank->id]],
        ])->assertSessionHasNoErrors();

        $this->actingAs($this->owner)->post('/admin/accounting/revenue-accounts', [
            'streams' => [['key' => 'chalet_bookings', 'account_id' => $revenue->id]],
        ])->assertSessionHasNoErrors();

        $row = RevenueAccount::where('stream', 'chalet_bookings')->firstOrFail();

        $this->assertSame($revenue->id, $row->account_id);
        $this->assertSame($bank->id, $row->deposit_account_id);
    }

    /**
     * الخانة الفارغة تمسح الاختيار ولا تحذف المصدر — يبقى في الشاشة على
     * حسابه الافتراضي.
     */
    public function test_clearing_a_choice_returns_the_stream_to_its_default(): void
    {
        $account = $this->chaletAccount();

        $this->actingAs($this->owner)->post('/admin/accounting/revenue-accounts', [
            'streams' => [['key' => 'chalet_bookings', 'account_id' => $account->id]],
        ]);

        $this->actingAs($this->owner)->post('/admin/accounting/revenue-accounts', [
            'streams' => [['key' => 'chalet_bookings', 'account_id' => null]],
        ]);

        $this->assertNull(RevenueAccount::where('stream', 'chalet_bookings')->value('account_id'));

        $this->actingAs($this->owner)
            ->get('/admin/accounting/revenue-accounts')
            ->assertInertia(fn ($page) => $page->has('streams', count(RevenueAccounts::STREAMS)));
    }
}
