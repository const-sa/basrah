<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\Client;
use App\Models\Contract;
use App\Models\ContractTemplate;
use App\Models\PaymentMethod;
use App\Models\Quotation;
use App\Models\Setting;
use App\Models\Treasury;
use App\Models\Voucher;
use App\Services\Accounting\ContractReceipts;
use App\Services\ContractPdf;
use App\Services\ContractService;
use App\Services\WhatsappNotifier;
use App\Support\ChaletContractTemplate;
use App\Support\ClientType;
use App\Support\HallServicesContractTemplate;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

class ContractsController extends Controller
{
    /**
     * حقول العربون المقبوض وقت تحرير العقد — مشتركة بين مصدري عقود المسابح.
     */
    private const DEPOSIT_RULES = [
        'deposit_amount' => ['nullable', 'numeric', 'min:0'],
        'deposit_paid_on' => ['nullable', 'date'],
        'payment_method_id' => ['nullable', 'exists:payment_methods,id'],
        'treasury_id' => ['nullable', 'exists:treasuries,id'],
    ];

    /** @var array<string, string> */
    private const DEPOSIT_LABELS = ['deposit_amount' => 'العربون'];

    public function __construct(
        private readonly ContractService $contracts,
        private readonly ContractPdf $pdf,
        private readonly WhatsappNotifier $whatsapp,
        private readonly ContractReceipts $receipts,
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
     * The same screen reached from the chalets menu, showing that activity's
     * own contracts: the sheets let on a chalet, and the ones written on the
     * chalet form with no booking behind them.
     */
    public function chaletsIndex(Request $request): Response
    {
        return $this->register($request, 'chalet');
    }

    /**
     * @param  'all'|'quotation'|'chalet'  $scope
     */
    private function register(Request $request, string $scope): Response
    {
        $user = $request->user();
        $poolsOnly = $scope === 'quotation';
        $chaletsOnly = $scope === 'chalet';

        $query = Contract::query()
            // Anything not drawn from a booking is the pools' — a quotation
            // contract or one written straight onto a client. Neither has a
            // unit behind it, so unit visibility cannot scope them and
            // `contracts.view` is the whole gate there. The chalets' own sheet
            // is the exception: it too may be written with no booking, and it
            // belongs in that activity's register, not in this one.
            ->when($poolsOnly, fn ($q) => $q->whereNull('booking_id')
                ->where(fn ($w) => $w->whereNull('data->form')
                    ->orWhere('data->form', '!=', ChaletContractTemplate::FORM)))
            // The chalets': a stay on a chalet the user may see, or a sheet
            // drawn on their form with no booking — that one has no unit
            // either, and is gated the same way as the pools' above.
            ->when($chaletsOnly, fn ($q) => $q->where(fn ($w) => $w
                ->whereHas('booking', fn ($b) => $b->visibleTo($user)
                    ->whereHas('unit', fn ($u) => $u->where('type', 'chalet')))
                ->orWhere(fn ($direct) => $direct->whereNull('booking_id')
                    ->where('data->form', ChaletContractTemplate::FORM))))
            ->when($scope === 'all', fn ($q) => $q->where(fn ($w) => $w
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
                    // ما قُبض على العقد وما بقي — من دفتر السندات لا من اللقطة.
                    'paid_amount' => $c->takesReceipts() ? number_format($c->paidAmount(), 2) : null,
                    'remaining_amount' => $c->takesReceipts() && $c->remainingAmount() !== null
                        ? number_format($c->remainingAmount(), 2)
                        : null,
                    'sent_at' => $c->sent_at?->format('Y-m-d H:i'),
                    'created_at' => $c->created_at->toDateString(),
                ]),
            'scope' => $scope,
            // The register is headed by whoever its contracts are drawn under —
            // the pools activity on its own screen, the business on the full one.
            'letterhead' => collect($this->issuer(null, $poolsOnly))
                ->only(['business_name', 'logo_url', 'phone'])->all(),
            // The عربون taken as the pools' sheet is drawn needs a till and a
            // way of paying to be written against.
            'payment_methods' => PaymentMethod::options(),
            'treasuries' => Treasury::where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'filters' => $request->only(['status', 'search']),
            'statuses' => collect(Contract::STATUSES)->map(fn ($l, $k) => ['key' => $k, 'label' => $l])->values(),
            // وأيُّ منها يحمل دفتر سنداته — فتظهر خانة العربون على نموذج
            // التركيب والصيانة وحدهما.
            'templates' => $this->templates($chaletsOnly)
                ->map(fn (ContractTemplate $t) => [
                    'id' => $t->id,
                    'name' => $t->name,
                    'is_default' => (bool) $t->is_default,
                    'takes_deposit' => ContractService::takesDeposit($t),
                ]),
            // الحجوزات التي لا عقد لها بعد — هي المرشّحة للتوليد.
            // A hall writes two papers, so its booking stays on offer until the
            // services list is drawn too; store() is what refuses a repeat.
            // The pools screen offers no booking source at all: a contract
            // drawn there would land outside the register that drew it.
            'bookings' => $poolsOnly ? [] : Booking::visibleTo($user)->blocking()
                ->where(fn ($q) => $q->whereDoesntHave('contracts')
                    ->orWhere(fn ($hall) => $hall
                        ->whereHas('unit', fn ($u) => $u->where('type', 'hall'))
                        ->whereDoesntHave('contracts', fn ($c) => $c
                            ->where('data->form', HallServicesContractTemplate::FORM))))
                ->withCount('contracts')
                // For the same reason the chalets' screen draws from their
                // own stays: a hall let there would leave the register.
                ->when($chaletsOnly, fn ($q) => $q->whereHas('unit', fn ($u) => $u->where('type', 'chalet')))
                ->with('unit:id,name', 'client:id,name')
                ->latest('id')->limit(200)->get()
                ->map(fn (Booking $b) => [
                    'id' => $b->id,
                    'label' => $b->reference.' — '.($b->unit?->name ?? '').' — '.($b->client?->name ?? 'بلا عميل')
                        // A booking still listed with a paper drawn on it is
                        // there for the other one.
                        .($b->contracts_count ? ' — له عقد' : ''),
                ]),
            // Quotations still open and not yet contracted. A rejected quotation
            // is excluded outright: the client turned that price down, and a
            // contract is exactly the thing that must not be drawn from it.
            //
            // A quotation is the pools' source; a chalet is let on a booking or
            // written on the client, so that screen is offered neither.
            'quotations' => $chaletsOnly ? [] : Quotation::where('status', '!=', 'rejected')
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
            // Whom a contract with no source document is written for. Each
            // activity's screen offers its own clients, as its counter does.
            'clients' => Client::query()
                ->when($poolsOnly, fn ($q) => $q->ofType([ClientType::POOL]))
                ->when($chaletsOnly, fn ($q) => $q->ofType([ClientType::CHALET]))
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

    /**
     * The pads a contract may be drawn on from this register.
     *
     * The chalets' screen offers their own form alone: a sheet drawn there on
     * another pad would fall outside the register that drew it. If that form is
     * not seeded, the whole list stands rather than nothing at all.
     *
     * @return Collection<int, ContractTemplate>
     */
    private function templates(bool $chaletsOnly): Collection
    {
        $templates = ContractTemplate::where('is_active', true)->get(['id', 'name', 'is_default']);

        $chaletPad = $chaletsOnly ? $templates->firstWhere('name', ChaletContractTemplate::NAME) : null;

        return $chaletPad ? collect([$chaletPad]) : $templates;
    }

    public function show(Contract $contract): Response
    {
        $contract->load([
            'booking.unit', 'booking.eventType', 'quotation', 'client', 'template',
            'vouchers' => fn ($q) => $q->with(['treasury:id,name', 'paymentMethod:id,name'])->latest('id'),
        ]);

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
                // On a sheet with a receipt ledger these two are its answer;
                // everywhere else the snapshot it was frozen with stands.
                ...$contract->paidBoxes($data),
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
                // Drawn on the halls' numbered rental pad — likewise.
                'is_hall_form' => $contract->isHallRentalForm(),
                // And on the halls' services list — the event's second paper.
                'is_hall_services_form' => $contract->isHallServicesForm(),
                'client_birth_place' => $data['client_birth_place'] ?? null,
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
                // A sheet with its own receipt book carries it on the page: the
                // deposit taken at signing and every payment after it.
                'takes_receipts' => $contract->takesReceipts(),
                'accepts_receipt' => $contract->takesReceipts() && $contract->acceptsSettlement(),
                'receipts' => $contract->vouchers->map(fn (Voucher $v) => [
                    'id' => $v->id,
                    'number' => $v->number,
                    'date' => $v->voucher_date->toDateString(),
                    'amount' => (float) $v->amount,
                    'method' => $v->methodLabel(),
                    'treasury' => $v->treasury?->name,
                    'status' => $v->status,
                    'status_label' => $v->statusLabel(),
                    'description' => $v->description,
                ])->values(),
            ],
            'payment_methods' => $contract->takesReceipts() ? PaymentMethod::options() : [],
            'treasuries' => $contract->takesReceipts()
                ? Treasury::where('is_active', true)->orderBy('name')->get(['id', 'name'])
                : [],
            'issuer' => $this->issuer($data['org_name'] ?? null, $contract->isPoolsForm()),
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

        // One paper of each kind per booking: a hall takes its rental pad and
        // its services list, and a second copy of either is two agreements.
        $form = ContractService::formFor($template ?? $this->contracts->templateFor($booking));

        if ($booking->contracts()->get()->contains(fn (Contract $c) => ($c->data['form'] ?? null) === $form)) {
            return back()->with('warning', 'لهذا الحجز عقد على هذا النموذج بالفعل.');
        }

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
            ...self::DEPOSIT_RULES,
        ], [], self::DEPOSIT_LABELS);

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

