<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\Department;
use App\Models\PaymentMethod;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\Setting;
use App\Models\Treasury;
use App\Models\Voucher;
use App\Services\Accounting\Ledger;
use App\Services\Accounting\VoucherService;
use App\Services\SalesService;
use App\Services\ZatcaQr;
use App\Support\Vat;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

/**
 * سجل فواتير الأقسام البائعة (المسابح وغيرها) منفصلًا عن شاشة الكاشير.
 *
 * الكاشير شاشة إصدار لا مراجعة: يعرض آخر عشر فواتير للمستخدم نفسه فقط.
 * أما المتابعة — من دفع، وكم بقي، وما أُرجع — فمكانها هنا، ومنها يُصدَر
 * المرتجع وسند القبض على الفاتورة نفسها.
 */
class SalesController extends Controller
{
    public function __construct(
        private readonly SalesService $sales,
        private readonly VoucherService $vouchers,
    ) {}

    public function index(Request $request): Response
    {
        $departments = Department::selling()->orderBy('sort_order')->get(['id', 'name', 'code']);

        // الشاشة تُفتح على قسم واحد (المسابح افتراضًا) لأن المطلوب استعراض
        // فواتير النشاط وحده. القيمة 'all' تفتحها على كل الأقسام عمدًا.
        // الفاتورة المطلوب فتحها فور دخول الشاشة — يأتي بها التحويل من عرض
        // السعر. `sales.show` تُجيب بـ JSON للنافذة المنبثقة ولا صفحة خلفها،
        // فالتحويل يقصد هذه القائمة ويُشير إلى فاتورته.
        $openSale = $request->integer('invoice')
            ? Sale::with(['client:id,name,mobile', 'department:id,name', 'user:id,name', 'paymentMethod:id,name'])
                ->find($request->integer('invoice'))
            : null;

        $departmentParam = $request->string('department_id')->toString();
        $departmentId = $departmentParam === 'all'
            ? null
            // القائمة تُفتح على قسم الفاتورة المقصودة، وإلا اختفت من خلفها.
            : ((int) $departmentParam ?: ($openSale?->department_id ?? $departments->first()->id ?? null));

        $filters = [
            'department_id' => $departmentParam === 'all' ? 'all' : (string) ($departmentId ?? ''),
            'type' => $request->string('type')->toString() ?: null,
            'payment_status' => $request->string('payment_status')->toString() ?: null,
            'payment_method_id' => $request->integer('payment_method_id') ?: null,
            'from' => $request->date('from')?->toDateString(),
            'to' => $request->date('to')?->toDateString(),
            'search' => $request->string('search')->toString() ?: null,
        ];

        $query = Sale::query()
            ->when($departmentId, fn ($q, $id) => $q->where('sales.department_id', $id))
            ->when($filters['type'], fn ($q, $t) => $q->where('sales.type', $t))
            ->when($filters['payment_method_id'], fn ($q, $id) => $q->where('sales.payment_method_id', $id))
            ->when($filters['payment_status'], fn ($q, $s) => $q->paymentStatus($s))
            ->when($filters['from'], fn ($q, $d) => $q->whereDate('sales.created_at', '>=', $d))
            ->when($filters['to'], fn ($q, $d) => $q->whereDate('sales.created_at', '<=', $d))
            ->when($filters['search'], fn ($q, $s) => $q->where(fn ($w) => $w
                ->where('sales.number', 'like', "%{$s}%")
                ->orWhereHas('client', fn ($c) => $c
                    ->where('name', 'like', "%{$s}%")
                    ->orWhere('mobile', 'like', "%{$s}%"))));

        return Inertia::render('admin/sales/Index', [
            'sales' => (clone $query)
                ->with(['client:id,name,mobile', 'department:id,name', 'user:id,name', 'paymentMethod:id,name'])
                ->withSum('returns as returned_amount', 'total_amount')
                ->latest('sales.id')
                ->paginate(20)
                ->withQueryString()
                ->through(fn (Sale $s) => $this->summarize($s)),
            'stats' => $this->stats(clone $query),
            'filters' => $filters,
            'departments' => $departments,
            'methods' => PaymentMethod::options(),
            'paymentStatuses' => collect(Sale::PAYMENT_STATUSES)->map(fn ($l, $k) => ['key' => $k, 'label' => $l])->values(),
            'treasuries' => Treasury::where('is_active', true)->orderBy('name')
                ->get()->map(fn (Treasury $t) => [
                    'id' => $t->id,
                    'name' => $t->name,
                    'type_label' => $t->typeLabel(),
                    'balance' => $t->balance(),
                ]),
            'voucherMethods' => PaymentMethod::options(),
            'openSale' => $openSale ? $this->summarize($openSale) : null,
        ]);
    }

