<?php

use App\Models\Contract;
use App\Services\Accounting\ContractAccounting;
use Illuminate\Database\Migrations\Migration;

/**
 * Undo the revenue «تم التنفيذ» booked on pools installations.
 *
 * Handover booked the whole contract value as revenue, the unpaid part as a
 * debt on the client. The client wants an installation's revenue to be what
 * was actually collected, each payment on its own date, so handover is now a
 * mark on the contract only.
 *
 * Every handover entry still standing is reversed on its own date. The
 * receipts it had absorbed are earned again on theirs, and the payments that
 * settled its debt are undone and earned as revenue instead. The handover
 * date on the contract stays. Running it twice changes nothing.
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
                    if (! $contract->isInstallationForm() || ! $accounting->standingEntry($contract)) {
                        continue;
                    }

                    $accounting->reverseRevenue($contract, "إلغاء إثبات إيراد العقد {$contract->number} عند التنفيذ");
                }
            });
    }

    public function down(): void
    {
        // The reversals stay; booking revenue on handover again is a decision.
    }
};
