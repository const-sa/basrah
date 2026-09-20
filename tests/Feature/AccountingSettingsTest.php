<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Booking;
use App\Models\Client;
use App\Models\Department;
use App\Models\Item;
use App\Models\JournalLine;
use App\Models\PaymentMethodAccount;
use App\Models\RevenueAccount;
use App\Models\Role;
use App\Models\Unit;
use App\Models\UnitPrice;
use App\Models\User;
use App\Services\Accounting\Ledger;
use App\Services\BookingService;
use App\Services\ChaletBookingService;
use App\Services\SalesService;
use App\Support\StayPeriod;
use Database\Seeders\AccountsSeeder;
use Database\Seeders\BookingSetupSeeder;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\DepartmentsSeeder;
use Database\Seeders\FacilitiesSeeder;
use Database\Seeders\RolesSeeder;
use Database\Seeders\UnitsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AccountingSettingsTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Unit $chalet;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([
            RolesSeeder::class, FacilitiesSeeder::class, DepartmentsSeeder::class,
            UnitsSeeder::class, BookingSetupSeeder::class, AccountsSeeder::class, CatalogSeeder::class,
        ]);

        $this->owner = User::factory()->create([
            'role_id' => Role::where('slug', 'super-admin')->firstOrFail()->id,
            'is_active' => true,
            'has_all_units' => true,
        ]);

        $this->chalet = Unit::where('type', 'chalet')->whereHas('sections')->firstOrFail();
    }

    private function chaletRevenueAccount(): Account
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

    public function test_screen_lists_the_three_activity_sections(): void
    {
        $this->actingAs($this->owner)
            ->get('/admin/accounting/settings')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('admin/accounting/AccountingSettings')
                ->has('sections', 3)
                ->where('sections.0.key', 'halls')
                ->where('sections.1.key', 'chalets')
                ->where('sections.2.key', 'pools')
                ->has('payment_methods')
                ->has('accounts')
                ->has('deposit_accounts'));
    }

    public function test_saving_persists_the_revenue_deposit_and_payment_method_accounts(): void
    {
        $revenue = $this->chaletRevenueAccount();
        $bank = $this->bankAccount();
        $cashId = $this->paymentMethodId('cash');

        $this->actingAs($this->owner)
            ->post('/admin/accounting/settings', [
                'sections' => [
                    [
                        'key' => 'chalets',
                        'account_id' => $revenue->id,
                        'deposit_account_id' => $bank->id,
                        'payment_accounts' => [
                            ['payment_method_id' => $cashId, 'account_id' => $bank->id],
                        ],
                    ],
                ],
            ])
            ->assertSessionHasNoErrors();

        $stored = RevenueAccount::where('stream', 'chalet_bookings')->firstOrFail();
        $this->assertSame($revenue->id, $stored->account_id);
        $this->assertSame($bank->id, $stored->deposit_account_id);

        $matrix = PaymentMethodAccount::where('section', 'chalets')->where('payment_method_id', $cashId)->firstOrFail();
        $this->assertSame($bank->id, $matrix->account_id);
    }

    /**
     * الأشد تخصيصًا يفوز: حساب طريقة الدفع داخل القسم أقوى من حساب إيداع
     * القسم العام، وإلا لم يكن للمصفوفة معنى.
     */
    public function test_the_payment_method_account_wins_over_the_section_deposit_account(): void
    {
        $sectionBank = $this->bankAccount('1130', 'بنك الشاليهات العام');
        $cashBank = $this->bankAccount('1140', 'بنك الشاليهات النقدي');
        $cashId = $this->paymentMethodId('cash');

        $this->actingAs($this->owner)
            ->post('/admin/accounting/settings', [
                'sections' => [
                    [
                        'key' => 'chalets',
                        'account_id' => null,
                        'deposit_account_id' => $sectionBank->id,
                        'payment_accounts' => [
                            ['payment_method_id' => $cashId, 'account_id' => $cashBank->id],
                        ],
                    ],
                ],
            ])
            ->assertSessionHasNoErrors();

        app(BookingService::class)->recordPayment($this->stay(), [
            'type' => 'deposit',
            'payment_method_id' => $cashId,
            'amount' => 500,
        ]);

        $this->assertSame(500.0, $this->balanceOf('1140'));
        $this->assertSame(0.0, $this->balanceOf('1130'));
        $this->assertSame(0.0, $this->balanceOf(Ledger::CASH));
    }

    /**
     * ما لم تُخصَّص له طريقة الدفع تُقبض عليه حسب حساب إيداع القسم، فإن لم
     * يُحدَّد فحسب حساب طريقة الدفع نفسها.
     */
    public function test_falls_back_to_the_section_deposit_then_to_the_payment_method(): void
    {
        $sectionBank = $this->bankAccount();

        $this->actingAs($this->owner)
            ->post('/admin/accounting/settings', [
                'sections' => [
                    ['key' => 'chalets', 'account_id' => null, 'deposit_account_id' => $sectionBank->id, 'payment_accounts' => []],
                ],
            ])
            ->assertSessionHasNoErrors();

        app(BookingService::class)->recordPayment($this->stay(), [
            'type' => 'deposit',
            'payment_method_id' => $this->paymentMethodId('cash'),
            'amount' => 500,
        ]);

        $this->assertSame(500.0, $this->balanceOf('1130'));
    }

    public function test_a_pool_sale_respects_the_payment_method_account_for_its_section(): void
    {
        $bank = $this->bankAccount();
        $cardId = $this->paymentMethodId('card');

        $this->actingAs($this->owner)
            ->post('/admin/accounting/settings', [
                'sections' => [
                    [
                        'key' => 'pools',
                        'account_id' => null,
                        'deposit_account_id' => null,
                        'payment_accounts' => [
                            ['payment_method_id' => $cardId, 'account_id' => $bank->id],
                        ],
                    ],
                ],
            ])
            ->assertSessionHasNoErrors();

        app(SalesService::class)->checkout([
            'lines' => [['item_id' => Item::where('code', 'SPR-001')->firstOrFail()->id, 'quantity' => 5]],
            'department_id' => Department::where('code', 'POOLS')->firstOrFail()->id,
            'payment_method_id' => $cardId,
        ]);

        $this->assertGreaterThan(0, $this->balanceOf('1130'));
        $this->assertSame(0.0, $this->balanceOf(Ledger::BANK));
    }

    public function test_saving_settings_leaves_posted_entries_untouched(): void
    {
        app(BookingService::class)->recordPayment($this->stay(), [
            'type' => 'deposit',
            'payment_method_id' => $this->paymentMethodId('cash'),
            'amount' => 500,
        ]);

        $bank = $this->bankAccount();

        $this->actingAs($this->owner)
            ->post('/admin/accounting/settings', [
                'sections' => [
                    ['key' => 'chalets', 'account_id' => null, 'deposit_account_id' => $bank->id, 'payment_accounts' => []],
                ],
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(500.0, $this->balanceOf(Ledger::CASH));
        $this->assertSame(0.0, $this->balanceOf('1130'));
    }

    public function test_the_remap_button_moves_posted_entries_onto_the_newly_chosen_accounts(): void
    {
        $cashId = $this->paymentMethodId('cash');
        $booking = $this->stay();

        app(BookingService::class)->recordPayment($booking, [
            'type' => 'deposit',
            'payment_method_id' => $cashId,
            'amount' => 500,
        ]);

        $this->assertSame(500.0, $this->balanceOf(Ledger::CASH));

        $revenue = $this->chaletRevenueAccount();
        $bank = $this->bankAccount();

        $this->actingAs($this->owner)
            ->post('/admin/accounting/settings', [
                'sections' => [
                    [
                        'key' => 'chalets',
                        'account_id' => $revenue->id,
                        'deposit_account_id' => $bank->id,
                        'payment_accounts' => [],
                    ],
                ],
            ])
            ->assertSessionHasNoErrors();

        // الحفظ وحده لا يمسّ ما رُحِّل من قبل.
        $this->assertSame(500.0, $this->balanceOf(Ledger::CASH));

        $this->actingAs($this->owner)
            ->post('/admin/accounting/settings/remap')
            ->assertRedirect();

        $this->assertSame(0.0, $this->balanceOf(Ledger::CASH));
        $this->assertSame(500.0, $this->balanceOf('1130'));

        $unearned = (float) JournalLine::query()
            ->join('accounts', 'accounts.id', '=', 'journal_lines.account_id')
            ->where('accounts.code', Ledger::UNEARNED_REVENUE)
            ->sum('journal_lines.credit');

        $this->assertGreaterThan(0, $unearned, 'الالتزام غير المكتسب لم يُمسّ — القيد ما زال متوازنًا');
    }

    public function test_the_remap_action_does_not_touch_another_sections_entries(): void
    {
        $hall = app(BookingService::class)->create([
            'unit_id' => Unit::where('type', 'hall')->value('id'),
            'scope' => 'whole',
            'booking_date' => '2027-07-10',
            'period' => 'full_day',
            'status' => 'deposit_paid',
        ]);

        app(BookingService::class)->recordPayment($hall, [
            'type' => 'deposit',
            'payment_method_id' => $this->paymentMethodId('cash'),
            'amount' => 300,
        ]);

        $bank = $this->bankAccount();

        $this->actingAs($this->owner)
            ->post('/admin/accounting/settings', [
                'sections' => [
                    ['key' => 'chalets', 'account_id' => null, 'deposit_account_id' => $bank->id, 'payment_accounts' => []],
                ],
            ])
            ->assertSessionHasNoErrors();

        $this->actingAs($this->owner)->post('/admin/accounting/settings/remap')->assertRedirect();

        $this->assertSame(300.0, $this->balanceOf(Ledger::CASH), 'دفعة القاعة لم تتأثر بإعدادات الشاليهات');
    }

    public function test_a_role_outside_the_accounting_section_is_refused(): void
    {
        $cashier = User::factory()->create([
            'role_id' => Role::where('slug', 'cashier')->firstOrFail()->id,
            'is_active' => true,
        ]);

        $this->actingAs($cashier)
            ->get('/admin/accounting/settings')
            ->assertForbidden();
    }
}
