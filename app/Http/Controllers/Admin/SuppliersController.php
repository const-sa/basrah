<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Supplier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class SuppliersController extends Controller
{
    public function index(Request $request): Response
    {
        $suppliers = Supplier::query()
            ->when($request->string('search')->toString(), fn ($q, $t) => $q->where(
                fn ($s) => $s->where('name', 'like', "%{$t}%")->orWhere('mobile', 'like', "%{$t}%"),
            ))
            ->orderBy('name')
            ->get()
            ->map(fn (Supplier $s) => [
                'id' => $s->id,
                'name' => $s->name,
                'mobile' => $s->mobile,
                'email' => $s->email,
                'tax_number' => $s->tax_number,
                'address' => $s->address,
                'notes' => $s->notes,
                'is_active' => $s->is_active,
                'balance' => $s->balance(),
            ]);

        return Inertia::render('admin/suppliers/Index', [
            'suppliers' => $suppliers,
            'filters' => $request->only(['search']),
            'stats' => [
                'total' => $suppliers->count(),
                'active' => $suppliers->where('is_active', true)->count(),
                'payable' => round((float) $suppliers->sum('balance'), 2),
            ],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        Supplier::create([...$this->validated($request), 'is_active' => true]);

        return back()->with('success', 'تم إضافة المورد');
    }

    /**
     * إضافة مورد سريعة من داخل فاتورة المشتريات.
     *
     * مغادرة الفاتورة إلى شاشة الموردين تُفقد ما عُبّئ فيها، فيُنشأ المورد
     * بالاسم والجوال فقط ويعود عبر JSON ليُحدَّد فورًا في النموذج. بقية
     * البيانات (الضريبي والعنوان والبريد) تُستكمل من شاشة الموردين.
     */
    public function quickStore(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'mobile' => ['nullable', 'string', 'max:30'],
        ]);

        $supplier = Supplier::create([
            'name' => $data['name'],
            'mobile' => $data['mobile'] ?? null,
            'is_active' => true,
        ]);

        return response()->json([
            'supplier' => [
                'id' => $supplier->id,
                'name' => $supplier->name,
                'mobile' => $supplier->mobile,
            ],
        ], 201);
    }

    public function update(Request $request, Supplier $supplier): RedirectResponse
    {
        $supplier->update($this->validated($request, $supplier));

        return back()->with('success', 'تم تحديث المورد');
    }

    public function toggle(Supplier $supplier): RedirectResponse
    {
        $supplier->update(['is_active' => ! $supplier->is_active]);

        return back()->with('success', 'تم تغيير حالة المورد');
    }

    public function destroy(Supplier $supplier): RedirectResponse
    {
        if ($supplier->vouchers()->exists()) {
            return back()->with('warning', 'لا يُحذف مورد له سندات — عطّله بدل حذفه.');
        }

        $supplier->delete();

        return back()->with('success', 'تم حذف المورد');
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?Supplier $supplier = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'mobile' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:255'],
            'tax_number' => ['nullable', 'string', 'max:50', Rule::unique('suppliers', 'tax_number')->ignore($supplier?->id)],
            'address' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'is_active' => ['boolean'],
        ]);
    }
}
