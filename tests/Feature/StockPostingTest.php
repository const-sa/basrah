<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Department;
use App\Models\Item;
use App\Models\StockMovement;
use App\Services\Accounting\Ledger;
use App\Services\InventoryService;
use Database\Seeders\AccountsSeeder;
use Database\Seeders\DepartmentsSeeder;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The opening balance and the stocktake reach the books.
 *
 * Both wrote a movement and nothing else, so the stock the business started
 * with was never capitalised and every shortage left the warehouse without
 * being charged — which kept the inventory account below the stock report
 * however many purchases were posted against it.
 */
class StockPostingTest extends TestCase
{
    use RefreshDatabase;

    private Item $item;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([RolesSeeder::class, DepartmentsSeeder::class, AccountsSeeder::class]);

        $this->item = Item::create([
            'department_id' => Department::firstOrFail()->id,
            'code' => 'IT-TEST',
            'name' => 'كلور',
            'type' => 'stock',
            'cost' => 20,
            'price' => 35,
            'is_active' => true,
        ]);
    }

    public function test_an_opening_balance_is_capitalised_against_capital(): void
    {
        app(InventoryService::class)->opening($this->item, 10);

        // What the owner put in, not a debt to a supplier who does not exist.
        $this->assertSame(200.0, $this->balanceOf(Ledger::INVENTORY));
        $this->assertSame(200.0, $this->balanceOf(Ledger::CAPITAL));
        $this->assertSame(0.0, $this->balanceOf(Ledger::PAYABLES));
    }

    public function test_a_shortage_found_by_a_stocktake_is_charged(): void
    {
        app(InventoryService::class)->opening($this->item, 10);
        app(InventoryService::class)->adjust($this->item->fresh(), 7);

        $this->assertSame(140.0, $this->balanceOf(Ledger::INVENTORY));
        $this->assertSame(60.0, $this->balanceOf(Ledger::STOCK_VARIANCE));
    }

    public function test_a_surplus_reduces_the_charge_rather_than_inventing_income(): void
    {
        app(InventoryService::class)->opening($this->item, 10);
        app(InventoryService::class)->adjust($this->item->fresh(), 12);

        $this->assertSame(240.0, $this->balanceOf(Ledger::INVENTORY));
        $this->assertSame(-40.0, $this->balanceOf(Ledger::STOCK_VARIANCE));
    }

    public function test_a_count_that_matches_the_book_posts_nothing(): void
    {
        app(InventoryService::class)->opening($this->item, 10);
        app(InventoryService::class)->adjust($this->item->fresh(), 10);

        $this->assertSame(1, StockMovement::whereNotNull('journal_entry_id')->count());
    }

    public function test_the_backfill_posts_what_was_left_behind_once(): void
    {
        app(InventoryService::class)->opening($this->item, 10);

        // As the movements stood before any of this existed: stock on the
        // shelf with no entry behind it. Deleting the entry nulls the column
        // on its own, which is what the real data looks like.
        JournalEntry::query()->delete();

        $this->artisan('accounting:backfill-stock')->assertSuccessful();
        $this->artisan('accounting:backfill-stock')->assertSuccessful();

        // Twice run, once posted: the column is what makes it idempotent.
        $this->assertSame(200.0, $this->balanceOf(Ledger::INVENTORY));
        $this->assertSame(1, JournalEntry::count());
    }

    private function balanceOf(string $code): float
    {
        return (float) Account::where('code', $code)->firstOrFail()->balance();
    }
}
