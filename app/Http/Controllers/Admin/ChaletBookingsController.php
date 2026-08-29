<?php

namespace App\Http\Controllers\Admin;

use App\Models\Addon;
use App\Models\Booking;
use App\Models\Unit;
use App\Services\BookingAvailability;
use App\Services\BookingPricing;
use App\Services\BookingService;
use App\Services\ChaletBookingService;
use App\Services\WhatsappNotifier;
use App\Support\BookingPeriod;
use App\Support\HourlyPeriod;
use App\Support\StayPeriod;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\ExcludeIf;
use Inertia\Inertia;
use Inertia\Response;

/**
 * حجوزات الشاليهات.
 *
 * A chalet is sold either as a stay — nights between a check-in and a
 * check-out date, priced night by night — or, where the period has been
 * priced, for a morning, an evening or a full day the way a hall is.
 *
 * ما يميّز هذه الشاشة عن شاشة القاعات: لا باقة ولا نوع مناسبة.
 */
class ChaletBookingsController extends BaseBookingsController
{
    public function __construct(
        BookingAvailability $availability,
        BookingPricing $pricing,
        BookingService $bookings,
        WhatsappNotifier $whatsapp,
        private readonly ChaletBookingService $stays,
    ) {
        parent::__construct($availability, $pricing, $bookings, $whatsapp);
    }

    /**
     * A chalet is let one section at a time.
     *
     * What the screens call a «قسم» is a room inside the chalet, not a wing of
     * it: a booking takes one room, and a guest who wants two is sold the
     * chalet whole. The rule is applied on every endpoint that accepts a
     * selection — quote, diary and save alike — so none of them can accept a
     * booking another would refuse.
     */
    private const ONE_SECTION = 'يُحجز قسم واحد فقط في الحجز الواحد — لحجز أكثر من قسم اختر «الشاليه كاملًا».';

    protected function unitType(): string
    {
        return 'chalet';
    }

    /**
     * No narrowing by period here. filteredQuery() already restricts the list
     * to chalets, and a chalet holds both shapes — filtering on stays() as
     * well would hide every day-use booking from its own screen while the
     * halls screen excludes it by unit type, leaving it visible nowhere.
     */
    protected function scopeToType(Builder $query): Builder
    {
        return $query;
    }

    protected function extraRelations(): array
    {
        // Payments ride along with the row so the preview can show what was
        // taken by which method — working it out per booking would open a
        // query per line.
        return ['payments:id,booking_id,type,payment_method_id,amount'];
    }

