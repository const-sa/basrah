<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Which revenue account each stream of income lands on, chosen by the operator.
 *
 * Until now the answer was frozen in the code: every booking credited 4110 and
 * every invoice 4120, whatever the chart of accounts said. An accountant who
 * opened «إيرادات القاعات» and «إيرادات الشاليهات» as two accounts had no way
 * to send the money to them — the tree could be split, the postings could not.
 *
 * The row is the setting. Nothing else changes today: each stream is seeded
 * with the account it has always posted to, so the books read the same the
 * morning after this runs, and the screen shows what was true all along.
 */
return new class extends Migration
{
    /**
     * The streams and the account each has been posting to, written out here
     * rather than read from the class: a migration records what happened on
     * the day it ran, and must not shift when the list is edited later.
     */
    private const SEED = [
        'hall_bookings' => '4110',
        'chalet_bookings' => '4110',
        'pool_revenue' => '4120',
        'sales' => '4120',
        'security_forfeit' => '4110',
    ];

    public function up(): void
    {
        Schema::create('revenue_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('stream')->unique()->comment('مفتاح مصدر الإيراد — حجوزات القاعات، المبيعات، …');
            // A deleted account releases the mapping instead of taking the
            // setting down with it: the stream falls back to its default and
            // keeps posting, rather than refusing the sale.
            $table->foreignId('account_id')->nullable()->constrained('accounts')->nullOnDelete();
            $table->timestamps();
        });

        $codes = DB::table('accounts')->whereIn('code', array_values(self::SEED))->pluck('id', 'code');
        $now = now();

        foreach (self::SEED as $stream => $code) {
            DB::table('revenue_accounts')->insert([
                'stream' => $stream,
                'account_id' => $codes[$code] ?? null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('revenue_accounts');
    }
};
