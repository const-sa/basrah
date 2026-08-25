<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\Unit;
use App\Models\User;
use App\Services\BookingService;
use App\Services\ChaletBookingService;
use Database\Seeders\BookingSetupSeeder;
use Database\Seeders\RolesSeeder;
use Database\Seeders\UnitsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * تصيير صفحات نظام الحجوزات والبيانات التي تصل الواجهة.
 */
class BookingPagesTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([RolesSeeder::class, UnitsSeeder::class, BookingSetupSeeder::class]);

        $this->owner = User::factory()->create([
            'role_id' => Role::where('slug', 'super-admin')->firstOrFail()->id,
            'is_active' => true,
            'has_all_units' => true,
        ]);
    }

    public function test_units_page_renders_units_with_their_sections(): void
    {
        $this->actingAs($this->owner)
            ->get('/admin/units')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('admin/units/Index')
                ->has('units', 8)
                ->has('units.0.sections')
                ->has('options.bookable_modes', 3)
                ->has('options.privacy_modes', 2)
                ->where('stats.total', 8)
                // حلّت «الموقوفة عن الحجز» محل عدّ الأقسام — أنفع للمشغّل
                ->where('stats.inactive', 0),
            );
    }

    /**
     * القاعات والشاليهات شاشتان منفصلتان — كل مدخل يعرض نوعه وحده.
     */
    public function test_halls_and_chalets_have_their_own_filtered_screens(): void
    {
        $halls = Unit::where('type', 'hall')->count();
        $chalets = Unit::where('type', 'chalet')->count();

        $this->actingAs($this->owner)
            ->get('/admin/units/halls')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('admin/units/Index')
                ->where('type', 'hall')
                ->has('units', $halls)
                ->where('stats.total', $halls)
                ->where('stats.chalets', 0),
            );

        $this->actingAs($this->owner)
            ->get('/admin/units/chalets')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('type', 'chalet')
                ->has('units', $chalets)
                ->where('stats.total', $chalets)
                ->where('stats.halls', 0),
            );

        // مقطع غير معروف لا يُفسَّر كمرشّح صامت — يسقط على مسار {unit} فيُرفض (405)
        $this->actingAs($this->owner)->get('/admin/units/villas')->assertStatus(405);
    }

    public function test_hall_bookings_page_renders_with_units_addons_and_meta(): void
    {
        $halls = Unit::where('type', 'hall')->count();

        $this->actingAs($this->owner)
            ->get('/admin/bookings/halls')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('admin/bookings/halls/Index')
                ->has('bookings.data')
                // الشاشة تعرض قاعاتها وحدها: الشاليه لا يُحجز من هنا فلا يُعرض
                ->has('units', $halls)
                ->has('meta.statuses', 7)
                ->has('meta.periods', 1),
            );
    }

    /**
     * نموذج الحجز صفحة مستقلة لا نافذة — وبياناته تصلها لا تصل القائمة.
     */
    public function test_the_booking_form_is_its_own_page(): void
    {
        $this->actingAs($this->owner)
            ->get('/admin/bookings/halls/create')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('admin/bookings/halls/Form')
                ->where('booking', null)
                ->has('units')
                ->has('clients')
                // القاعة تُباع بسعرها وباقتها — لا خدمات إضافية في شاشتها
                ->missing('addons')
                ->has('packages')
                ->has('eventTypes'),
            );

        $this->actingAs($this->owner)
            ->get('/admin/bookings/chalets/create')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('admin/bookings/chalets/Form')
                ->where('booking', null)
                ->has('units')
                ->has('meta.stay.max_nights'),
            );
    }

    public function test_the_edit_page_opens_on_the_saved_booking(): void
    {
        $hall = Unit::where('type', 'hall')->firstOrFail();

        $booking = app(BookingService::class)->create([
            'unit_id' => $hall->id, 'scope' => 'whole',
            'booking_date' => '2026-11-15', 'period' => 'full_day', 'status' => 'confirmed',
        ]);

        $this->actingAs($this->owner)
            ->get("/admin/bookings/halls/{$booking->id}/edit")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('admin/bookings/halls/Form')
                ->where('booking.id', $booking->id)
                ->where('booking.period', 'full_day')
                ->where('booking.unit.id', $hall->id),
            );
    }

    public function test_chalet_bookings_page_renders_with_stay_meta(): void
    {
        $chalets = Unit::where('type', 'chalet')->count();

        $this->actingAs($this->owner)
            ->get('/admin/bookings/chalets')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('admin/bookings/chalets/Index')
                ->has('bookings.data')
                ->has('units', $chalets)
                // شاشة الإقامة تحتاج ساعتَي الدخول والخروج وسقف الليالي،
                // ولا تحتاج باقات ولا أنواع مناسبات.
                ->has('meta.stay.check_in_time')
                ->has('meta.stay.check_out_time')
                ->has('meta.stay.max_nights')
                ->missing('packages')
                ->missing('eventTypes'),
            );
    }

    /**
     * الشاشتان لا تتسرّب إحداهما إلى الأخرى: حجز القاعة يظهر في شاشة القاعات
     * وحدها، وإقامة الشاليه في شاشة الشاليهات وحدها.
     */
    public function test_each_screen_lists_only_its_own_bookings(): void
    {
        $hall = Unit::where('type', 'hall')->firstOrFail();
        $chalet = $this->chaletLetWhole();

        app(BookingService::class)->create([
            'unit_id' => $hall->id, 'scope' => 'whole',
            'booking_date' => '2026-11-15', 'period' => 'full_day', 'status' => 'confirmed',
        ]);

        app(ChaletBookingService::class)->create([
            'unit_id' => $chalet->id, 'scope' => 'whole',
            'booking_date' => '2026-11-10', 'check_out_date' => '2026-11-13', 'status' => 'confirmed',
        ]);

        $this->actingAs($this->owner)
            ->get('/admin/bookings/halls')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('bookings.data', 1)
                ->where('bookings.data.0.unit.id', $hall->id)
                ->where('bookings.data.0.period', 'full_day'),
            );

        $this->actingAs($this->owner)
            ->get('/admin/bookings/chalets')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('bookings.data', 1)
                ->where('bookings.data.0.unit.id', $chalet->id)
                ->where('bookings.data.0.nights', 3)
                ->where('bookings.data.0.check_out_date', '2026-11-13'),
            );
    }

    public function test_hall_calendar_renders_a_full_month_grid(): void
    {
        $unit = Unit::where('type', 'hall')->firstOrFail();
        $halls = Unit::where('type', 'hall')->count();

        app(BookingService::class)->create([
            'unit_id' => $unit->id,
            'scope' => 'whole',
            'booking_date' => '2026-11-15',
            'period' => 'full_day',
            'status' => 'confirmed',
        ]);

        $this->actingAs($this->owner)
            ->get('/admin/calendar/halls?month=2026-11')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('admin/bookings/halls/Calendar')
                ->where('month', '2026-11')
                ->has('days', 30)
                ->has('units', $halls)
                ->has('bookings', 1)
                ->where('bookings.0.scope', 'whole')
                ->where('bookings.0.color', 'emerald'),
            );
    }

    /**
     * تقويم الشاليهات يرسم الإقامة شريطًا: موضعه وطوله يُحسبان في الخادم.
     */
    public function test_chalet_calendar_places_the_stay_as_a_span(): void
    {
        $chalet = $this->chaletLetWhole();

        app(ChaletBookingService::class)->create([
            'unit_id' => $chalet->id, 'scope' => 'whole',
            'booking_date' => '2026-11-10', 'check_out_date' => '2026-11-13', 'status' => 'confirmed',
        ]);

        $this->actingAs($this->owner)
            ->get('/admin/calendar/chalets?month=2026-11')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('admin/bookings/chalets/Calendar')
                ->has('days', 30)
                ->has('bookings', 1)
                // ليالي 10 و11 و12 — يوم الخروج لا يُحتسب ليلةً
                ->where('bookings.0.start_index', 9)
                ->where('bookings.0.span', 3)
                ->where('bookings.0.nights', 3)
                ->where('bookings.0.continues_before', false)
                ->where('bookings.0.continues_after', false),
            );
    }

    /**
     * الإقامة العابرة للشهر تُقصّ على حدوده وتُعلَّم بأنها تستمر خارجه —
     * وإلا اختفت من الشهر الذي تشغل أكثر لياليه.
     */
    public function test_a_stay_crossing_the_month_boundary_is_clipped_and_flagged(): void
    {
        $chalet = $this->chaletLetWhole();

        app(ChaletBookingService::class)->create([
            'unit_id' => $chalet->id, 'scope' => 'whole',
            'booking_date' => '2026-10-29', 'check_out_date' => '2026-11-03', 'status' => 'confirmed',
        ]);

        $this->actingAs($this->owner)
            ->get('/admin/calendar/chalets?month=2026-11')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('bookings', 1)
                // ليالي 1 و2 من نوفمبر فقط
                ->where('bookings.0.start_index', 0)
                ->where('bookings.0.span', 2)
                ->where('bookings.0.continues_before', true)
                ->where('bookings.0.continues_after', false),
            );
    }

    public function test_calendar_excludes_cancelled_bookings(): void
    {
        $unit = Unit::where('type', 'hall')->firstOrFail();
        $service = app(BookingService::class);

        $booking = $service->create([
            'unit_id' => $unit->id,
            'scope' => 'whole',
            'booking_date' => '2026-11-20',
            'period' => 'full_day',
            'status' => 'confirmed',
        ]);
        $service->cancel($booking, 'اعتذر العميل');

        $this->actingAs($this->owner)
            ->get('/admin/calendar/halls?month=2026-11')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('bookings', 0));
    }

    public function test_bookings_can_be_filtered_by_status(): void
    {
        $unit = Unit::where('type', 'hall')->firstOrFail();
        $service = app(BookingService::class);

        $service->create(['unit_id' => $unit->id, 'scope' => 'whole', 'booking_date' => '2026-11-01', 'period' => 'full_day', 'status' => 'confirmed']);
        $service->create(['unit_id' => $unit->id, 'scope' => 'whole', 'booking_date' => '2026-11-02', 'period' => 'full_day', 'status' => 'tentative']);

        $this->actingAs($this->owner)
            ->get('/admin/bookings/halls?status=confirmed')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('bookings.data', 1)
                ->where('bookings.data.0.status', 'confirmed'),
            );
    }

    public function test_units_page_only_shows_units_within_the_users_scope(): void
    {
        $supervisor = User::factory()->create([
            'role_id' => Role::where('slug', 'unit-supervisor')->firstOrFail()->id,
            'is_active' => true,
            'has_all_units' => false,
        ]);

        $mine = Unit::firstOrFail();
        $supervisor->units()->sync([$mine->id]);

        $this->actingAs($supervisor)
            ->get('/admin/units')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('units', 1)
                ->where('units.0.id', $mine->id),
            );
    }
}
