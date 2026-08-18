<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\BookingPayment;
use App\Models\Client;
use App\Models\Role;
use App\Models\Unit;
use App\Models\User;
use App\Support\Reports\ReportRegistry;
use Database\Seeders\BookingSetupSeeder;
use Database\Seeders\RolesSeeder;
use Database\Seeders\UnitsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * مركز التقارير (§12): تقارير الحجوزات والمالية والموظفين.
 */
class ReportsTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Unit $unit;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([RolesSeeder::class, UnitsSeeder::class, BookingSetupSeeder::class]);

        $this->owner = User::factory()->create([
            'name' => 'مالك النظام',
            'role_id' => Role::where('slug', 'super-admin')->firstOrFail()->id,
            'is_active' => true,
            'has_all_units' => true,
        ]);

        $this->unit = Unit::where('code', 'HALL-01')->firstOrFail();
    }

    private int $sequence = 0;

    /**
     * الرقم يُولَّد هنا لأن مولّده في BookingService، والتقارير تُختبر على
     * صفوفٍ جاهزة لا على مسار الحجز.
     */
    private function booking(array $attributes = []): Booking
    {
        $client = Client::firstOrCreate(['name' => 'عميل التقارير'], ['is_active' => true]);

        return Booking::create([
            'reference' => 'a-'.(++$this->sequence),
            'unit_id' => $this->unit->id,
            'client_id' => $client->id,
            'created_by' => $this->owner->id,
            'scope' => 'whole',
            'period' => 'evening',
            'booking_date' => '2026-08-16',
            'starts_at' => '2026-08-16 18:00:00',
            'ends_at' => '2026-08-16 23:00:00',
            'status' => 'confirmed',
            'base_amount' => 1000,
            'total_amount' => 1000,
            'paid_amount' => 400,
            'guests_count' => 50,
            ...$attributes,
        ]);
    }

    /**
     * العرض المعتمد ينصّ على ثلاثة وعشرين تقريرًا في ثلاث مجموعات.
     */
    public function test_the_registry_covers_every_report_in_the_specification(): void
    {
        $keys = ReportRegistry::all()->keys();

        foreach ([
            'bookings-daily', 'bookings-weekly', 'bookings-monthly', 'bookings-yearly',
            'bookings-cancelled', 'bookings-postponed', 'bookings-upcoming', 'bookings-by-unit',
            'revenue', 'expenses', 'profit-loss', 'collected', 'outstanding',
            'expenses-by-category', 'revenue-by-unit', 'revenue-by-employee',
            'revenue-by-payment-method', 'revenue-by-department', 'unit-profit',
            'employees', 'salaries', 'deductions', 'overtime', 'staff-performance', 'staff-operations',
        ] as $key) {
            $this->assertTrue($keys->contains($key), "التقرير {$key} غير مسجَّل");
        }

        $this->assertCount(25, $keys);
    }

    public function test_the_hub_lists_the_reports_in_their_groups(): void
    {
        $this->actingAs($this->owner)
            ->get('/admin/reports')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('admin/reports/Index')
                ->has('groups', 3),
            );
    }

    /**
     * كل تقرير يُفتح ويبني صفوفه — ولو على قاعدة بلا بيانات.
     */
    public function test_every_report_renders(): void
    {
        $this->actingAs($this->owner);

        foreach (ReportRegistry::all()->keys() as $key) {
            $this->get("/admin/reports/{$key}")
                ->assertOk()
                ->assertInertia(fn ($page) => $page
                    ->component('admin/reports/Show')
                    ->where('report.key', $key)
                    ->has('rows')
                    ->has('summary'),
                );
        }
    }

    public function test_daily_bookings_report_groups_and_totals(): void
    {
        $this->booking();
        $this->booking(['booking_date' => '2026-08-16', 'total_amount' => 500, 'paid_amount' => 500, 'guests_count' => 20]);
        $this->booking(['booking_date' => '2026-08-10', 'total_amount' => 300, 'paid_amount' => 0, 'guests_count' => 10]);

        $this->actingAs($this->owner)
            ->get('/admin/reports/bookings-daily?from=2026-08-01&to=2026-08-31')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('rows', 2)
                // الأحدث أولًا: 2026-08-16 يحمل حجزين
                ->where('rows.0.period', '2026-08-16')
                ->where('rows.0.count', 2)
                ->where('rows.0.total', 1500)
                ->where('rows.0.paid', 900)
                ->where('rows.0.remaining', 600)
                ->where('rows.0.guests', 70)
                ->where('summary.0.value', 3),
            );
    }

    public function test_cancelled_and_postponed_reports_separate_their_bookings(): void
    {
        $this->booking(['status' => 'cancelled', 'cancellation_reason' => 'ظرف طارئ', 'cancelled_at' => now()]);
        $this->booking(['status' => 'postponed']);
        $this->booking();

        $this->actingAs($this->owner);

        $this->get('/admin/reports/bookings-cancelled?from=2026-08-01&to=2026-08-31')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('rows', 1)
                ->where('rows.0.reason', 'ظرف طارئ'),
            );

        $this->get('/admin/reports/bookings-postponed?from=2026-08-01&to=2026-08-31')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('rows', 1));
    }

    /**
     * كشف الذمم لا يطالب بحجز ملغى ولا بحجز مسدَّد.
     */
    public function test_outstanding_report_excludes_settled_and_cancelled_bookings(): void
    {
        $this->booking();                                                   // متبقٍ 600
        $this->booking(['total_amount' => 800, 'paid_amount' => 800]);      // مسدَّد
        $this->booking(['status' => 'cancelled', 'paid_amount' => 0]);      // ملغى

        $this->actingAs($this->owner)
            ->get('/admin/reports/outstanding?from=2026-08-01&to=2026-08-31')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('rows', 1)
                ->where('rows.0.remaining', 600),
            );
    }

    public function test_bookings_by_unit_can_be_filtered_by_unit(): void
    {
        $other = Unit::where('code', '!=', 'HALL-01')->firstOrFail();

        $this->booking();
        $this->booking(['unit_id' => $other->id, 'total_amount' => 2000, 'paid_amount' => 2000]);

        $this->actingAs($this->owner)
            ->get('/admin/reports/bookings-by-unit?from=2026-08-01&to=2026-08-31')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('rows', 2));

        $this->get("/admin/reports/bookings-daily?from=2026-08-01&to=2026-08-31&unit_id={$other->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('rows.0.total', 2000));
    }

    public function test_staff_operations_report_reads_the_audit_log(): void
    {
        $this->actingAs($this->owner);

        Client::create(['name' => 'عميل جديد', 'is_active' => true]);

        $response = $this->get('/admin/reports/staff-operations')->assertOk();

        $rows = collect($response->viewData('page')['props']['rows']);
        $owner = $rows->firstWhere('employee', 'مالك النظام');

        $this->assertNotNull($owner, 'المالك لا يظهر في تقرير العمليات');
        $this->assertSame(1, $owner['created']);
        $this->assertSame($owner['created'] + $owner['updated'] + $owner['deleted'] + $owner['restored'], $owner['total']);
    }

    /**
     * الإيراد بطريقة الدفع يُقرأ من المقبوض — والاسترداد يخرج بنفس الطريقة
     * التي دخل بها، فيُطرح منها لا من غيرها.
     */
    public function test_revenue_by_payment_method_nets_refunds_against_their_method(): void
    {
        $booking = $this->booking();

        BookingPayment::create([
            'booking_id' => $booking->id, 'type' => 'deposit',
            'payment_method_id' => $this->paymentMethodId('cash'),
            'amount' => 500, 'paid_on' => '2026-08-16',
        ]);

        BookingPayment::create([
            'booking_id' => $booking->id, 'type' => 'refund',
            'payment_method_id' => $this->paymentMethodId('cash'),
            'amount' => 200, 'paid_on' => '2026-08-16',
        ]);

        BookingPayment::create([
            'booking_id' => $booking->id, 'type' => 'payment',
            'payment_method_id' => $this->paymentMethodId('transfer'),
            'amount' => 100, 'paid_on' => '2026-08-16',
        ]);

        $this->actingAs($this->owner)
            ->get('/admin/reports/revenue-by-payment-method?from=2026-08-01&to=2026-08-31')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('rows', 2)
                ->where('rows.0.method', 'نقدًا')
                ->where('rows.0.total', 300)
                ->where('rows.1.total', 100)
                ->where('summary.0.value', 400),
            );
    }

    /**
     * القسم يجيب «من أين جاء المال»، والحجوزات لا قسم لها فتُعرض بسطرها —
     * وإسقاطها يجعل المجموع أقلّ من إيراد المؤسسة بلا سبب ظاهر.
     */
    public function test_revenue_by_department_includes_bookings_as_their_own_line(): void
    {
        $this->booking(['total_amount' => 1200, 'paid_amount' => 700]);

        $this->actingAs($this->owner)
            ->get('/admin/reports/revenue-by-department?from=2026-08-01&to=2026-08-31')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('rows', 1)
                ->where('rows.0.department', 'الحجوزات (قاعات وشاليهات)')
                ->where('rows.0.amount', 1200)
                ->where('rows.0.collected', 700)
                ->where('rows.0.share', 100),
            );
    }

    public function test_a_report_exports_the_shown_rows_as_csv(): void
    {
        $this->booking();

        $this->actingAs($this->owner)
            ->get('/admin/reports/bookings-daily/export?from=2026-08-01&to=2026-08-31')
            ->assertOk()
            ->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
    }

    public function test_an_unknown_report_is_not_found(): void
    {
        $this->actingAs($this->owner)->get('/admin/reports/not-a-report')->assertNotFound();
        $this->actingAs($this->owner)->get('/admin/reports/not-a-report/export')->assertNotFound();
    }

    public function test_reports_are_guarded_by_permission(): void
    {
        $cashier = User::factory()->create([
            'role_id' => Role::where('slug', 'cashier')->firstOrFail()->id,
            'is_active' => true,
            'has_all_units' => true,
        ]);

        $this->actingAs($cashier)->get('/admin/reports')->assertForbidden();
        $this->actingAs($cashier)->get('/admin/reports/bookings-daily')->assertForbidden();
    }
}
