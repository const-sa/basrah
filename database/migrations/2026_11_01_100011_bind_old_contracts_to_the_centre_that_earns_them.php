<?php

use App\Models\Contract;
use App\Services\Accounting\ContractAccounting;
use Illuminate\Database\Migrations\Migration;

/**
 * Freeze every existing contract onto the centre it earns on.
 *
 * The centre was worked out afresh on every receipt — from the quotation's
 * department, else the booking's unit, else the form it is printed on. A
 * quotation re-pointed at another department would take the next receipt
 * somewhere the last one did not go, and a direct sheet on an unpinned
 * template fell through to «عام» silently.
 *
 * Idempotent by the null check, and it posts nothing: this is classification,
 * not an entry. Entries already written keep the centre they were written on.
 */
return new class extends Migration
{
    public function up(): void
    {
        $accounting = app(ContractAccounting::class);

        Contract::query()
            ->whereNull('cost_center_id')
            ->with(['quotation.department', 'booking.unit', 'unit'])
            ->chunkById(200, function ($contracts) use ($accounting) {
                foreach ($contracts as $contract) {
                    $accounting->bindCostCenter($contract);
                }
            });
    }

    public function down(): void
    {
        // Nothing to undo: the column goes with its own migration, and the
        // answer written here is the one the code would work out anyway.
    }
};
