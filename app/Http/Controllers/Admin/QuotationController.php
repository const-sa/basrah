<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\ItemOptionResource;
use App\Models\Client;
use App\Models\PaymentMethod;
use App\Models\Quotation;
use App\Models\QuotationItem;
use App\Models\Setting;
use App\Services\QuotationPdf;
use App\Services\SalesService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response as HttpResponse;
use Inertia\Inertia;
use Inertia\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class QuotationController extends Controller
{
    public function index(Request $request): Response
    {
        $filters = [
            'client_id' => $request->integer('client_id') ?: null,
            'from' => $request->date('from')?->toDateString(),
            'to' => $request->date('to')?->toDateString(),
            'search' => $request->string('search')->toString() ?: null,
            'status' => $request->string('status')->toString() ?: null,
        ];

        $query = Quotation::query()
            ->when($filters['client_id'], fn ($q, $id) => $q->where('quotations.client_id', $id))
            ->when($filters['status'], fn ($q, $s) => $q->where('quotations.status', $s))
            ->when($filters['from'], fn ($q, $d) => $q->whereDate('quotations.created_at', '>=', $d))
            ->when($filters['to'], fn ($q, $d) => $q->whereDate('quotations.created_at', '<=', $d))
            ->when($filters['search'], fn ($q, $s) => $q->where(fn ($w) => $w
                ->where('quotations.number', 'like', "%{$s}%")
                ->orWhereHas('client', fn ($c) => $c->where('name', 'like', "%{$s}%"))));

        return Inertia::render('admin/quotations/Index', [
            'quotations' => (clone $query)
                ->with(['client:id,name', 'user:id,name', 'invoice:id,quotation_id,number'])
                ->latest('quotations.id')
                ->paginate(20)
                ->withQueryString()
                ->through(fn (Quotation $q) => $this->summarize($q)),
            'stats' => $this->stats(clone $query),
            'filters' => $filters,
            'clients' => Client::orderByDesc('is_walk_in')->orderBy('name')->limit(300)->get(['id', 'name']),
            'methods' => PaymentMethod::options(),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('admin/quotations/Form', [
            'departments' => \App\Models\Department::selling()->orderBy('sort_order')->get(['id', 'name']),
            'clients' => Client::orderByDesc('is_walk_in')->orderBy('name')->limit(300)->get(['id', 'name']),
            'items' => ItemOptionResource::list(
                \App\Models\Item::where('is_active', true)->with(['category:id,name'])->orderBy('name')->get(),
            ),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'client_id' => ['required', 'exists:clients,id'],
            'department_id' => ['required', 'exists:departments,id'],
            'discount_amount' => ['required', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'valid_until' => ['nullable', 'date'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.item_id' => ['required', 'exists:items,id'],
            'items.*.quantity' => ['required', 'numeric', 'min:0.001'],
            'items.*.unit_price' => ['required', 'numeric', 'min:0'],
            'items.*.tax_amount' => ['required', 'numeric', 'min:0'],
        ]);

        try {
            DB::transaction(function () use ($data, $request) {
                // Generate a quotation number
                $last = Quotation::latest('id')->first();
                $number = 'QT-' . str_pad(($last ? $last->id + 1 : 1), 6, '0', STR_PAD_LEFT);

                // Calculate totals
                $subtotal = 0;
                $taxAmount = 0;

                foreach ($data['items'] as $item) {
                    $itemTotal = round($item['quantity'] * $item['unit_price'], 2);
                    $subtotal += $itemTotal;
                    $taxAmount += $item['tax_amount'];
                }

                $totalAmount = $subtotal - $data['discount_amount'] + $taxAmount;

                $quotation = Quotation::create([
                    'number' => $number,
                    'client_id' => $data['client_id'],
                    'department_id' => $data['department_id'],
                    'user_id' => $request->user()->id,
                    'subtotal' => $subtotal,
                    'discount_amount' => $data['discount_amount'],
                    'tax_amount' => $taxAmount,
                    'total_amount' => $totalAmount,
                    'notes' => $data['notes'],
                    'valid_until' => $data['valid_until'],
                    'status' => 'pending',
                ]);

                foreach ($data['items'] as $itemData) {
                    QuotationItem::create([
                        'quotation_id' => $quotation->id,
                        'item_id' => $itemData['item_id'],
                        'quantity' => $itemData['quantity'],
                        'unit_price' => $itemData['unit_price'],
                        'total_price' => round($itemData['quantity'] * $itemData['unit_price'], 2),
                    ]);
                }
            });
        } catch (RuntimeException $e) {
            return back()->with('warning', $e->getMessage());
        }

        return redirect()->route('quotations.index')->with('success', 'تم حفظ عرض السعر بنجاح');
    }

    public function edit(Quotation $quotation): Response
    {
        $quotation->load(['items.item:id,name,code,price,tax_rate']);

        return Inertia::render('admin/quotations/Form', [
            'quotation' => $quotation,
            'departments' => \App\Models\Department::selling()->orderBy('sort_order')->get(['id', 'name']),
            'clients' => Client::orderByDesc('is_walk_in')->orderBy('name')->limit(300)->get(['id', 'name']),
            'items' => ItemOptionResource::list(
                \App\Models\Item::where('is_active', true)->with(['category:id,name'])->orderBy('name')->get(),
            ),
        ]);
    }

    public function update(Request $request, Quotation $quotation)
    {
        $data = $request->validate([
            'client_id' => ['required', 'exists:clients,id'],
            'department_id' => ['required', 'exists:departments,id'],
            'discount_amount' => ['required', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'valid_until' => ['nullable', 'date'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.item_id' => ['required', 'exists:items,id'],
            'items.*.quantity' => ['required', 'numeric', 'min:0.001'],
            'items.*.unit_price' => ['required', 'numeric', 'min:0'],
            'items.*.tax_amount' => ['required', 'numeric', 'min:0'],
        ]);

        try {
            DB::transaction(function () use ($data, $quotation) {
                // Calculate totals
                $subtotal = 0;
                $taxAmount = 0;

                foreach ($data['items'] as $item) {
                    $itemTotal = round($item['quantity'] * $item['unit_price'], 2);
                    $subtotal += $itemTotal;
                    $taxAmount += $item['tax_amount'];
                }

                $totalAmount = $subtotal - $data['discount_amount'] + $taxAmount;

                $quotation->update([
                    'client_id' => $data['client_id'],
                    'department_id' => $data['department_id'],
                    'subtotal' => $subtotal,
                    'discount_amount' => $data['discount_amount'],
                    'tax_amount' => $taxAmount,
                    'total_amount' => $totalAmount,
                    'notes' => $data['notes'],
                    'valid_until' => $data['valid_until'],
                ]);

                $quotation->items()->delete();

                foreach ($data['items'] as $itemData) {
                    QuotationItem::create([
                        'quotation_id' => $quotation->id,
                        'item_id' => $itemData['item_id'],
                        'quantity' => $itemData['quantity'],
                        'unit_price' => $itemData['unit_price'],
                        'total_price' => round($itemData['quantity'] * $itemData['unit_price'], 2),
                    ]);
                }
            });
        } catch (RuntimeException $e) {
            return back()->with('warning', $e->getMessage());
        }

        return redirect()->route('quotations.index')->with('success', 'تم تعديل عرض السعر بنجاح');
    }

    public function destroy(Quotation $quotation)
    {
        $quotation->delete();
        return redirect()->route('quotations.index')->with('success', 'تم حذف عرض السعر بنجاح');
    }

    public function show(Request $request, Quotation $quotation)
    {
        $quotation->load([
            'items.item:id,name,code',
            'client:id,name,mobile',
            'user:id,name'
        ]);
        
        $data = [
            'quotation' => $this->summarize($quotation) + [
                'notes' => $quotation->notes,
                'client_mobile' => $quotation->client?->mobile,
                'subtotal' => (float) $quotation->subtotal,
                'discount_amount' => (float) $quotation->discount_amount,
            ],
            'items' => $quotation->items->map(fn (QuotationItem $l) => [
                'id' => $l->id,
                'item_id' => $l->item_id,
                'name' => $l->item?->name ?? '—',
                'code' => $l->item?->code,
                'quantity' => (float) $l->quantity,
                'unit_price' => (float) $l->unit_price,
                'total_price' => (float) $l->total_price,
                'tax_amount' => round((float) $l->quantity * (float) $l->unit_price * (($l->item?->tax_rate ?? 15) / 100), 2),
            ]),
        ];

        // Only the printable sheet in the list modal draws the business identity.
        // The Show page never renders it, and passing it there would leak a stray
        // attribute onto the component root.
        if ($request->wantsJson()) {
            return response()->json($data + ['issuer' => $this->issuer()]);
        }

        return Inertia::render('admin/quotations/Show', $data);
    }

    /**
     * Header of the printed quotation: identity of the issuing business.
     *
     * No ZATCA QR here — that code belongs on a tax invoice for a completed
     * sale, and a quotation is only an offer, so stamping one on it would
     * present a price offer as a settled invoice.
     *
     * @return array<string, mixed>
     */
    private function issuer(): array
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
        ];
    }

    public function convert(Request $request, Quotation $quotation, SalesService $sales): RedirectResponse
    {
        $data = $request->validate([
            'payment_method_id' => ['required', Rule::exists('payment_methods', 'id')->where('is_active', true)],
            'paid_amount' => ['nullable', 'numeric', 'min:0'],
        ]);

        $quotation->load(['invoice:id,quotation_id,number', 'items']);

        if ($quotation->invoice) {
            return back()->with('warning', "لعرض السعر {$quotation->number} فاتورة بالفعل ({$quotation->invoice->number}).");
        }

        if ($quotation->status === 'rejected') {
            return back()->with('warning', 'عرض السعر مرفوض — لا تُصدر عليه فاتورة.');
        }

        if ($quotation->items->isEmpty()) {
            return back()->with('warning', 'عرض السعر بلا أصناف — لا فاتورة له.');
        }

        try {
            $sale = $sales->checkout([
                'lines' => $quotation->items->map(fn (QuotationItem $line) => [
                    'item_id' => $line->item_id,
                    'quantity' => (float) $line->quantity,
                    'unit_price' => (float) $line->unit_price,
                ])->all(),
                'client_id' => $quotation->client_id,
                'department_id' => $quotation->department_id,
                'quotation_id' => $quotation->id,
                'payment_method_id' => $data['payment_method_id'],
                'discount_amount' => (float) $quotation->discount_amount,
                'paid_amount' => $data['paid_amount'] ?? null,
                'notes' => $quotation->notes,
            ], $request->user()->id);
        } catch (ValidationException $e) {
            return back()->with('warning', collect($e->errors())->flatten()->first());
        } catch (RuntimeException $e) {
            return back()->with('warning', $e->getMessage());
        }

        if ($quotation->status !== 'accepted') {
            $quotation->update(['status' => 'accepted']);
        }

        return redirect()->route('sales.show', $sale)
            ->with('success', "تم إصدار الفاتورة {$sale->number} من عرض السعر {$quotation->number}");
    }

    public function changeStatus(Request $request, Quotation $quotation): RedirectResponse
    {
        $data = $request->validate([
            'status' => ['required', 'in:pending,accepted,rejected'],
        ]);

        $quotation->update(['status' => $data['status']]);

        return back()->with('success', 'تم تحديث حالة عرض السعر');
    }

    public function pdf(Request $request, Quotation $quotation, QuotationPdf $pdfService): HttpResponse
    {
        try {
            $content = $pdfService->render($quotation);
        } catch (RuntimeException $e) {
            abort(500, $e->getMessage());
        }

        $disposition = $request->boolean('download') ? 'attachment' : 'inline';

        return response($content, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => $disposition.'; filename="QT-'.$quotation->number.'.pdf"',
        ]);
    }

    private function summarize(Quotation $quotation): array
    {
        $statuses = [
            'pending' => 'قيد الانتظار',
            'accepted' => 'مقبول',
            'rejected' => 'مرفوض',
        ];

        return [
            'id' => $quotation->id,
            'number' => $quotation->number,
            'date' => $quotation->created_at->format('Y-m-d'),
            'time' => $quotation->created_at->format('H:i'),
            'valid_until' => $quotation->valid_until?->format('Y-m-d'),
            'client' => $quotation->client?->name,
            'user' => $quotation->user?->name,
            'subtotal' => (float) $quotation->subtotal,
            'discount_amount' => (float) $quotation->discount_amount,
            'tax_amount' => (float) $quotation->tax_amount,
            'total' => (float) $quotation->total_amount,
            'status' => $quotation->status,
            'status_label' => $statuses[$quotation->status] ?? $quotation->status,
            'invoice' => $quotation->relationLoaded('invoice') && $quotation->invoice
                ? ['id' => $quotation->invoice->id, 'number' => $quotation->invoice->number]
                : null,
        ];
    }

    private function stats($query): array
    {
        $total = round((float) (clone $query)->sum('total_amount'), 2);

        return [
            'count' => (clone $query)->count(),
            'total' => $total,
            'accepted_count' => (clone $query)->where('quotations.status', 'accepted')->count(),
            'pending_count' => (clone $query)->where('quotations.status', 'pending')->count(),
        ];
    }
}
