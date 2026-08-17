<?php

namespace App\Services;

use App\Models\Item;
use App\Models\StockMovement;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * حركة المخزون — المنفذ الوحيد لتغيير رصيد أي صنف.
 *
 * تمرير كل تغيير من هنا يضمن أن عمود stock_qty وجدول الحركات
 * لا يفترقان أبدًا، وهو الفرق بين مخزون يُوثق به ومخزون يُخمَّن.
 */
class InventoryService
{
    /**
     * تسجيل حركة وتحديث الرصيد.
     *
     * @param  float  $quantity  موجب يزيد الرصيد وسالب ينقصه
     */
    public function move(
        Item $item,
        float $quantity,
        string $type,
        ?Model $reference = null,
        ?int $userId = null,
        ?float $unitCost = null,
        ?string $notes = null,
    ): ?StockMovement {
        // الخدمي والحزمة لا رصيد لهما — الحزمة تخصم مكوّناتها لا نفسها.
        if (! $item->tracksStock()) {
            return null;
        }

        return DB::transaction(function () use ($item, $quantity, $type, $reference, $userId, $unitCost, $notes) {
            $locked = Item::lockForUpdate()->findOrFail($item->id);

            $balance = round((float) $locked->stock_qty + $quantity, 3);

            $movement = StockMovement::create([
                'item_id' => $locked->id,
                'user_id' => $userId,
                'type' => $type,
                'quantity' => round($quantity, 3),
                'unit_cost' => $unitCost ?? (float) $locked->cost,
                'balance_after' => $balance,
                'reference_type' => $reference ? $reference::class : null,
                'reference_id' => $reference?->getKey(),
                'notes' => $notes,
            ]);

            $locked->update(['stock_qty' => $balance]);

            return $movement;
        });
    }

    /**
     * خصم صنف من المخزون عند البيع.
     * الحزمة تُفكَّك إلى مكوّناتها، فيُخصم كل مكوّن بكميته مضروبة في كمية الحزمة.
     *
     * @return list<StockMovement>
     */
    public function consumeForSale(Item $item, float $quantity, ?Model $reference = null, ?int $userId = null): array
    {
        if ($item->isBundle()) {
            $moves = [];

            foreach ($item->components as $component) {
                $needed = (float) $component->pivot->quantity * $quantity;

                $move = $this->move(
                    $component,
                    -$needed,
                    'bundle_consume',
                    $reference,
                    $userId,
                    null,
                    "خصم مكوّن ضمن الحزمة «{$item->name}»",
                );

                if ($move) {
                    $moves[] = $move;
                }
            }

            return $moves;
        }

        $move = $this->move($item, -$quantity, 'sale', $reference, $userId);

        return $move ? [$move] : [];
    }

    /**
     * إعادة صنف للمخزون عند المرتجع.
     *
     * @return list<StockMovement>
     */
    public function restoreForReturn(Item $item, float $quantity, ?Model $reference = null, ?int $userId = null): array
    {
        if ($item->isBundle()) {
            $moves = [];

            foreach ($item->components as $component) {
                $move = $this->move(
                    $component,
                    (float) $component->pivot->quantity * $quantity,
                    'return',
                    $reference,
                    $userId,
                    null,
                    "إرجاع مكوّن ضمن الحزمة «{$item->name}»",
                );

                if ($move) {
                    $moves[] = $move;
                }
            }

            return $moves;
        }

        $move = $this->move($item, $quantity, 'return', $reference, $userId);

        return $move ? [$move] : [];
    }

    /**
     * تسوية جرد: ضبط الرصيد على المعدود فعليًا وتسجيل الفرق.
     */
    public function adjust(Item $item, float $countedQty, ?int $userId = null, ?string $notes = null): ?StockMovement
    {
        if (! $item->tracksStock()) {
            throw new RuntimeException("الصنف «{$item->name}» غير مخزني فلا يُجرد.");
        }

        $difference = round($countedQty - (float) $item->stock_qty, 3);

        if (abs($difference) < 0.001) {
            return null;
        }

        return $this->move(
            $item,
            $difference,
            'adjustment',
            null,
            $userId,
            null,
            $notes ?? 'تسوية جرد: المعدود '.$countedQty.' مقابل الدفتري '.$item->stock_qty,
        );
    }

    /**
     * التحقق من كفاية الرصيد قبل البيع.
     * الحزمة تُفحص على مكوّناتها لا على نفسها.
     *
     * @return array{ok: bool, reason: string|null}
     */
    public function checkAvailability(Item $item, float $quantity): array
    {
        if ($item->isBundle()) {
            foreach ($item->components as $component) {
                $needed = (float) $component->pivot->quantity * $quantity;

                if ($component->tracksStock() && (float) $component->stock_qty < $needed) {
                    return [
                        'ok' => false,
                        'reason' => "رصيد المكوّن «{$component->name}» غير كافٍ: المتاح {$component->stock_qty} والمطلوب {$needed}.",
                    ];
                }
            }

            return ['ok' => true, 'reason' => null];
        }

        if ($item->tracksStock() && (float) $item->stock_qty < $quantity) {
            return [
                'ok' => false,
                'reason' => "رصيد «{$item->name}» غير كافٍ: المتاح {$item->stock_qty} والمطلوب {$quantity}.",
            ];
        }

        return ['ok' => true, 'reason' => null];
    }
}