    /**
     * تفاصيل فاتورة: سطورها ومرتجعاتها وسنداتها — تُطلب عند فتح النافذة
     * فلا تُحمَّل مع كل صفوف القائمة.
     */
    public function show(Sale $sale): JsonResponse
    {
        $sale->load([
            'lines.item:id,name,code',
            'client:id,name,mobile',
            'department:id,name',
            'user:id,name',
            'originalSale:id,number',
            'returns' => fn ($q) => $q->with('lines')->latest('id'),
            'vouchers' => fn ($q) => $q->with('treasury:id,name')->latest('id'),
        ]);

        // المتاح للإرجاع من كل سطر = المباع − ما سبق إرجاعه.
        $returnedPerItem = SaleItem::whereIn('sale_id', $sale->returns->pluck('id'))
            ->selectRaw('item_id, SUM(quantity) AS qty')
            ->groupBy('item_id')
            ->pluck('qty', 'item_id');

        return response()->json([
            'sale' => [
                ...$this->summarize($sale),
                'notes' => $sale->notes,
                'original' => $sale->originalSale?->only(['id', 'number']),
                'subtotal' => (float) $sale->subtotal,
                'discount_amount' => (float) $sale->discount_amount,
            ],
            'lines' => $sale->lines->map(fn (SaleItem $l) => [
                'id' => $l->id,
                'item_id' => $l->item_id,
                'name' => $l->item?->name ?? '—',
                'code' => $l->item?->code,
                'quantity' => (float) $l->quantity,
                'returned_quantity' => round((float) ($returnedPerItem[$l->item_id] ?? 0), 3),
                'returnable_quantity' => round((float) $l->quantity - (float) ($returnedPerItem[$l->item_id] ?? 0), 3),
                'unit_price' => (float) $l->unit_price,
                'discount_amount' => (float) $l->discount_amount,
                'tax_amount' => (float) $l->tax_amount,
                'total' => (float) $l->total,
            ]),
            'returns' => $sale->returns->map(fn (Sale $r) => [
                'id' => $r->id,
                'number' => $r->number,
                'date' => $r->created_at->format('Y-m-d H:i'),
                'total' => (float) $r->total_amount,
                'notes' => $r->notes,
            ]),
            'vouchers' => $sale->vouchers->map(fn (Voucher $v) => [
                'id' => $v->id,
                'number' => $v->number,
                'date' => $v->voucher_date->toDateString(),
                'amount' => (float) $v->amount,
                'treasury' => $v->treasury?->name,
                'method_label' => $v->methodLabel(),
                'status' => $v->status,
                'status_label' => $v->statusLabel(),
                'description' => $v->description,
            ]),
            'issuer' => $this->issuer($sale),
        ]);
    }

