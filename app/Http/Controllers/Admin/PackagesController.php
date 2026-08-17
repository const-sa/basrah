<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Package;
use App\Models\Unit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * باقات القاعات — اسم الباقة وبنودها بأعدادها.
 */
class PackagesController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();

        $packages = Package::query()
            ->with(['items', 'unit:id,name'])
            // الباقة العامة تظهر للجميع، والخاصة لمن يرى قاعتها
            ->where(fn ($q) => $q->whereNull('unit_id')
                ->orWhereHas('unit', fn ($u) => $u->visibleTo($user)))
            ->when($request->integer('unit_id'), fn ($q, $id) => $q->where(
                fn ($sub) => $sub->where('unit_id', $id)->orWhereNull('unit_id'),
            ))
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->map(fn (Package $p) => [
                'id' => $p->id,
                'name' => $p->name,
                'description' => $p->description,
                'unit_id' => $p->unit_id,
                'unit_name' => $p->unit?->name,
                'is_general' => $p->isGeneral(),
                'price' => (float) $p->price,
                'is_active' => $p->is_active,
                'items' => $p->items->map(fn ($i) => [
                    'id' => $i->id,
                    'name' => $i->name,
                    'quantity' => (float) $i->quantity,
                    'unit_label' => $i->unit_label,
                    'notes' => $i->notes,
                    'quantity_label' => $i->quantityLabel(),
                ])->values(),
            ]);

        return Inertia::render('admin/units/Packages', [
            'packages' => $packages,
            'filters' => ['unit_id' => $request->integer('unit_id') ?: null],
            // الباقات تخصّ القاعات، فلا تُعرض الشاليهات في قائمة الإسناد
            'halls' => Unit::visibleTo($user)->where('type', 'hall')
                ->orderBy('sort_order')->get(['id', 'name']),
            'stats' => [
                'total' => $packages->count(),
                'general' => $packages->where('is_general', true)->count(),
                'inactive' => $packages->where('is_active', false)->count(),
            ],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);

        $package = Package::create([
            ...collect($data)->except('items')->all(),
            'sort_order' => (Package::max('sort_order') ?? 0) + 1,
        ]);

        $this->syncItems($package, $data['items'] ?? []);

        return back()->with('success', 'تم إضافة الباقة');
    }

    public function update(Request $request, Package $package): RedirectResponse
    {
        $data = $this->validated($request);

        $package->update(collect($data)->except('items')->all());
        $this->syncItems($package, $data['items'] ?? []);

        return back()->with('success', 'تم تحديث الباقة');
    }

    public function toggle(Package $package): RedirectResponse
    {
        $package->update(['is_active' => ! $package->is_active]);

        return back()->with('success', 'تم تغيير حالة الباقة');
    }

    /**
     * نسخ باقة — أسرع من إعادة كتابة بنودها لباقة مشابهة.
     */
    public function duplicate(Package $package): RedirectResponse
    {
        $package->loadMissing('items');

        $copy = $package->replicate(['created_at', 'updated_at']);
        $copy->name = $package->name.' — نسخة';
        $copy->sort_order = (Package::max('sort_order') ?? 0) + 1;
        $copy->save();

        foreach ($package->items as $item) {
            $copy->items()->create($item->only(['name', 'quantity', 'unit_label', 'notes', 'sort_order']));
        }

        return back()->with('success', "تم نسخ الباقة باسم «{$copy->name}»");
    }

    public function destroy(Package $package): RedirectResponse
    {
        $package->delete();

        return back()->with('success', 'تم حذف الباقة');
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'unit_id' => ['nullable', 'exists:units,id'],
            'price' => ['nullable', 'numeric', 'min:0'],
            'is_active' => ['boolean'],

            'items' => ['array'],
            'items.*.name' => ['required', 'string', 'max:255'],
            'items.*.quantity' => ['required', 'numeric', 'min:0'],
            'items.*.unit_label' => ['nullable', 'string', 'max:50'],
            'items.*.notes' => ['nullable', 'string', 'max:255'],
        ]);
    }

    /**
     * البنود تُعاد كتابتها كاملة عند الحفظ — أبسط من مطابقة السطور،
     * وهي بيانات وصفية لا يشير إليها شيء آخر فلا خطر في استبدالها.
     *
     * @param  list<array<string, mixed>>  $items
     */
    private function syncItems(Package $package, array $items): void
    {
        $package->items()->delete();

        foreach ($items as $i => $item) {
            if (blank($item['name'] ?? null)) {
                continue;
            }

            $package->items()->create([
                'name' => $item['name'],
                'quantity' => $item['quantity'] ?? 0,
                'unit_label' => $item['unit_label'] ?? null,
                'notes' => $item['notes'] ?? null,
                'sort_order' => $i,
            ]);
        }
    }
}
