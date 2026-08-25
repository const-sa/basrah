<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\ItemOptionResource;
use App\Models\Item;
use App\Models\ItemGroup;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * مجموعات الأصناف — تحديد محفوظ يُملأ به الفاتورة أو عرض السعر دفعةً واحدة.
 */
class ItemGroupsController extends Controller
{
    public function index(Request $request): Response
    {
        $groups = ItemGroup::query()
            ->with(['items:id,code,name,item_category_id,price,tax_rate,is_active', 'items.category:id,name'])
            ->when($request->string('search')->toString(), fn ($q, $s) => $q->where('name', 'like', "%{$s}%"))
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->map(fn (ItemGroup $group) => [
                'id' => $group->id,
                'name' => $group->name,
                'description' => $group->description,
                'is_active' => $group->is_active,
                'items' => $group->items->map(fn (Item $item) => [
                    'id' => $item->id,
                    'code' => $item->code,
                    'name' => $item->name,
                    'category' => $item->category?->name,
                    'price' => (float) $item->price,
                    'is_active' => $item->is_active,
                ])->values(),
            ]);

        return Inertia::render('admin/items/Groups', [
            'groups' => $groups,
            'filters' => ['search' => $request->string('search')->toString() ?: null],
            'items' => ItemOptionResource::list(
                Item::where('is_active', true)->with('category:id,name')->orderBy('name')->get(),
            ),
            'stats' => [
                'total' => $groups->count(),
                'inactive' => $groups->where('is_active', false)->count(),
            ],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);

        $group = ItemGroup::create([
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'is_active' => $data['is_active'] ?? true,
            'sort_order' => (ItemGroup::max('sort_order') ?? 0) + 1,
        ]);

        $this->syncItems($group, $data['item_ids']);

        return back()->with('success', 'تم إضافة المجموعة');
    }

    public function update(Request $request, ItemGroup $itemGroup): RedirectResponse
    {
        $data = $this->validated($request);

        $itemGroup->update([
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'is_active' => $data['is_active'] ?? true,
        ]);

        $this->syncItems($itemGroup, $data['item_ids']);

        return back()->with('success', 'تم تحديث المجموعة');
    }

    public function toggle(ItemGroup $itemGroup): RedirectResponse
    {
        $itemGroup->update(['is_active' => ! $itemGroup->is_active]);

        return back()->with('success', 'تم تغيير حالة المجموعة');
    }

    /**
     * نسخ مجموعة — أسرع من إعادة اختيار أصناف مجموعة مشابهة.
     */
    public function duplicate(ItemGroup $itemGroup): RedirectResponse
    {
        $itemGroup->loadMissing('lines');

        $copy = $itemGroup->replicate(['created_at', 'updated_at']);
        $copy->name = $itemGroup->name.' — نسخة';
        $copy->sort_order = (ItemGroup::max('sort_order') ?? 0) + 1;
        $copy->save();

        foreach ($itemGroup->lines as $line) {
            $copy->lines()->create($line->only(['item_id', 'sort_order']));
        }

        return back()->with('success', "تم نسخ المجموعة باسم «{$copy->name}»");
    }

    public function destroy(ItemGroup $itemGroup): RedirectResponse
    {
        $itemGroup->delete();

        return back()->with('success', 'تم حذف المجموعة');
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'is_active' => ['boolean'],
            // مجموعة بلا أصناف لا تفعل شيئًا عند اختيارها، فلا تُحفظ أصلًا.
            'item_ids' => ['required', 'array', 'min:1'],
            'item_ids.*' => ['integer', 'exists:items,id'],
        ], [
            'item_ids.required' => 'اختر صنفًا واحدًا على الأقل للمجموعة.',
            'item_ids.min' => 'اختر صنفًا واحدًا على الأقل للمجموعة.',
        ]);
    }

    /**
     * الأصناف تُعاد كتابتها كاملة عند الحفظ — أبسط من مطابقة السطور، والمجموعة
     * انتماءٌ لا تاريخ له، فلا شيء يشير إلى سطورها القديمة.
     *
     * الترتيب يتبع ترتيب الاختيار، وتكرار الصنف يُطرح: المجموعة تحديدٌ لا كمية.
     *
     * @param  list<int>  $itemIds
     */
    private function syncItems(ItemGroup $group, array $itemIds): void
    {
        $group->lines()->delete();

        foreach (array_values(array_unique(array_map('intval', $itemIds))) as $i => $itemId) {
            $group->lines()->create([
                'item_id' => $itemId,
                'sort_order' => $i,
            ]);
        }
    }
}
