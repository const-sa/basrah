<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Give a contract the cost centre it earns on, and the day it was delivered.
 *
 * A contract's centre was worked out afresh every time a receipt was written
 * for it — from its quotation's department, else its booking's unit, else the
 * form it was printed on. That answer can move under a contract that is
 * already posted: a quotation re-pointed at another department would take the
 * next receipt to a different centre than the last. Frozen at signing, it
 * cannot.
 *
 * completed_at is what recognizes an installation's revenue. The work is
 * delivered when the pool is handed over, not when the paper is signed —
 * the terms themselves say half at signing and half when the equipment is
 * ordered, so signing is demonstrably not delivery.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contracts', function (Blueprint $table) {
            // nullOnDelete: a posted contract outlives a centre that was
            // stopped, and its entries keep the centre they were written on.
            $table->foreignId('cost_center_id')->nullable()->after('unit_id')
                ->constrained('cost_centers')->nullOnDelete();

            $table->timestamp('completed_at')->nullable()->after('signed_at');
        });
    }

    public function down(): void
    {
        Schema::table('contracts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('cost_center_id');
            $table->dropColumn('completed_at');
        });
    }
};
