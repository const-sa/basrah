<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Employee;
use App\Models\Item;
use App\Models\Role;
use App\Models\Unit;
use App\Models\User;
use App\Services\BookingService;
use App\Services\SalesService;
use Database\Seeders\AccountsSeeder;
use Database\Seeders\BookingSetupSeeder;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\DepartmentsSeeder;
use Database\Seeders\FacilitiesSeeder;
use Database\Seeders\RolesSeeder;
use Database\Seeders\UnitsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * مساحة عمل الوحدة: لكل قاعة وشاليه شاشته المستقلة بحجوزاته وفواتيره.
 */
class UnitWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Unit $unit;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        $this->seed([
            RolesSeeder::class, FacilitiesSeeder::class, DepartmentsSeeder::class,
            UnitsSeeder::class, BookingSetupSeeder::class, AccountsSeeder::class, CatalogSeeder::class,
        ]);

        $this->owner = User::factory()->create([
            'role_id' => Role::where('slug', 'super-admin')->value('id'),
            'is_active' => true,
            'has_all_units' => true,
        ]);

        $this->unit = $this->chaletLetWhole();
    }

    public function test_the_workspace_shows_the_units_own_bookings_only(): void
    {
        $other = $this->chaletLetWhole('CH-LULU');
        $service = app(BookingService::class);

        $service->create([
            'unit_id' => $this->unit->id, 'scope' => 'whole',
            'booking_date' => now()->addDays(3)->toDateString(), 'period' => 'full_day', 'status' => 'confirmed',
        ]);
        $service->create([
            'unit_id' => $other->id, 'scope' => 'whole',
            'booking_date' => now()->addDays(4)->toDateString(), 'period' => 'full_day', 'status' => 'confirmed',
        ]);

        $this->actingAs($this->owner)
            ->get("/admin/units/{$this->unit->id}/workspace")
            ->assertOk()
            ->assertInertia(fn ($p) => $p
                ->component('admin/units/Workspace')
                ->where('unit.id', $this->unit->id)
                ->where('unit.name', 'شاليه البصرة')
                ->has('upcoming', 1)
                ->where('stats.upcoming', 1),
            );
    }

    public function test_the_workspace_lists_invoices_billed_to_the_unit(): void
    {
        $part = Item::where('code', 'SPR-001')->firstOrFail();
        $client = Client::create(['name' => 'عميل الوحدة', 'mobile' => '0551234567']);

        app(SalesService::class)->checkout([
            'lines' => [['item_id' => $part->id, 'quantity' => 4]],
            'unit_id' => $this->unit->id,
            'client_id' => $client->id,
            'payment_method_id' => $this->paymentMethodId('cash'),
        ], $this->owner->id);

        // فاتورة على وحدة أخرى يجب ألا تظهر هنا
        app(SalesService::class)->checkout([
            'lines' => [['item_id' => $part->id, 'quantity' => 2]],
            'unit_id' => Unit::where('code', 'CH-LULU')->value('id'),
            'payment_method_id' => $this->paymentMethodId('cash'),
        ], $this->owner->id);

        $this->actingAs($this->owner)
            ->get("/admin/units/{$this->unit->id}/workspace")
            ->assertOk()
            ->assertInertia(fn ($p) => $p
                ->has('invoices', 1)
                ->where('invoices.0.client', 'عميل الوحدة'),
            );
    }

    public function test_the_workspace_reports_the_units_own_profitability(): void
    {
        $part = Item::where('code', 'SPR-001')->firstOrFail();

        app(SalesService::class)->checkout([
            'lines' => [['item_id' => $part->id, 'quantity' => 10]],
            'unit_id' => $this->unit->id,
            'payment_method_id' => $this->paymentMethodId('cash'),
        ], $this->owner->id);

        $this->actingAs($this->owner)
            ->get("/admin/units/{$this->unit->id}/workspace")
            ->assertOk()
            // JSON يُسلسل 350.0 كعدد صحيح، فالمقارنة بالقيمة لا بالنوع
            ->assertInertia(fn ($p) => $p
                ->where('stats.revenue', 747.5)
                ->where('stats.expense', 350)
                ->where('stats.profit', 397.5),
            );
    }

    public function test_the_manager_and_facilities_appear_on_the_workspace(): void
    {
        $employee = Employee::create(['name' => 'مدير الشاليه', 'phone' => '0509998888', 'basic_salary' => 5000, 'is_active' => true]);
        $this->unit->update(['manager_id' => $employee->id]);

        $this->actingAs($this->owner)
            ->get("/admin/units/{$this->unit->id}/workspace")
            ->assertOk()
            ->assertInertia(fn ($p) => $p
                ->where('unit.manager.name', 'مدير الشاليه')
                ->where('unit.manager.phone', '0509998888')
                ->has('unit.sections', $this->unit->sections()->count()),
            );
    }

    public function test_a_scoped_user_cannot_open_another_units_workspace(): void
    {
        $supervisor = User::factory()->create([
            'role_id' => Role::where('slug', 'unit-supervisor')->value('id'),
            'is_active' => true,
            'has_all_units' => false,
        ]);
        $supervisor->units()->sync([$this->unit->id]);

        $this->actingAs($supervisor)
            ->get("/admin/units/{$this->unit->id}/workspace")
            ->assertOk();

        $other = Unit::where('code', 'CH-LULU')->firstOrFail();

        // العنوان المباشر ليس ثغرة — النطاق يُطبَّق هنا أيضًا
        $this->actingAs($supervisor)
            ->get("/admin/units/{$other->id}/workspace")
            ->assertForbidden();
    }

    public function test_the_logo_is_uploaded_and_removed_from_the_workspace(): void
    {
        Storage::fake('public');

        $this->actingAs($this->owner)
            ->post("/admin/units/{$this->unit->id}/logo", ['logo' => UploadedFile::fake()->image('logo.png')])
            ->assertRedirect();

        $path = $this->unit->refresh()->logo_path;
        $this->assertNotNull($path);
        Storage::disk('public')->assertExists($path);

        // الحذف يُزيل السجل والملف معًا فلا تبقى ملفات يتيمة
        $this->actingAs($this->owner)
            ->post("/admin/units/{$this->unit->id}/logo", ['remove_logo' => true])
            ->assertRedirect();

        $this->assertNull($this->unit->refresh()->logo_path);
        Storage::disk('public')->assertMissing($path);
    }

    public function test_a_scoped_user_cannot_change_another_units_logo(): void
    {
        Storage::fake('public');

        $supervisor = User::factory()->create([
            'role_id' => Role::where('slug', 'unit-supervisor')->value('id'),
            'is_active' => true,
            'has_all_units' => false,
        ]);
        $supervisor->units()->sync([$this->unit->id]);

        $other = Unit::where('code', 'CH-LULU')->firstOrFail();

        $this->actingAs($supervisor)
            ->post("/admin/units/{$other->id}/logo", ['logo' => UploadedFile::fake()->image('logo.png')])
            ->assertForbidden();

        $this->assertNull($other->refresh()->logo_path);
    }

    public function test_the_sidebar_receives_only_the_units_the_user_may_see(): void
    {
        $supervisor = User::factory()->create([
            'role_id' => Role::where('slug', 'unit-supervisor')->value('id'),
            'is_active' => true,
            'has_all_units' => false,
        ]);
        $supervisor->units()->sync([$this->unit->id]);

        // الشريط يُبنى من sidebarUnits المشتركة عالميًا لا من بيانات الصفحة
        $this->actingAs($supervisor)
            ->get('/admin/bookings/halls')
            ->assertOk()
            ->assertInertia(fn ($p) => $p
                ->has('sidebarUnits', 1)
                ->where('sidebarUnits.0.id', $this->unit->id)
                ->where('sidebarUnits.0.type', 'chalet'),
            );
    }

    public function test_a_disabled_unit_is_dropped_from_the_sidebar(): void
    {
        $total = Unit::where('is_active', true)->count();
        $this->unit->update(['is_active' => false]);

        $this->actingAs($this->owner)
            ->get('/admin/bookings/halls')
            ->assertOk()
            ->assertInertia(fn ($p) => $p->has('sidebarUnits', $total - 1));
    }
}
