<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The constraint on purchases.payment_method_id was repaired inside the very
 * migration that creates it, and a database that had already recorded that
 * migration never reads the file again — so the repair reaches a fresh install
 * and nothing else. This one converges any database on the same end state,
 * whatever route it took to get here: orphan references emptied, the
 * constraint present exactly once.
 */
return new class extends Migration
{
    public function up(): void
    {
        // A purchase pointing at a payment method that no longer exists. The
        // row itself is sound — only its reference is missing — so it is
        // emptied rather than deleted, which is what the constraint would have
        // done on delete anyway. MySQL refuses the constraint while one of
        // these is present (errno 1452).
        DB::table('purchases')
            ->whereNotNull('payment_method_id')
            ->whereNotIn('payment_method_id', DB::table('payment_methods')->select('id'))
            ->update(['payment_method_id' => null]);

        // Both routes to the constraint end in the same name, so adding a
        // second one is a duplicate-name error, not a harmless repeat: the
        // column carried an inline constrained() when purchases was first
        // created, before that was deferred to a migration of its own.
        if ($this->hasForeignKey()) {
            return;
        }

        Schema::table('purchases', function (Blueprint $table) {
            $table->foreign('payment_method_id')
                ->references('id')->on('payment_methods')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        // Nothing to undo. The emptied references cannot be restored, and the
        // constraint belongs to the migration that introduces it — dropping it
        // here would leave that one with nothing to roll back.
    }

    private function hasForeignKey(): bool
    {
        foreach (Schema::getForeignKeys('purchases') as $foreignKey) {
            if (in_array('payment_method_id', $foreignKey['columns'], true)) {
                return true;
            }
        }

        return false;
    }
};
