<?php

use App\Models\Account;
use App\Models\Contract;
use App\Models\JournalLine;
use App\Models\Voucher;
use App\Services\Accounting\ContractAccounting;
use App\Services\Accounting\Ledger;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Earn the monthly-maintenance receipts that were parked as deposits.
 *
 * A maintenance sheet is paid at the start of each month for that month, so
 * its receipt is revenue the day it is taken — the way ContractReceipts has
 * written it since 1 November. Every receipt before that credited unearned
 * revenue, and nothing will ever clear it: a maintenance contract has no
 * handover to recognize. Those receipts sat in «عرابين مقدمًا» on the pools
 * for good, and the pools read a loss against money already earned.
 *
 * Each such receipt is rewritten as it would be written today: its voucher
 * and the credit line of its entry move from unearned revenue to the pools'
 * revenue account. Amount, date, centre and till are untouched, and a receipt
 * cancelled later reverses the corrected entry, so nothing is left behind.
 */
return new class extends Migration
{
    public function up(): void
    {
        $unearned = Account::where('code', Ledger::UNEARNED_REVENUE)->value('id');

        if (! $unearned) {
            return;
        }

        $accounting = app(ContractAccounting::class);

        Voucher::query()
            ->where('status', 'posted')
            ->where('type', 'receipt')
            ->whereNotNull('contract_id')
            ->where('account_id', $unearned)
            ->with('contract')
            ->orderBy('id')
            ->chunkById(200, function ($vouchers) use ($accounting, $unearned) {
                foreach ($vouchers as $voucher) {
                    /** @var Contract|null $contract */
                    $contract = $voucher->contract;

                    if (! $contract?->isMaintenanceForm()) {
                        continue;
                    }

                    $revenue = $accounting->revenueAccountFor($contract);

                    DB::transaction(function () use ($voucher, $unearned, $revenue) {
                        $voucher->update(['account_id' => $revenue]);

                        if ($voucher->journal_entry_id) {
                            JournalLine::where('journal_entry_id', $voucher->journal_entry_id)
                                ->where('account_id', $unearned)
                                ->update(['account_id' => $revenue]);
                        }
                    });
                }
            });
    }

    public function down(): void
    {
        // Not reversed: the receipts are earned, and parking them back in a
        // liability nothing clears is the fault this corrects.
    }
};
