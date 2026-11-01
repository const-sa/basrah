<?php

use App\Models\Account;
use Illuminate\Database\Migrations\Migration;

/**
 * Where a stocktake's difference goes.
 *
 * Counting the shelves against the book has always written a movement and
 * nothing else, so a shortage was taken out of the stock column and charged
 * to nobody — the goods left the warehouse and the cost left the books with
 * them. It belongs in cost of sales: it is a cost of the goods, not an
 * overhead of the month.
 *
 * One account takes both sides. A shortage debits it and a surplus credits
 * it, so a count that finds more than the book says reduces the charge
 * instead of inventing income.
 */
return new class extends Migration
{
    public function up(): void
    {
        Account::firstOrCreate(['code' => '5120'], [
            'name' => 'عجز وفائض الجرد',
            'parent_id' => Account::where('code', '5100')->value('id'),
            'type' => 'expense',
            'is_group' => false,
            'is_active' => true,
        ]);
    }

    public function down(): void
    {
        $account = Account::where('code', '5120')->first();

        // Only while nothing was ever posted on it.
        if ($account && ! $account->lines()->exists()) {
            $account->delete();
        }
    }
};
