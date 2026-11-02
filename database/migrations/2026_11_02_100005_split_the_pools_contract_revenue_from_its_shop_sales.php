<?php

use App\Models\Account;
use App\Services\Accounting\PoolContractEntries;
use App\Services\Accounting\RevenueAccounts;
use Illuminate\Database\Migrations\Migration;

/**
 * Give the pools' contract work its own revenue account.
 *
 * Installation and maintenance contracts credited «إيرادات المبيعات» like the
 * pools shop did, so the client could not tell what the contracts earned from
 * what the shop sold. They now credit 4140 «إيرادات عقود المسابح».
 *
 * This opens the account and moves the contract revenue already posted onto
 * it: entries on a contract, receipts written on one, invoices raised off a
 * quotation, and their reversals. Totals do not change — only which of the
 * two revenue accounts holds them. Running it twice moves nothing.
 */
return new class extends Migration
{
    public function up(): void
    {
        $parent = Account::where('code', '4100')->value('id');

        if (! $parent) {
            return;
        }

        Account::firstOrCreate(['code' => '4140'], [
            'name' => 'إيرادات عقود المسابح',
            'parent_id' => $parent,
            'type' => 'revenue',
            'is_group' => false,
            'is_active' => true,
        ]);

        app(RevenueAccounts::class)->forget();
        app(PoolContractEntries::class)->moveToContractsAccount();
    }

    public function down(): void
    {
        // The lines stay on the contracts' account; moving them back is a
        // choice for the accounting settings, not a rollback.
    }
};
