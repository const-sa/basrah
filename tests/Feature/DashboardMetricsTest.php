<?php

namespace Tests\Feature;

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
            'booking_date' => now()->toDateString(), 'period' => 'full_day', 'status' => 'confirmed',
        ]);
        $service->create([
            'unit_id' => $unit->id, 'scope' => 'whole',
            'booking_date' => now()->addDays(5)->toDateString(), 'period' => 'full_day', 'status' => 'confirmed',
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
                'period' => 'full_day', 'status' => 'confirmed',
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
            'method' => 'cash',
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
                'booking_date' => now()->toDateString(), 'period' => 'full_day', 'status' => 'confirmed',
            ]);
        }

        $this->actingAs($supervisor)
            ->get('/admin')
            ->assertOk()
            ->assertInertia(fn ($p) => $p->where('bookings.today', 1)->has('today', 1));
    }
}
