<?php

namespace App\Services\Accounting;

use App\Models\CostCenter;
use App\Models\JournalEntry;
use App\Models\StockMovement;
use Illuminate\Support\Facades\DB;

/**
 * The two stock movements that never reached the books.
 *
 * A purchase posts, a sale posts its cost, an issue posts — but the opening
 * balance an item is created with, and the difference a stocktake finds,
 * wrote a movement and nothing more. So the stock the business started with
 * was never capitalised, and every shortage left the warehouse without ever
 * being charged to anyone. The inventory account sat below the stock report
 * by the whole of both, and no amount of posting purchases could close it.
 *
 *   opening    Dr inventory / Cr capital — what the owner put in, not a debt
 *   shortage   Dr stocktake difference / Cr inventory
 *   surplus    Dr inventory / Cr stocktake difference
 */
class StockAccounting
{
    /** The kinds this class answers for. The rest post where they are made. */
    public const POSTS = ['opening', 'adjustment'];

    public function __construct(private readonly Ledger $ledger) {}

    public function post(StockMovement $movement, ?int $userId = null): ?JournalEntry
    {
        if ($movement->journal_entry_id || ! in_array($movement->type, self::POSTS, true)) {
            return null;
        }

        $value = round(abs((float) $movement->quantity) * (float) $movement->unit_cost, 2);

        if ($value <= 0) {
            return null;
        }

        $movement->loadMissing('item.department');
        $centre = $movement->item?->department
            ? CostCenter::forDepartment($movement->item->department)->id
            : CostCenter::general()->id;

        $lines = $this->lines($movement, $value, $centre);

        return DB::transaction(function () use ($movement, $lines, $userId) {
            $entry = $this->ledger->post(
                $movement->created_at->toDateString(),
                $this->describe($movement),
                $lines,
                'stock_issue',
                $movement,
                $userId ?? $movement->user_id,
            );

            $movement->update(['journal_entry_id' => $entry->id]);

            return $entry;
        });
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function lines(StockMovement $movement, float $value, ?int $centre): array
    {
        // An opening balance is what the owner put in, not something owed to a
        // supplier: there is no invoice behind it and nobody to ever pay.
        if ($movement->type === 'opening') {
            return [
                ['account' => Ledger::INVENTORY, 'debit' => $value, 'cost_center_id' => $centre],
                ['account' => Ledger::CAPITAL, 'credit' => $value, 'cost_center_id' => $centre],
            ];
        }

        return (float) $movement->quantity < 0
            ? [
                ['account' => Ledger::STOCK_VARIANCE, 'debit' => $value, 'cost_center_id' => $centre],
                ['account' => Ledger::INVENTORY, 'credit' => $value, 'cost_center_id' => $centre],
            ]
            : [
                ['account' => Ledger::INVENTORY, 'debit' => $value, 'cost_center_id' => $centre],
                ['account' => Ledger::STOCK_VARIANCE, 'credit' => $value, 'cost_center_id' => $centre],
            ];
    }

    private function describe(StockMovement $movement): string
    {
        $name = $movement->item?->name ?? 'صنف';

        return match (true) {
            $movement->type === 'opening' => "رصيد افتتاحي — {$name}",
            (float) $movement->quantity < 0 => "عجز جرد — {$name}",
            default => "فائض جرد — {$name}",
        };
    }
}
