<?php

namespace App\Http\Resources;

use App\Models\Item;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One item as the invoice and quotation pickers expect it.
 *
 * The purchase form appends a line straight from whatever the item search or
 * the quick-add endpoint returns, so those two payloads have to agree field for
 * field. Keeping the shape in one class makes that a compile-time fact instead
 * of a convention someone has to remember when editing either endpoint.
 *
 * The richer item payloads — the items screen, the POS grid — deliberately do
 * not use this: they carry stock, unit and type columns a picker has no use for.
 *
 * @mixin Item
 */
class ItemOptionResource extends JsonResource
{
    /** Inertia props are consumed as bare objects — no "data" envelope. */
    public static $wrap = null;

    /**
     * @param  bool  $withCost  purchase lines price by cost; sales lines never see it
     */
    public function __construct($resource, private readonly bool $withCost = false)
    {
        parent::__construct($resource);
    }

    /**
     * Map a whole collection, since JsonResource::collection() cannot carry the flag.
     *
     * @param  iterable<int, Item>  $items
     * @return list<array<string, mixed>>
     */
    public static function list(iterable $items, bool $withCost = false): array
    {
        return collect($items)
            ->map(fn (Item $item) => (new self($item, $withCost))->resolve())
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'category' => $this->category?->name,
            'price' => (float) $this->price,
            'tax_rate' => (float) $this->tax_rate,
            ...($this->withCost ? ['cost' => (float) $this->cost] : []),
        ];
    }
}
