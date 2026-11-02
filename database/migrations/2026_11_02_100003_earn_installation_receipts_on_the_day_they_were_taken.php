<?php

use App\Models\Contract;
use App\Services\Accounting\ContractAccounting;
use Illuminate\Database\Migrations\Migration;

/**
 * Earn the receipts already taken on pools installations, each on its own date.
 *
 * An installation used to earn nothing until «تم التنفيذ», while the costs of
 * building the pool reached the pools the day they were spent — so the pools
 * read a loss through every job in progress and a jump in the month it was
 * handed over. The client chose to earn each payment as it is received: it
 * pays for the stage of work done, and is kept even if the job is cancelled.
 *
 * This applies that to the receipts taken before the change. Contracts already
 * handed over are left alone — their receipts were earned by the handover —
 * and so are contracts invoiced from their quotation, which earn on the
 * invoice. Idempotent: a receipt already moved out of deposits is skipped.
 */
return new class extends Migration
{
    public function up(): void
    {
        $accounting = app(ContractAccounting::class);

        Contract::query()
            ->orderBy('id')
            ->chunkById(200, function ($contracts) use ($accounting) {
                foreach ($contracts as $contract) {
                    if (! $contract->isInstallationForm()
                        || $accounting->standingEntry($contract)
                        || $accounting->invoiceFor($contract)) {
                        continue;
                    }

                    $accounting->earnHeldReceipts($contract);
                }
            });
    }

    public function down(): void
    {
        // The entries stay; undoing a policy is a decision, not a rollback.
    }
};
