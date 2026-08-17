<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\Contract;
use App\Models\ContractTemplate;
use App\Models\Setting;
use App\Services\ContractService;
use App\Services\WhatsappNotifier;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

class ContractsController extends Controller
{
    public function __construct(
        private readonly ContractService $contracts,
        private readonly WhatsappNotifier $whatsapp,
    ) {}

    public function index(Request $request): Response
    {
        $user = $request->user();

        $query = Contract::query()
            ->whereHas('booking', fn ($q) => $q->visibleTo($user))
            ->with(['booking:id,reference,unit_id,booking_date', 'booking.unit:id,name', 'client:id,name,mobile'])
            ->when($request->string('status')->toString(), fn ($q, $s) => $q->where('status', $s))
            ->when($request->string('search')->toString(), fn ($q, $term) => $q->where(
                fn ($sub) => $sub->where('number', 'like', "%{$term}%")
                    ->orWhereHas('client', fn ($c) => $c->where('name', 'like', "%{$term}%")),
            ));

        return Inertia::render('admin/contracts/Index', [
            'contracts' => (clone $query)->latest('id')->paginate(20)->withQueryString()
                ->through(fn (Contract $c) => [
                    'id' => $c->id,
                    'number' => $c->number,
                    'status' => $c->status,
                    'status_label' => $c->statusLabel(),
                    'client_name' => $c->client?->name,
                    'client_mobile' => $c->client?->mobile,
                    'booking_reference' => $c->booking?->reference,
                    'unit_name' => $c->booking?->unit?->name,
                    'booking_date' => $c->booking?->booking_date?->toDateString(),
                    'sent_at' => $c->sent_at?->format('Y-m-d H:i'),
                    'created_at' => $c->created_at->toDateString(),
                ]),
            'filters' => $request->only(['status', 'search']),
            'statuses' => collect(Contract::STATUSES)->map(fn ($l, $k) => ['key' => $k, 'label' => $l])->values(),
            'templates' => ContractTemplate::where('is_active', true)->get(['id', 'name', 'is_default']),
            // الحجوزات التي لا عقد لها بعد — هي المرشّحة للتوليد
            'bookings' => Booking::visibleTo($user)->blocking()
                ->whereDoesntHave('contracts')
                ->with('unit:id,name', 'client:id,name')
                ->latest('id')->limit(200)->get()
                ->map(fn (Booking $b) => [
                    'id' => $b->id,
                    'label' => $b->reference.' — '.($b->unit?->name ?? '').' — '.($b->client?->name ?? 'بلا عميل'),
                ]),
            'stats' => [
                'total' => (clone $query)->count(),
                'draft' => (clone $query)->where('status', 'draft')->count(),
                'sent' => (clone $query)->where('status', 'sent')->count(),
                'signed' => (clone $query)->where('status', 'signed')->count(),
            ],
        ]);
    }

