<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\CostCenter;
use App\Models\JournalEntry;
use App\Models\Role;
use App\Models\Unit;
use App\Models\User;
use App\Services\Accounting\Ledger;
use Database\Seeders\AccountsSeeder;
use Database\Seeders\RolesSeeder;
use Database\Seeders\UnitsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Cost centre screen: per-centre profit, its statement, and system centres kept safe.
 */
class CostCentersTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([RolesSeeder::class, UnitsSeeder::class, AccountsSeeder::class]);

        $this->owner = User::factory()->create([
            'role_id' => Role::where('slug', 'super-admin')->firstOrFail()->id,
            'is_active' => true,
            'has_all_units' => true,
        ]);
    }

    public function test_index_shows_each_centre_revenue_expense_and_profit(): void
    {
        $centre = CostCenter::create(['code' => 'CC-T1', 'name' => 'قاعة الاختبار', 'is_active' => true]);

        $this->postEntry($centre, revenue: 1000, expense: 400);

        $this->actingAs($this->owner)
            ->get('/admin/accounting/cost-centers?search=CC-T1')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('admin/accounting/CostCenters')
                ->where('centers.data.0.name', 'قاعة الاختبار')
                ->where('centers.data.0.revenue', 1000)
                ->where('centers.data.0.expense', 400)
                ->where('centers.data.0.profit', 600)
                ->where('totals.profit', 600),
            );
    }

    public function test_a_draft_entry_does_not_reach_the_profit(): void
    {
        $centre = CostCenter::create(['code' => 'CC-T2', 'name' => 'مركز المسوّدة', 'is_active' => true]);

        $this->postEntry($centre, revenue: 700, expense: 100, status: 'draft');

        $this->actingAs($this->owner)
            ->get('/admin/accounting/cost-centers?search=CC-T2')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('centers.data.0.profit', 0));
    }

    public function test_show_renders_the_statement_with_its_account_breakdown(): void
    {
        $centre = CostCenter::create(['code' => 'CC-T3', 'name' => 'مركز الكشف', 'is_active' => true]);

        $this->postEntry($centre, revenue: 800, expense: 300);

        $this->actingAs($this->owner)
            ->get("/admin/accounting/cost-centers/{$centre->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('admin/accounting/CostCenterStatement')
                ->where('statement.profit', 500)
                ->where('statement.total_debit', 1100.0)
                ->has('breakdown', 2),
            );
    }

    public function test_export_streams_a_csv(): void
    {
        $centre = CostCenter::create(['code' => 'CC-T4', 'name' => 'مركز التصدير', 'is_active' => true]);

        $this->actingAs($this->owner)
            ->get("/admin/accounting/cost-centers/{$centre->id}/export")
            ->assertOk()
            ->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
    }

    public function test_a_manual_centre_is_created_with_a_generated_code(): void
    {
        $this->actingAs($this->owner)
            ->post('/admin/accounting/cost-centers', ['name' => 'مركز يدوي'])
            ->assertRedirect();

        $this->assertSame('CC-M1', CostCenter::where('name', 'مركز يدوي')->value('code'));
    }

    public function test_a_system_centre_keeps_its_code_but_may_be_renamed(): void
    {
        $centre = CostCenter::forUnit(Unit::where('code', 'HALL-01')->firstOrFail());

        $this->actingAs($this->owner)
            ->put("/admin/accounting/cost-centers/{$centre->id}", [
                'name' => 'اسم جديد', 'code' => 'CC-CHANGED', 'is_active' => true,
            ])
            ->assertSessionHas('warning');

        $this->assertSame($centre->code, $centre->fresh()->code);

        $this->actingAs($this->owner)
            ->put("/admin/accounting/cost-centers/{$centre->id}", [
                'name' => 'اسم جديد', 'code' => $centre->code, 'is_active' => true,
            ])
            ->assertSessionHas('success');

        $this->assertSame('اسم جديد', $centre->fresh()->name);
    }

    public function test_a_system_centre_is_not_deleted(): void
    {
        $centre = CostCenter::general();

        $this->actingAs($this->owner)
            ->delete("/admin/accounting/cost-centers/{$centre->id}")
            ->assertSessionHas('warning');

        $this->assertModelExists($centre);
    }

    public function test_a_centre_carrying_entries_is_not_deleted(): void
    {
        $centre = CostCenter::create(['code' => 'CC-T5', 'name' => 'مركز عليه قيود', 'is_active' => true]);

        $this->postEntry($centre, revenue: 100, expense: 0);

        $this->actingAs($this->owner)
            ->delete("/admin/accounting/cost-centers/{$centre->id}")
            ->assertSessionHas('warning');

        $this->assertModelExists($centre);
    }

    public function test_an_unused_manual_centre_is_deleted(): void
    {
        $centre = CostCenter::create(['code' => 'CC-T6', 'name' => 'مركز فارغ', 'is_active' => true]);

        $this->actingAs($this->owner)
            ->delete("/admin/accounting/cost-centers/{$centre->id}")
            ->assertSessionHas('success');

        $this->assertModelMissing($centre);
    }

    public function test_a_cashier_without_the_permission_is_forbidden(): void
    {
        $cashier = User::factory()->create([
            'role_id' => Role::where('slug', 'cashier')->firstOrFail()->id,
            'is_active' => true,
            'has_all_units' => true,
        ]);

        $this->actingAs($cashier)->get('/admin/accounting/cost-centers')->assertForbidden();
    }

    /**
     * One entry on the centre: revenue against cash, expense against cash.
     */
    private function postEntry(CostCenter $centre, float $revenue, float $expense, string $status = 'posted'): void
    {
        $cash = Account::where('code', Ledger::CASH)->value('id');

        JournalEntry::create([
            'number' => 'JV-CC-'.$centre->id.'-'.$status,
            'entry_date' => now()->toDateString(),
            'description' => 'قيد اختبار مركز التكلفة',
            'status' => $status,
            'source' => 'manual',
            'total_debit' => $revenue + $expense,
            'total_credit' => $revenue + $expense,
        ])->lines()->createMany([
            ['account_id' => $cash, 'cost_center_id' => $centre->id, 'debit' => $revenue, 'credit' => 0],
            ['account_id' => Account::where('code', Ledger::BOOKING_REVENUE)->value('id'), 'cost_center_id' => $centre->id, 'debit' => 0, 'credit' => $revenue],
            ['account_id' => Account::where('code', Ledger::GENERAL_EXPENSE)->value('id'), 'cost_center_id' => $centre->id, 'debit' => $expense, 'credit' => 0],
            ['account_id' => $cash, 'cost_center_id' => $centre->id, 'debit' => 0, 'credit' => $expense],
        ]);
    }
}
