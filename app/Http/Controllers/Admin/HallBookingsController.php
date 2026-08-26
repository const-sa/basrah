<?php

namespace App\Http\Controllers\Admin;

use App\Models\Booking;
use App\Models\BookingPayment;
use App\Models\EventType;
use App\Models\Package;
use App\Models\Unit;
use App\Models\User;
use App\Support\BookingPeriod;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;
use Inertia\Inertia;
use Inertia\Response;

/**
 * حجوزات القاعات — مناسبة تشغل اليوم.
 *
 * ما يميّز هذه الشاشة: الباقات وأنواع المناسبات، وحجز الوحدة كاملة أو أقسامًا
 * منها. والقاعة تُباع يومًا كاملًا لا فتراتٍ — see BookingPeriod::HALL_KEYS.
 */
class HallBookingsController extends BaseBookingsController
{
    protected function unitType(): string
    {
        return 'hall';
    }

    protected function scopeToType(Builder $query): Builder
    {
        return $query->events();
    }

    /**
     * القاعة تُباع يومًا كاملًا — الصباحي والمسائي إسكانٌ نهاري يخصّ الشاليه.
     *
     * Narrowed here rather than at each screen so the register, the form, the
     * calendar and the month view all read one list: whoever calls
     * HallBookingsController::meta() gets a hall's periods, not the catalogue.
     *
     * @return array<string, mixed>
     */
    public static function meta(): array
    {
        return [...parent::meta(), 'periods' => BookingPeriod::hallPeriods()];
    }

    protected function extraRelations(): array
    {
        // الدفعات تُحمَّل مع الصف: السجل يعرض المقبوض موزّعًا على طرقه
        // والمسترجع، وحسابها لكل حجز على حدة يفتح استعلامًا لكل سطر.
        return ['eventType:id,name,color', 'package:id,name', 'payments:id,booking_id,type,payment_method_id,amount'];
    }

    protected function applyExtraFilters(Builder $query, Request $request): Builder
    {
        return $query->when(
            $request->integer('event_type_id'),
            fn ($q, $id) => $q->where('event_type_id', $id),
        );
    }

    /**
     * صف القاعة يحمل مناسبته وباقته — وهما ما يميّزان حجز المناسبة.
     *
     * @return array<string, mixed>
     */
    protected function present(Booking $b): array
    {
        return [
            ...parent::present($b),
            'event_type' => $b->eventType ? [
                'id' => $b->eventType->id, 'name' => $b->eventType->name, 'color' => $b->eventType->color,
            ] : null,
            'package' => $b->package ? ['id' => $b->package->id, 'name' => $b->package->name] : null,
            'package_amount' => (float) $b->package_amount,
            'event_fee_amount' => (float) $b->event_fee_amount,
            // المناسبة قد تمتد أيامًا: تاريخ آخر يوم يُرسل معها ليعرضه السجل
            // بدل أن تظهر مناسبة ثلاثة أيام كأنها يوم واحد.
            'days_count' => $b->daysCount(),
            'last_day_date' => $b->lastDayDate(),
            ...$this->ledger($b),
        ];
    }

    public function index(Request $request): Response
    {
        $user = $request->user();
        $query = $this->filteredQuery($request);

        $bookings = (clone $query)
            ->orderByDesc('booking_date')
            ->orderByDesc('id')
            ->paginate(20)
            ->withQueryString()
            ->through(fn (Booking $b) => $this->present($b));

        return Inertia::render('admin/bookings/halls/Index', [
            'bookings' => $bookings,
            'filters' => $this->filterState($request, ['status', 'unit_id', 'event_type_id', 'from', 'to', 'search']),
            'units' => $this->unitOptions($user),
            // القائمة تحتاج الأنواع للفلتر وحده — النموذج له شاشته وبياناتها.
            'eventTypes' => $this->eventTypeOptions($user),
            'meta' => static::meta(),
            'stats' => $this->stats($query),
            // أعمدة طرق الدفع تُبنى من جدول الطرق لا من الصفوف المعروضة:
            // الطريقة التي لم يُقبض بها في هذه الصفحة يبقى عمودها بصفره،
            // فلا تتبدّل الأعمدة بين صفحة وأخرى.
            'methods' => collect($this->methodColumns())
                ->map(fn (array $m) => ['key' => $m['id'], 'label' => $m['label']])
                ->values(),
            'totals' => [
                'page' => $this->ledgerTotals($bookings->getCollection()),
                'all' => $this->allTotals($query),
            ],
        ]);
    }

