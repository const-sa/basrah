<?php

namespace App\Console\Commands;

use App\Models\Purchase;
use App\Services\PurchaseService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Post the purchases that never reached the books.
 *
 * Purchases moved stock and wrote no entry, for as long as there have been
 * purchases. So inventory has only ever been credited — by the cost of goods
 * sold on each invoice — and has been drifting below zero ever since;
 * supplier payables have never existed; and money paid to suppliers never
 * left the treasury. The trial balance is wrong by the whole of it.
 *
 * Deliberately a command and not a migration: this moves the trial balance
 * materially and writes into months that are closed. It is run once, after
 * --pretend has been read and a backup taken, not silently on deploy.
 */
class BackfillPurchaseEntries extends Command
{
    protected $signature = 'accounting:backfill-purchases
        {--pretend : List what would be posted without posting it}';

    protected $description = 'Post purchase invoices that were never written to the ledger';

    public function handle(PurchaseService $books): int
    {
        $pending = Purchase::whereNull('journal_entry_id')->count();

        if ($pending === 0) {
            $this->info('Every purchase is already in the books.');

            return self::SUCCESS;
        }

        $pretend = (bool) $this->option('pretend');
        $this->warn("{$pending} purchase invoice(s) have never been posted.");

        $posted = 0;
        $failed = 0;
        $total = 0.0;

        Purchase::whereNull('journal_entry_id')
            ->with(['department', 'supplier'])
            ->orderBy('id')
            ->chunkById(200, function ($chunk) use ($books, $pretend, &$posted, &$failed, &$total) {
                foreach ($chunk as $purchase) {
                    $total = round($total + (float) $purchase->total_amount, 2);

                    if ($pretend) {
                        $date = ($purchase->purchase_date ?? $purchase->created_at)->toDateString();
                        $this->line("  {$purchase->number} — {$date} — ".number_format((float) $purchase->total_amount, 2));

                        continue;
                    }

                    try {
                        $books->post($purchase);
                        $posted++;
                    } catch (Throwable $e) {
                        // One invoice must not take the rest down with it.
                        $failed++;
                        $this->error("Could not post {$purchase->number}: {$e->getMessage()}");
                        report($e);
                    }
                }
            });

        $this->info($pretend
            ? 'Would post '.number_format($total, 2).' across '.$pending.' invoice(s).'
            : "Posted {$posted} invoice(s)".($failed > 0 ? ", {$failed} failed." : '.'));

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
