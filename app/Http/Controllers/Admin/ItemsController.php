<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Concerns\StatesFilters;
use App\Http\Controllers\Controller;
use App\Http\Resources\ItemOptionResource;
use App\Models\Department;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\MeasureUnit;
use App\Models\StockMovement;
use App\Services\InventoryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * الأصناف والمخزون والجرد.
 */
class ItemsController extends Controller
{
    use StatesFilters;

    public function __construct(private readonly InventoryService $inventory) {}

    public function index(Request $request): Response
    {
        $query = Item::with(['category:id,name', 'department:id,name', 'measureUnit:id,name,allows_fraction', 'components:id,name'])
            ->when($request->string('type')->toString(), fn ($q, $t) => $q->where('type', $t))
            ->when($request->integer('department_id'), fn ($q, $id) => $q->where('department_id', $id))
            ->when($request->integer('category_id'), fn ($q, $id) => $q->where('item_category_id', $id))
            ->when($request->boolean('low_stock'), fn ($q) => $q->lowStock())
            ->when($request->string('search')->toString(), fn ($q, $term) => $q->where(
                fn ($sub) => $sub->where('name', 'like', "%{$term}%")
                    ->orWhere('code', 'like', "%{$term}%")
                    ->orWhere('barcode', 'like', "%{$term}%"),
            ));

        return Inertia::render('admin/items/Index', [
            'items' => (clone $query)->orderBy('name')->paginate(25)->withQueryString()
                ->through(fn (Item $i) => [
                    'id' => $i->id,
                    'code' => $i->code,
                    'barcode' => $i->barcode,
                    'name' => $i->name,
                    'category' => $i->category?->name,
                    'category_id' => $i->item_category_id,
                    'department' => $i->department?->name,
                    'department_id' => $i->department_id,
                    'type' => $i->type,
                    'type_label' => $i->typeLabel(),
                    'unit' => $i->unit,
                    'measure_unit_id' => $i->measure_unit_id,
                    'unit_label' => $i->unitLabel(),
                    'cost' => (float) $i->cost,
                    'price' => (float) $i->price,
                    'tax_rate' => (float) $i->tax_rate,
                    'stock_qty' => (float) $i->stock_qty,
                    'reorder_point' => (float) $i->reorder_point,
                    'tracks_stock' => $i->tracksStock(),
                    'low_stock' => $i->isBelowReorderPoint(),
                    'is_active' => $i->is_active,
                    'components' => $i->components->map(fn ($c) => [
                        'id' => $c->id, 'name' => $c->name, 'quantity' => (float) $c->pivot->quantity,
                    ])->values(),
                ]),
            'filters' => [
                ...$this->filterState($request, ['type', 'category_id', 'department_id', 'search']),
                // The tick reads a real boolean. Returned as the string "true"
                // it would sit unticked while the filter it names is in force.
                'low_stock' => $request->boolean('low_stock'),
            ],
            'categories' => ItemCategory::orderBy('name')->get(['id', 'name']),
            'departments' => Department::where('is_active', true)->orderBy('sort_order')->get(['id', 'name']),
            'types' => collect(Item::TYPES)->map(fn ($l, $k) => ['key' => $k, 'label' => $l])->values(),
            'measureUnits' => MeasureUnit::where('is_active', true)->orderBy('sort_order')
                ->get(['id', 'name', 'symbol', 'allows_fraction']),
            'stockItems' => Item::whereIn('type', Item::STOCKED_TYPES)->where('is_active', true)
                ->orderBy('name')->get(['id', 'name', 'stock_qty']),
            'stats' => [
                'total' => Item::count(),
                'low_stock' => Item::lowStock()->count(),
                'stock_value' => round((float) Item::whereIn('type', Item::STOCKED_TYPES)
                    ->selectRaw('SUM(stock_qty * cost) AS v')->value('v'), 2),
                'categories' => ItemCategory::count(),
            ],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);

        $item = Item::create(collect($data)->except('components')->all());
        $this->syncComponents($item, $data['components'] ?? []);

        return back()->with('success', 'تم إضافة الصنف');
    }

    /**
     * Quick item creation from inside the purchase invoice.
     *
     * Leaving the invoice for the items screen loses whatever was entered
     * there, so the item is created from the least an invoice line needs —
     * name, cost, price, tax — and returned as JSON to be appended on the spot.
     * Category, barcode and reorder point are filled in later from the items
     * screen.
     */
    public function quickStore(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'code' => ['nullable', 'string', 'max:50', Rule::unique('items', 'code')],
            'type' => ['required', Rule::in(array_keys(Item::TYPES))],
            'unit' => ['required', Rule::in(array_keys(Item::UNITS))],
            'cost' => ['required', 'numeric', 'min:0'],
            'price' => ['required', 'numeric', 'min:0'],
            'tax_rate' => ['required', 'numeric', 'min:0', 'max:100'],
            'department_id' => ['nullable', 'exists:departments,id'],
        ]);

        $item = Item::create([
            'name' => $data['name'],
            // Absent and empty both mean "number it for me" — a nullable rule
            // leaves the key out entirely when the field is never sent.
            'code' => ($data['code'] ?? null) ?: $this->nextCode(),
            'type' => $data['type'],
            'unit' => $data['unit'],
            'cost' => $data['cost'],
            'price' => $data['price'],
            'tax_rate' => $data['tax_rate'],
            'department_id' => $data['department_id'] ?? null,
            'stock_qty' => 0,
            'reorder_point' => 0,
            'is_active' => true,
        ]);

