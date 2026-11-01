<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Let a purchase reach the books.
 *
 * A purchase moved stock and nothing else: no entry was ever written for one.
 * So inventory was only ever credited — by the cost of goods sold on each
 * invoice — and never debited by what was bought, which drove the account
 * steadily negative. Supplier payables had never been recorded at all, and
 * money paid to a supplier never left the treasury in the books.
 *
 * journal_entry_id is also what keeps the backfill idempotent: a purchase that
 * already carries one is not posted twice.
 *
 * purchase_date is kept apart from created_at so a supplier's invoice entered
 * a week late still books in the month it was actually issued.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchases', function (Blueprint $table) {
            $table->foreignId('journal_entry_id')->nullable()->after('paid_amount')
                ->constrained('journal_entries')->nullOnDelete();

            $table->date('purchase_date')->nullable()->after('number');
        });

        DB::table('purchases')->whereNull('purchase_date')->update([
            'purchase_date' => DB::raw('DATE(created_at)'),
        ]);
    }

    public function down(): void
    {
        Schema::table('purchases', function (Blueprint $table) {
            $table->dropConstrainedForeignId('journal_entry_id');
            $table->dropColumn('purchase_date');
        });
    }
};
