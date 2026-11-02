<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Department;
use App\Models\Item;
use App\Models\JournalEntry;
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

    /**
     * «سلم 3 درج مورو»: opened with 58 instead of 2, set back to 2 by a
     * stocktake a minute later. The correction comes off capital, not off
     * the stocktake difference — it was never a shortage.
     */
    public function test_a_stocktake_that_corrected_a_mistyped_opening_moves_to_capital(): void
    {
        app(InventoryService::class)->opening($this->item, 58);
        app(InventoryService::class)->adjust($this->item->fresh(), 2);
        $this->assertSame(1120.0, $this->balanceOf(Ledger::STOCK_VARIANCE));

        $this->artisan('stock:correct-opening', ['items' => [$this->item->id], '--pretend' => true])
            ->expectsOutputToContain('shortage 1,120.00')
            ->assertSuccessful();
        $this->assertSame(1120.0, $this->balanceOf(Ledger::STOCK_VARIANCE));

        $this->artisan('stock:correct-opening', ['items' => [$this->item->id]])->assertSuccessful();

        // As if it had been opened with 2 all along.
        $this->assertSame(0.0, $this->balanceOf(Ledger::STOCK_VARIANCE));
        $this->assertSame(40.0, $this->balanceOf(Ledger::INVENTORY));
        $this->assertSame(40.0, $this->balanceOf(Ledger::CAPITAL));
        $this->assertSame(2.0, (float) $this->item->fresh()->stock_qty);

        // Once done, a second run refuses rather than moving it twice.
        $this->artisan('stock:correct-opening', ['items' => [$this->item->id]])->assertFailed();
        $this->assertSame(40.0, $this->balanceOf(Ledger::CAPITAL));
    }

    /** A count after the item has moved is a real count: its shortage stands. */
    public function test_a_real_stocktake_is_not_moved_to_capital(): void
    {
        app(InventoryService::class)->opening($this->item, 10);
        app(InventoryService::class)->move($this->item->fresh(), -2, 'issue');
        app(InventoryService::class)->adjust($this->item->fresh(), 5);

        $this->artisan('stock:correct-opening', ['items' => [$this->item->id]])->assertFailed();
        $this->assertSame(60.0, $this->balanceOf(Ledger::STOCK_VARIANCE));
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
