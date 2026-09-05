<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Item;
use App\Models\Role;
use App\Models\Unit;
use App\Models\User;
use App\Services\BookingService;
use App\Services\InventoryService;
use App\Services\SalesService;
use Database\Seeders\AccountsSeeder;
use Database\Seeders\BookingSetupSeeder;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\DepartmentsSeeder;
use Database\Seeders\RolesSeeder;
use Database\Seeders\UnitsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * مؤشرات لوحة التحكم مع بيانات فعلية، ومحكومة بالصلاحيات والنطاق.
 */
class DashboardMetricsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // مبالغ هذا الاختبار شاملة للضريبة، فالمنشأة فيه مسجَّلة.
        $this->registerForVat();

        Queue::fake();
        $this->seed([RolesSeeder::class, DepartmentsSeeder::class, UnitsSeeder::class, BookingSetupSeeder::class, AccountsSeeder::class, CatalogSeeder::class]);
    }

    private function user(string $slug, bool $allUnits = true): User
    {
        return User::factory()->create([
            'role_id' => Role::where('slug', $slug)->value('id'),
            'is_active' => true,
            'has_all_units' => $allUnits,
        ]);
    }

    public function test_today_schedule_lists_only_todays_bookings(): void
    {
        $service = app(BookingService::class);
        $unit = Unit::firstOrFail();

        $service->create([
            'unit_id' => $unit->id, 'scope' => 'whole',
            'booking_date' => now()->toDateString(), 'period' => 'full_day', 'status' => 'deposit_paid',
        ]);
        $service->create([
            'unit_id' => $unit->id, 'scope' => 'whole',
            'booking_date' => now()->addDays(5)->toDateString(), 'period' => 'full_day', 'status' => 'deposit_paid',
        ]);

        $this->actingAs($this->user('super-admin'))
            ->get('/admin')
            ->assertOk()
            ->assertInertia(fn ($p) => $p
                ->has('today', 1)
                ->where('bookings.today', 1)
                ->where('bookings.upcoming', 1),
            );
    }

    public function test_occupancy_is_computed_without_database_specific_sql(): void
    {
        $service = app(BookingService::class);
        $unit = Unit::firstOrFail();

        // ثلاثة أيام مختلفة على وحدة واحدة — بعد اليوم لا قبله، فالنظام يرفض
        // الحجز في تاريخ مضى. وتبقى داخل الشهر الجاري لأن الإشغال شهري.
        foreach ([1, 2, 3] as $d) {
            $service->create([
                'unit_id' => $unit->id, 'scope' => 'whole',
                'booking_date' => now()->addDays($d)->toDateString(),
                'period' => 'full_day', 'status' => 'deposit_paid',
            ]);
        }

        $response = $this->actingAs($this->user('super-admin'))->get('/admin')->assertOk();

        $occupancy = $response->viewData('page')['props']['bookings']['occupancy'];

        // 3 ليالٍ ÷ (8 وحدات × أيام الشهر) — رقم موجب صغير لا صفر ولا خطأ
        $this->assertGreaterThan(0, $occupancy);
        $this->assertLessThan(100, $occupancy);
    }

    public function test_pos_metrics_reflect_actual_sales(): void
    {
        $cashier = $this->user('cashier');
        $part = Item::where('code', 'SPR-001')->firstOrFail();

        app(SalesService::class)->checkout([
            'lines' => [['item_id' => $part->id, 'quantity' => 10]],
            'payment_method_id' => $this->paymentMethodId('cash'),
        ], $cashier->id);

        $this->actingAs($cashier)
            ->get('/admin')
            ->assertOk()
            ->assertInertia(fn ($p) => $p
                ->where('pos.today_count', 1)
                ->where('pos.today_total', 747.5),
            );
    }

    public function test_low_stock_alert_appears_when_an_item_drops(): void
    {
        $owner = $this->user('super-admin');
        $pump = Item::where('code', 'PMP-001')->firstOrFail();

        app(InventoryService::class)->adjust($pump, 2, $owner->id);

        $response = $this->actingAs($owner)->get('/admin')->assertOk();
        $alerts = $response->viewData('page')['props']['alerts'];

        $this->assertNotEmpty(array_filter($alerts, fn ($a) => str_contains($a['text'], 'حد إعادة الطلب')));
    }

    public function test_series_returns_a_row_for_every_one_of_the_last_fourteen_days(): void
    {
        $owner = $this->user('super-admin');
        $service = app(BookingService::class);

        $booking = $service->create([
            'unit_id' => Unit::firstOrFail()->id, 'scope' => 'whole',
            'booking_date' => now()->toDateString(), 'period' => 'full_day', 'status' => 'deposit_paid',
        ]);

        $service->recordPayment($booking, [
            'type' => 'deposit', 'payment_method_id' => $this->paymentMethodId(),
            'amount' => 400, 'paid_on' => now()->toDateString(),
        ], $owner->id);

        $this->actingAs($owner)
            ->get('/admin')
            ->assertOk()
            ->assertInertia(fn ($p) => $p
                ->has('series', 14)
                // اليوم آخر الصف، والأيام الخالية قبله تُملأ بأصفار لا تُحذف.
                ->where('series.13.date', now()->toDateString())
                ->where('series.13.bookings', 1)
                ->where('series.13.collected', 400)
                ->where('series.12.bookings', 0)
                ->where('series.12.collected', 0),
            );
    }

    public function test_series_is_hidden_from_a_user_without_booking_permission(): void
    {
        $this->actingAs($this->user('cashier'))
            ->get('/admin')
            ->assertOk()
            ->assertInertia(fn ($p) => $p->where('series', []));
    }

    public function test_cashier_sees_pos_metrics_but_not_accounting_or_bookings(): void
    {
        $this->actingAs($this->user('cashier'))
            ->get('/admin')
            ->assertOk()
            ->assertInertia(fn ($p) => $p
                ->where('canSee.pos', true)
                ->where('canSee.bookings', false)
                ->where('canSee.accounting', false)
                ->where('bookings', null)
                ->where('profitability', []),
            );
    }

    public function test_scoped_supervisor_only_counts_their_own_units_bookings(): void
    {
        $mine = Unit::firstOrFail();
        $other = Unit::where('id', '!=', $mine->id)->firstOrFail();

        $supervisor = $this->user('unit-supervisor', allUnits: false);
        $supervisor->units()->sync([$mine->id]);

        $service = app(BookingService::class);
        foreach ([$mine, $other] as $unit) {
            $service->create([
                'unit_id' => $unit->id, 'scope' => 'whole',
                'booking_date' => now()->toDateString(), 'period' => 'full_day', 'status' => 'deposit_paid',
            ]);
        }

        $this->actingAs($supervisor)
            ->get('/admin')
            ->assertOk()
            ->assertInertia(fn ($p) => $p->where('bookings.today', 1)->has('today', 1));
    }

    /**
     * الوحدات المشغولة تُعدّ بالوحدات لا بالحجوزات (§13): قاعةٌ فيها حجزان
     * في اليوم مشغولةٌ مرةً واحدة، ولولا ذلك لفاق المشغولُ عددَ الوحدات.
     */
    public function test_occupied_and_available_units_count_units_not_bookings(): void
    {
        $service = app(BookingService::class);
        $unit = Unit::firstOrFail();
        $units = Unit::where('is_active', true)->count();

        foreach (['morning', 'evening'] as $period) {
            $service->create([
                'unit_id' => $unit->id, 'scope' => 'whole',
                'booking_date' => now()->toDateString(), 'period' => $period, 'status' => 'deposit_paid',
            ]);
        }

        $this->actingAs($this->user('super-admin'))
            ->get('/admin')
            ->assertOk()
            ->assertInertia(fn ($p) => $p
                ->where('bookings.today', 2)
                ->where('bookings.units_occupied', 1)
                ->where('bookings.units_total', $units)
                ->where('bookings.units_available', $units - 1),
            );
    }

    /**
     * إيراد اليوم هو ما دخل الصندوق فعلًا — والاسترداد يُطرح لأنه خروج.
     */
    public function test_today_revenue_counts_collected_payments_minus_refunds(): void
    {
        $owner = $this->user('super-admin');
        $service = app(BookingService::class);

        $booking = $service->create([
            'unit_id' => Unit::firstOrFail()->id, 'scope' => 'whole',
            'booking_date' => now()->addDay()->toDateString(), 'period' => 'full_day', 'status' => 'deposit_paid',
        ]);

        $service->recordPayment($booking, [
            'type' => 'deposit', 'payment_method_id' => $this->paymentMethodId(),
            'amount' => 500, 'paid_on' => now()->toDateString(),
        ], $owner->id);

        $service->recordPayment($booking->fresh(), [
            'type' => 'refund', 'payment_method_id' => $this->paymentMethodId(),
            'amount' => 200, 'paid_on' => now()->toDateString(),
        ], $owner->id);

        $this->actingAs($owner)
            ->get('/admin')
            ->assertOk()
            ->assertInertia(fn ($p) => $p->where('bookings.collected_today', 300));
    }

    public function test_cancelled_bookings_and_clients_are_reported(): void
    {
        $owner = $this->user('super-admin');
        $service = app(BookingService::class);

        Client::create(['name' => 'عميل جديد', 'is_active' => true]);

        $booking = $service->create([
            'unit_id' => Unit::firstOrFail()->id, 'scope' => 'whole',
            'booking_date' => now()->addDay()->toDateString(), 'period' => 'full_day', 'status' => 'deposit_paid',
        ]);

        $service->cancel($booking, 'ظرف طارئ');

        $this->actingAs($owner)
            ->get('/admin')
            ->assertOk()
            ->assertInertia(fn ($p) => $p
                ->where('bookings.cancelled_month', 1)
                ->where('clients.total', 1)
                ->where('clients.new_this_month', 1)
                ->has('finance.revenue')
                ->has('finance.expense')
                ->has('finance.profit'),
            );
    }

    /**
     * أرقام الدفاتر وعدد العملاء محكومة بصلاحياتها كبقية اللوحة.
     */
    public function test_finance_and_clients_blocks_are_hidden_without_permission(): void
    {
        // الكاشير يرى العملاء (يختار العميل في الفاتورة) ولا يرى الدفاتر.
        $this->actingAs($this->user('cashier'))
            ->get('/admin')
            ->assertOk()
            ->assertInertia(fn ($p) => $p->where('finance', null)->has('clients'));

        // اللوحة نفسها تحتاج صلاحيتها، وما وراءها من أرقام يحتاج صلاحياته.
        $role = Role::create(['name' => 'لوحة فقط', 'slug' => 'dashboard-only', 'permissions' => ['dashboard.view']]);
        $stranger = User::factory()->create(['role_id' => $role->id, 'is_active' => true, 'has_all_units' => true]);

        $this->actingAs($stranger)
            ->get('/admin')
            ->assertOk()
            ->assertInertia(fn ($p) => $p->where('finance', null)->where('clients', null));
    }
}