    /**
     * مجاميع صفحة معروضة — تُجمع من صفوفها لا باستعلام ثانٍ.
     *
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    private function ledgerTotals($rows): array
    {
        $sum = fn (string $key) => round((float) $rows->sum(fn (array $r) => (float) ($r[$key] ?? 0)), 2);

        $byMethod = [];

        foreach ($this->methodIds() as $methodId) {
            $byMethod[$methodId] = round(
                (float) $rows->sum(fn (array $r) => (float) ($r['paid_by_method'][$methodId] ?? 0)),
                2,
            );
        }

        return [
            'subtotal' => $sum('subtotal_amount'),
            'discount' => $sum('discount_amount'),
            'deposit' => $sum('deposit_amount'),
            'tax' => $sum('tax_amount'),
            'total' => $sum('total_amount'),
            'paid' => $sum('paid_amount'),
            'paid_by_method' => $byMethod,
            'remaining' => $sum('remaining_amount'),
            'refunded' => $sum('refunded_amount'),
            'count' => $rows->count(),
        ];
    }

    /**
     * مجاميع كل الصفحات المفلترة — تُحسب في قاعدة البيانات لا بجلب الصفوف:
     * الفلتر قد يشمل آلاف الحجوزات، وتحميلها كلها لجمع أعمدةٍ إسرافٌ بيّن.
     *
     * @return array<string, mixed>
     */
    private function allTotals(Builder $query): array
    {
        $row = (clone $query)->selectRaw(
            'COUNT(*) as c,'
            .' COALESCE(SUM(base_amount + package_amount + event_fee_amount + addons_amount), 0) as subtotal,'
            .' COALESCE(SUM(discount_amount), 0) as discount,'
            .' COALESCE(SUM(deposit_amount), 0) as deposit,'
            .' COALESCE(SUM(total_amount), 0) as total,'
            .' COALESCE(SUM(paid_amount), 0) as paid,'
            .' COALESCE(SUM(total_amount - paid_amount), 0) as remaining',
        )->reorder()->first();

        $ids = (clone $query)->reorder()->select('bookings.id');

        $byMethod = [];

        foreach ($this->methodIds() as $methodId) {
            $byMethod[$methodId] = round((float) BookingPayment::whereIn('booking_id', $ids)
                ->where('type', '!=', 'refund')
                ->where('payment_method_id', $methodId)
                ->sum('amount'), 2);
        }

        $total = round((float) ($row->total ?? 0), 2);
        $subtotal = round((float) ($row->subtotal ?? 0), 2);
        $discount = round((float) ($row->discount ?? 0), 2);

        return [
            'subtotal' => $subtotal,
            'discount' => $discount,
            'deposit' => round((float) ($row->deposit ?? 0), 2),
            // الضريبة أُضيفت فوق المُسعَّر، فهي فرق الإجمالي عنه — لا نسبةٌ
            // تُستخرج اليوم فتضع ضريبةً على حجوزٍ سُجِّلت بلا ضريبة.
            'tax' => round(max(0, $total - ($subtotal - $discount)), 2),
            'total' => $total,
            'paid' => round((float) ($row->paid ?? 0), 2),
            'paid_by_method' => $byMethod,
            'remaining' => round((float) ($row->remaining ?? 0), 2),
            'refunded' => round((float) BookingPayment::whereIn('booking_id', $ids)
                ->where('type', 'refund')->sum('amount'), 2),
            'count' => (int) ($row->c ?? 0),
        ];
    }

