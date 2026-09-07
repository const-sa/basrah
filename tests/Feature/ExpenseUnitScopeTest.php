<?php

namespace Tests\Feature;

use App\Models\CostCenter;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\PaymentMethod;
use App\Models\Role;
use App\Models\Treasury;
use App\Models\Unit;
use App\Models\User;
use Database\Seeders\AccountsSeeder;
use Database\Seeders\DepartmentsSeeder;
use Database\Seeders\RolesSeeder;
use Database\Seeders\UnitsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * المصروف يُسجَّل حيث يقع: كل نشاطٍ سجلّه، وكل موظفٍ وحداته.
 */
class ExpenseUnitScopeTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Treasury $treasury;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([RolesSeeder::class, UnitsSeeder::class, DepartmentsSeeder::class, AccountsSeeder::class]);

        $this->owner = User::factory()->create([
            'role_id' => Role::where('slug', 'super-admin')->firstOrFail()->id,
            'is_active' => true,
            'has_all_units' => true,
        ]);

        $this->treasury = Treasury::where('is_active', true)->firstOrFail();
    }

    private function unitOfType(string $type): Unit
    {
        return Unit::where('type', $type)->firstOrFail();
    }

    /**
     * A supervisor restricted to one unit — the case the whole feature is for.
     */
    private function supervisorOf(Unit $unit): User
    {
        $user = User::factory()->create([
            'role_id' => Role::where('slug', 'unit-supervisor')->value('id'),
            'is_active' => true,
            'has_all_units' => false,
        ]);
        $user->units()->sync([$unit->id]);

        return $user;
    }

    private function spendOn(?CostCenter $center, float $amount = 100): Expense
    {
        return Expense::create([
            'number' => 'EXP-'.fake()->unique()->numberBetween(1000, 999999),
            'expense_date' => now()->toDateString(),
            'expense_category_id' => ExpenseCategory::where('code', 'electricity')->firstOrFail()->id,
            'amount' => $amount,
            'cost_center_id' => $center?->id,
            'treasury_id' => $this->treasury->id,
            'payment_method_id' => PaymentMethod::where('is_active', true)->firstOrFail()->id,
            'status' => 'posted',
        ]);
    }

    public function test_a_scoped_user_sees_only_the_spend_of_his_own_units(): void
    {
        $mine = $this->unitOfType('hall');
        $other = Unit::where('id', '!=', $mine->id)->firstOrFail();

        $this->spendOn(CostCenter::forUnit($mine));
        $this->spendOn(CostCenter::forUnit($other));
        // ما لا مركز له لا نشاط له، فلا يظهر للمقيَّد بوحدة
        $this->spendOn(null);

        $this->actingAs($this->supervisorOf($mine))->get('/admin/accounting/expenses')
            ->assertInertia(fn ($p) => $p->has('expenses.data', 1)->where('scoped', true));

        $this->actingAs($this->owner)->get('/admin/accounting/expenses')
            ->assertInertia(fn ($p) => $p->has('expenses.data', 3)->where('scoped', false));
    }

    public function test_the_totals_count_what_the_screen_shows(): void
    {
        $mine = $this->unitOfType('hall');
        $other = Unit::where('id', '!=', $mine->id)->firstOrFail();

        $this->spendOn(CostCenter::forUnit($mine), 300);
        $this->spendOn(CostCenter::forUnit($other), 900);

        $this->actingAs($this->supervisorOf($mine))->get('/admin/accounting/expenses')
            ->assertInertia(fn ($p) => $p
                ->where('stats.total', 300)
                // المربّع الشهري يتجاهل التواريخ لا النطاق
                ->where('stats.month', 300));
    }

    public function test_a_scoped_user_cannot_charge_another_unit(): void
    {
        $mine = $this->unitOfType('hall');
        $other = Unit::where('id', '!=', $mine->id)->firstOrFail();

        $this->actingAs($this->supervisorOf($mine))
            ->post('/admin/accounting/expenses', $this->payload(CostCenter::forUnit($other)->id))
            ->assertSessionHasErrors('cost_center_id');

        $this->assertSame(0, Expense::count());
    }

    public function test_a_scoped_user_cannot_leave_the_expense_unattributed(): void
    {
        $mine = $this->unitOfType('hall');

        // بلا مركز يعني بلا نشاط، وهو باب الالتفاف على النطاق لو تُرك مفتوحًا
        $this->actingAs($this->supervisorOf($mine))
            ->post('/admin/accounting/expenses', $this->payload(null))
            ->assertSessionHasErrors('cost_center_id');

        $this->assertSame(0, Expense::count());
    }

    public function test_a_scoped_user_records_the_spend_of_his_own_unit(): void
    {
        $mine = $this->unitOfType('hall');

        $this->actingAs($this->supervisorOf($mine))
            ->post('/admin/accounting/expenses', $this->payload(CostCenter::forUnit($mine)->id))
            ->assertSessionHasNoErrors();

        $this->assertSame(1, Expense::where('cost_center_id', CostCenter::forUnit($mine)->id)->count());
    }

    public function test_an_expense_outside_the_scope_is_not_touched_by_its_id(): void
    {
        $mine = $this->unitOfType('hall');
        $other = Unit::where('id', '!=', $mine->id)->firstOrFail();

        $theirs = $this->spendOn(CostCenter::forUnit($other), 900);
        // مسوّدة، ليكون المنع من النطاق لا من كونها مرحَّلة
        $theirs->update(['status' => 'draft']);

        // القائمة تُخفيه، والمعرّف في الرابط ليس بابًا خلفيًا إليه —
        // والمشرف يملك expenses.edit، فالردّ من النطاق لا من الصلاحية
        $this->actingAs($this->supervisorOf($mine))
            ->put("/admin/accounting/expenses/{$theirs->id}", $this->payload(CostCenter::forUnit($mine)->id))
            ->assertForbidden();

        $this->assertSame(900.0, (float) $theirs->fresh()->amount);
    }

    public function test_a_recorder_who_may_not_approve_leaves_a_draft(): void
    {
        $mine = $this->unitOfType('hall');
        $supervisor = $this->supervisorOf($mine);

        $this->assertTrue($supervisor->hasPermission('expenses.create'));
        $this->assertFalse($supervisor->hasPermission('expenses.approve'));

        // خانة «ترحيل فوري» لا تمنحه دفترًا حجبته عنه صلاحيته
        $this->actingAs($supervisor)
            ->post('/admin/accounting/expenses', array_merge($this->payload(CostCenter::forUnit($mine)->id), ['post_now' => true]))
            ->assertSessionHasNoErrors();

        $this->assertSame('draft', Expense::firstOrFail()->status);
    }

    public function test_each_activity_opens_its_own_register(): void
    {
        $hall = $this->unitOfType('hall');
        $chalet = $this->unitOfType('chalet');
        $pools = Department::where('code', 'POOLS')->firstOrFail();

        $this->spendOn(CostCenter::forUnit($hall), 100);
        $this->spendOn(CostCenter::forUnit($chalet), 200);
        $this->spendOn(CostCenter::forDepartment($pools), 400);

        foreach ([
            '/admin/halls/expenses' => ['halls', 100],
            '/admin/chalets/expenses' => ['chalets', 200],
            '/admin/pools/expenses' => ['pools', 400],
        ] as $url => [$activity, $total]) {
            $this->actingAs($this->owner)->get($url)
                ->assertInertia(fn ($p) => $p
                    ->where('activity', $activity)
                    ->has('expenses.data', 1)
                    ->where('stats.total', $total));
        }

        // ودفتر المحاسب يبقى جامعًا للثلاثة
        $this->actingAs($this->owner)->get('/admin/accounting/expenses')
            ->assertInertia(fn ($p) => $p->where('activity', null)->has('expenses.data', 3));
    }

    public function test_the_pinned_register_offers_only_that_activity_centres(): void
    {
        $this->actingAs($this->owner)->get('/admin/chalets/expenses')
            ->assertInertia(fn ($p) => $p->where(
                'costCenters',
                fn ($centers) => collect($centers)->every(fn ($c) => $c['segment'] === 'chalets') && count($centers) > 0,
            ));
    }

    /**
     * المسابح ليست وحدة، فنطاق موظفها قسمه في ملف الموارد البشرية.
     */
    public function test_a_pools_employee_is_scoped_by_his_department(): void
    {
        $pools = Department::where('code', 'POOLS')->firstOrFail();
        $poolCenter = CostCenter::forDepartment($pools);

        $this->spendOn($poolCenter, 500);
        $this->spendOn(CostCenter::forUnit($this->unitOfType('hall')), 700);

        $employee = Employee::create([
            'name' => 'موظف المسابح',
            'basic_salary' => 2000,
            'is_active' => true,
            'department_id' => $pools->id,
        ]);

        $user = User::factory()->create([
            'role_id' => Role::where('slug', 'unit-supervisor')->value('id'),
            'is_active' => true,
            'has_all_units' => false,
            'employee_id' => $employee->id,
        ]);

        $this->assertSame([$poolCenter->id], $user->accessibleCostCenterIds());

        $this->actingAs($user)->get('/admin/pools/expenses')
            ->assertInertia(fn ($p) => $p->has('expenses.data', 1)->where('stats.total', 500));
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(?int $costCenterId): array
    {
        return [
            'expense_date' => now()->toDateString(),
            'amount' => 120,
            'expense_category_id' => ExpenseCategory::where('code', 'electricity')->firstOrFail()->id,
            'treasury_id' => $this->treasury->id,
            'payment_method_id' => PaymentMethod::where('is_active', true)->firstOrFail()->id,
            'cost_center_id' => $costCenterId,
            'description' => 'فاتورة كهرباء',
            'post_now' => false,
        ];
    }
}
