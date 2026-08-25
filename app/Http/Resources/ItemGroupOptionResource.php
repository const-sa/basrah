<?php

namespace App\Http\Resources;

use App\Models\Item;
use App\Models\ItemGroup;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One saved item group as the invoice, quotation and purchase pickers expect it.
 *
 * The members are rendered through ItemOptionResource, so a line appended from
 * a group is byte-for-byte the same shape as a line appended from the item
 * search. That is what lets all three forms reuse one insertion path.
 *
 * @mixin ItemGroup
 */
class ItemGroupOptionResource extends JsonResource
{
    /** Inertia props are consumed as bare objects — no "data" envelope. */
    public static $wrap = null;

    /**
     * @param  bool  $withCost  purchase lines price by cost; sales lines never see it
     * @param  list<int>|null  $allowedItemIds  restrict members to items the screen can actually use
     */
    public function __construct(
        $resource,
        private readonly bool $withCost = false,
        private readonly ?array $allowedItemIds = null,
    ) {
        parent::__construct($resource);
    }

    /**
     * Map a whole collection, since JsonResource::collection() cannot carry the flags.
     *
     * A group left with no usable member is dropped rather than offered: picking
     * it would add nothing and read as a broken button.
     *
     * @param  iterable<int, ItemGroup>  $groups
     * @return list<array<string, mixed>>
     */
    public static function list(iterable $groups, bool $withCost = false, ?array $allowedItemIds = null): array
    {
        return collect($groups)
            ->map(fn (ItemGroup $group) => (new self($group, $withCost, $allowedItemIds))->resolve())
            ->filter(fn (array $group) => $group['items'] !== [])
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $items = $this->items
            ->where('is_active', true)
            ->when(
                $this->allowedItemIds !== null,
                fn ($members) => $members->whereIn('id', $this->allowedItemIds),
            );

        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'items' => ItemOptionResource::list($items->all(), $this->withCost),
            /** Members dropped as out of scope — the picker says so instead of silently adding fewer lines. */
            'skipped_count' => $this->items->where('is_active', true)->count() - $items->count(),
        ];
    }

    /**
     * The ids a screen may offer, or null for no restriction.
     *
     * @param  iterable<int, Item>  $items
     * @return list<int>
     */
    public static function idsOf(iterable $items): array
    {
        return collect($items)->pluck('id')->map(fn ($id) => (int) $id)->values()->all();
    }
}
