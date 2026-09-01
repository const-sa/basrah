<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A purchase invoice states whether it carries tax.
 *
 * Tax used to be an unavoidable consequence of the items on the invoice: any
 * item with a rate added its share, so a delivery note from an unregistered
 * supplier was billed as if it had charged VAT. Whether tax is due belongs to
 * the invoice, not to the catalogue — the same item is bought with tax from
 * one supplier and without it from another.
 *
 * Existing invoices were all priced with tax, so that is what they keep.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchases', function (Blueprint $table) {
            $table->boolean('is_taxable')->default(true)->after('tax_amount')
                ->comment('هل الفاتورة بضريبة أم بدونها');
        });
    }

    public function down(): void
    {
        Schema::table('purchases', function (Blueprint $table) {
            $table->dropColumn('is_taxable');
        });
    }
};