    /**
     * شاشة إنشاء حجز — صفحة كاملة لا نافذة.
     *
     * نموذج حجز القاعة ليس حقلين: قاعة وأقسامًا ونوع مناسبة وباقة وفترة
     * وخدمات ولوحة تسعير ودفعة أولى. حشره في نافذة يجعل نصفه خلف تمرير
     * ويخفي لوحة التسعير وقت الحاجة إليها.
     */
    public function create(Request $request): Response
    {
        return Inertia::render('admin/bookings/halls/Form', [
            'booking' => null,
            // التقويم الشهري يفتح النموذج من خلية اليوم، فيصل ومعه القاعة
            // والتاريخ والفترة والقسم — والموظف يبدأ من العميل لا من إعادة
            // إدخال ما اختاره بالفعل. القيم اقتراحٌ يُعدَّل، والتحقق عند
            // الحفظ هو الحَكَم لا هذه المعاملات.
            'prefill' => array_filter([
                'unit_id' => $request->integer('unit_id') ?: null,
                'booking_date' => $request->date('booking_date')?->toDateString(),
                'period' => in_array($request->string('period')->toString(), BookingPeriod::hallKeys(), true)
                    ? $request->string('period')->toString()
                    : null,
                'section_ids' => array_values(array_filter(array_map(
                    'intval',
                    (array) $request->input('section_ids', []),
                ))),
            ], fn ($value) => $value !== null && $value !== []),
            ...$this->formData($request),
        ]);
    }

