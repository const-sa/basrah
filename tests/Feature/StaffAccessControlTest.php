<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\Role;
use App\Models\Unit;
use App\Models\User;
use App\Services\BookingService;
use Database\Seeders\AccountsSeeder;
use Database\Seeders\BookingSetupSeeder;
use Database\Seeders\RolesSeeder;
use Database\Seeders\UnitsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * تحكّم الموظفين: إسناد الدور والوحدات وربط ملف الموارد البشرية.
 */
class StaffAccessControlTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([RolesSeeder::class, UnitsSeeder::class, BookingSetupSeeder::class, AccountsSeeder::class]);

        $this->owner = User::factory()->create([
            'role_id' => Role::where('slug', 'super-admin')->firstOrFail()->id,
            'is_active' => true,
            'has_all_units' => true,
        ]);
    }

    public function test_creating_a_user_scoped_to_specific_units(): void
    {
        $units = Unit::limit(2)->pluck('id')->all();

        $this->actingAs($this->owner)->post('/admin/employees', [
            'name' => 'مشرف شاليه',
            'email' => 'supervisor@bsrah.test',
            'password' => 'Passw0rd!2026',
            'password_confirmation' => 'Passw0rd!2026',
            'role_id' => Role::where('slug', 'unit-supervisor')->value('id'),
            'is_active' => true,
            'has_all_units' => false,
            'unit_ids' => $units,
        ])->assertSessionHasNoErrors();

        $user = User::where('email', 'supervisor@bsrah.test')->firstOrFail();

        $this->assertFalse($user->has_all_units);
        $this->assertEqualsCanonicalizing($units, $user->accessibleUnitIds());
    }

    public function test_granting_all_units_clears_the_explicit_list(): void
    {
        $user = User::factory()->create([
            'role_id' => Role::where('slug', 'unit-supervisor')->value('id'),
            'is_active' => true,
            'has_all_units' => false,
        ]);
        $user->units()->sync(Unit::limit(3)->pluck('id')->all());

        $this->assertCount(3, $user->accessibleUnitIds());

        $this->actingAs($this->owner)->put("/admin/employees/{$user->id}", [
            'name' => $user->name,
            'email' => $user->email,
            'role_id' => $user->role_id,
            'is_active' => true,
            'has_all_units' => true,
        ])->assertSessionHasNoErrors();

        // بيانات ميتة لا تبقى: من يرى كل الوحدات لا يحتفظ بقائمة
        $this->assertTrue($user->fresh()->has_all_units);
        $this->assertSame(0, $user->fresh()->units()->count());
        $this->assertNull($user->fresh()->accessibleUnitIds());
    }

    public function test_scope_toggle_route_flips_and_clears(): void
    {
        $user = User::factory()->create(['is_active' => true, 'has_all_units' => false]);
        $user->units()->sync(Unit::limit(2)->pluck('id')->all());

        $this->actingAs($this->owner)->patch("/admin/employees/{$user->id}/scope")->assertSessionHasNoErrors();

        $this->assertTrue($user->fresh()->has_all_units);
        $this->assertSame(0, $user->fresh()->units()->count());
    }

    public function test_a_user_can_be_linked_to_one_employee_file_only(): void
    {
        $employee = Employee::create(['name' => 'سالم', 'basic_salary' => 3000, 'is_active' => true]);

        $this->actingAs($this->owner)->post('/admin/employees', [
            'name' => 'سالم', 'email' => 'salem@bsrah.test',
            'password' => 'Passw0rd!2026', 'password_confirmation' => 'Passw0rd!2026',
            'employee_id' => $employee->id, 'is_active' => true, 'has_all_units' => true,
        ])->assertSessionHasNoErrors();

        // ملف الموظف لا يُربط بحسابين — وإلا تضاعف راتبه في المسيّر
        $this->actingAs($this->owner)->post('/admin/employees', [
            'name' => 'حساب ثانٍ', 'email' => 'second@bsrah.test',
            'password' => 'Passw0rd!2026', 'password_confirmation' => 'Passw0rd!2026',
            'employee_id' => $employee->id, 'is_active' => true,
        ])->assertSessionHasErrors('employee_id');
    }

    public function test_employees_list_offers_only_unlinked_employee_files(): void
    {
        $linked = Employee::create(['name' => 'مرتبط', 'basic_salary' => 3000, 'is_active' => true]);
        Employee::create(['name' => 'غير مرتبط', 'basic_salary' => 3000, 'is_active' => true]);

        User::factory()->create(['employee_id' => $linked->id, 'is_active' => true]);

        $this->actingAs($this->owner)
            ->get('/admin/employees')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('employees', 1)
                ->where('employees.0.name', 'غير مرتبط')
                ->has('units', 8),
            );
    }

    public function test_scoped_user_sees_only_their_units_bookings_everywhere(): void
    {
        $mine = Unit::firstOrFail();
        $other = Unit::where('id', '!=', $mine->id)->firstOrFail();

        $user = User::factory()->create([
            'role_id' => Role::where('slug', 'unit-supervisor')->value('id'),
            'is_active' => true,
            'has_all_units' => false,
        ]);
        $user->units()->sync([$mine->id]);

        $service = app(BookingService::class);
        foreach ([$mine, $other] as $i => $unit) {
            $service->create([
                'unit_id' => $unit->id, 'scope' => 'whole',
                'booking_date' => '2026-12-0'.($i + 1), 'period' => 'full_day', 'status' => 'deposit_paid',
            ]);
        }

        $this->actingAs($user)->get('/admin/units')
            ->assertInertia(fn ($p) => $p->has('units', 1));

        $this->actingAs($user)->get('/admin/bookings/halls')
            ->assertInertia(fn ($p) => $p->has('bookings.data', 1));

        // التقويم يعرض شهرًا محددًا، والحجزان في ديسمبر
        $this->actingAs($user)->get('/admin/calendar/halls?month=2026-12')
            ->assertInertia(fn ($p) => $p->has('units', 1)->has('bookings', 1));
    }

    public function test_super_admin_is_never_restricted_by_unit_scope(): void
    {
        // حتى لو ضُبط has_all_units = false، الدور يتغلّب
        $this->owner->update(['has_all_units' => false]);

        $this->assertTrue($this->owner->fresh()->seesAllUnits());
        $this->assertNull($this->owner->fresh()->accessibleUnitIds());
    }
}