    /**
     * The stay row carries the same money ledger as an event row: a chalet has
     * no package or event fee, so those terms are simply zero.
     *
     * @return array<string, mixed>
     */
    protected function present(Booking $b): array
    {
        return [
            ...parent::present($b),
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

        return Inertia::render('admin/bookings/chalets/Index', [
            'bookings' => $bookings,
            'filters' => $this->filterState($request, ['status', 'unit_id', 'from', 'to', 'search']),
            'units' => $this->unitOptions($user),
            'meta' => static::meta(),
            'stats' => $this->stats($query),
        ]);
    }

    /**
     * شاشة إنشاء إقامة — صفحة كاملة لا نافذة، كشاشة القاعات.
     */
    public function create(Request $request): Response
    {
        return Inertia::render('admin/bookings/chalets/Form', [
            'booking' => null,
            ...$this->formData($request),
        ]);
    }

    public function edit(Request $request, Booking $booking): Response
    {
        $this->authorizeUnit($request, $booking->unit_id);

        $booking->load(['unit:id,name,code', 'client:id,name,mobile', 'sections:id,name', 'addons']);

        return Inertia::render('admin/bookings/chalets/Form', [
            'booking' => [
                ...$this->present($booking),
                'discount_amount' => (float) $booking->discount_amount,
                // المبلغ المتَّفق عليه في الحجز بالساعات — تفتح عليه الشاشة
                // خانةَ المبلغ، فلا يُعاد الاتفاق كتابةً في كل تعديل.
                'base_amount' => (float) $booking->base_amount,
                'addons' => $booking->addons->mapWithKeys(
                    fn ($a) => [$a->id => (int) $a->pivot->quantity],
                ),
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
            fn () => $this->stays->create($data, $request->user()?->id),
            route('bookings.chalets.index'),
        );
    }

    public function update(Request $request, Booking $booking): RedirectResponse
    {
        $data = $this->validated($request);
        $unitId = (int) ($data['unit_id'] ?? $booking->unit_id);

        $this->authorizeUnit($request, $unitId);
        $this->authorizeUnitType($unitId);

        $this->stays->update($booking, $data);

        return to_route('bookings.chalets.index')->with('success', 'تم تحديث الحجز');
    }

    /**
     * ما يحتاجه نموذج الإقامة من قوائم — لا باقات ولا أنواع مناسبات.
     *
     * @return array<string, mixed>
     */
    private function formData(Request $request): array
    {
        return [
            'units' => $this->unitOptions($request->user()),
            'clients' => $this->clientOptions(),
            'addons' => Addon::where('is_active', true)->orderBy('sort_order')
                ->get(['id', 'name', 'price', 'pricing']),
            'meta' => static::meta(),
        ];
    }

    /**
     * فحص الإتاحة واحتساب سعر الإقامة قبل الحفظ.
     */
    public function quote(Request $request): JsonResponse
    {
        $data = $request->validate([
            'unit_id' => ['required', 'exists:units,id'],
            'scope' => ['required', Rule::in(['whole', 'sections'])],
            'section_ids' => ['array', 'max:1'],
            'section_ids.*' => ['integer', 'exists:unit_sections,id'],
            'booking_date' => ['required', 'date'],
            'period' => ['nullable', Rule::in([...StayPeriod::pricingKeys(), HourlyPeriod::PERIOD])],
            // ساعتا الحجز بالساعات ومبلغه المتَّفق عليه — تُقرأ في هذا الشكل
            // وحده، ويتجاهلها غيره كما يتجاهل تاريخ الخروج.
            'start_time' => ['nullable', 'date_format:H:i'],
            'end_time' => ['nullable', 'date_format:H:i'],
            'hourly_amount' => ['nullable', 'numeric', 'min:0', 'max:9999999999'],
            // Dropped outright for a day-use booking rather than merely made
            // optional: the form keeps a check-out date in state while the
            // field is hidden, and validating one nothing reads would fail the
            // save against an input the day-use form never shows.
            'check_out_date' => [
                $this->excludeUnlessStay($request),
                'required', 'date', 'after:booking_date',
            ],
            'days_count' => ['nullable', 'integer', 'min:1', 'max:'.BookingPeriod::MAX_DAYS],
            'client_id' => ['nullable', 'exists:clients,id'],
            'addons' => ['array'],
            'discount_amount' => ['nullable', 'numeric', 'min:0'],
            'ignore_booking_id' => ['nullable', 'integer'],
        ], ['section_ids.max' => self::ONE_SECTION]);

        $unit = Unit::with(['sections', 'prices'])->findOrFail($data['unit_id']);
        $sectionIds = $data['scope'] === 'sections' ? array_map('intval', $data['section_ids'] ?? []) : [];
        $period = $data['period'] ?? StayPeriod::PERIOD;

        if ($period === HourlyPeriod::PERIOD) {
            return $this->hourlyQuote($unit, $data, $sectionIds);
        }

        if ($period !== StayPeriod::PERIOD) {
            return $this->dayUseQuote($unit, $data, $period, $sectionIds);
        }

        $nights = StayPeriod::nights($data['booking_date'], $data['check_out_date']);

        // السقف يُفحص هنا أيضًا لا في الحفظ وحده، حتى ترى الواجهة السبب
        // قبل أن يملأ الموظف بقية النموذج.
        if ($nights > StayPeriod::maxNights()) {
            return response()->json([
                'availability' => [
                    'ok' => false,
                    'reason' => 'أقصى مدة إقامة '.StayPeriod::maxNights()." ليلة، والمطلوب {$nights}.",
                    'conflicts' => [],
                ],
                'pricing' => null,
            ]);
        }

        [$startsAt, $endsAt] = StayPeriod::range($data['booking_date'], $data['check_out_date'], $unit);

        return response()->json([
            'availability' => $this->availability->checkRange(
                $unit,
                $data['scope'],
                $startsAt,
                $endsAt,
                $sectionIds,
                isset($data['client_id']) ? (int) $data['client_id'] : null,
                $data['ignore_booking_id'] ?? null,
            ),
            'pricing' => $this->pricing->quoteStay(
                $unit,
                $data['booking_date'],
                $data['check_out_date'],
                $sectionIds,
                $data['addons'] ?? [],
                (float) ($data['discount_amount'] ?? 0),
            ),
        ]);
    }

    /**
     * الإتاحة والسعر لحجزٍ بالساعات.
     *
     * السعر هنا هو ما أدخله الموظف لا ما يُحسب له: لا جدول لهذا الشكل. وتبقى
     * الإتاحة تُفحص كما تُفحص في سائر الأشكال — على المدى نفسه — فالساعتان
     * تقفلان الشاليه فيهما ويقفلانه على الإقامة التي تتقاطع معهما.
     *
     * @param  array<string, mixed>  $data
     * @param  list<int>  $sectionIds
     */
    private function hourlyQuote(Unit $unit, array $data, array $sectionIds): JsonResponse
    {
        $start = $data['start_time'] ?? null;
        $end = $data['end_time'] ?? null;

        // الشاشة تسأل عن السعر قبل أن تُكتب الساعتان، فيُردّ عليها ببيانٍ
        // لما ينقص لا برفضٍ يبدو خطأً في الشاليه.
        if (! $start || ! $end) {
            return response()->json([
                'availability' => [
                    'ok' => false,
                    'reason' => 'اكتب ساعة البداية وساعة النهاية.',
                    'conflicts' => [],
                ],
                'pricing' => null,
            ]);
        }

        [$startsAt, $endsAt] = HourlyPeriod::range($data['booking_date'], $start, $end);
        $minutes = $startsAt->diffInMinutes($endsAt);

        if ($minutes < HourlyPeriod::MIN_MINUTES || $minutes > HourlyPeriod::MAX_HOURS * 60) {
            return response()->json([
                'availability' => [
                    'ok' => false,
                    'reason' => $minutes < HourlyPeriod::MIN_MINUTES
                        ? 'أقصر حجز بالساعات '.HourlyPeriod::MIN_MINUTES.' دقيقة.'
                        : 'ما تجاوز '.HourlyPeriod::MAX_HOURS.' ساعة يُحجز بالليلة لا بالساعات.',
                    'conflicts' => [],
                ],
                'pricing' => null,
            ]);
        }

        return response()->json([
            'availability' => $this->availability->checkRange(
                $unit,
                $data['scope'],
                $startsAt,
                $endsAt,
                $sectionIds,
                isset($data['client_id']) ? (int) $data['client_id'] : null,
                $data['ignore_booking_id'] ?? null,
            ),
            'pricing' => $this->pricing->quoteHourly(
                $unit,
                (float) ($data['hourly_amount'] ?? 0),
                HourlyPeriod::hours($startsAt, $endsAt),
                $sectionIds,
                $data['addons'] ?? [],
                (float) ($data['discount_amount'] ?? 0),
            ),
        ]);
    }

    /**
     * Availability and price for a chalet sold by the day rather than the
     * night. Refuses an unpriced period here as well as on save, so the form
     * says why before the rest of it is filled in.
     *
     * @param  array<string, mixed>  $data
     * @param  list<int>  $sectionIds
     */
    private function dayUseQuote(Unit $unit, array $data, string $period, array $sectionIds): JsonResponse
    {
        if (! in_array($period, $unit->dayUsePeriods(), true)) {
            return response()->json([
                'availability' => [
                    'ok' => false,
                    'reason' => 'هذه الفترة غير مسعَّرة لهذا الشاليه — أضف سعرها من شاشة الأسعار أولًا.',
                    'conflicts' => [],
                ],
                'pricing' => null,
            ]);
        }

        $days = BookingPeriod::days((int) ($data['days_count'] ?? 1));
        [$startsAt, $endsAt] = BookingPeriod::range($data['booking_date'], $period, $days, $unit);

        return response()->json([
            'availability' => $this->availability->checkRange(
                $unit,
                $data['scope'],
                $startsAt,
                $endsAt,
                $sectionIds,
                isset($data['client_id']) ? (int) $data['client_id'] : null,
                $data['ignore_booking_id'] ?? null,
            ),
            'pricing' => $this->pricing->quote(
                $unit,
                $data['scope'],
                $data['booking_date'],
                $period,
                $sectionIds,
                $data['addons'] ?? [],
                (float) ($data['discount_amount'] ?? 0),
                null,
                null,
                $days,
            ),
        ]);
    }

    /**
     * How far ahead one availability request may look. The form asks for the
     * grid it is about to draw — a month and its spill days — and the cap
     * keeps a hand-made request from sweeping years of diary in one go.
     */
    private const CALENDAR_MAX_DAYS = 92;

    /**
     * Which days of a window this chalet is free on — the night of each day,
     * and each day period beside it.
     *
     * The calendar in the form has to know before a date is picked, while the
     * quote answers one chosen range at a time. This is for display only: a
     * day shown as free is still quoted when it is picked, so the calendar can
     * never let through what the quote would refuse.
     */
    public function availability(Request $request): JsonResponse
    {
        $data = $request->validate([
            'unit_id' => ['required', 'exists:units,id'],
            'scope' => ['required', Rule::in(['whole', 'sections'])],
            'section_ids' => ['array', 'max:1'],
            'section_ids.*' => ['integer', 'exists:unit_sections,id'],
            'from' => ['required', 'date'],
            'to' => ['required', 'date', 'after_or_equal:from'],
            'client_id' => ['nullable', 'exists:clients,id'],
            'ignore_booking_id' => ['nullable', 'integer'],
        ], ['section_ids.max' => self::ONE_SECTION]);

        $unit = Unit::with(['sections', 'prices'])->findOrFail($data['unit_id']);
        $this->authorizeUnit($request, $unit->id);

        $from = CarbonImmutable::parse($data['from'])->startOfDay();
        $to = CarbonImmutable::parse($data['to'])->startOfDay()
            ->min($from->addDays(self::CALENDAR_MAX_DAYS - 1));

        $sectionIds = $data['scope'] === 'sections' ? array_map('intval', $data['section_ids'] ?? []) : [];
        $periods = $unit->dayUsePeriods();

        $free = $this->availability->freeRanges(
            $unit,
            $data['scope'],
            $this->calendarRanges($unit, $from, $to, $periods),
            $sectionIds,
            isset($data['client_id']) ? (int) $data['client_id'] : null,
            $data['ignore_booking_id'] ?? null,
        );

        $days = [];

        for ($day = $from; $day->lessThanOrEqualTo($to); $day = $day->addDay()) {
            $date = $day->toDateString();

            $days[$date] = [
                'stay' => $free[StayPeriod::PERIOD."|{$date}"] ?? true,
                'periods' => collect($periods)
                    ->mapWithKeys(fn (string $period) => [$period => $free["{$period}|{$date}"] ?? true])
                    ->all(),
            ];
        }

        return response()->json(['days' => $days]);
    }

    /**
     * One range per thing the calendar draws: a night per day, plus every day
     * period the chalet is priced for.
     *
     * The night is keyed to the day it starts on, not the day it ends — a stay
     * from the 5th to the 7th takes the nights of the 5th and 6th, and the 7th
     * is free again for someone arriving that afternoon.
     *
     * الساعات المرسومة هي ساعات هذا الشاليه نفسه، فما يظهر في التقويم متاحًا
     * هو ما يقبله عرض السعر عند اختياره لا مدًى آخر بساعات النظام.
     *
     * @param  list<string>  $periods
     * @return list<array{key: string, starts_at: CarbonImmutable, ends_at: CarbonImmutable}>
     */
    private function calendarRanges(Unit $unit, CarbonImmutable $from, CarbonImmutable $to, array $periods): array
    {
        $ranges = [];

        for ($day = $from; $day->lessThanOrEqualTo($to); $day = $day->addDay()) {
            $date = $day->toDateString();

            [$starts, $ends] = StayPeriod::range($date, $day->addDay()->toDateString(), $unit);
            $ranges[] = ['key' => StayPeriod::PERIOD."|{$date}", 'starts_at' => $starts, 'ends_at' => $ends];

            foreach ($periods as $period) {
                [$starts, $ends] = BookingPeriod::range($date, $period, 1, $unit);
                $ranges[] = ['key' => "{$period}|{$date}", 'starts_at' => $starts, 'ends_at' => $ends];
            }
        }

        return $ranges;
    }

    /**
     * يُطلَب الحقل في الحجز بالساعات، ويُسقَط في غيره.
     *
     * الإسقاط لا الاختيار: الشاشة تحتفظ بالساعتين في حالتها بعد التبديل إلى
     * شكل آخر، فالتحقق عليهما هناك يردّ حجزًا صحيحًا بسبب حقلٍ لا يُعرض.
     */
    private function requiredIfHourly(Request $request): ExcludeIf|string
    {
        return $request->input('period') === HourlyPeriod::PERIOD
            ? 'required'
            : Rule::excludeIf(true);
    }

    /**
     * Drops the field unless this request is an overnight stay, so a day-use
     * booking neither validates nor keeps a check-out date.
     */
    private function excludeUnlessStay(Request $request): ExcludeIf
    {
        return Rule::excludeIf(
            fn () => ($request->input('period') ?? StayPeriod::PERIOD) !== StayPeriod::PERIOD,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        return $request->validate([
            'unit_id' => ['required', 'exists:units,id'],
            'client_id' => ['nullable', 'exists:clients,id'],
            'scope' => ['required', Rule::in(['whole', 'sections'])],
            'section_ids' => ['array', 'max:1', Rule::requiredIf(fn () => $request->input('scope') === 'sections')],
            'section_ids.*' => ['integer', 'exists:unit_sections,id'],
            // booking_date هو تاريخ الدخول؛ سُمّي كذلك ليبقى عمودًا واحدًا
            // يفهمه التقويم والتقارير في النوعين.
            'booking_date' => ['required', 'date'],
            // A chalet is a stay by default. A day-use booking names one of
            // the day periods instead, and then carries days_count rather
            // than a check-out date — see ChaletBookingService::plan().
            'period' => ['nullable', Rule::in([...StayPeriod::pricingKeys(), HourlyPeriod::PERIOD])],
            // الحجز بالساعات وحده يحمل ساعتيه ومبلغه، ويلزمانه: بلا واحدة
            // منهما لا مدى يُحجز به.
            'start_time' => [$this->requiredIfHourly($request), 'date_format:H:i'],
            'end_time' => [$this->requiredIfHourly($request), 'date_format:H:i'],
            'hourly_amount' => [$this->requiredIfHourly($request), 'numeric', 'min:0', 'max:9999999999'],
            // See the note in quote(): a day-use booking has no check-out
            // date, so a leftover one is dropped instead of validated.
            'check_out_date' => [
                $this->excludeUnlessStay($request),
                'required', 'date', 'after:booking_date',
            ],
            'days_count' => ['nullable', 'integer', 'min:1', 'max:'.BookingPeriod::MAX_DAYS],
            'status' => ['nullable', Rule::in(array_keys(Booking::STATUSES))],
            'addons' => ['array'],
            'discount_amount' => ['nullable', 'numeric', 'min:0'],
            // The security deposit is held, not charged, so it has no bearing
            // on the total and is only bounded against a typing slip.
            'security_deposit_amount' => ['nullable', 'numeric', 'min:0', 'max:9999999999'],
            'guests_count' => ['nullable', 'integer', 'min:1'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ], ['section_ids.max' => self::ONE_SECTION]);
    }
}
