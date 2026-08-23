<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\Facility;
use App\Models\Role;
use App\Models\Unit;
use App\Models\User;
use App\Services\BookingService;
use Database\Seeders\AccountsSeeder;
use Database\Seeders\BookingSetupSeeder;
use Database\Seeders\DepartmentsSeeder;
use Database\Seeders\FacilitiesSeeder;
use Database\Seeders\RolesSeeder;
use Database\Seeders\UnitsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * إدارة الوحدات: الإيقاف يمنع الحجز، الشعار، المدير، والمرافق.
 */
class UnitManagementTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([
            RolesSeeder::class, FacilitiesSeeder::class, DepartmentsSeeder::class,
            UnitsSeeder::class, BookingSetupSeeder::class, AccountsSeeder::class,
        ]);

        $this->owner = User::factory()->create([
            'role_id' => Role::where('slug', 'super-admin')->value('id'),
            'is_active' => true,
            'has_all_units' => true,
        ]);
    }

    // ── إيقاف الوحدة يمنع الحجز ──────────────────────────────

    public function test_a_disabled_unit_cannot_be_booked(): void
    {
        $unit = Unit::where('code', 'CH-BSR1')->firstOrFail();
        $unit->update(['is_active' => false]);

        try {
            app(BookingService::class)->create([
                'unit_id' => $unit->id, 'scope' => 'whole',
                'booking_date' => '2026-12-20', 'period' => 'full_day',
            ]);
            $this->fail('كان يجب رفض الحجز على وحدة موقوفة.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('موقوفة عن الحجز', $e->errors()['availability'][0]);
        }
    }

    public function test_re_enabling_the_unit_allows_booking_again(): void
    {
        $unit = Unit::where('code', 'CH-BSR1')->firstOrFail();
        $unit->update(['is_active' => false]);

        $this->actingAs($this->owner)
            ->patch("/admin/units/{$unit->id}/toggle")
            ->assertSessionHas('success');

        $this->assertTrue($unit->fresh()->is_active);

        $booking = app(BookingService::class)->create([
            'unit_id' => $unit->id, 'scope' => 'whole',
            'booking_date' => '2026-12-20', 'period' => 'full_day',
        ]);

        $this->assertNotNull($booking->id);
    }

    public function test_a_disabled_section_cannot_be_booked_even_if_the_unit_is_active(): void
    {
        $unit = Unit::where('code', 'CH-BSR1')->firstOrFail();
        $men = $unit->sections()->where('gender', 'men')->firstOrFail();
        $men->update(['is_active' => false]);

        try {
            app(BookingService::class)->create([
                'unit_id' => $unit->id, 'scope' => 'sections', 'section_ids' => [$men->id],
                'booking_date' => '2026-12-21', 'period' => 'full_day',
            ]);
            $this->fail('كان يجب رفض حجز قسم موقوف.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('موقوف عن الحجز', $e->errors()['availability'][0]);
        }
    }

    public function test_existing_bookings_survive_disabling_the_unit(): void
    {
        $unit = Unit::where('code', 'CH-LULU')->firstOrFail();

        $booking = app(BookingService::class)->create([
            'unit_id' => $unit->id, 'scope' => 'whole',
            'booking_date' => '2026-12-22', 'period' => 'full_day', 'status' => 'confirmed',
        ]);

        $unit->update(['is_active' => false]);

        // الإيقاف يمنع الجديد ولا يمسّ القائم
        $this->assertSame('confirmed', $booking->fresh()->status);
    }

    // ── سعة القسم محذوفة ─────────────────────────────────────

    public function test_section_capacity_column_is_gone(): void
    {
        $columns = Schema::getColumnListing('unit_sections');

        $this->assertNotContains('capacity', $columns);
        $this->assertNotContains('shared_facilities', $columns);
    }

    // ── الشعار ───────────────────────────────────────────────

    public function test_a_unit_logo_can_be_uploaded_and_removed(): void
    {
        Storage::fake('public');

        $unit = Unit::where('code', 'CH-BSR1')->firstOrFail();

        $this->actingAs($this->owner)->put("/admin/units/{$unit->id}", [
            'code' => $unit->code, 'name' => $unit->name,
            'type' => $unit->type, 'bookable_mode' => $unit->bookable_mode,
            'privacy_mode' => $unit->privacy_mode, 'is_active' => true,
            'logo' => UploadedFile::fake()->image('logo.png', 200, 200),
        ])->assertSessionHasNoErrors();

        $path = $unit->fresh()->logo_path;

        $this->assertNotNull($path);
        Storage::disk('public')->assertExists($path);
        $this->assertNotNull($unit->fresh()->logoUrl());

        // الإزالة الصريحة تحذف الملف كذلك فلا يبقى يتيمًا
        $this->actingAs($this->owner)->put("/admin/units/{$unit->id}", [
            'code' => $unit->code, 'name' => $unit->name,
            'type' => $unit->type, 'bookable_mode' => $unit->bookable_mode,
            'privacy_mode' => $unit->privacy_mode, 'is_active' => true,
            'remove_logo' => true,
        ])->assertSessionHasNoErrors();

        $this->assertNull($unit->fresh()->logo_path);
        Storage::disk('public')->assertMissing($path);
    }

    public function test_a_non_image_logo_is_rejected(): void
    {
        Storage::fake('public');
        $unit = Unit::where('code', 'CH-BSR1')->firstOrFail();

        $this->actingAs($this->owner)->put("/admin/units/{$unit->id}", [
            'code' => $unit->code, 'name' => $unit->name,
            'type' => $unit->type, 'bookable_mode' => $unit->bookable_mode,
            'privacy_mode' => $unit->privacy_mode, 'is_active' => true,
            'logo' => UploadedFile::fake()->create('doc.pdf', 100, 'application/pdf'),
        ])->assertSessionHasErrors('logo');
    }

    // ── مدير الوحدة ──────────────────────────────────────────

    public function test_a_manager_is_assigned_from_the_employee_files(): void
    {
        $employee = Employee::create(['name' => 'سعد المشرف', 'basic_salary' => 4000, 'is_active' => true]);
        $unit = Unit::where('code', 'CH-BSR1')->firstOrFail();

        $this->actingAs($this->owner)->put("/admin/units/{$unit->id}", [
            'code' => $unit->code, 'name' => $unit->name,
            'type' => $unit->type, 'bookable_mode' => $unit->bookable_mode,
            'privacy_mode' => $unit->privacy_mode, 'is_active' => true,
            'manager_id' => $employee->id,
        ])->assertSessionHasNoErrors();

        $this->assertSame($employee->id, $unit->fresh()->manager_id);
        $this->assertSame('سعد المشرف', $unit->fresh()->manager->name);
    }

    public function test_deleting_the_employee_leaves_the_unit_without_a_manager(): void
    {
        $employee = Employee::create(['name' => 'مدير مؤقت', 'basic_salary' => 3000, 'is_active' => true]);
        $unit = Unit::where('code', 'CH-BSR1')->firstOrFail();
        $unit->update(['manager_id' => $employee->id]);

        $employee->delete();

        // الوحدة تبقى بلا مدير: الموظف المؤرشف لا تراه العلاقة.
        $this->assertNotNull($unit->fresh());
        $this->assertNull($unit->fresh()->manager);

        // والإسناد نفسه محفوظ، فاسترجاع الموظف من الأرشيف يُعيد المدير
        // إلى وحدته بلا إعادة إسناد يدوية.
        $employee->restore();

        $this->assertSame($employee->id, $unit->fresh()->manager?->id);
    }

    public function test_units_screen_offers_employees_as_managers(): void
    {
        Employee::create(['name' => 'موظف أ', 'basic_salary' => 3000, 'is_active' => true]);
        Employee::create(['name' => 'موظف موقوف', 'basic_salary' => 3000, 'is_active' => false]);

        $this->actingAs($this->owner)
            ->get('/admin/units')
            ->assertOk()
            ->assertInertia(fn ($p) => $p
                ->has('options.managers', 1)          // الموقوف لا يُقترح مديرًا
                ->where('options.managers.0.name', 'موظف أ')
                ->has('options.facilities', Facility::count()),
            );
    }

    // ── طاقم الوحدة ──────────────────────────────────────────

    /**
     * Post accounts to a unit as its staff, and return the unit fresh.
     *
     * @param  list<int>  $staffIds
     */
    private function postStaff(Unit $unit, array $staffIds): Unit
    {
        $this->actingAs($this->owner)->put("/admin/units/{$unit->id}", [
            'code' => $unit->code, 'name' => $unit->name,
            'type' => $unit->type, 'bookable_mode' => $unit->bookable_mode,
            'privacy_mode' => $unit->privacy_mode, 'is_active' => true,
            'staff_ids' => $staffIds,
        ])->assertSessionHasNoErrors();

        return $unit->fresh();
    }

    /**
     * An ordinary staff account — anything but the super admin.
     */
    private function staffAccount(string $name, bool $seesAllUnits = false): User
    {
        return User::factory()->create([
            'name' => $name,
            'role_id' => Role::where('slug', '!=', 'super-admin')->value('id'),
            'is_active' => true,
            'has_all_units' => $seesAllUnits,
        ]);
    }

    public function test_an_account_is_posted_to_a_unit_and_may_serve_several(): void
    {
        $user = $this->staffAccount('منسّق الحجوزات');
        $first = Unit::where('code', 'CH-BSR1')->firstOrFail();
        $second = Unit::where('type', 'hall')->firstOrFail();

        $this->assertSame([$user->id], $this->postStaff($first, [$user->id])->users->pluck('id')->all());
        $this->assertSame([$user->id], $this->postStaff($second, [$user->id])->users->pluck('id')->all());

        // One person, two units at once — and both are reachable.
        $this->assertEqualsCanonicalizing(
            [$first->id, $second->id],
            $user->fresh()->units->pluck('id')->all(),
        );
    }

    public function test_posting_opens_the_unit_and_unposting_closes_it(): void
    {
        $user = $this->staffAccount('موظف قاعة');
        $unit = Unit::where('code', 'CH-BSR1')->firstOrFail();

        $this->postStaff($unit, [$user->id]);
        $this->assertTrue($user->fresh()->canAccessUnit($unit));

        // The scope follows the staff list in both directions.
        $this->postStaff($unit, []);
        $this->assertFalse($user->fresh()->canAccessUnit($unit));
    }

    public function test_posting_narrows_an_account_that_saw_every_unit(): void
    {
        $user = $this->staffAccount('محاسب سابق', seesAllUnits: true);

        $unit = Unit::where('code', 'CH-BSR1')->firstOrFail();
        $other = Unit::where('type', 'hall')->firstOrFail();

        $this->postStaff($unit, [$user->id]);

        // Only the super admin sees everything, so the blanket flag gives way
        // to the posting — otherwise the scope would never bite.
        $user = $user->fresh();
        $this->assertFalse($user->has_all_units);
        $this->assertTrue($user->canAccessUnit($unit));
        $this->assertFalse($user->canAccessUnit($other));
    }

    public function test_the_super_admin_is_never_offered_nor_posted(): void
    {
        $admin = User::factory()->create([
            'name' => 'المالك',
            'role_id' => Role::where('slug', 'super-admin')->value('id'),
            'is_active' => true,
            'has_all_units' => true,
        ]);

        $unit = Unit::where('code', 'CH-BSR1')->firstOrFail();

        // Even asked for by id, the posting is refused — the picker never offers them.
        $this->postStaff($unit, [$admin->id]);

        $this->assertSame(0, $unit->fresh()->users()->count());
        $this->assertTrue($admin->fresh()->has_all_units);
        $this->assertTrue($admin->fresh()->seesAllUnits());
    }

    public function test_units_screen_offers_every_account_but_the_super_admin(): void
    {
        $role = Role::where('slug', '!=', 'super-admin')->firstOrFail();

        User::factory()->create(['name' => 'أ موظف بدور', 'role_id' => $role->id, 'is_active' => true]);
        User::factory()->create(['name' => 'ب موظف بلا دور', 'role_id' => null, 'is_active' => true]);

        // A second super admin, to prove exclusion is by role and not by
        // «whoever happens to be signed in».
        User::factory()->create([
            'name' => 'ج مدير عام', 'role_id' => Role::where('slug', 'super-admin')->value('id'), 'is_active' => true,
        ]);

        $this->actingAs($this->owner)
            ->get('/admin/units')
            ->assertOk()
            ->assertInertia(fn ($p) => $p
                // Two of the four accounts: both super admins are left out.
                ->has('options.staff', 2)
                ->where('options.staff.0.name', 'أ موظف بدور')
                ->where('options.staff.0.role_name', $role->name)
                ->where('options.staff.1.name', 'ب موظف بلا دور')
                ->where('options.staff.1.role_name', null),
            );
    }

    // ── المرافق ──────────────────────────────────────────────

    public function test_facilities_screen_lists_and_creates(): void
    {
        $this->actingAs($this->owner)
            ->get('/admin/units-facilities')
            ->assertOk()
            ->assertInertia(fn ($p) => $p
                ->component('admin/units/Facilities')
                ->has('facilities', Facility::count()),
            );

        $this->actingAs($this->owner)->post('/admin/units-facilities', [
            'name' => 'ساونا', 'icon' => 'Flame', 'is_active' => true,
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('facilities', ['name' => 'ساونا', 'icon' => 'Flame']);
    }

    public function test_facilities_are_attached_to_sections_with_a_shared_flag(): void
    {
        $unit = Unit::where('code', 'CH-BSR1')->firstOrFail();
        $men = $unit->sections()->where('gender', 'men')->firstOrFail();
        $women = $unit->sections()->where('gender', 'women')->firstOrFail();

        $pool = Facility::firstWhere('name', 'مسبح');
        $entrance = Facility::firstWhere('name', 'مدخل خاص');

        $this->actingAs($this->owner)->put("/admin/units/{$unit->id}", [
            'code' => $unit->code, 'name' => $unit->name,
            'type' => $unit->type, 'bookable_mode' => 'both',
            'privacy_mode' => $unit->privacy_mode, 'is_active' => true,
            'sections' => [
                [
                    'id' => $men->id, 'name' => $men->name, 'gender' => 'men', 'is_active' => true,
                    // المسبح مشترك والمدخل خاص
                    'facility_ids' => [$pool->id, $entrance->id],
                    'shared_facility_ids' => [$pool->id],
                ],
                [
                    'id' => $women->id, 'name' => $women->name, 'gender' => 'women', 'is_active' => true,
                    'facility_ids' => [$pool->id],
                    'shared_facility_ids' => [$pool->id],
                ],
            ],
        ])->assertSessionHasNoErrors();

        $men->refresh();

        $this->assertCount(2, $men->facilities);
        $this->assertTrue($men->hasSharedFacilities());
        $this->assertTrue((bool) $men->facilities->firstWhere('id', $pool->id)->pivot->is_shared);
        $this->assertFalse((bool) $men->facilities->firstWhere('id', $entrance->id)->pivot->is_shared);
    }

    public function test_a_facility_attached_to_sections_cannot_be_deleted(): void
    {
        $unit = Unit::firstOrFail();
        $section = $unit->sections()->firstOrFail();
        $pool = Facility::firstWhere('name', 'مسبح');

        $section->facilities()->attach($pool->id, ['is_shared' => true]);

        $this->actingAs($this->owner)
            ->delete("/admin/units-facilities/{$pool->id}")
            ->assertSessionHas('warning');

        $this->assertNotNull($pool->fresh());
    }

    public function test_facility_counts_units_it_appears_in(): void
    {
        $pool = Facility::firstWhere('name', 'مسبح');
        $unitA = Unit::where('code', 'CH-BSR1')->firstOrFail();
        $unitB = Unit::where('code', 'CH-LULU')->firstOrFail();

        // قسمان من نفس الوحدة + قسم من وحدة أخرى ⇒ وحدتان لا ثلاث
        foreach ($unitA->sections()->limit(2)->get() as $s) {
            $s->facilities()->attach($pool->id, ['is_shared' => true]);
        }
        $unitB->sections()->first()->facilities()->attach($pool->id, ['is_shared' => false]);

        $this->assertSame(2, $pool->unitsCount());
    }
}