    /**
     * ترويسة الفاتورة المطبوعة: هوية المنشأة ورمز الزكاة والضريبة.
     *
     * الرمز لا يُولَّد إلا بوجود رقم ضريبي — الفاتورة بلا تسجيل ضريبي
     * لا رمز لها، ورمزٌ برقم فارغ لا يقرؤه تطبيق الهيئة.
     *
     * @return array<string, mixed>
     */
    private function issuer(Sale $sale): array
    {
        $settings = Setting::current();
        // من القاعدة الواحدة: التفعيل وحده لا يكفي — رقمٌ بلا نسبةٍ سارية
        // يطبع رمزًا لفاتورةٍ ضريبتها صفر، وهو ما يرفضه تطبيق الهيئة.
        //
        // والفاتورة الصادرة يبقى رمزها ما دامت تحمل ضريبةً حُصّلت: ورقةٌ
        // خرجت للعميل برمزٍ لا يصحّ أن تُطبع بعدها بلا رمز.
        $taxNumber = Vat::applies() || (float) $sale->tax_amount > 0
            ? $settings->tax_number
            : null;

        $qr = null;

        if ($taxNumber) {
            $zatca = app(ZatcaQr::class);

            $qr = $zatca->dataUri($zatca->payload(
                $settings->business_name ?: config('app.name'),
                $taxNumber,
                $sale->created_at->toIso8601String(),
                (float) $sale->total_amount,
                (float) $sale->tax_amount,
            ));
        }

        return [
            'business_name' => $settings->business_name ?: config('app.name'),
            'logo_url' => $settings->logo_path ? asset($settings->logo_path) : null,
            'address' => $settings->address,
            'phone' => $settings->phone,
            'email' => $settings->email,
            'tax_number' => $taxNumber,
            'commercial_register' => $settings->commercial_register,
            'activity' => $sale->department?->name,
            'qr' => $qr,
        ];
    }