        // Same shape the pickers are fed elsewhere, so the front end appends the
        // line without reshaping anything.
        return response()->json([
            'item' => new ItemOptionResource($item, withCost: true),
        ], 201);
    }

    /**
     * Sequential code for a quickly created item.
     *
     * Soft-deleted rows stay in the table and keep their code under the unique
     * index, so the loop steps over taken codes rather than trusting max(id)
     * alone — otherwise the insert hits the constraint.
     */
    private function nextCode(): string
    {
        $sequence = (int) Item::withTrashed()->max('id') + 1;

        do {
            $code = 'ITM-'.str_pad((string) $sequence, 5, '0', STR_PAD_LEFT);
            $sequence++;
        } while (Item::withTrashed()->where('code', $code)->exists());

        return $code;
    }

    public function update(Request $request, Item $item): RedirectResponse
    {
        $data = $this->validated($request, $item);

        // الرصيد لا يُعدَّل من هنا — يتغيّر بالحركات والجرد فقط، وإلا انفصل
        // عمود الرصيد عن سجل الحركات وفقد المخزون مصداقيته.
        $item->update(collect($data)->except(['components', 'stock_qty'])->all());
        $this->syncComponents($item, $data['components'] ?? []);

        return back()->with('success', 'تم تحديث الصنف');
    }

    public function toggle(Item $item): RedirectResponse
    {
        $item->update(['is_active' => ! $item->is_active]);

        return back()->with('success', 'تم تغيير حالة الصنف');
    }

    public function destroy(Item $item): RedirectResponse
    {
        if ($item->movements()->exists()) {
            return back()->with('warning', 'لا يُحذف صنف له حركات مخزون — عطّله بدل حذفه.');
        }

        $item->delete();

        return back()->with('success', 'تم حذف الصنف');
    }

    /**
     * تسوية جرد لعدة أصناف دفعة واحدة.
     */
    public function adjust(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'adjustments' => ['required', 'array', 'min:1'],
            'adjustments.*.item_id' => ['required', 'exists:items,id'],
            'adjustments.*.counted_qty' => ['required', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $changed = 0;

        foreach ($data['adjustments'] as $row) {
            $item = Item::find($row['item_id']);

            if ($item && $item->tracksStock()) {
                $moved = $this->inventory->adjust(
                    $item,
                    (float) $row['counted_qty'],
                    $request->user()->id,
                    $data['notes'] ?? null,
                );

                if ($moved) {
                    $changed++;
                }
            }
        }

        return back()->with('success', "تمت تسوية {$changed} صنف");
    }

    /**
     * سجل حركات المخزون.
     */
    public function movements(Request $request): Response
    {
        $movements = StockMovement::with(['item:id,name,code', 'user:id,name'])
            ->when($request->integer('item_id'), fn ($q, $id) => $q->where('item_id', $id))
            ->when($request->string('type')->toString(), fn ($q, $t) => $q->where('type', $t))
            ->latest('id')
            ->paginate(30)
            ->withQueryString()
            ->through(fn (StockMovement $m) => [
                'id' => $m->id,
                'item_name' => $m->item?->name,
                'item_code' => $m->item?->code,
                'type' => $m->type,
                'type_label' => $m->typeLabel(),
                'quantity' => (float) $m->quantity,
                'balance_after' => (float) $m->balance_after,
                'user_name' => $m->user?->name,
                'notes' => $m->notes,
                'created_at' => $m->created_at->format('Y-m-d H:i'),
            ]);

        return Inertia::render('admin/items/Movements', [
            'movements' => $movements,
            'filters' => $this->filterState($request, ['item_id', 'type']),
            'items' => Item::orderBy('name')->get(['id', 'name']),
            'types' => collect(StockMovement::TYPES)->map(fn ($l, $k) => ['key' => $k, 'label' => $l])->values(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?Item $item = null): array
    {
        return $request->validate([
            'code' => ['required', 'string', 'max:50', Rule::unique('items', 'code')->ignore($item?->id)],
            'barcode' => ['nullable', 'string', 'max:80', Rule::unique('items', 'barcode')->ignore($item?->id)],
            'name' => ['required', 'string', 'max:255'],
            'item_category_id' => ['nullable', 'exists:item_categories,id'],
            'department_id' => ['nullable', 'exists:departments,id'],
            'measure_unit_id' => ['nullable', 'exists:measure_units,id'],
            'type' => ['required', Rule::in(array_keys(Item::TYPES))],
            'unit' => ['required', Rule::in(array_keys(Item::UNITS))],
            'cost' => ['required', 'numeric', 'min:0'],
            'price' => ['required', 'numeric', 'min:0'],
            'tax_rate' => ['required', 'numeric', 'min:0', 'max:100'],
            'stock_qty' => ['nullable', 'numeric', 'min:0'],
            'reorder_point' => ['required', 'numeric', 'min:0'],
            'description' => ['nullable', 'string', 'max:1000'],
            'is_active' => ['boolean'],
            'components' => ['array'],
            'components.*.component_id' => ['required', 'exists:items,id'],
            'components.*.quantity' => ['required', 'numeric', 'min:0.001'],
        ]);
    }

    /**
     * @param  list<array<string, mixed>>  $components
     */
    private function syncComponents(Item $item, array $components): void
    {
        if (! $item->isBundle()) {
            $item->components()->detach();

            return;
        }

        $sync = [];
        foreach ($components as $row) {
            // الحزمة لا تحتوي نفسها — وإلا صار الخصم لانهائيًا.
            if ((int) $row['component_id'] !== $item->id) {
                $sync[$row['component_id']] = ['quantity' => (float) $row['quantity']];
            }
        }

        $item->components()->sync($sync);
    }
}
