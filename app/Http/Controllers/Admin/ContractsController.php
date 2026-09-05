<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\Client;
use App\Models\Contract;
use App\Models\ContractTemplate;
use App\Models\Quotation;
use App\Models\Setting;
use App\Services\ContractPdf;
use App\Services\ContractService;
use App\Services\WhatsappNotifier;
use App\Support\ClientType;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

class ContractsController extends Controller
{
    public function __construct(
        private readonly ContractService $contracts,
        private readonly ContractPdf $pdf,
        private readonly WhatsappNotifier $whatsapp,
    ) {}

    /**
     * ملف العقد PDF — يُعرض في المتصفح افتراضيًا ويُنزَّل بـ?download=1.
     */
    public function pdf(Request $request, Contract $contract): HttpResponse
    {
        try {
            $content = $this->pdf->render($contract);
        } catch (RuntimeException $e) {
            abort(500, $e->getMessage());
        }

        $disposition = $request->boolean('download') ? 'attachment' : 'inline';

        return response($content, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => $disposition.'; filename="'.$this->pdf->filename($contract).'"',
        ]);
    }

    /**
     * The full contract register — every contract the user may see, whatever
     * it was drawn from. This is the contracts section's own screen.
     */
    public function index(Request $request): Response
    {
        return $this->register($request, 'all');
    }

    /**
     * The same screen reached from the pools menu, showing that activity's own
     * contracts only.
     *
     * A pools employee opening «العقود» from their menu is looking for the
     * maintenance and installation agreements they wrote, and a register that
     * answers with hall and chalet rentals buries them under work that is not
     * theirs. The overseeing contracts section still sees everything.
     */
    public function poolsIndex(Request $request): Response
    {
        return $this->register($request, 'quotation');
    }

    /**
     * @param  'all'|'quotation'  $scope
     */
    private function register(Request $request, string $scope): Response
    {
        $user = $request->user();
        $poolsOnly = $scope === 'quotation';

        $query = Contract::query()
            // Anything not drawn from a booking is the pools' — a quotation
            // contract or one written straight onto a client. Neither has a
            // unit behind it, so unit visibility cannot scope them and
            // `contracts.view` is the whole gate there.
            ->when($poolsOnly, fn ($q) => $q->whereNull('booking_id'))
            ->unless($poolsOnly, fn ($q) => $q->where(fn ($w) => $w
                ->whereNull('booking_id')
                ->orWhereHas('booking', fn ($b) => $b->visibleTo($user))))
            ->with([
                'booking:id,reference,unit_id,booking_date', 'booking.unit:id,name',
                'quotation:id,number', 'client:id,name,mobile',
            ])
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
                    'from_quotation' => $c->fromQuotation(),
                    'subject' => $c->subject(),
                    'quotation_number' => $c->quotation?->number ?? ($c->data['quotation_number'] ?? null),
                    'booking_reference' => $c->booking?->reference,
                    'unit_name' => $c->booking?->unit?->name,
                    'booking_date' => $c->booking?->booking_date?->toDateString(),
                    'total_amount' => $c->data['total_amount'] ?? null,
                    'sent_at' => $c->sent_at?->format('Y-m-d H:i'),
                    'created_at' => $c->created_at->toDateString(),
                ]),
            'scope' => $scope,
            'filters' => $request->only(['status', 'search']),
            'statuses' => collect(Contract::STATUSES)->map(fn ($l, $k) => ['key' => $k, 'label' => $l])->values(),
            'templates' => ContractTemplate::where('is_active', true)->get(['id', 'name', 'is_default']),
            // الحجوزات التي لا عقد لها بعد — هي المرشّحة للتوليد.
            // The pools screen offers no booking source at all: a contract
            // drawn there would land outside the register that drew it.
            'bookings' => $poolsOnly ? [] : Booking::visibleTo($user)->blocking()
                ->whereDoesntHave('contracts')
                ->with('unit:id,name', 'client:id,name')
                ->latest('id')->limit(200)->get()
                ->map(fn (Booking $b) => [
                    'id' => $b->id,
                    'label' => $b->reference.' — '.($b->unit?->name ?? '').' — '.($b->client?->name ?? 'بلا عميل'),
                ]),
            // Quotations still open and not yet contracted. A rejected quotation
            // is excluded outright: the client turned that price down, and a
            // contract is exactly the thing that must not be drawn from it.
            'quotations' => Quotation::where('status', '!=', 'rejected')
                ->whereDoesntHave('contracts')
                ->with('client:id,name', 'department:id,name')
                ->latest('id')->limit(200)->get()
                ->map(fn (Quotation $q) => [
                    'id' => $q->id,
                    'label' => $q->number.' — '.($q->client?->name ?? 'بلا عميل')
                        .' — '.number_format((float) $q->total_amount, 2).' ريال',
                    'department' => $q->department?->name,
                    'status' => $q->status,
                    'accepted' => $q->status === 'accepted',
                ]),
            // Whom a contract with no source document is written for. The pools
            // screen offers its own clients, as its counter does.
            'clients' => Client::query()
                ->when($poolsOnly, fn ($q) => $q->ofType([ClientType::POOL]))
                ->where('is_active', true)
                ->orderBy('name')->limit(300)->get(['id', 'name', 'mobile'])
                ->map(fn (Client $c) => [
                    'id' => $c->id,
                    'label' => $c->name.($c->mobile ? ' — '.$c->mobile : ''),
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
        $contract->load(['booking.unit', 'booking.eventType', 'quotation', 'client', 'template']);

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
                'contract_date_hijri' => $data['contract_date_hijri'] ?? null,
                'client_name' => $contract->client?->name ?? ($data['client_name'] ?? null),
                'client_mobile' => $contract->client?->mobile ?? ($data['client_mobile'] ?? null),
                'client_id_number' => $data['client_id_number'] ?? $contract->client?->national_id,
                'client_address' => $data['client_address'] ?? null,
                'booking_id' => $contract->booking_id,
                'booking_reference' => $contract->booking?->reference ?? ($data['booking_reference'] ?? null),
                'unit_name' => $data['unit_name'] ?? $contract->booking?->unit?->name,
                'unit_code' => $contract->booking?->unit?->code,
                'unit_logo_url' => $contract->booking?->unit?->logoUrl(),
                'unit_type' => $contract->booking?->unit?->type,
                'event_name' => $contract->booking?->eventType?->name,
                'sections' => $data['sections'] ?? null,
                'booking_date' => $data['booking_date'] ?? null,
                'booking_date_hijri' => $data['booking_date_hijri'] ?? null,
                'last_day_date' => $data['last_day_date'] ?? null,
                'last_day_date_hijri' => $data['last_day_date_hijri'] ?? null,
                'days_count' => $data['days_count'] ?? null,
                'duration_label' => $data['duration_label'] ?? null,
                'check_in_day' => $data['check_in_day'] ?? null,
                'check_out_day' => $data['check_out_day'] ?? null,
                'check_in_time' => $data['check_in_time'] ?? null,
                'check_out_time' => $data['check_out_time'] ?? null,
                'period' => $data['period'] ?? null,
                'starts_at' => $data['starts_at'] ?? null,
                'ends_at' => $data['ends_at'] ?? null,
                'guests_count' => $data['guests_count'] ?? null,
                'total_amount' => $data['total_amount'] ?? null,
                'total_amount_words' => $data['total_amount_words'] ?? null,
                'deposit_amount' => $data['deposit_amount'] ?? null,
                'remaining_amount' => $data['remaining_amount'] ?? null,
                'security_deposit' => $data['security_deposit'] ?? null,
                // Quotation contracts: the priced lines are the scope of work,
                // read from the snapshot so a later edit to the quotation
                // cannot change what a contract already issued says.
                'from_quotation' => $contract->fromQuotation(),
                // Drawn on the pools' piping-and-installation pad — the page
                // prints that form instead of the standard contract sheet.
                'is_installation_form' => $contract->isInstallationForm(),
                // Drawn on the pools' monthly-maintenance sheet — likewise.
                'is_maintenance_form' => $contract->isMaintenanceForm(),
                'first_installment' => $data['first_installment'] ?? null,
                'second_installment' => $data['second_installment'] ?? null,
                // Measured at the site and typed onto the contract; whatever is
                // still missing prints as a blank run to be written by hand.
                ...collect(ContractService::DIMENSIONS)
                    ->mapWithKeys(fn (string $key) => [$key => $data[$key] ?? null])->all(),
                'subject' => $contract->subject(),
                'quotation_id' => $contract->quotation_id,
                'quotation_number' => $contract->quotation?->number ?? ($data['quotation_number'] ?? null),
                'quotation_date' => $data['quotation_date'] ?? null,
                'valid_until' => $data['valid_until'] ?? null,
                'items' => $contract->lines(),
                'subtotal' => $data['subtotal'] ?? null,
                'discount_amount' => $data['discount_amount'] ?? null,
                'tax_amount' => $data['tax_amount'] ?? null,
                // الضريبة كما جُمِّدت يوم إصدار العقد — لا كما هي اليوم.
                'is_taxable' => (bool) ($data['is_taxable'] ?? false),
                'tax_rate' => $data['tax_rate'] ?? null,
                'sent_at' => $contract->sent_at?->format('Y-m-d H:i'),
                'signed_at' => $contract->signed_at?->format('Y-m-d H:i'),
            ],
            'issuer' => $this->issuer($data['org_name'] ?? null),
        ]);
    }

    /**
     * Generate a contract from a booking (halls and chalets).
     */
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
     * Generate a contract from a quotation (pools — sales and maintenance).
     */
    public function storeFromQuotation(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'quotation_id' => ['required', 'exists:quotations,id'],
            'contract_template_id' => ['nullable', 'exists:contract_templates,id'],
        ]);

        $quotation = Quotation::findOrFail($data['quotation_id']);

        // One contract per quotation. Two contracts over the same priced lines
        // would be two live agreements for one job, and the client holds both.
        if ($quotation->contracts()->exists()) {
            return back()->with('warning', 'لعرض السعر هذا عقد بالفعل.');
        }

        if ($quotation->status === 'rejected') {
            return back()->with('warning', 'عرض السعر مرفوض — لا يُحرَّر عليه عقد.');
        }

        $template = isset($data['contract_template_id'])
            ? ContractTemplate::find($data['contract_template_id'])
            : null;

        try {
            $contract = $this->contracts->generateFromQuotation($quotation, $template, $request->user()?->id);
        } catch (RuntimeException $e) {
            return back()->with('warning', $e->getMessage());
        }

        // Drawing the contract is the acceptance: the client agreed to this
        // price, so the quotation should not keep showing as still pending.
        if ($quotation->status !== 'accepted') {
            $quotation->update(['status' => 'accepted']);
        }

        return back()->with('success', "تم توليد العقد {$contract->number} من عرض السعر {$quotation->number}");
    }

    /**
     * Write a contract straight onto a client — no booking, no quotation.
     *
     * The installation pad is filled at the client's house, so the job is often
     * contracted before anything is quoted. The value may be left out and
     * written on the paper by hand.
     */
    public function storeDirect(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'client_id' => ['required', 'exists:clients,id'],
            'contract_template_id' => ['nullable', 'exists:contract_templates,id'],
            'total_amount' => ['nullable', 'numeric', 'min:0'],
        ]);

        $template = isset($data['contract_template_id'])
            ? ContractTemplate::find($data['contract_template_id'])
            : null;

        try {
            $contract = $this->contracts->generateDirect(
                Client::findOrFail($data['client_id']),
                $template,
                isset($data['total_amount']) ? (float) $data['total_amount'] : null,
                $request->user()?->id,
            );
        } catch (RuntimeException $e) {
            return back()->with('warning', $e->getMessage());
        }

        return back()->with('success', "تم تحرير العقد {$contract->number}");
    }

    /**
     * The letterhead the contract is printed under — the same one whether the
     * sheet is being read or filled in, so the edit screen is the document.
     *
     * @return array<string, mixed>
     */
    private function issuer(?string $orgName = null): array
    {
        $settings = Setting::current();

        return [
            'business_name' => $orgName ?: ($settings->business_name ?: config('app.name')),
            'logo_url' => $settings->logo_path ? asset($settings->logo_path) : null,
            'phone' => $settings->phone,
            'whatsapp' => $settings->whatsapp !== $settings->phone ? $settings->whatsapp : null,
            'address' => $settings->address,
            'tax_number' => $settings->tax_enabled ? $settings->tax_number : null,
            // The maintenance sheet's letterhead carries the CR number where
            // the installation pad carries the tax number.
            'commercial_register' => $settings->commercial_register,
            'manager_name' => $settings->manager_name,
            'manager_signature_url' => $settings->manager_signature_path
                ? asset($settings->manager_signature_path)
                : null,
            'stamp_url' => $settings->stamp_path ? asset($settings->stamp_path) : null,
        ];
    }

    /**
     * Which box on the edit screen each field belongs in. Anything unlisted
     * falls into the last group, so a field added to the placeholders is
     * editable the day it exists rather than the day this list is remembered.
     */
    private const FIELD_GROUPS = [
        'بيانات العقد' => ['contract_date', 'contract_date_hijri', 'subject', 'org_name',
            'quotation_number', 'quotation_date', 'valid_until'],
        'الطرف الثاني' => ['client_name', 'client_mobile', 'client_id_number', 'client_address'],
        'القيمة والدفعات' => ['total_amount', 'total_amount_words', 'subtotal', 'discount_amount',
            'tax_rate', 'tax_amount', 'deposit_amount', 'remaining_amount',
            'first_installment', 'second_installment', 'security_deposit'],
        'مقاسات المسبح' => ContractService::DIMENSIONS,
    ];

    /**
     * The edit form for a draft — every field the contract prints.
     */
    public function edit(Contract $contract): Response|RedirectResponse
    {
        if ($contract->isSent()) {
            return redirect()->route('contracts.show', $contract)
                ->with('warning', 'لا يُعدَّل عقد أُرسل للعميل أو وُقِّع — ولّد عقدًا جديدًا بدله.');
        }

        $contract->load(['booking.unit', 'booking.eventType', 'quotation', 'client']);

        $data = $contract->data ?? [];

        // A field the contract carries, plus the site measurements when it is
        // printed on the form that asks for them. The number is not offered:
        // it identifies the contract.
        $keys = collect(ContractTemplate::PLACEHOLDERS)->keys()
            ->reject(fn (string $key) => $key === 'contract_number')
            ->filter(fn (string $key) => (isset($data[$key]) && is_scalar($data[$key]))
                || ($contract->isInstallationForm() && in_array($key, ContractService::DIMENSIONS, true)));

        $groups = $keys
            ->groupBy(fn (string $key) => collect(self::FIELD_GROUPS)
                ->search(fn (array $members) => in_array($key, $members, true)) ?: 'تفاصيل العقد')
            ->map(fn ($members, $title) => [
                'title' => $title,
                'fields' => $members->map(fn (string $key) => [
                    'key' => $key,
                    'label' => ContractTemplate::PLACEHOLDERS[$key],
                    // «—» is the snapshot's empty, and an input should show it
                    // as empty rather than asking the employee to erase a dash.
                    'value' => ($data[$key] ?? '—') === '—' ? '' : (string) $data[$key],
                ])->values(),
            ])->values();

        return Inertia::render('admin/contracts/Edit', [
            'contract' => [
                'id' => $contract->id,
                'number' => $contract->number,
                'client_id' => $contract->client_id,
                'items' => $contract->lines(),
                'body' => $contract->body,
                'terms' => $contract->terms,
                'groups' => $groups,
                // A contract with a source document says so, so an edit that
                // parts it from its quotation or booking is a knowing one.
                'quotation_number' => $contract->quotation?->number,
                'booking_reference' => $contract->booking?->reference,
                'is_installation_form' => $contract->isInstallationForm(),
                'is_maintenance_form' => $contract->isMaintenanceForm(),
                // What the sheet draws around the editable runs: the logo it is
                // headed with, and the few facts it states rather than asks for.
                'from_quotation' => $contract->fromQuotation(),
                'unit_type' => $contract->booking?->unit?->type,
                'unit_logo_url' => $contract->booking?->unit?->logoUrl(),
                'event_name' => $contract->booking?->eventType?->name,
                'quotation_date' => ($data['quotation_date'] ?? '—') === '—' ? null : $data['quotation_date'],
                'is_taxable' => (bool) ($data['is_taxable'] ?? false),
            ],
            'issuer' => $this->issuer($data['org_name'] ?? null),
            'clients' => Client::where('is_active', true)
                ->orderBy('name')->limit(300)->get(['id', 'name', 'mobile'])
                ->map(fn (Client $c) => [
                    'id' => $c->id,
                    'label' => $c->name.($c->mobile ? ' — '.$c->mobile : ''),
                ]),
        ]);
    }

    /**
     * Save the edited draft — same number, same date, rewritten text.
     */
    public function update(Request $request, Contract $contract): RedirectResponse
    {
        // A sent or signed contract is the paper the client holds: correcting
        // it under its own number forges what was signed.
        if ($contract->isSent()) {
            return back()->with('warning', 'لا يُعدَّل عقد أُرسل للعميل أو وُقِّع — ولّد عقدًا جديدًا بدله.');
        }

        // A count may arrive as a number from one client and as the words «حسب
        // الاتفاق» from another; it is read as text either way, so it is made
        // text before the rules see it rather than being guessed at twice.
        $text = fn ($value) => is_scalar($value) ? (string) $value : null;

        $request->merge(['items' => collect($request->input('items', []))
            ->map(fn ($line) => is_array($line) ? [
                ...$line,
                'quantity' => $text($line['quantity'] ?? null),
                'unit_price' => $text($line['unit_price'] ?? null),
                'total_price' => $text($line['total_price'] ?? null),
            ] : $line)
            ->all()]);

        $data = $request->validate([
            'client_id' => ['nullable', 'exists:clients,id'],
            // The snapshot's own fields, whatever the contract carries — the
            // service keeps the write to the known placeholders.
            'fields' => ['nullable', 'array'],
            'fields.*' => ['nullable', 'string', 'max:500'],
            'items' => ['nullable', 'array', 'max:60'],
            'items.*.name' => ['nullable', 'string', 'max:190'],
            'items.*.code' => ['nullable', 'string', 'max:60'],
            // A count is usually a number, but a cell of the paper may say
            // «حسب الاتفاق» — the sheet prints whichever was written.
            'items.*.quantity' => ['nullable', 'string', 'max:30'],
            // Prices come back as the sheet prints them — «1,200.00» — so they
            // are read as text and parsed, not rejected for the commas the
            // document itself put there.
            'items.*.unit_price' => ['nullable', 'string', 'max:30'],
            'items.*.total_price' => ['nullable', 'string', 'max:30'],
            'body' => ['required', 'string', 'max:40000'],
            'terms' => ['nullable', 'string', 'max:40000'],
        ]);

        $this->contracts->applyEdit($contract, $data);

        return redirect()->route('contracts.show', $contract)
            ->with('success', 'تم حفظ تعديل العقد');
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

        // الملف يُبنى ويُحفظ قبل الإرسال: الرسالة تقول «مرفق العقد»، ولا
        // يصحّ أن تقولها بلا مرفق. وفشل التوليد يوقف الإرسال ولا يعلّم
        // العقد مُرسلًا — عقدٌ حالته «أُرسل» ولم يصل أسوأ من عقد لم يُرسل.
        try {
            $path = $this->pdf->store($contract);
        } catch (RuntimeException $e) {
            return back()->with('warning', $e->getMessage());
        }

        $this->whatsapp->contract($contract, $request->user()?->id, $this->pdf->publicUrl($path));

        $contract->update(['status' => 'sent', 'sent_at' => now()]);

        return back()->with('success', 'تم إرسال العقد (PDF) على واتساب العميل');
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