    /**
     * سند قبض على فاتورة مسدَّدة جزئيًا (أو غير مسدَّدة).
     */
    public function settle(Request $request, Sale $sale): RedirectResponse
    {
        if (! $sale->acceptsSettlement()) {
            return back()->with('warning', "الفاتورة {$sale->number} لا يتبقى عليها مبلغ.");
        }

        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01', 'max:'.$sale->remainingAmount()],
            'treasury_id' => ['required', 'exists:treasuries,id'],
            'payment_method_id' => ['required', Rule::exists('payment_methods', 'id')
                ->where('is_active', true)],
            'voucher_date' => ['required', 'date'],
            'description' => ['nullable', 'string', 'max:1000'],
        ], [], ['amount' => 'المبلغ']);

        // القبض على فاتورة يُقفل ذمّة العميل، فالحساب المقابل هو الذمم.
        $receivables = Account::where('code', Ledger::RECEIVABLES)->value('id');

        if (! $receivables) {
            return back()->with('warning', 'حساب ذمم العملاء غير موجود في شجرة الحسابات.');
        }

        $voucher = $this->vouchers->create([
            'type' => 'receipt',
            'voucher_date' => $data['voucher_date'],
            'amount' => round((float) $data['amount'], 2),
            'treasury_id' => $data['treasury_id'],
            'account_id' => $receivables,
            'cost_center_id' => $this->sales->costCenterFor($sale),
            'client_id' => $sale->client_id,
            'sale_id' => $sale->id,
            'payment_method_id' => $data['payment_method_id'],
            'reference' => $sale->number,
            'description' => ($data['description'] ?? null) ?: "سداد على الفاتورة {$sale->number}",
        ], $request->user()?->id);

        try {
            $this->vouchers->post($voucher, $request->user()?->id);
        } catch (RuntimeException $e) {
            return back()->with('warning', $e->getMessage());
        }

        $remaining = $sale->fresh()->remainingAmount();

        return back()->with('success', "تم سند القبض {$voucher->number} — المتبقي ".number_format($remaining, 2));
    }

    /**
     * مرتجع كلي أو جزئي من فاتورة — نفس منطق شاشة الكاشير.
     */
    public function refund(Request $request, Sale $sale): RedirectResponse
    {
        $data = $request->validate([
            'quantities' => ['required', 'array', 'min:1'],
            'quantities.*' => ['numeric', 'min:0'],
            'reason' => ['nullable', 'string', 'max:1000'],
        ]);

        try {
            $return = $this->sales->refund(
                $sale,
                array_map('floatval', $data['quantities']),
                $request->user()?->id,
                $data['reason'] ?? null,
            );
        } catch (RuntimeException $e) {
            return back()->with('warning', $e->getMessage());
        }

        return back()->with('success', "تم المرتجع {$return->number} بمبلغ ".number_format((float) $return->total_amount, 2));
    }

    /**
     * الصف المعروض في القائمة — مبالغ الفاتورة وحالتها.
     *
     * @return array<string, mixed>
     */
    private function summarize(Sale $sale): array
    {
        return [
            'id' => $sale->id,
            'number' => $sale->number,
            'type' => $sale->type,
            'type_label' => $sale->isReturn() ? 'مرتجع' : 'فاتورة',
            'date' => $sale->created_at->format('Y-m-d'),
            'time' => $sale->created_at->format('H:i'),
            'client' => $sale->client?->name,
            'client_mobile' => $sale->client?->mobile,
            'department' => $sale->department?->name,
            'cashier' => $sale->user?->name,
            'payment_method_id' => $sale->payment_method_id,
            'method_label' => $sale->methodLabel(),
            // السعر المعروض في القائمة صافي الضريبة بعد الخصم، فيصحّ الجمع:
            // السعر + الضريبة = الإجمالي.
            'amount_before_tax' => round((float) $sale->total_amount - (float) $sale->tax_amount, 2),
            'tax_amount' => (float) $sale->tax_amount,
            'total' => (float) $sale->total_amount,
            'returned' => $sale->returnedAmount(),
            'net_total' => $sale->netTotal(),
            'paid' => (float) $sale->paid_amount,
            'remaining' => $sale->remainingAmount(),
            'payment_status' => $sale->paymentStatus(),
            'payment_status_label' => $sale->paymentStatusLabel(),
            'can_settle' => $sale->acceptsSettlement(),
            'can_refund' => ! $sale->isReturn() && $sale->netTotal() > 0,
        ];
    }

    /**
     * ملخّص المجموعة المفلترة — يُحسب في قاعدة البيانات لا على الصفحة
     * المعروضة، فيبقى صحيحًا مع الترقيم.
     *
     * @param  Builder<Sale>  $query
     * @return array<string, float|int>
     */
    private function stats($query): array
    {
        $returned = Sale::returnedAmountExpression();

        $salesTotal = round((float) (clone $query)->where('sales.type', 'sale')->sum('total_amount'), 2);
        $collected = round((float) (clone $query)->where('sales.type', 'sale')->sum('paid_amount'), 2);

        // مرتجعات فواتير المجموعة نفسها — لا كل مرتجعات الفترة، حتى لا
        // يُطرح مرتجع فاتورة خارج الفلتر من إجماليها.
        $returnsTotal = round((float) (clone $query)->where('sales.type', 'sale')
            ->selectRaw("COALESCE(SUM({$returned}), 0) AS v")->value('v'), 2);

        // الضريبة صافيةً: المستحقة على الفواتير ناقص ما رُدَّ منها، بالطريقة
        // نفسها حتى لا تختلف عن بقية الأرقام عند تصفية النوع.
        $returnedTax = Sale::returnedAmountExpression('tax_amount');

        $taxTotal = round((float) (clone $query)->where('sales.type', 'sale')->sum('tax_amount')
            - (float) (clone $query)->where('sales.type', 'sale')
                ->selectRaw("COALESCE(SUM({$returnedTax}), 0) AS v")->value('v'), 2);

        return [
            'count' => (clone $query)->count(),
            'sales_total' => $salesTotal,
            'tax_total' => $taxTotal,
            'returns_total' => $returnsTotal,
            'collected' => $collected,
            'remaining' => round(max(0, $salesTotal - $returnsTotal - $collected), 2),
            'partial_count' => (clone $query)->paymentStatus('partial')->count(),
            'unpaid_count' => (clone $query)->paymentStatus('unpaid')->count(),
        ];
    }
}
