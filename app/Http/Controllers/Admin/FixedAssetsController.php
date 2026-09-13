<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\CostCenter;
use App\Models\FixedAsset;
use App\Services\Accounting\DepreciationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

/**
 * الأصول الثابتة وإهلاكها الشهري.
 */
class FixedAssetsController extends Controller
{
    public function __construct(private readonly DepreciationService $depreciation) {}

    public function index(Request $request): Response
    {
        $status = $request->string('status')->toString();

        $assets = FixedAsset::with('costCenter:id,name')
            ->when($status, fn ($q, $s) => $q->where('status', $s))
            ->orderByDesc('purchase_date')
            ->paginate(30)
            ->withQueryString()
            ->through(fn (FixedAsset $a) => [
                'id' => $a->id,
                'code' => $a->code,
                'name' => $a->name,
                'category' => $a->category,
                'cost_center' => $a->costCenter?->name,
                'purchase_date' => $a->purchase_date->toDateString(),
                'cost' => (float) $a->cost,
                'salvage_value' => (float) $a->salvage_value,
                'useful_life_months' => $a->useful_life_months,
                'monthly_depreciation' => $a->monthlyDepreciation(),
                'accumulated_depreciation' => $a->accumulatedDepreciation(),
                'book_value' => $a->bookValue(),
                'status' => $a->status,
                'status_label' => $a->statusLabel(),
            ]);

        return Inertia::render('admin/accounting/FixedAssets', [
            'assets' => $assets,
            'filters' => ['status' => $status ?: null],
            'costCenters' => CostCenter::where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'statuses' => collect(FixedAsset::STATUSES)->map(fn ($l, $k) => ['key' => $k, 'label' => $l])->values(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'category' => ['nullable', 'string', 'max:100'],
            'cost_center_id' => ['nullable', 'exists:cost_centers,id'],
            'purchase_date' => ['required', 'date'],
            'cost' => ['required', 'numeric', 'min:0.01'],
            'salvage_value' => ['nullable', 'numeric', 'min:0'],
            'useful_life_months' => ['required', 'integer', 'min:1'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $this->depreciation->registerAsset($data, $request->user()?->id);

        return back()->with('success', 'تم تسجيل الأصل');
    }

    public function update(Request $request, FixedAsset $fixedAsset): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'category' => ['nullable', 'string', 'max:100'],
            'cost_center_id' => ['nullable', 'exists:cost_centers,id'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $fixedAsset->update($data);

        return back()->with('success', 'تم تحديث بيانات الأصل');
    }

    public function destroy(FixedAsset $fixedAsset): RedirectResponse
    {
        if ($fixedAsset->depreciationEntries()->exists()) {
            return back()->with('warning', 'لا يُحذف أصل رُحِّل له إهلاك — استبعده بدل حذفه.');
        }

        $fixedAsset->delete();

        return back()->with('success', 'تم حذف الأصل');
    }

    public function dispose(FixedAsset $fixedAsset): RedirectResponse
    {
        try {
            $this->depreciation->disposeAsset($fixedAsset);
        } catch (RuntimeException $e) {
            return back()->with('warning', $e->getMessage());
        }

        return back()->with('success', 'تم استبعاد الأصل — لن يُرحَّل له إهلاك بعد اليوم');
    }

    public function postDepreciation(Request $request): RedirectResponse
    {
        $data = $request->validate(['period' => ['required', 'date']]);

        try {
            $result = $this->depreciation->postMonthlyDepreciation($data['period'], $request->user()?->id);
        } catch (RuntimeException $e) {
            return back()->with('warning', $e->getMessage());
        }

        return back()->with('success', "تم ترحيل إهلاك {$result['assets_count']} أصل بإجمالي {$result['total_amount']}");
    }
}
