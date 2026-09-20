<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where each stream of income is deposited — the other half of the setting.
 *
 * Choosing the revenue account settled where income is *earned*: 4110, 4120,
 * or whatever the accountant split them into. It said nothing about where the
 * money *lands*, and that answer was frozen in two constants: cash at 1110,
 * everything else at 1120. A business banking the halls with one bank and the
 * pools with another could open the two accounts in the tree and then watch
 * every riyal from both pile onto «البنك».
 *
 * The column is the choice. Left null — as every row starts — the money keeps
 * following the payment method exactly as before, so the books read the same
 * the morning after this runs.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('revenue_accounts', function (Blueprint $table) {
            // Null is a meaning here, not an absence: «بحسب طريقة الدفع».
            // A deleted account releases the mapping rather than taking the
            // till down with it — the stream goes back to following the
            // payment method and the cashier keeps ringing sales up.
            $table->foreignId('deposit_account_id')
                ->nullable()
                ->after('account_id')
                ->constrained('accounts')
                ->nullOnDelete()
                ->comment('حساب الأصول الذي يُودع فيه مقبوض هذا المصدر — فارغ: بحسب طريقة الدفع');
        });
    }

    public function down(): void
    {
        Schema::table('revenue_accounts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('deposit_account_id');
        });
    }
};
