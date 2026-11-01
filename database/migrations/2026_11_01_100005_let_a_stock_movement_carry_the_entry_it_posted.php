<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tie a stock movement to the entry it wrote.
 *
 * Two kinds of movement never reached the books: the opening balance an item
 * is created with, and the difference a stocktake finds. So the stock the
 * business started with was never capitalised, and shrinkage was never
 * charged — which is why the inventory account sits below the stock report
 * however many purchases are posted against it.
 *
 * The column is what keeps the backfill idempotent, and what lets a movement
 * be traced to its entry afterwards.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_movements', function (Blueprint $table) {
            $table->foreignId('journal_entry_id')->nullable()->after('reference_id')
                ->constrained('journal_entries')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('stock_movements', function (Blueprint $table) {
            $table->dropConstrainedForeignId('journal_entry_id');
        });
    }
};
