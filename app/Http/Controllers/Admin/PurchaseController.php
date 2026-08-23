<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\ItemOptionResource;
use App\Models\Department;
use App\Models\Item;
use App\Models\PaymentMethod;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use App\Models\Setting;
use App\Models\Supplier;
use App\Services\InventoryService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class PurchaseController extends Controller
{
    public function index(Request $request): Response
    {
        $departments = Department::orderBy('sort_order')->get(['id', 'name']);
        
        $filters = [
            'department_id' => $request->string('department_id')->toString() ?: null,
            'supplier_id' => $request->integer('supplier_id') ?: null,
            'payment_method_id' => $request->integer('payment_method_id') ?: null,
            'from' => $request->date('from')?->toDateString(),
            'to' => $request->date('to')?->toDateString(),
            'search' => $request->string('search')->toString() ?: null,
        ];

        $query = Purchase::query()
            ->when($filters['department_id'], fn ($q, $id) => $q->where('purchases.department_id', $id))
            ->when($filters['supplier_id'], fn ($q, $id) => $q->where('purchases.supplier_id', $id))
            ->when($filters['payment_method_id'], fn ($q, $id) => $q->where('purchases.payment_method_id', $id))
            ->when($filters['from'], fn ($q, $d) => $q->whereDate('purchases.created_at', '>=', $d))
            ->when($filters['to'], fn ($q, $d) => $q->whereDate('purchases.created_at', '<=', $d))
            ->when($filters['search'], fn ($q, $s) => $q->where(fn ($w) => $w
                ->where('purchases.number', 'like', "%{$s}%")
                ->orWhereHas('supplier', fn ($c) => $c->where('name', 'like', "%{$s}%"))));

        return Inertia::render('admin/purchases/Index', [
            'purchases' => (clone $query)
                ->with(['supplier:id,name', 'department:id,name', 'user:id,name', 'paymentMethod:id,name'])
                ->latest('purchases.id')
                ->paginate(20)
                ->withQueryString()
                ->through(fn (Purchase $p) => $this->summarize($p)),
            'stats' => $this->stats(clone $query),
            'filters' => $filters,
            'departments' => $departments,
            'suppliers' => Supplier::get(['id', 'name']),
            'methods' => PaymentMethod::options(),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('admin/purchases/Form', $this->formOptions());
    }

    /**
     * Options for the invoice form — shared by create and edit so the two
     * screens can never drift apart.
     *
     * @return array<string, mixed>
     */
    private function formOptions(): array
    {
        $settings = Setting::current();

        return [
            'departments' => Department::orderBy('sort_order')->get(['id', 'name']),
            'suppliers' => Supplier::get(['id', 'name']),
            'methods' => PaymentMethod::options(),
            'items' => ItemOptionResource::list(
                Item::where('is_active', true)->with(['category:id,name'])->orderBy('name')->get(),
                withCost: true,
            ),
            // A bundle is assembled from other items and holds no stock of its
            // own, so it is not something a purchase invoice can bring in.
            'itemTypes' => collect(Item::TYPES)->except('bundle')
                ->map(fn ($label, $key) => ['key' => $key, 'label' => $label])->values(),
            'itemUnits' => collect(Item::UNITS)
                ->map(fn ($label, $key) => ['key' => $key, 'label' => $label])->values(),
            'defaultTaxRate' => $settings->tax_enabled ? (float) $settings->tax_rate : 0.0,
        ];
    }

    public function store(Request $request, InventoryService $inventory)
    {
        $data = $request->validate([
            'supplier_id' => ['required', 'exists:suppliers,id'],
            'department_id' => ['required', 'exists:departments,id'],
            'payment_method_id' => ['nullable', 'exists:payment_methods,id'],
            'paid_amount' => ['required', 'numeric', 'min:0'],
            'discount_amount' => ['required', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.item_id' => ['required', 'exists:items,id'],
            'items.*.quantity' => ['required', 'numeric', 'min:0.001'],
            'items.*.unit_cost' => ['required', 'numeric', 'min:0'],
            'items.*.tax_amount' => ['required', 'numeric', 'min:0'],
        ]);

        try {
            DB::transaction(function () use ($data, $inventory, $request) {
                // Generate a purchase number
                $lastPurchase = Purchase::latest('id')->first();
                $number = 'PUR-' . str_pad(($lastPurchase ? $lastPurchase->id + 1 : 1), 6, '0', STR_PAD_LEFT);

                // Calculate totals
                $subtotal = 0;
                $taxAmount = 0;

                foreach ($data['items'] as $item) {
                    $itemTotal = round($item['quantity'] * $item['unit_cost'], 2);
                    $subtotal += $itemTotal;
                    $taxAmount += $item['tax_amount'];
                }

                $totalAmount = $subtotal - $data['discount_amount'] + $taxAmount;

                $purchase = Purchase::create([
                    'number' => $number,
                    'supplier_id' => $data['supplier_id'],
                    'user_id' => $request->user()->id,
                    'department_id' => $data['department_id'],
                    'subtotal' => $subtotal,
                    'discount_amount' => $data['discount_amount'],
                    'tax_amount' => $taxAmount,
                    'total_amount' => $totalAmount,
                    'payment_method_id' => $data['payment_method_id'],
                    'paid_amount' => $data['paid_amount'],
                    'notes' => $data['notes'],
                ]);

                foreach ($data['items'] as $itemData) {
                    $purchaseItem = PurchaseItem::create([
                        'purchase_id' => $purchase->id,
                        'item_id' => $itemData['item_id'],
                        'quantity' => $itemData['quantity'],
                        'unit_cost' => $itemData['unit_cost'],
                        'total_cost' => round($itemData['quantity'] * $itemData['unit_cost'], 2),
                    ]);

                    // Add to stock using InventoryService
                    $itemModel = \App\Models\Item::find($itemData['item_id']);
                    $inventory->move(
                        $itemModel,
                        (float) $itemData['quantity'],
                        'purchase',
                        $purchase,
                        $request->user()->id,
                        (float) $itemData['unit_cost'],
                        "شراء بالفاتورة {$purchase->number}"
                    );
                }
            });
        } catch (RuntimeException $e) {
            return back()->with('warning', $e->getMessage());
        }

        return redirect()->route('purchases.index')->with('success', 'تم حفظ فاتورة المشتريات وتحديث المخزون بنجاح');
    }

    public function edit(Purchase $purchase): Response
    {
        $purchase->load(['items.item:id,name,code,price,cost,tax_rate']);

        return Inertia::render('admin/purchases/Form', $this->formOptions() + ['purchase' => $purchase]);
    }

    public function update(Request $request, Purchase $purchase, InventoryService $inventory)
    {
        $data = $request->validate([
            'supplier_id' => ['required', 'exists:suppliers,id'],
            'department_id' => ['required', 'exists:departments,id'],
            'payment_method_id' => ['nullable', 'exists:payment_methods,id'],
            'paid_amount' => ['required', 'numeric', 'min:0'],
            'discount_amount' => ['required', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.item_id' => ['required', 'exists:items,id'],
            'items.*.quantity' => ['required', 'numeric', 'min:0.001'],
            'items.*.unit_cost' => ['required', 'numeric', 'min:0'],
            'items.*.tax_amount' => ['required', 'numeric', 'min:0'],
        ]);

        try {
            DB::transaction(function () use ($data, $inventory, $request, $purchase) {
                // Reverse old inventory
                foreach ($purchase->items as $oldItem) {
                    $itemModel = \App\Models\Item::find($oldItem->item_id);
                    if ($itemModel) {
                        $inventory->move(
                            $itemModel,
                            -(float) $oldItem->quantity,
                            'purchase_revert',
                            $purchase,
                            $request->user()->id,
                            (float) $oldItem->unit_cost,
                            "إلغاء شراء بالفاتورة {$purchase->number} للتعديل"
                        );
                    }
                }

                // Delete old items
                $purchase->items()->delete();

                // Calculate totals
                $subtotal = 0;
                $taxAmount = 0;

                foreach ($data['items'] as $item) {
                    $itemTotal = round($item['quantity'] * $item['unit_cost'], 2);
                    $subtotal += $itemTotal;
                    $taxAmount += $item['tax_amount'];
                }

                $totalAmount = $subtotal - $data['discount_amount'] + $taxAmount;

                $purchase->update([
                    'supplier_id' => $data['supplier_id'],
                    'department_id' => $data['department_id'],
                    'subtotal' => $subtotal,
                    'discount_amount' => $data['discount_amount'],
                    'tax_amount' => $taxAmount,
                    'total_amount' => $totalAmount,
                    'payment_method_id' => $data['payment_method_id'],
                    'paid_amount' => $data['paid_amount'],
                    'notes' => $data['notes'],
                ]);

                foreach ($data['items'] as $itemData) {
                    PurchaseItem::create([
                        'purchase_id' => $purchase->id,
                        'item_id' => $itemData['item_id'],
                        'quantity' => $itemData['quantity'],
                        'unit_cost' => $itemData['unit_cost'],
                        'total_cost' => round($itemData['quantity'] * $itemData['unit_cost'], 2),
                    ]);

                    // Add to stock using InventoryService
                    $itemModel = \App\Models\Item::find($itemData['item_id']);
                    $inventory->move(
                        $itemModel,
                        (float) $itemData['quantity'],
                        'purchase',
                        $purchase,
                        $request->user()->id,
                        (float) $itemData['unit_cost'],
                        "شراء بالفاتورة {$purchase->number}"
                    );
                }
            });
        } catch (RuntimeException $e) {
            return back()->with('warning', $e->getMessage());
        }

        return redirect()->route('purchases.index')->with('success', 'تم تعديل فاتورة المشتريات وتحديث المخزون بنجاح');
    }

    public function destroy(Purchase $purchase, InventoryService $inventory, Request $request)
    {
        try {
            DB::transaction(function () use ($purchase, $inventory, $request) {
                // Reverse old inventory
                foreach ($purchase->items as $oldItem) {
                    $itemModel = \App\Models\Item::find($oldItem->item_id);
                    if ($itemModel) {
                        $inventory->move(
                            $itemModel,
                            -(float) $oldItem->quantity,
                            'purchase_revert',
                            $purchase,
                            $request->user()->id,
                            (float) $oldItem->unit_cost,
                            "حذف فاتورة شراء {$purchase->number}"
                        );
                    }
                }
                
                $purchase->delete();
            });
        } catch (RuntimeException $e) {
            return back()->with('warning', $e->getMessage());
        }

        return redirect()->route('purchases.index')->with('success', 'تم حذف فاتورة المشتريات وتعديل المخزون بنجاح');
    }

    public function show(Request $request, Purchase $purchase)
    {
        $purchase->load([
            'items.item:id,name,code',
            'supplier:id,name',
            'department:id,name',
            'user:id,name',
            'paymentMethod:id,name'
        ]);
        
        $data = [
            'purchase' => $this->summarize($purchase) + [
                'notes' => $purchase->notes,
                'subtotal' => (float) $purchase->subtotal,
                'discount_amount' => (float) $purchase->discount_amount,
            ],
            'items' => $purchase->items->map(fn (PurchaseItem $l) => [
                'id' => $l->id,
                'item_id' => $l->item_id,
                'name' => $l->item?->name ?? '—',
                'code' => $l->item?->code,
                'quantity' => (float) $l->quantity,
                'unit_cost' => (float) $l->unit_cost,
                'tax_amount' => $l->item?->tax_rate > 0 ? (float) $l->total_cost * ((float) $l->item->tax_rate / 100) : 0,
                'total_cost' => (float) $l->total_cost,
            ]),
        ];

        // Only the printable sheet in the list modal draws the business identity.
        // The Show page never renders it, and passing it there would leak a stray
        // attribute onto the component root.
        if ($request->wantsJson()) {
            return response()->json($data + ['issuer' => $this->issuer($purchase)]);
        }

        return Inertia::render('admin/purchases/Show', $data);
    }

    /**
     * Header of the printed purchase invoice: identity of the receiving business.
     *
     * No ZATCA QR here — that code is issued by the seller, and a purchase
     * invoice comes in from the supplier rather than out from us, so stamping
     * one on this sheet would attribute to us an invoice we never issued.
     *
     * @return array<string, mixed>
     */
    private function issuer(Purchase $purchase): array
    {
        $settings = Setting::current();

        return [
            'business_name' => $settings->business_name ?: config('app.name'),
            'logo_url' => $settings->logo_path ? asset($settings->logo_path) : null,
            'address' => $settings->address,
            'phone' => $settings->phone,
            'email' => $settings->email,
            'tax_number' => $settings->tax_enabled ? $settings->tax_number : null,
            'commercial_register' => $settings->commercial_register,
            'activity' => $purchase->department?->name,
        ];
    }

    private function summarize(Purchase $purchase): array
    {
        return [
            'id' => $purchase->id,
            'number' => $purchase->number,
            'date' => $purchase->created_at->format('Y-m-d'),
            'time' => $purchase->created_at->format('H:i'),
            'supplier' => $purchase->supplier?->name,
            'department' => $purchase->department?->name,
            'user' => $purchase->user?->name,
            'payment_method_id' => $purchase->payment_method_id,
            'method_label' => $purchase->paymentMethod?->name ?? '—',
            'subtotal' => (float) $purchase->subtotal,
            'discount_amount' => (float) $purchase->discount_amount,
            'tax_amount' => (float) $purchase->tax_amount,
            'total' => (float) $purchase->total_amount,
            'paid' => (float) $purchase->paid_amount,
            'remaining' => round(max(0, (float) $purchase->total_amount - (float) $purchase->paid_amount), 2),
            'status' => $purchase->paid_amount >= $purchase->total_amount ? 'paid' 
                        : ($purchase->paid_amount > 0 ? 'partial' : 'unpaid'),
            'status_label' => $purchase->paid_amount >= $purchase->total_amount ? 'مسدّدة بالكامل' 
                        : ($purchase->paid_amount > 0 ? 'مسدّدة جزئياً' : 'غير مسدّدة'),
        ];
    }

    private function stats($query): array
    {
        $purchasesTotal = round((float) (clone $query)->sum('total_amount'), 2);
        $taxTotal = round((float) (clone $query)->sum('tax_amount'), 2);
        $collected = round((float) (clone $query)->sum('paid_amount'), 2);

        return [
            'count' => (clone $query)->count(),
            'purchases_total' => $purchasesTotal,
            'tax_total' => $taxTotal,
            'paid' => $collected,
            'remaining' => round(max(0, $purchasesTotal - $collected), 2),
        ];
    }
}
