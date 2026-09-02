<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A booking states whether its invoice carries tax.
 *
 * Tax followed the VAT switch alone: register the business and every booking
 * was priced with it, whoever the guest was. But a hall let to a government
 * body or a charity is invoiced without tax while the one beside it is not,
 * and the screen had no way to say so — the operator had to fake it with a
 * discount, which then printed as a discount on the contract.
 *
 * The answer is stored with the booking, so the invoice reads it back instead
 * of asking the settings: a stay agreed without tax keeps its total after the
 * switch is turned on, exactly as one agreed with tax keeps its own after it
 * is turned off. Bookings already taken were all priced under the switch, so
 * that is what they keep.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->boolean('is_taxable')->default(true)->after('total_amount')
                ->comment('هل فاتورة الحجز بضريبة أم بدونها');
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropColumn('is_taxable');
        });
    }
};
