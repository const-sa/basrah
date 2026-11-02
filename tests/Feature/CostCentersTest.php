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
                ->where('statement.total_debit', 1100)
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
     * A hall let by room: revenue on its rooms, expense on itself.
     *
     * Reading the hall's centre alone showed it as all loss — expense with no
     * income — and the room as all profit, out of the same money.
     */
    public function test_a_unit_row_carries_what_its_rooms_earned(): void
    {
        [$unitCentre, $roomCentre] = $this->hallWithARoom();

        $this->postEntry($unitCentre, revenue: 0, expense: 400);
        $this->postEntry($roomCentre, revenue: 1000, expense: 0);

        $this->actingAs($this->owner)
            ->get('/admin/accounting/cost-centers?search='.$unitCentre->code)
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('centers.data.0.revenue', 1000)
                ->where('centers.data.0.expense', 400)
                ->where('centers.data.0.profit', 600)
                // Totals read parent rows alone, or a room's revenue counts twice.
                ->where('totals.revenue', 1000)
                ->where('totals.profit', 600)
                ->etc(),
            );
    }

    public function test_a_room_is_nested_under_its_unit_and_never_a_row_of_its_own(): void
    {
        [$unitCentre, $roomCentre] = $this->hallWithARoom();

        $this->postEntry($roomCentre, revenue: 1000, expense: 0);

        $this->actingAs($this->owner)
            ->get('/admin/accounting/cost-centers?search='.$unitCentre->code)
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('centers.data.0.is_section', false)
                ->where('centers.data.0.children', fn ($children) => collect($children)
                    ->contains(fn ($child) => (int) $child['id'] === $roomCentre->id
                        && (float) $child['revenue'] === 1000.0
                        && $child['is_section'] === true))
                ->etc(),
            );

        // And a room is never a row of its own, summed on top of its unit.
        $this->actingAs($this->owner)
            ->get('/admin/accounting/cost-centers?search='.$roomCentre->code)
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('centers.data', [])->etc());
    }

    public function test_searching_a_room_finds_the_unit_that_holds_it(): void
    {
        [$unitCentre, $roomCentre] = $this->hallWithARoom();

        $this->actingAs($this->owner)
            ->get('/admin/accounting/cost-centers?search='.urlencode($roomCentre->section->name))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                // Every unit has a room by this name, so ours must be among
                // them and every row must be a unit, not a room.
                ->where('centers.data', fn ($rows) => collect($rows)->contains(fn ($row) => (int) $row['id'] === $unitCentre->id)
                    && collect($rows)->every(fn ($row) => $row['is_section'] === false))
                ->etc(),
            );
    }

    /**
     * A seeded hall, its own centre, and the centre of one of its rooms.
     *
     * @return array{0: CostCenter, 1: CostCenter}
     */
    private function hallWithARoom(): array
    {
        $unit = Unit::whereHas('sections')->firstOrFail();
        $section = $unit->sections()->firstOrFail();

        return [CostCenter::forUnit($unit), CostCenter::forSection($section)->load('section')];
    }

    /**
     * One entry on the centre: revenue against cash, expense against cash.
     */
    /**
     * «مش موضحلي ايه الأرقام بالتفصيل»: a hall's page lists the lines behind
     * its figures — its rooms' revenue among them, each naming its room — and
     * reads the same totals as the row that was clicked to reach it.
     */
    public function test_a_units_statement_details_its_rooms_lines_and_matches_its_row(): void
    {
        [$unitCentre, $roomCentre] = $this->hallWithARoom();

        $this->postEntry($unitCentre, revenue: 0, expense: 400);
        $this->postEntry($roomCentre, revenue: 1000, expense: 0);

        $room = $roomCentre->section?->name;

        $this->actingAs($this->owner)
            ->get("/admin/accounting/cost-centers/{$unitCentre->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('statement.revenue', 1000)
                ->where('statement.expense', 400)
                ->where('statement.profit', 600)
                ->where('statement.has_rooms', true)
                ->where('statement.rows', fn ($rows) => collect($rows)->contains(fn ($r) => $r['account_type'] === 'revenue'
                    && (float) $r['credit'] === 1000.0
                    && $r['centre'] === $room
                    && $r['label'] === 'قيد اختبار مركز التكلفة'))
                ->etc(),
            );
    }

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
