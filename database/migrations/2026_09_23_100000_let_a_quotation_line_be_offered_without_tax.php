<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quotation_items', function (Blueprint $table) {
            $table->boolean('is_taxable')->default(true)->after('total_price')
                ->comment('هل يحمل هذا البند ضريبة');
            $table->decimal('tax_amount', 15, 2)->default(0)->after('is_taxable');
        });

        $this->backfillLineTax();
    }

    public function down(): void
    {
        Schema::table('quotation_items', function (Blueprint $table) {
            $table->dropColumn(['is_taxable', 'tax_amount']);
        });
    }

    private function backfillLineTax(): void
    {
        DB::table('quotations')->where('tax_amount', '>', 0)->orderBy('id')
            ->chunkById(200, function ($quotations) {
                foreach ($quotations as $quotation) {
                    $lines = DB::table('quotation_items')
                        ->where('quotation_id', $quotation->id)
                        ->orderBy('id')
                        ->get(['id', 'total_price']);

                    $base = (float) $lines->sum('total_price');

                    if ($base <= 0) {
                        continue;
                    }

                    $offerTax = round((float) $quotation->tax_amount, 2);
                    $spent = 0.0;
                    $last = $lines->count() - 1;

                    foreach ($lines as $index => $line) {
                        $share = $index === $last
                            ? round($offerTax - $spent, 2)
                            : round($offerTax * (float) $line->total_price / $base, 2);

                        $spent = round($spent + $share, 2);

                        DB::table('quotation_items')->where('id', $line->id)
                            ->update(['tax_amount' => max(0, $share)]);
                    }
                }
            });
    }
};