    public function edit(Request $request, Booking $booking): Response
    {
        $this->authorizeUnit($request, $booking->unit_id);

        $booking->load(['unit:id,name,code', 'client:id,name,mobile', 'sections:id,name',
            'eventType:id,name,color', 'package:id,name']);

        return Inertia::render('admin/bookings/halls/Form', [
            'booking' => [
                ...$this->present($booking),
                'package_id' => $booking->package_id,
                'event_type_id' => $booking->event_type_id,
                'discount_amount' => (float) $booking->discount_amount,
            ],
            ...$this->formData($request),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);

        $this->authorizeUnit($request, (int) $data['unit_id']);
        $this->authorizeUnitType((int) $data['unit_id']);

        return $this->createWithPayment(
            $request,
            fn () => $this->bookings->create($data, $request->user()?->id),
            route('bookings.halls.index'),
        );
    }

    public function update(Request $request, Booking $booking): RedirectResponse
    {
        $data = $this->validated($request);
        $unitId = (int) ($data['unit_id'] ?? $booking->unit_id);

        $this->authorizeUnit($request, $unitId);
        $this->authorizeUnitType($unitId);

        $this->bookings->update($booking, $data);

        return to_route('bookings.halls.index')->with('success', 'تم تحديث الحجز');
    }

    /**
     * ما يحتاجه نموذج الحجز من قوائم.
     *
     * الباقات والأنواع تُرسَل كلها مرة واحدة وتُصفّى في الواجهة بالقاعة
     * المختارة: عددها عشرات لا آلاف، فطلبها مع كل تبديل قاعة تأخير بلا مقابل.
     *
     * @return array<string, mixed>
     */
    private function formData(Request $request): array
    {
        $user = $request->user();

        return [
            'units' => $this->unitOptions($user),
            'clients' => $this->clientOptions(),
            // بنود الباقة تُرسل معها لتُعرض تحتها فور اختيارها: الموظف يقرأ
            // للعميل ما تشمله الباقة من عدد المعازيم والصبّابين والضيافة.
            'packages' => Package::with('items:id,package_id,name,quantity,unit_label,sort_order')
                ->where('is_active', true)->orderBy('sort_order')->orderBy('id')
                ->get(['id', 'name', 'unit_id', 'price'])
                ->map(fn (Package $p) => [
                    'id' => $p->id,
                    'name' => $p->name,
                    'unit_id' => $p->unit_id,
                    'price' => (float) $p->price,
                    'items' => $p->items->map(fn ($i) => [
                        'name' => $i->name,
                        'quantity' => $i->quantityLabel(),
                    ])->values(),
                ]),
            'eventTypes' => $this->eventTypeOptions($user),
            'meta' => static::meta(),
        ];
    }

    /**
     * أنواع المناسبات في القاعات التي يصل إليها المستخدم، بأسعارها.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function eventTypeOptions(?User $user)
    {
        return EventType::active()
            ->whereIn('unit_id', Unit::visibleTo($user)->where('type', 'hall')->pluck('id'))
            ->orderBy('unit_id')->orderBy('sort_order')->orderBy('id')
            ->get(['id', 'unit_id', 'name', 'color', 'price'])
            ->map(fn (EventType $t) => [
                'id' => $t->id,
                'unit_id' => $t->unit_id,
                'name' => $t->name,
                'color' => $t->color,
                'price' => (float) $t->price,
            ]);
    }

    /**
     * فحص الإتاحة واحتساب السعر قبل الحفظ — تستدعيه الواجهة عند كل تغيير.
     */
    public function quote(Request $request): JsonResponse
    {
        $data = $request->validate([
            'unit_id' => ['required', 'exists:units,id'],
            'scope' => ['required', Rule::in(['whole', 'sections'])],
            'section_ids' => ['array'],
            'section_ids.*' => ['integer', 'exists:unit_sections,id'],
            'booking_date' => ['required', 'date'],
            'days_count' => ['nullable', 'integer', 'min:1', 'max:'.BookingPeriod::MAX_DAYS],
            'period' => ['required', Rule::in(BookingPeriod::hallKeys())],
            'client_id' => ['nullable', 'exists:clients,id'],
            'event_type_id' => ['nullable', $this->eventTypeBelongsToUnit($request)],
            'package_id' => ['nullable', 'exists:packages,id'],
            'discount_amount' => ['nullable', 'numeric', 'min:0'],
            'ignore_booking_id' => ['nullable', 'integer'],
        ]);

        $unit = Unit::with('sections')->findOrFail($data['unit_id']);
        $sectionIds = $data['scope'] === 'sections' ? array_map('intval', $data['section_ids'] ?? []) : [];
        $days = BookingPeriod::days($data['days_count'] ?? null);

        return response()->json([
            'availability' => $this->availability->check(
                $unit,
                $data['scope'],
                $data['booking_date'],
                $data['period'],
                $sectionIds,
                isset($data['client_id']) ? (int) $data['client_id'] : null,
                $data['ignore_booking_id'] ?? null,
                $days,
            ),
            'pricing' => $this->pricing->quote(
                $unit,
                $data['scope'],
                $data['booking_date'],
                $data['period'],
                $sectionIds,
                // حجز القاعة بلا خدمات إضافية: القاعة تُباع بسعرها وباقتها
                [],
                (float) ($data['discount_amount'] ?? 0),
                isset($data['package_id']) ? (int) $data['package_id'] : null,
                isset($data['event_type_id']) ? (int) $data['event_type_id'] : null,
                $days,
            ),
            // آخر يوم يُحسب في الخادم لا في المتصفح: هو ما سيُخزَّن فعلًا،
            // فيرى الموظف قبل الحفظ ما سيُقفَل بالضبط.
            'schedule' => [
                'days' => $days,
                'last_day_date' => BookingPeriod::lastDay($data['booking_date'], $days),
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        return $request->validate([
            'unit_id' => ['required', 'exists:units,id'],
            'client_id' => ['nullable', 'exists:clients,id'],
            'event_type_id' => ['nullable', $this->eventTypeBelongsToUnit($request)],
            'package_id' => ['nullable', 'exists:packages,id'],
            'scope' => ['required', Rule::in(['whole', 'sections'])],
            'section_ids' => ['array', Rule::requiredIf(fn () => $request->input('scope') === 'sections')],
            'section_ids.*' => ['integer', 'exists:unit_sections,id'],
            'booking_date' => ['required', 'date'],
            // المناسبة قد تمتد أيامًا متتالية (حنّاء ثم زواج) فتُحجز حجزًا
            // واحدًا: الحد الأعلى حارس خطأ إدخال لا سياسة تسعير.
            'days_count' => ['nullable', 'integer', 'min:1', 'max:'.BookingPeriod::MAX_DAYS],
            // فترة القاعة لا تشمل «المبيت»: تلك طريقة الشاليه وشاشته.
            'period' => ['required', Rule::in(BookingPeriod::hallKeys())],
            'status' => ['nullable', Rule::in(array_keys(Booking::STATUSES))],
            'discount_amount' => ['nullable', 'numeric', 'min:0'],
            'guests_count' => ['nullable', 'integer', 'min:1'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);
    }

    /**
     * نوع المناسبة يجب أن يتبع القاعة المختارة.
     *
     * السعر الآن في النوع، فنوعٌ من قاعة أخرى يعني بيع هذه القاعة بسعر
     * جارتها. الرفض هنا لا في التسعير وحده حتى يرى الموظف السبب.
     */
    private function eventTypeBelongsToUnit(Request $request): Exists
    {
        return Rule::exists('event_types', 'id')
            ->where('unit_id', $request->integer('unit_id'));
    }
}
