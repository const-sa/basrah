<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The reversal of a purchase is a movement type the ledger knows.
 *
 * Editing or deleting a purchase invoice returns its items to the supplier's
 * side by writing a negative 'purchase_revert' movement — a name the column
 * never allowed. The insert failed the check constraint, the transaction rolled
 * back, and every edit came back with a warning and nothing saved.
 *
 * It is kept apart from a plain 'adjustment': a stocktake correction and the
 * undoing of an invoice read the same in the ledger otherwise, and only one of
 * them means an item never arrived.
 */
return new class extends Migration
{
    private const TYPES = [
        'purchase', 'purchase_revert', 'sale', 'return',
        'adjustment', 'bundle_consume', 'opening',
    ];

    private const OLD_TYPES = ['purchase', 'sale', 'return', 'adjustment', 'bundle_consume', 'opening'];

    public function up(): void
    {
        Schema::table('stock_movements', function (Blueprint $table) {
            $table->enum('type', self::TYPES)
                ->comment('شراء / إلغاء شراء / بيع / مرتجع / تسوية جرد / خصم مكوّنات حزمة / رصيد افتتاحي')
                ->change();
        });
    }

    public function down(): void
    {
        // A reversal already recorded keeps its effect on the balance; it goes
        // back under the nearest surviving name rather than being deleted.
        DB::table('stock_movements')->where('type', 'purchase_revert')->update(['type' => 'adjustment']);

        Schema::table('stock_movements', function (Blueprint $table) {
            $table->enum('type', self::OLD_TYPES)
                ->comment('شراء / بيع / مرتجع / تسوية جرد / خصم مكوّنات حزمة / رصيد افتتاحي')
                ->change();
        });
    }
};
