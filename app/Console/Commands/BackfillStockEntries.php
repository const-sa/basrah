<?php

namespace App\Console\Commands;

use App\Models\StockMovement;
use App\Services\Accounting\StockAccounting;
use Illuminate\Console\Command;
use Throwable;

/**
 * Capitalise the opening stock, and charge the stocktakes that were never charged.
 *
 * Posting the purchases lifted the inventory account but did not close it: the
 * stock the business started with was never capitalised, and every difference
 * a stocktake found left the warehouse without being charged to anyone. Until
 * both are posted, the account stays below the stock report no matter how many
 * purchases go in.
 *
 * Deliberately a command and not a migration: it writes into closed months and
 * moves both the inventory account and capital. Run it after --pretend has
 * been read and a backup taken.
 */
class BackfillStockEntries extends Command
{
    protected $signature = 'accounting:backfill-stock
        {--pretend : List what would be posted without posting it}';

    protected $description = 'Post opening balances and stocktake differences that never reached the ledger';

    public function handle(StockAccounting $books): int
    {
        $pending = StockMovement::whereNull('journal_entry_id')
            ->whereIn('type', StockAccounting::POSTS)
            ->count();

        if ($pending === 0) {
            $this->info('Every opening balance and stocktake is already in the books.');

            return self::SUCCESS;
        }

        $pretend = (bool) $this->option('pretend');
        $this->warn("{$pending} movement(s) have never been posted.");

        $posted = 0;
        $failed = 0;
        $byType = [];

        StockMovement::whereNull('journal_entry_id')
            ->whereIn('type', StockAccounting::POSTS)
            ->with('item.department')
            ->orderBy('id')
            ->chunkById(200, function ($chunk) use ($books, $pretend, &$posted, &$failed, &$byType) {
                foreach ($chunk as $movement) {
                    $value = round(abs((float) $movement->quantity) * (float) $movement->unit_cost, 2);
                    $key = $movement->type === 'opening'
                        ? 'opening'
                        : ((float) $movement->quantity < 0 ? 'shortage' : 'surplus');

                    $byType[$key] = round(($byType[$key] ?? 0) + $value, 2);

                    if ($pretend) {
                        continue;
                    }

                    try {
                        $books->post($movement);
                        $posted++;
                    } catch (Throwable $e) {
                        // One movement must not take the rest down with it.
                        $failed++;
                        $this->error("Could not post movement {$movement->id}: {$e->getMessage()}");
                        report($e);
                    }
                }
            });

        foreach ($byType as $kind => $value) {
            $this->line(str_pad($kind, 10).number_format($value, 2));
        }

        $this->info($pretend
            ? "Would post {$pending} movement(s)."
            : "Posted {$posted} movement(s)".($failed > 0 ? ", {$failed} failed." : '.'));

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
