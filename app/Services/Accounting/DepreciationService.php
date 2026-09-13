<?php

namespace App\Services\Accounting;

use App\Models\AssetDepreciationEntry;
use App\Models\FixedAsset;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * تسجيل الأصول الثابتة وترحيل إهلاكها الشهري بالقسط الثابت.
 */
class DepreciationService
{
    public function __construct(private readonly Ledger $ledger) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function registerAsset(array $data, ?int $userId = null): FixedAsset
    {
        return FixedAsset::create([
            ...$data,
            'code' => $this->nextCode(),
            'status' => 'active',
            'created_by' => $userId,
        ]);
    }

    public function disposeAsset(FixedAsset $asset): void
    {
        if (! $asset->isActive()) {
            throw new RuntimeException('الأصل مستبعد بالفعل.');
        }

        $asset->update(['status' => 'disposed', 'disposed_at' => now()]);
    }

    /**
     * ترحيل إهلاك شهر واحد لكل الأصول النشطة التي لم يُرحَّل لها إهلاك عن
     * هذا الشهر بعد ولم يكتمل إهلاكها — بقيدٍ واحد مجمَّع لا قيد لكل أصل.
     *
     * @return array{entry_id: int|null, assets_count: int, total_amount: float}
     */
    public function postMonthlyDepreciation(string $period, ?int $userId = null): array
    {
        $period = Carbon::parse($period)->startOfMonth()->toDateString();

        $alreadyPosted = AssetDepreciationEntry::where('period', $period)->pluck('fixed_asset_id');

        $assets = FixedAsset::where('status', 'active')
            ->whereNotIn('id', $alreadyPosted)
            ->get()
            ->filter(fn (FixedAsset $a) => ! $a->isFullyDepreciated());

        if ($assets->isEmpty()) {
            throw new RuntimeException("لا أصول يُرحَّل لها إهلاك عن شهر {$period} — إما رُحِّلت بالفعل أو اكتمل إهلاكها.");
        }

        return DB::transaction(function () use ($assets, $period, $userId) {
            $lines = [];
            $amounts = [];
            $total = 0.0;

            foreach ($assets as $asset) {
                $remaining = round((float) $asset->cost - (float) $asset->salvage_value - $asset->accumulatedDepreciation(), 2);
                $amount = min($asset->monthlyDepreciation(), max(0.0, $remaining));

                if ($amount <= 0) {
                    continue;
                }

                $lines[] = [
                    'account' => Ledger::DEPRECIATION_EXPENSE,
                    'debit' => $amount,
                    'cost_center_id' => $asset->cost_center_id,
                    'description' => "إهلاك {$asset->name} ({$asset->code})",
                ];
                $amounts[$asset->id] = $amount;
                $total = round($total + $amount, 2);
            }

            if ($amounts === []) {
                throw new RuntimeException("لا أصول يُرحَّل لها إهلاك عن شهر {$period}.");
            }

            $lines[] = ['account' => Ledger::ACCUMULATED_DEPRECIATION, 'credit' => $total];

            $entry = $this->ledger->post(
                $period,
                "إهلاك شهر {$period}",
                $lines,
                'manual',
                null,
                $userId,
            );

            foreach ($amounts as $assetId => $amount) {
                AssetDepreciationEntry::create([
                    'fixed_asset_id' => $assetId,
                    'period' => $period,
                    'amount' => $amount,
                    'journal_entry_id' => $entry->id,
                ]);
            }

            return ['entry_id' => $entry->id, 'assets_count' => count($amounts), 'total_amount' => $total];
        });
    }

    private function nextCode(): string
    {
        $prefix = 'FA-'.now()->year.'-';

        $last = FixedAsset::withTrashed()
            ->where('code', 'like', $prefix.'%')
            ->orderByDesc('id')
            ->value('code');

        $next = $last ? ((int) substr($last, strlen($prefix))) + 1 : 1;

        return $prefix.str_pad((string) $next, 5, '0', STR_PAD_LEFT);
    }
}
