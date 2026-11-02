<?php

namespace App\Console\Commands;

use App\Models\Item;
use App\Models\JournalEntry;
use App\Models\StockMovement;
use App\Services\Accounting\Ledger;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Turn a stocktake that only corrected a mistyped opening balance into what it
 * was: a correction of the opening balance, not a shortage.
 *
 * «سلم 3 درج مورو» was created with 58 on hand instead of 2, and a stocktake a
 * minute later set it back to 2. The shelf and the quantity are right, but the
 * books took the 58 in as the owner's capital and then charged the 56 that
 * never existed to the pools as a 19,600 shortage — a loss that never happened
 * on one side, capital that was never put in on the other.
 *
 * This reverses the stocktake's entry and posts the same amount against
 * capital instead, on the same date. Inventory is unchanged; the stock
 * movements stay as they are. Only where the stocktake is the item's very
 * next movement after its opening balance: once anything else has moved, a
 * count is a real count, and its shortage a real one.
 */
class CorrectOpeningStock extends Command
{
    protected $signature = 'stock:correct-opening
        {items* : Item ids whose first stocktake only corrected a mistyped opening balance}
        {--pretend : Show what would be moved without moving it}';

    protected $description = 'Move a stocktake that corrected a mistyped opening balance off the stocktake difference and onto capital';

    private float $total = 0.0;

    public function handle(Ledger $ledger): int
    {
        $failed = false;

        foreach ($this->argument('items') as $id) {
            $failed = ! $this->correct((int) $id, $ledger) || $failed;
        }

        $this->line('Total: '.number_format($this->total, 2));
        $this->comment($this->option('pretend') ? 'Pretend — nothing was changed.' : 'Done.');

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    private function correct(int $id, Ledger $ledger): bool
    {
        $item = Item::find($id);

        if (! $item) {
            return $this->refuse("item {$id}", 'no item by that id');
        }

        $ref = "item {$item->id} «{$item->name}»";
        [$opening, $count] = StockMovement::where('item_id', $item->id)->orderBy('id')->take(2)->get()->pad(2, null)->all();

        if ($opening?->type !== 'opening') {
            return $this->refuse($ref, 'its first movement is not an opening balance');
        }

        if ($count?->type !== 'adjustment') {
            return $this->refuse($ref, 'the movement after its opening balance is not a stocktake');
        }

        $entry = $count->journal_entry_id ? JournalEntry::with('lines')->find($count->journal_entry_id) : null;
        $variance = $ledger->accountId(Ledger::STOCK_VARIANCE);
        $line = $entry?->lines->firstWhere('account_id', $variance);

        if ($entry?->status !== 'posted' || ! $line) {
            return $this->refuse($ref, 'its stocktake has no standing stocktake-difference entry — already corrected?');
        }

        $shortage = (float) $count->quantity < 0;
        $amount = round((float) $line->debit + (float) $line->credit, 2);
        $centre = $line->cost_center_id;

        $this->line(sprintf(
            '%s: opened with %s, counted %s the same day — %s %s of entry %s moves to capital.',
            $ref,
            rtrim(rtrim((string) $opening->quantity, '0'), '.'),
            rtrim(rtrim((string) $count->balance_after, '0'), '.'),
            $shortage ? 'shortage' : 'surplus',
            number_format($amount, 2),
            $entry->number,
        ));
        $this->total += $amount;

        if ($this->option('pretend')) {
            return true;
        }

        DB::transaction(function () use ($ledger, $item, $count, $entry, $shortage, $amount, $centre) {
            $reason = "تصحيح الرصيد الافتتاحي للصنف {$item->name} — لم يكن عجزًا";
            $ledger->reverse($entry, $reason);

            $capital = ['account' => Ledger::CAPITAL, 'cost_center_id' => $centre];
            $stock = ['account' => Ledger::INVENTORY, 'cost_center_id' => $centre];

            $corrected = $ledger->post(
                $entry->entry_date->toDateString(),
                "تصحيح الرصيد الافتتاحي — {$item->name}",
                $shortage
                    ? [[...$capital, 'debit' => $amount], [...$stock, 'credit' => $amount]]
                    : [[...$stock, 'debit' => $amount], [...$capital, 'credit' => $amount]],
                'stock_issue',
                $count,
            );

            $count->update(['journal_entry_id' => $corrected->id]);
        });

        return true;
    }

    private function refuse(string $ref, string $why): bool
    {
        $this->error("{$ref}: {$why}");

        return false;
    }
}
