<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A sales invoice states whether it carries tax.
 *
 * Tax used to be an unavoidable consequence of the items sold: any item with a
 * rate added its share, so a sale to an exempt buyer — a diplomatic mission, a
 * charity, an export order — was billed as if VAT were due and the cashier had
 * no way to say otherwise. Whether tax is due belongs to the invoice, not to
 * the catalogue.
 *
 * Existing invoices were all issued with tax, so that is what they keep: a
 * return then inherits the answer of the invoice it reverses, and neither
 * changes because the column arrived.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->boolean('is_taxable')->default(true)->after('tax_amount')
                ->comment('هل الفاتورة بضريبة أم بدونها');
        });
    }

    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->dropColumn('is_taxable');
        });
    }
};
