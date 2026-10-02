<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Department;
use App\Models\Item;
use App\Models\JournalEntry;
use App\Models\Role;
use App\Models\StockIssue;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\Accounting\Ledger;
use Database\Seeders\AccountsSeeder;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\DepartmentsSeeder;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * إذن الصرف: كلور يخرج مع الفني فينقص من الرصيد ويُقيَّد مصروفًا.
 */
class StockIssueTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Item $item;

    private Account $expense;

    private Department $pools;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([RolesSeeder::class, DepartmentsSeeder::class, AccountsSeeder::class, CatalogSeeder::class]);

        $this->admin = User::factory()->create([
            'role_id' => Role::where('slug', 'super-admin')->value('id'),
            'is_active' => true,
        ]);

        $this->item = Item::where('type', 'stock')->firstOrFail();
        $this->item->update(['stock_qty' => 0, 'cost' => 12.5]);
        app(\App\Services\InventoryService::class)->move($this->item, 20, 'opening');

        $this->expense = Account::where('code', '5330')->firstOrFail();
        $this->pools = Department::firstWhere('code', 'POOLS') ?? Department::firstOrFail();
    }

    private function payload(float $quantity): array
    {
        return [
            'issue_date' => now()->toDateString(),
            'department_id' => $this->pools->id,
            'employee_id' => null,
            'recipient_name' => 'الفني أحمد',
            'contract_id' => null,
            'expense_account_id' => $this->expense->id,
            'notes' => 'صيانة دورية',
            'items' => [['item_id' => $this->item->id, 'quantity' => $quantity]],
        ];
    }

    public function test_issue_deducts_stock_and_posts_an_expense_entry(): void
    {
        $this->actingAs($this->admin)
            ->post('/admin/inventory/issues', $this->payload(4))
            ->assertSessionHas('success');

        $this->assertSame(16.0, (float) $this->item->fresh()->stock_qty);

        $issue = StockIssue::firstOrFail();
        $this->assertSame(50.0, (float) $issue->total_cost);
        $this->assertSame(1, StockMovement::where('type', 'issue')->where('quantity', -4)->count());

        $entry = JournalEntry::with('lines.account')->findOrFail($issue->journal_entry_id);
        $this->assertSame('stock_issue', $entry->source);

        $debit = $entry->lines->firstWhere('debit', '>', 0);
        $credit = $entry->lines->firstWhere('credit', '>', 0);
        $this->assertSame('5330', $debit->account->code);
        $this->assertSame(Ledger::INVENTORY, $credit->account->code);
        $this->assertSame(50.0, (float) $debit->debit);
        $this->assertNotNull($debit->cost_center_id);
    }

    public function test_issue_beyond_the_balance_is_refused(): void
    {
        $this->actingAs($this->admin)
            ->post('/admin/inventory/issues', $this->payload(25))
            ->assertSessionHas('warning');

        $this->assertSame(20.0, (float) $this->item->fresh()->stock_qty);
        $this->assertSame(0, StockIssue::count());
    }

    public function test_cancelling_returns_the_stock_and_reverses_the_entry(): void
    {
        $this->actingAs($this->admin)->post('/admin/inventory/issues', $this->payload(4));
        $issue = StockIssue::firstOrFail();

        $this->actingAs($this->admin)
            ->post("/admin/inventory/issues/{$issue->id}/cancel", ['reason' => 'خطأ إدخال'])
            ->assertSessionHas('success');

        $this->assertSame(20.0, (float) $this->item->fresh()->stock_qty);
        $this->assertSame('cancelled', $issue->fresh()->status);
        $this->assertSame('reversed', $issue->journalEntry->fresh()->status);
    }

    public function test_recipient_is_required(): void
    {
        $payload = $this->payload(1);
        $payload['recipient_name'] = null;

        $this->actingAs($this->admin)
            ->post('/admin/inventory/issues', $payload)
            ->assertSessionHasErrors('employee_id');
    }

    public function test_the_screen_renders(): void
    {
        $this->actingAs($this->admin)->get('/admin/inventory/issues')->assertOk();

        $this->actingAs($this->admin)->post('/admin/inventory/issues', $this->payload(2));
        $this->actingAs($this->admin)->get('/admin/inventory/issues/'.StockIssue::firstOrFail()->id)->assertOk();
    }
}
