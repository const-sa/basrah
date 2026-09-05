<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\SendWhatsappMessage;
use App\Models\Booking;
use App\Models\BookingPayment;
use App\Models\City;
use App\Models\Client;
use App\Models\Contract;
use App\Models\NotificationTemplate;
use App\Models\Sale;
use App\Models\Setting;
use App\Models\Voucher;
use App\Services\WaGateway;
use Illuminate\Database\Eloquent\Builder;
use App\Support\ClientType;
use App\Support\NotificationCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ClientsController extends Controller
{
    public function hallClients(Request $request): Response
    {
        return $this->index($request, ClientType::HALL);
    }

    public function chaletClients(Request $request): Response
    {
        return $this->index($request, ClientType::CHALET);
    }

    public function poolClients(Request $request): Response
    {
        return $this->index($request, ClientType::POOL);
    }

    /**
     * دليل العملاء — كله، أو سجل نشاطٍ واحد حين تُفتح الشاشة من قائمته.
     */
    public function index(Request $request, ?string $activity = null): Response
    {
        $filters = $this->parseFilters($request, $activity);

        $clients = $this->filteredQuery($filters)
            // عدّادات الملف في القائمة: الصف يقول كم تعامل معنا العميل وكم بقي
            // عليه قبل فتح ملفه — وتُحسب بالتجميع لا بالحلقة فلا تتكاثر الاستعلامات.
            ->withCount([
                'bookings as bookings_count' => fn ($q) => $q->where('status', '!=', 'cancelled'),
                'sales as sales_count' => fn ($q) => $q->where('type', 'sale'),
            ])
            ->withSum(['bookings as bookings_total' => fn ($q) => $q->where('status', '!=', 'cancelled')], 'total_amount')
            ->withSum(['bookings as bookings_paid' => fn ($q) => $q->where('status', '!=', 'cancelled')], 'paid_amount')
            ->latest('id')
            ->paginate(10)
            ->withQueryString()
            ->through(fn (Client $c) => [
                'id' => $c->id,
                'name' => $c->name,
                'mobile' => $c->mobile,
                'email' => $c->email,
                'city' => $c->city,
                'type' => $c->type,
                'type_label' => $c->typeLabel(),
                'national_id' => $c->national_id,
                'is_taxable' => $c->is_taxable,
                'tax_number' => $c->tax_number,
                'tax_address' => $c->tax_address,
                'is_active' => $c->is_active,
                'is_walk_in' => $c->is_walk_in,
                'bookings_count' => (int) $c->bookings_count,
                'sales_count' => (int) $c->sales_count,
                // المتبقي هنا من الحجوزات القائمة وحدها؛ تفصيل الفواتير في الملف.
                'remaining' => round((float) $c->bookings_total - (float) $c->bookings_paid, 2),
                'created_at' => $c->created_at?->format('Y-m-d'),
            ]);

        // عدّ كل نشاط مرةً واحدة بالتجميع — لا استعلامًا لكل نوع.
        $byType = Client::selectRaw('type, COUNT(*) AS total')->groupBy('type')->pluck('total', 'type');

        // المربّعات تعدّ ما تعرضه الشاشة: سجل النشاط المفتوح لا الدليل كله.
        $scoped = fn () => Client::query()->when($activity, fn ($q, $type) => $q->where('type', $type));

        $stats = [
            'total' => $scoped()->count(),
            'active' => $scoped()->where('is_active', true)->count(),
            'inactive' => $scoped()->where('is_active', false)->count(),
            'taxable' => $scoped()->where('is_taxable', true)->count(),
            'non_taxable' => $scoped()->where('is_taxable', false)->count(),
            'by_type' => collect(ClientType::keys())
                ->mapWithKeys(fn (string $key) => [$key => (int) ($byType[$key] ?? 0)])
                ->all(),
        ];

        return Inertia::render('admin/clients/Index', [
            'clients' => $clients,
            'filters' => $filters,
            'stats' => $stats,
            'types' => ClientType::forFrontend(),
            // النشاط المثبَّت — الشاشة تُخفي تبويبات الأنشطة حين يُفتح سجلٌّ بعينه.
            'activity' => $activity,
            'activityLabel' => $activity ? ClientType::label($activity) : null,
            // قائمة المدن المفعّلة لتعبئة قائمة الاختيار في نموذج العميل.
            'cities' => City::where('is_active', true)->orderBy('name')->pluck('name'),
        ]);
    }

    /**
     * تصدير العملاء (وفق الفلاتر الحالية) إلى ملف CSV يفتح مباشرة في Excel.
     */
    public function export(Request $request): StreamedResponse
    {
        $filters = $this->parseFilters($request);
        $query = $this->filteredQuery($filters)->latest('id');

        $filename = 'clients-'.now()->format('Ymd-His').'.csv';

        $columns = [
            '#', 'الاسم', 'الجوال', 'البريد', 'المدينة', 'النشاط', 'رقم الهوية',
            'النوع', 'الرقم الضريبي', 'العنوان الضريبي', 'الحالة', 'تاريخ الإنشاء',
        ];

        // تحييد حقن الصيغ (CSV/Formula Injection): أي خلية تبدأ بـ = + - @ أو
        // محرف تحكّم تُسبَق بفاصلة عليا حتى لا تُنفَّذ كصيغة داخل Excel.
        $safe = function ($value) {
            $value = (string) $value;

            return preg_match('/^[=+\-@\t\r]/', $value) ? "'".$value : $value;
        };

        return response()->streamDownload(function () use ($query, $columns, $safe) {
            $out = fopen('php://output', 'w');
            // BOM لضمان قراءة Excel للأحرف العربية بشكل صحيح.
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, $columns);

            $i = 0;
            $query->chunk(500, function ($clients) use ($out, &$i, $safe) {
                foreach ($clients as $c) {
                    fputcsv($out, [
                        ++$i,
                        $safe($c->name),
                        $safe($c->mobile),
                        $safe($c->email),
                        $safe($c->city),
                        $c->typeLabel(),
                        $safe($c->national_id),
                        $c->is_taxable ? 'ضريبي' : 'عادي',
                        $safe($c->tax_number),
                        $safe($c->tax_address),
                        $c->is_active ? 'مفعّل' : 'موقوف',
                        $c->created_at?->format('Y-m-d'),
                    ]);
                }
            });

            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    /**
     * ملف العميل المتكامل (§5 من العرض المعتمد).
     *
     * الشاشة تجيب عن السؤال الذي يُطرح والعميل على الهاتف: من هذا؟ كم حجز
     * لنا معه؟ كم دفع وكم عليه؟ وأين عقده وفاتورته؟ — فتُجمع حجوزاته
     * ودفعاته وفواتيره وعقوده وسنداته في صفحة واحدة، وترتيبها بالأحدث
     * لأن آخر تعامل هو المسؤول عنه غالبًا.
     */
    public function show(Client $client): Response
    {
        $bookings = $client->bookings()
            ->with(['unit:id,name,type', 'eventType:id,name'])
            ->orderByDesc('booking_date')
            ->get();

        $sales = $client->sales()
            ->with('unit:id,name')
            ->orderByDesc('id')
            ->limit(50)
            ->get();

        return Inertia::render('admin/clients/Show', [
            'client' => [
                'id' => $client->id,
                'name' => $client->name,
                'mobile' => $client->mobile,
                'email' => $client->email,
                'city' => $client->city,
                'type' => $client->type,
                'type_label' => $client->typeLabel(),
                'national_id' => $client->national_id,
                'is_taxable' => $client->is_taxable,
                'tax_number' => $client->tax_number,
                'tax_address' => $client->tax_address,
                'is_active' => $client->is_active,
                'is_walk_in' => $client->is_walk_in,
                'notes' => $client->notes,
                'created_at' => $client->created_at?->format('Y-m-d'),
            ],
            'stats' => $this->profileStats($bookings, $sales),
            'bookings' => $bookings->map(fn (Booking $b) => [
                'id' => $b->id,
                'reference' => $b->reference,
                'booking_date' => $b->booking_date?->format('Y-m-d'),
                'unit' => $b->unit?->name,
                'unit_type' => $b->unit?->type,
                'event_type' => $b->eventType?->name,
                'period' => $b->periodLabel(),
                'status' => $b->status,
                'status_label' => $b->statusLabel(),
                'color' => Booking::STATUS_COLORS[$b->status] ?? 'slate',
                'total' => round((float) $b->total_amount, 2),
                'paid' => round((float) $b->paid_amount, 2),
                'remaining' => round((float) $b->total_amount - (float) $b->paid_amount, 2),
            ])->values(),
            'payments' => $client->payments()
                ->with(['paymentMethod:id,name', 'booking:id,reference'])
                ->orderByDesc('paid_on')
                ->orderByDesc('booking_payments.id')
                ->limit(100)
                ->get()
                ->map(fn (BookingPayment $p) => [
                    'id' => $p->id,
                    'booking_id' => $p->booking_id,
                    'reference' => $p->booking?->reference,
                    'paid_on' => $p->paid_on?->format('Y-m-d'),
                    'type' => $p->type,
                    'type_label' => BookingPayment::TYPES[$p->type] ?? $p->type,
                    'method' => $p->paymentMethod?->name,
                    // الاسترداد خروجٌ من الصندوق، فيُعرض بإشارته لا كدفعة.
                    'amount' => round((float) $p->amount * ($p->type === 'refund' ? -1 : 1), 2),
                ])->values(),
            'sales' => $sales->map(fn (Sale $s) => [
                'id' => $s->id,
                'number' => $s->number,
                'created_at' => $s->created_at?->format('Y-m-d'),
                'unit' => $s->unit?->name,
                'type' => $s->type,
                'total' => round((float) $s->total_amount, 2),
                'paid' => round((float) $s->paid_amount, 2),
                'remaining' => round((float) $s->remainingAmount(), 2),
            ])->values(),
            'contracts' => $client->contracts()
                ->with('booking:id,reference')
                ->orderByDesc('id')
                ->get()
                ->map(fn (Contract $c) => [
                    'id' => $c->id,
                    'number' => $c->number,
                    'reference' => $c->booking?->reference,
                    'status' => $c->status,
                    'status_label' => $c->statusLabel(),
                    'sent_at' => $c->sent_at?->format('Y-m-d'),
                    'created_at' => $c->created_at?->format('Y-m-d'),
                ])->values(),
            'vouchers' => $client->vouchers()
                ->where('status', 'posted')
                ->orderByDesc('voucher_date')
                ->limit(50)
                ->get()
                ->map(fn (Voucher $v) => [
                    'id' => $v->id,
                    'number' => $v->number,
                    'voucher_date' => $v->voucher_date?->toDateString(),
                    'type_label' => $v->typeLabel(),
                    'amount' => round((float) $v->amount, 2),
                    'description' => $v->description,
                ])->values(),
            'services' => $this->servicesUsed($bookings),
        ]);
    }

    /**
     * مؤشرات الملف: كم تعامل، وبكم، وكم بقي عليه، ومتى آخر مرة.
     *
     * @param  Collection<int, Booking>  $bookings
     * @param  Collection<int, Sale>  $sales
     * @return array<string, mixed>
     */
    private function profileStats(Collection $bookings, Collection $sales): array
    {
        // الملغى لا يُحتسب تعاملًا ولا يُطالَب به.
        $live = $bookings->where('status', '!=', 'cancelled');

        $bookingsValue = round((float) $live->sum('total_amount'), 2);
        $bookingsPaid = round((float) $live->sum('paid_amount'), 2);
        $salesValue = round((float) $sales->where('type', 'sale')->sum('total_amount'), 2);

        return [
            'bookings_count' => $live->count(),
            'cancelled_count' => $bookings->where('status', 'cancelled')->count(),
            'bookings_value' => $bookingsValue,
            'paid' => $bookingsPaid,
            'remaining' => round($bookingsValue - $bookingsPaid, 2),
            'sales_count' => $sales->where('type', 'sale')->count(),
            'sales_value' => $salesValue,
            'lifetime_value' => round($bookingsValue + $salesValue, 2),
            'last_visit' => $live->max('booking_date')?->format('Y-m-d'),
            'upcoming' => $live->filter(
                fn (Booking $b) => $b->booking_date && $b->booking_date->gte(now()->startOfDay()),
            )->count(),
        ];
    }

    /**
     * الخدمات السابقة: ما اختاره العميل من باقات وخدمات إضافية عبر حجوزاته.
     *
     * الغاية بيعية لا إحصائية — من عرف أن العميل يأخذ الضيافة كل مرة عرضها
     * عليه قبل أن يسأل.
     *
     * @param  Collection<int, Booking>  $bookings
     * @return list<array<string, mixed>>
     */
    private function servicesUsed($bookings): array
    {
        $ids = $bookings->pluck('id');

        if ($ids->isEmpty()) {
            return [];
        }

        $addons = Booking::whereIn('id', $ids)
            ->with('addons:id,name')
            ->get()
            ->flatMap(fn (Booking $b) => $b->addons)
            ->groupBy('name')
            ->map(fn ($group, string $name) => ['name' => $name, 'times' => $group->count(), 'kind' => 'خدمة'])
            ->values();

        $packages = $bookings
            ->filter(fn (Booking $b) => $b->package_id)
            ->load('package:id,name')
            ->groupBy(fn (Booking $b) => $b->package?->name ?? '—')
            ->map(fn ($group, string $name) => ['name' => $name, 'times' => $group->count(), 'kind' => 'باقة'])
            ->values();

        // collect() تُخرج النتيجة من مجموعة إيلوكوينت إلى مجموعة عادية: الصفوف
        // هنا مصفوفاتٌ لا نماذج، ودمجها في مجموعة نماذج يطلب منها مفتاحًا.
        return collect($packages)->merge($addons)->sortByDesc('times')->values()->all();
    }

    /**
     * @param  string|null  $activity  نشاطٌ تُثبَّت عليه الشاشة، فلا يُوسّعه فلتر.
     * @return array<string, string>
     */
    private function parseFilters(Request $request, ?string $activity = null): array
    {
        return [
            'name' => trim((string) $request->get('name', '')),
            'mobile' => trim((string) $request->get('mobile', '')),
            'email' => trim((string) $request->get('email', '')),
            'city' => trim((string) $request->get('city', '')),
            'type' => $activity ?? (in_array($request->get('type'), ClientType::keys(), true) ? (string) $request->get('type') : ''),
            'status' => (string) $request->get('status', ''),
            'from' => (string) $request->get('from', ''),
            'to' => (string) $request->get('to', ''),
        ];
    }

    /**
     * @param  array<string, string>  $filters
     */
    private function filteredQuery(array $filters): Builder
    {
        return Client::query()
            ->when($filters['name'] !== '', fn ($q) => $q->where('name', 'like', "%{$filters['name']}%"))
            ->when($filters['mobile'] !== '', fn ($q) => $q->where('mobile', 'like', "%{$filters['mobile']}%"))
            ->when($filters['email'] !== '', fn ($q) => $q->where('email', 'like', "%{$filters['email']}%"))
            ->when($filters['city'] !== '', fn ($q) => $q->where('city', 'like', "%{$filters['city']}%"))
            ->when($filters['type'] !== '', fn ($q) => $q->where('type', $filters['type']))
            ->when($filters['status'] === 'active', fn ($q) => $q->where('is_active', true))
            ->when($filters['status'] === 'inactive', fn ($q) => $q->where('is_active', false))
            ->when($filters['status'] === 'taxable', fn ($q) => $q->where('is_taxable', true))
            ->when($filters['status'] === 'non_taxable', fn ($q) => $q->where('is_taxable', false))
            ->when($filters['from'] !== '', fn ($q) => $q->whereDate('created_at', '>=', $filters['from']))
            ->when($filters['to'] !== '', fn ($q) => $q->whereDate('created_at', '<=', $filters['to']));
    }

    public function store(Request $request): RedirectResponse
    {
        $client = $this->persist($request, new Client);

        $this->sendWelcome($client, $client->type);

        return back()->with('success', 'تم إضافة العميل بنجاح');
    }

    /**
     * إضافة سريعة من داخل شاشة الحجز أو الفواتير: الاسم والجوال فقط.
     *
     * تُعيد العميل بصيغة JSON — لا إعادة توجيه — حتى يُضاف إلى قائمة الاختيار
     * ويُحدَّد فورًا دون مغادرة النموذج وفقدان ما عُبِّئ فيه.
     * وبقية الحقول (المدينة، الهوية، البيانات الضريبية) تُستكمل لاحقًا من شاشة العملاء.
     */
    public function quickStore(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'mobile' => ['nullable', 'string', 'max:50'],
            // نشاط الشاشة التي أُضيف منها: العميل يُقيَّد في سجلّها لا في سجلٍّ
            // يبحث عنه الموظف بعد حين. وهو نفسه قسم قالب الترحيب.
            'type' => ['nullable', Rule::in(ClientType::keys())],
        ]);

        $type = ClientType::normalize($data['type'] ?? null);

        $client = (new Client)->fill([
            'name' => $data['name'],
            'mobile' => $data['mobile'] ?? null,
            'type' => $type,
            'is_active' => true,
        ]);

        $client->save();

        $this->sendWelcome($client, $type);

        return response()->json([
            'client' => [
                'id' => $client->id,
                'name' => $client->name,
                'mobile' => $client->mobile,
                'type' => $client->type,
            ],
        ], 201);
    }

    public function update(Request $request, Client $client): RedirectResponse
    {
        $this->persist($request, $client);

        return back()->with('success', 'تم تحديث بيانات العميل');
    }

    public function toggle(Client $client): RedirectResponse
    {
        // إيقاف العميل النقدي يوقف كل بيع بلا عميل محدد — فلا يُوقَف.
        if ($client->isWalkIn()) {
            return back()->with('warning', 'العميل النقدي الافتراضي لا يُوقَف — عليه تُحمل فواتير البيع بلا عميل.');
        }

        $client->update(['is_active' => ! $client->is_active]);

        return back()->with('success', 'تم تغيير حالة العميل');
    }

    public function destroy(Client $client): RedirectResponse
    {
        if ($client->isWalkIn()) {
            return back()->with('warning', 'العميل النقدي الافتراضي لا يُحذف — عليه تُحمل فواتير البيع بلا عميل.');
        }

        $client->delete();

        return back()->with('success', 'تم حذف العميل');
    }

    private function persist(Request $request, Client $client): Client
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'mobile' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'max:255'],
            'city' => ['nullable', 'string', 'max:255'],
            'type' => ['nullable', Rule::in(ClientType::keys())],
            // رقم الهوية اختياري — لا يُشترط في أي من النوعين.
            'national_id' => ['nullable', 'string', 'max:50'],
            'is_taxable' => ['boolean'],
            'tax_number' => ['nullable', 'required_if:is_taxable,true', 'string', 'max:100'],
            'tax_address' => ['nullable', 'string', 'max:500'],
            'is_active' => ['boolean'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ], [
            'tax_number.required_if' => 'الرقم الضريبي مطلوب للعميل الضريبي',
        ]);

        $taxable = $data['is_taxable'] ?? false;

        $client->fill([
            'name' => $data['name'],
            'mobile' => $data['mobile'] ?? null,
            'email' => $data['email'] ?? null,
            'city' => $data['city'] ?? null,
            // كالملاحظات: نموذجٌ لا يرسل النشاط لا يغيّره، والعميل الجديد
            // بلا نشاط يقع على سجل المسابح.
            'type' => $data['type'] ?? $client->type ?? ClientType::DEFAULT,
            'national_id' => $data['national_id'] ?? null,
            'is_taxable' => $taxable,
            'tax_number' => $taxable ? ($data['tax_number'] ?? null) : null,
            'tax_address' => $taxable ? ($data['tax_address'] ?? null) : null,
            'is_active' => $data['is_active'] ?? true,
            // الملاحظات تُحرَّر من الملف وحده، فلا يمسحها نموذج القائمة
            // الذي لا يرسل الحقل أصلًا.
            'notes' => array_key_exists('notes', $data) ? $data['notes'] : $client->notes,
        ])->save();

        return $client;
    }

    /**
     * إرسال رسالة الترحيب عبر الواتساب عند إضافة عميل جديد (إن كان التكامل والترحيب مفعّلين وللعميل رقم جوال).
     * لا يجب أن يُفشل إنشاء العميل إن تعذّر الإرسال — نلتقط أي خطأ ونسجّله فقط.
     *
     * @param  'chalet'|'hall'|'pool'|null  $category  نشاط العميل — وهو نفسه قسم القالب.
     */
    private function sendWelcome(Client $client, ?string $category = null): void
    {
        if (blank($client->mobile)) {
            return;
        }

        $settings = Setting::current();

        if (! $settings->wa_enabled || ! $settings->wa_welcome_enabled) {
            return;
        }

        $templateCategory = in_array($category, NotificationCatalog::categoryKeys(), true)
            && $category !== 'general'
            ? $category
            : 'general';

        // قالب المكتبة أولاً — هو ما يراه المستخدم ويحرّره ويقسّمه —
        // ونصّ الإعدادات احتياطٌ لمن لم يُنشئ قالب ترحيب بعد.
        $body = NotificationTemplate::resolve('welcome', $templateCategory)?->body
            ?? $settings->wa_welcome_template;

        if (blank($body)) {
            return;
        }

        try {
            $message = WaGateway::renderTemplate($body, [
                'name' => $client->name,
                'business_name' => $settings->business_name ?? '',
                'mobile' => (string) $client->mobile,
            ]);

            // الإرسال في الطابور حتى لا يُعلّق إنشاء العميل على استجابة البوابة.
            SendWhatsappMessage::dispatch((string) $client->mobile, $message);
        } catch (\Throwable $e) {
            Log::warning('تعذّر جدولة رسالة ترحيب الواتساب', [
                'client_id' => $client->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