        $taken = $this->takeDeposit($contract, $data, $request->user()?->id);

        if ($taken instanceof RuntimeException) {
            return back()->with('warning', "تم توليد العقد {$contract->number} ولم يُسجل العربون — ".$taken->getMessage());
        }

        return back()->with('success', "تم توليد العقد {$contract->number} من عرض السعر {$quotation->number}".$this->depositNote($taken));
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
            ...self::DEPOSIT_RULES,
        ], [], self::DEPOSIT_LABELS);

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

        $taken = $this->takeDeposit($contract, $data, $request->user()?->id);

        if ($taken instanceof RuntimeException) {
            return back()->with('warning', "تم تحرير العقد {$contract->number} ولم يُسجل العربون — ".$taken->getMessage());
        }

        return back()->with('success', "تم تحرير العقد {$contract->number}".$this->depositNote($taken));
    }

    /**
     * سند قبض على عقد قائم — دفعة بعد العربون، أو العربون نفسه إن لم يُقبض
     * وقت التحرير.
     */
    public function receipt(Request $request, Contract $contract): RedirectResponse
    {
        abort_unless($contract->takesReceipts(), 404);

        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01'],
            'payment_method_id' => ['nullable', Rule::exists('payment_methods', 'id')->where('is_active', true)],
            'treasury_id' => ['nullable', Rule::exists('treasuries', 'id')->where('is_active', true)],
            'voucher_date' => ['nullable', 'date'],
            'description' => ['nullable', 'string', 'max:1000'],
        ], [], ['amount' => 'المبلغ']);

        try {
            $voucher = $this->receipts->record($contract, $data, $request->user()?->id);
        } catch (RuntimeException $e) {
            return back()->with('warning', $e->getMessage());
        }

        $remaining = $contract->fresh()->remainingAmount();

        return back()->with('success', "تم سند القبض {$voucher->number}"
            .($remaining === null ? '' : ' — المتبقي '.number_format($remaining, 2)));
    }

    /**
     * العربون المقبوض لحظة تحرير العقد.
     *
     * The note the request carries is the whole point of the field: the money
     * changes hands as the sheet is signed, and asking the employee to open
     * the contract afterwards and write a second document is how a deposit
     * ends up recorded nowhere. So the receipt is drawn in the same action
     * that draws the contract.
     *
     * Only a sheet with its own receipt ledger takes one — a contract drawn
     * from a booking leaves its deposit on that booking's payment ledger.
     *
     * Returns the posted voucher, null when nothing was paid, and the failure
     * itself when the receipt could not be written: the contract is already
     * drawn by then and must not be rolled back for it, so the caller says so
     * rather than reporting a clean save.
     *
     * @param  array<string, mixed>  $data
     */
    private function takeDeposit(Contract $contract, array $data, ?int $userId): Voucher|RuntimeException|null
    {
        $amount = round((float) ($data['deposit_amount'] ?? 0), 2);

        if ($amount <= 0 || ! $contract->takesReceipts()) {
            return null;
        }

        try {
            return $this->receipts->record($contract, [
                'amount' => $amount,
                'payment_method_id' => $data['payment_method_id'] ?? null,
                'treasury_id' => $data['treasury_id'] ?? null,
                'voucher_date' => $data['deposit_paid_on'] ?? null,
            ], $userId);
        } catch (RuntimeException $e) {
            return $e;
        }
    }

    /** ذيل رسالة النجاح حين قُبض عربون مع العقد. */
    private function depositNote(Voucher|RuntimeException|null $voucher): string
    {
        return $voucher instanceof Voucher
            ? " — وسند القبض {$voucher->number} بمبلغ ".number_format((float) $voucher->amount, 2)
            : '';
    }

    /**
     * The letterhead the contract is printed under — the same one whether the
     * sheet is being read or filled in, so the edit screen is the document.
     *
     * @return array<string, mixed>
     */
    private function issuer(?string $orgName = null, bool $pools = false): array
    {
        $settings = Setting::current();

        // A pools sheet is headed by that activity's own letterhead, which
        // falls back to the business's wherever it has not been given one.
        $letterhead = $settings->poolsLetterhead();

        return [
            'business_name' => $orgName ?: ($pools
                ? $letterhead['name']
                : ($settings->business_name ?: config('app.name'))),
            'logo_url' => ($logo = $pools ? $letterhead['logo_path'] : $settings->logo_path)
                ? asset($logo)
                : null,
            'phone' => $pools ? $letterhead['phone'] : $settings->phone,
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

        // المدفوع والمتبقي على ورقة المسابح يُقرآن من دفتر السندات،
        // فالشاشة تعرضهما كما تُطبعان.
        $data = [...$data, ...$contract->paidBoxes($data)];

        // A field the contract carries, plus the site measurements when it is
        // printed on the form that asks for them. The number is not offered:
        // it identifies the contract.
        //
        // Nor the paid and remaining boxes on a sheet with a receipt ledger:
        // they are its posted receipts' answer, moved only by a new receipt.
        $keys = collect(ContractTemplate::PLACEHOLDERS)->keys()
            ->reject(fn (string $key) => $key === 'contract_number')
            ->reject(fn (string $key) => $contract->takesReceipts()
                && in_array($key, ['deposit_amount', 'remaining_amount'], true))
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
                'is_hall_form' => $contract->isHallRentalForm(),
                'is_hall_services_form' => $contract->isHallServicesForm(),
                // ما تطبعه خانتا المدفوع والمتبقي حين لا تكونان من حقول التحرير —
                // على ورقة المسابح هما حصيلة سندات القبض.
                'deposit_amount' => ($data['deposit_amount'] ?? '—') === '—' ? null : $data['deposit_amount'],
                'remaining_amount' => ($data['remaining_amount'] ?? '—') === '—' ? null : $data['remaining_amount'],
                'client_birth_place' => ($data['client_birth_place'] ?? '—') === '—' ? null : $data['client_birth_place'],
                'unit_name' => ($data['unit_name'] ?? '—') === '—' ? null : $data['unit_name'],
                // What the sheet draws around the editable runs: the logo it is
                // headed with, and the few facts it states rather than asks for.
                'from_quotation' => $contract->fromQuotation(),
                'unit_type' => $contract->booking?->unit?->type,
                'unit_logo_url' => $contract->booking?->unit?->logoUrl(),
                'event_name' => $contract->booking?->eventType?->name,
                'quotation_date' => ($data['quotation_date'] ?? '—') === '—' ? null : $data['quotation_date'],
                'is_taxable' => (bool) ($data['is_taxable'] ?? false),
            ],
            'issuer' => $this->issuer($data['org_name'] ?? null, $contract->isPoolsForm()),
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

        // Only when the sheet posts its lines. A form with no grid — the halls'
        // pad — posts none at all, and reading that as an empty list would
        // erase the lines the contract already carries.
        if ($request->has('items')) {
            $request->merge(['items' => collect($request->input('items', []))
                ->map(fn ($line) => is_array($line) ? [
                    ...$line,
                    'quantity' => $text($line['quantity'] ?? null),
                    'unit_price' => $text($line['unit_price'] ?? null),
                    'total_price' => $text($line['total_price'] ?? null),
                ] : $line)
                ->all()]);
        }

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
            // The services list rules a remark beside every line.
            'items.*.notes' => ['nullable', 'string', 'max:190'],
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
