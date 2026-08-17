<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Department;
use App\Models\Item;
use App\Models\Sale;
use App\Models\Unit;
use App\Services\SalesService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * شاشة الكاشير.
 */
class PosController extends Controller
{
    public function __construct(
        private readonly SalesService $sales,
    ) {}

    public function index(Request $request): Response
    {
        $user = $request->user();

        $departments = Department::selling()->orderBy('sort_order')->get(['id', 'name', 'code']);

        // القسم المختار من الفلتر، وإلا أول قسم بائع (المسابح).
        $departmentId = $request->integer('department_id')
            ?: ($departments->first()->id ?? null);

        return Inertia::render('admin/pos/Index', [
            'departments' => $departments,
            'departmentId' => $departmentId,
            // أصناف القسم المختار فقط — مستودع كل نشاط مستقل عن غيره
            'items' => Item::where('is_active', true)
                ->when($departmentId, fn ($q, $id) => $q->where('department_id', $id))
                ->with(['category:id,name', 'measureUnit:id,name,allows_fraction'])
                ->orderBy('name')
                ->get()
                ->map(fn (Item $i) => [
                    'id' => $i->id,
                    'code' => $i->code,
                    'barcode' => $i->barcode,
                    'name' => $i->name,
                    'category' => $i->category?->name,
                    'type' => $i->type,
                    'type_label' => $i->typeLabel(),
                    'unit' => $i->unit,
                    'unit_label' => $i->unitLabel(),
                    'price' => (float) $i->price,
                    'tax_rate' => (float) $i->tax_rate,
                    'stock_qty' => (float) $i->stock_qty,
                    'tracks_stock' => $i->tracksStock(),
                    'fractional' => $i->allowsFractionalQuantity(),
                    'low_stock' => $i->isBelowReorderPoint(),
                ]),
            // العميل النقدي أولًا في القائمة لأنه المختار افتراضيًا وأكثرها استعمالًا.
            'clients' => Client::orderByDesc('is_walk_in')->orderBy('name')
                ->limit(300)->get(['id', 'name', 'mobile']),
            'defaultClientId' => Client::walkIn()->id,
            'methods' => collect(Sale::METHODS)->map(fn ($l, $k) => ['key' => $k, 'label' => $l])->values(),
            // آخر فواتير المستخدم نفسه.
            'recentSales' => Sale::where('user_id', $user->id)
                ->latest('id')->limit(10)->get()->map(fn (Sale $s) => [
                    'id' => $s->id,
                    'number' => $s->number,
                    'type' => $s->type,
                    'total' => (float) $s->total_amount,
                    'method_label' => $s->methodLabel(),
                    'time' => $s->created_at->format('H:i'),
                ]),
        ]);
    }

    public function checkout(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.item_id' => ['required', 'exists:items,id'],
            'lines.*.quantity' => ['required', 'numeric', 'min:0.001'],
            'lines.*.unit_price' => ['nullable', 'numeric', 'min:0'],
            'lines.*.discount_amount' => ['nullable', 'numeric', 'min:0'],
            'client_id' => ['nullable', 'exists:clients,id'],
            'department_id' => ['nullable', 'exists:departments,id'],
            'method' => ['required', Rule::in(array_keys(Sale::METHODS))],
            'discount_amount' => ['nullable', 'numeric', 'min:0'],
            // المقبوض عند الإصدار — يُحدّه الإجمالي في الخدمة لا هنا، فالإجمالي
            // لا يُحسب إلا بعد بناء السطور.
            'paid_amount' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $sale = $this->sales->checkout($data, $request->user()->id);

        $message = "تمت الفاتورة {$sale->number} بمبلغ ".number_format((float) $sale->total_amount, 2);

        // المتبقي يُذكر صراحةً: الكاشير يحتاج أن يعرف أنّ على الفاتورة دَينًا.
        if ($sale->remainingAmount() > 0) {
            return back()->with('warning', $message.' — المتبقي '.number_format($sale->remainingAmount(), 2)
                .' ('.$sale->paymentStatusLabel().')');
        }

        return back()->with('success', $message);
    }

    public function refund(Request $request, Sale $sale): RedirectResponse
    {
        $data = $request->validate([
            'quantities' => ['required', 'array', 'min:1'],
            'quantities.*' => ['numeric', 'min:0'],
            'reason' => ['nullable', 'string', 'max:1000'],
        ]);

        $return = $this->sales->refund(
            $sale,
            array_map('floatval', $data['quantities']),
            $request->user()->id,
            $data['reason'] ?? null,
        );

        return back()->with('success', "تم المرتجع {$return->number}");
    }
}
