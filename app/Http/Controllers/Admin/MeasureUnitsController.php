<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Models\Item;
use App\Models\MeasureUnit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * التحكم بوحدات القياس وأقسام المستودع.
 */
class MeasureUnitsController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('admin/items/Units', [
            'units' => MeasureUnit::withCount('items')->orderBy('sort_order')->orderBy('id')->get()
                ->map(fn (MeasureUnit $u) => [
                    'id' => $u->id,
                    'code' => $u->code,
                    'name' => $u->name,
                    'symbol' => $u->symbol,
                    'allows_fraction' => $u->allows_fraction,
                    'is_active' => $u->is_active,
                    'items_count' => $u->items_count,
                ]),
            'departments' => Department::withCount('items')->orderBy('sort_order')->get()
                ->map(fn (Department $d) => [
                    'id' => $d->id,
                    'code' => $d->code,
                    'name' => $d->name,
                    'description' => $d->description,
                    'sells' => $d->sells,
                    'is_active' => $d->is_active,
                    'items_count' => $d->items_count,
                    'stock_value' => $d->stockValue(),
                ]),
        ]);
    }

    // ── وحدات القياس ─────────────────────────────────────────

    public function store(Request $request): RedirectResponse
    {
        MeasureUnit::create([
            ...$this->unitRules($request),
            'sort_order' => (MeasureUnit::max('sort_order') ?? 0) + 1,
        ]);

        return back()->with('success', 'تم إضافة وحدة القياس');
    }

    public function update(Request $request, MeasureUnit $unit): RedirectResponse
    {
        $unit->update($this->unitRules($request, $unit));

        return back()->with('success', 'تم تحديث وحدة القياس');
    }

    public function destroy(MeasureUnit $unit): RedirectResponse
    {
        if ($unit->items()->exists()) {
            return back()->with('warning', 'لا تُحذف وحدة مستخدمة في أصناف — عطّلها بدل حذفها.');
        }

        $unit->delete();

        return back()->with('success', 'تم حذف وحدة القياس');
    }

    /**
     * @return array<string, mixed>
     */
    private function unitRules(Request $request, ?MeasureUnit $unit = null): array
    {
        return $request->validate([
            'code' => ['required', 'string', 'max:20', Rule::unique('measure_units', 'code')->ignore($unit?->id)],
            'name' => ['required', 'string', 'max:100'],
            'symbol' => ['nullable', 'string', 'max:20'],
            'allows_fraction' => ['boolean'],
            'is_active' => ['boolean'],
        ]);
    }

    // ── الأقسام ──────────────────────────────────────────────

    public function storeDepartment(Request $request): RedirectResponse
    {
        $department = Department::create([
            ...$this->departmentRules($request),
            'sort_order' => (Department::max('sort_order') ?? 0) + 1,
        ]);

        // كل قسم يحتاج مركز تكلفة ليُقاس نشاطه ماليًا
        \App\Models\CostCenter::forDepartment($department);

        return back()->with('success', 'تم إضافة القسم');
    }

    public function updateDepartment(Request $request, Department $department): RedirectResponse
    {
        $department->update($this->departmentRules($request, $department));

        return back()->with('success', 'تم تحديث القسم');
    }

    public function destroyDepartment(Department $department): RedirectResponse
    {
        if ($department->items()->exists()) {
            return back()->with('warning', 'لا يُحذف قسم له أصناف — انقل أصنافه أولًا أو عطّله.');
        }

        if ($department->sales()->exists()) {
            return back()->with('warning', 'لا يُحذف قسم له فواتير — عطّله بدل حذفه.');
        }

        $department->delete();

        return back()->with('success', 'تم حذف القسم');
    }

    /**
     * @return array<string, mixed>
     */
    private function departmentRules(Request $request, ?Department $department = null): array
    {
        return $request->validate([
            'code' => ['nullable', 'string', 'max:20', Rule::unique('departments', 'code')->ignore($department?->id)],
            'name' => ['required', 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:500'],
            'sells' => ['boolean'],
            'is_active' => ['boolean'],
        ]);
    }
}
