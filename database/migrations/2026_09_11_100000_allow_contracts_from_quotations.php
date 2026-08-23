<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Let a contract be drawn from a quotation instead of a booking.
 *
 * A contract could only exist as generated from a booking, which left the
 * pools business (sales, installation, maintenance) with no contracts at all:
 * there is no booking there to generate one from — its agreement starts as a
 * quotation the client accepts. So the booking becomes optional and a
 * quotation takes its place as the other possible source: hall and chalet
 * contracts stay bound to their booking exactly as before, and a pools
 * contract carries the same numbering, statuses, PDF and WhatsApp delivery.
 */
return new class extends Migration
{
    public function up(): void
    {
        // The foreign key is dropped before the column changes and restored
        // after: MySQL will not modify a column while a constraint holds it.
        Schema::table('contracts', function (Blueprint $table) {
            $table->dropForeign(['booking_id']);
        });

        Schema::table('contracts', function (Blueprint $table) {
            $table->foreignId('booking_id')->nullable()->change();

            $table->foreign('booking_id')->references('id')->on('bookings')->cascadeOnDelete();
        });

        Schema::table('contracts', function (Blueprint $table) {
            // nullOnDelete, not cascade: a signed contract is the client's copy
            // of what was agreed and must outlive the quotation it was drawn
            // from. Everything it prints is in its own frozen snapshot, so it
            // survives losing the link intact.
            $table->foreignId('quotation_id')->nullable()->after('booking_id')
                ->constrained('quotations')->nullOnDelete();

            $table->index(['quotation_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('contracts', function (Blueprint $table) {
            $table->dropIndex(['quotation_id', 'status']);
            $table->dropConstrainedForeignId('quotation_id');
        });

        // Contracts with no booking are dropped before the column goes back to
        // NOT NULL — the database would refuse the change over their null rows.
        Schema::table('contracts', function (Blueprint $table) {
            $table->dropForeign(['booking_id']);
        });

        \Illuminate\Support\Facades\DB::table('contracts')->whereNull('booking_id')->delete();

        Schema::table('contracts', function (Blueprint $table) {
            $table->foreignId('booking_id')->nullable(false)->change();

            $table->foreign('booking_id')->references('id')->on('bookings')->cascadeOnDelete();
        });
    }
};