    public function show(Contract $contract): Response
    {
        $contract->load(['booking.unit', 'booking.eventType', 'client', 'template']);

        $settings = Setting::current();

        // بيانات العقد تُقرأ من اللقطة المجمَّدة لا من الحجز: العقد يشهد على
        // ما اتُّفق عليه يوم توقيعه، وتعديل الحجز بعده لا يغيّر ما وُقّع.
        //
        // اللقطة تكتب «—» مكان الحقل الغائب لأنها تُصاغ لتُطبع في نص العقد،
        // فتُعاد هنا إلى null: الصفحة تميّز الغائب لتخفي حقله وتمنع الإرسال
        // إلى «رقم» ليس رقمًا.
        $data = collect($contract->data ?? [])
            ->map(fn ($value) => $value === '—' ? null : $value)
            ->all();

        return Inertia::render('admin/contracts/Show', [
            'contract' => [
                'id' => $contract->id,
                'number' => $contract->number,
                'body' => $contract->body,
                // العقود المولّدة قبل فصل الشروط تحمل النص كاملًا في body،
                // فيبقى بابُ الشروط عندها فارغًا بدل أن يكرّر النص.
                'terms' => $contract->terms,
                'status' => $contract->status,
                'status_label' => $contract->statusLabel(),
                'contract_date' => $data['contract_date'] ?? $contract->created_at?->toDateString(),
                'client_name' => $contract->client?->name ?? ($data['client_name'] ?? null),
                'client_mobile' => $contract->client?->mobile ?? ($data['client_mobile'] ?? null),
                'client_id_number' => $data['client_id_number'] ?? $contract->client?->national_id,
                'booking_id' => $contract->booking_id,
                'booking_reference' => $contract->booking?->reference ?? ($data['booking_reference'] ?? null),
                'unit_name' => $data['unit_name'] ?? $contract->booking?->unit?->name,
                'unit_logo_url' => $contract->booking?->unit?->logoUrl(),
                'unit_type' => $contract->booking?->unit?->type,
                'event_name' => $contract->booking?->eventType?->name,
                'sections' => $data['sections'] ?? null,
                'booking_date' => $data['booking_date'] ?? null,
                'last_day_date' => $data['last_day_date'] ?? null,
                'days_count' => $data['days_count'] ?? null,
                'period' => $data['period'] ?? null,
                'starts_at' => $data['starts_at'] ?? null,
                'ends_at' => $data['ends_at'] ?? null,
                'guests_count' => $data['guests_count'] ?? null,
                'total_amount' => $data['total_amount'] ?? null,
                'deposit_amount' => $data['deposit_amount'] ?? null,
                'remaining_amount' => $data['remaining_amount'] ?? null,
                'sent_at' => $contract->sent_at?->format('Y-m-d H:i'),
                'signed_at' => $contract->signed_at?->format('Y-m-d H:i'),
            ],
            'issuer' => [
                'business_name' => $data['org_name'] ?? ($settings->business_name ?: config('app.name')),
                'logo_url' => $settings->logo_path ? asset($settings->logo_path) : null,
                'phone' => $settings->phone,
                'address' => $settings->address,
                'tax_number' => $settings->tax_enabled ? $settings->tax_number : null,
                'manager_name' => $settings->manager_name,
                'manager_signature_url' => $settings->manager_signature_path
                    ? asset($settings->manager_signature_path)
                    : null,
                'stamp_url' => $settings->stamp_path ? asset($settings->stamp_path) : null,
            ],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'booking_id' => ['required', 'exists:bookings,id'],
            'contract_template_id' => ['nullable', 'exists:contract_templates,id'],
        ]);

        $booking = Booking::findOrFail($data['booking_id']);

        if (! $request->user()?->canAccessUnit($booking->unit_id)) {
            abort(403, 'ليس لديك صلاحية العمل على هذه الوحدة.');
        }

        $template = isset($data['contract_template_id'])
            ? ContractTemplate::find($data['contract_template_id'])
            : null;

        // التوليد يُطلب الآن من سجل الحجوزات أيضًا، وهناك لا يختار الموظف
        // قالبًا — فغياب القالب الافتراضي رسالةٌ توجّهه لا صفحة خطأ.
        try {
            $contract = $this->contracts->generate($booking, $template, $request->user()?->id);
        } catch (RuntimeException $e) {
            return back()->with('warning', $e->getMessage());
        }

        return back()->with('success', "تم توليد العقد {$contract->number}");
    }

    /**
     * إعادة بناء نص المسودة من نموذج العقد الحالي.
     *
     * يُطلب بعد تحرير النموذج: العقد يُجمَّد وقت توليده، فلا تصل تعديلات
     * النموذج إلى مسودة صدرت قبلها إلا بهذا الطلب الصريح.
     */
    public function refresh(Request $request, Contract $contract): RedirectResponse
    {
        // العقد المُرسل أو الموقّع نسخةٌ بيد العميل: تغيير نصه بعدها تزويرٌ
        // للورقة التي وقّعها، فيُلغى ويُولَّد غيره لا أن يُبدَّل تحت رقمه.
        if ($contract->isSent()) {
            return back()->with('warning', 'لا يُحدَّث نص عقد أُرسل للعميل أو وُقِّع — ولّد عقدًا جديدًا بدله.');
        }

        $data = $request->validate([
            'contract_template_id' => ['nullable', 'exists:contract_templates,id'],
        ]);

        $template = isset($data['contract_template_id'])
            ? ContractTemplate::find($data['contract_template_id'])
            : null;

        try {
            $this->contracts->refresh($contract, $template);
        } catch (RuntimeException $e) {
            return back()->with('warning', $e->getMessage());
        }

        return back()->with('success', 'تم تحديث نص العقد من النموذج المعتمد');
    }

    /**
     * إرسال العقد على واتساب العميل.
     */
    public function send(Request $request, Contract $contract): RedirectResponse
    {
        $contract->loadMissing('client');

        if (blank($contract->client?->mobile)) {
            return back()->with('warning', 'لا يوجد رقم جوال للعميل — لا يمكن الإرسال.');
        }

        $this->whatsapp->contract($contract, $request->user()?->id);

        $contract->update(['status' => 'sent', 'sent_at' => now()]);

        return back()->with('success', 'تم إرسال العقد على واتساب العميل');
    }

    public function changeStatus(Request $request, Contract $contract): RedirectResponse
    {
        $data = $request->validate([
            'status' => ['required', Rule::in(array_keys(Contract::STATUSES))],
        ]);

        $contract->update([
            'status' => $data['status'],
            'signed_at' => $data['status'] === 'signed' ? now() : $contract->signed_at,
        ]);

        return back()->with('success', 'تم تحديث حالة العقد');
    }

    public function destroy(Contract $contract): RedirectResponse
    {
        if ($contract->isSent()) {
            return back()->with('warning', 'لا يُحذف عقد أُرسل للعميل — ألغِه بدل حذفه.');
        }

        $contract->delete();

        return back()->with('success', 'تم حذف العقد');
    }
}
