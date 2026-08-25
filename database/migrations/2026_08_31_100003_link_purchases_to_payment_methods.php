<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The purchases table carries a payment_method_id from the day it was created,
 * but payment_methods itself only becomes a table later in the order. The
 * column therefore waited here for the constraint that gives it meaning.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchases', function (Blueprint $table) {
            $table->foreign('payment_method_id')
                ->references('id')->on('payment_methods')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('purchases', function (Blueprint $table) {
            $table->dropForeign(['payment_method_id']);
        });
    }
};
