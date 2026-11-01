<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Two more kinds of paper that reach the books: a contract, and a purchase.
 *
 * Both are posted from here on — a pools contract recognizes its revenue, and
 * a purchase finally debits the stock it brought in. The column is an enum, so
 * a posting with a name it does not hold is refused by the database and takes
 * the whole transaction down with it: the name has to be opened before any
 * code writes it.
 */
return new class extends Migration
{
    private const SOURCES = [
        'manual', 'booking', 'payment', 'sale', 'expense',
        'voucher', 'payroll', 'stock_issue', 'contract', 'purchase',
    ];

    private const OLD_SOURCES = [
        'manual', 'booking', 'payment', 'sale', 'expense',
        'voucher', 'payroll', 'stock_issue',
    ];

    public function up(): void
    {
        Schema::table('journal_entries', function (Blueprint $table) {
            $table->enum('source', self::SOURCES)->default('manual')->change();
        });
    }

    public function down(): void
    {
        // The rows have to lose the name before the column does.
        DB::table('journal_entries')->whereIn('source', ['contract', 'purchase'])->update(['source' => 'manual']);

        Schema::table('journal_entries', function (Blueprint $table) {
            $table->enum('source', self::OLD_SOURCES)->default('manual')->change();
        });
    }
};
