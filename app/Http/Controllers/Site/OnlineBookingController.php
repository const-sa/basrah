<?php

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\Client;
use App\Models\EventType;
use App\Models\Unit;
use App\Services\BookingPricing;
use App\Services\BookingService;
use App\Services\ChaletBookingService;
use App\Services\WhatsappNotifier;
use App\Support\BookingPeriod;
use App\Support\SiteIdentity;
use App\Support\StayPeriod;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * الحجز الإلكتروني من الموقع العام (§12).
 *
 * الطلب القادم من الزائر يمرّ بنفس خدمات الحجز التي تستعملها شاشات
 * الإدارة — لا مسارًا موازيًا لها. وهذا مقصود: قواعد منع التعارض
 * والتسعير والخصوصية يجب أن تكون واحدة، وإلا باع الموقع ليلةً باعتها
 * الإدارة قبله بدقيقة.
 *
 * الفرق الوحيد أن الحجز يُسجَّل «بانتظار العربون» لا «مؤكدًا»: خلف حجز
 * الإدارة موظفٌ اتفق مع العميل، وخلف حجز الموقع زائرٌ ضغط زرًّا. والحالة
 * تحجز التاريخ فعلًا (فهي ضمن BLOCKING_STATUSES) فلا يُباع مرتين، وتبقى
 * موسومةً بأنها تنتظر عربونًا حتى تراجعها الإدارة.
 *
 * الدفع الإلكتروني خارج النطاق حاليًا، فالسداد يتم بالتحويل أو في المقر.
 */
class OnlineBookingController extends Controller
{
    public function __construct(
        private readonly BookingService $bookings,
        private readonly ChaletBookingService $stays,
        private readonly BookingPricing $pricing,
        private readonly WhatsappNotifier $whatsapp,
    ) {}

    /**
     * نموذج الحجز لوحدة بعينها.
     */
    public function create(Unit $unit): Response
    {
        abort_unless($unit->is_active, 404);

        $unit->load(['sections' => fn ($q) => $q->where('is_active', true)]);

        $isStay = $unit->type === 'chalet';

        return Inertia::render('site/Book', [
            'org' => SiteIdentity::current(),
            'unit' => [
                'id' => $unit->id,
                'name' => $unit->name,
                'type' => $unit->type,
                'capacity' => $unit->capacity,
                'logo_url' => $unit->logoUrl(),
                'allows_whole' => $unit->allowsWholeBooking(),
                'allows_sections' => $unit->allowsSectionBooking(),
                'sections' => $unit->sections->map(fn ($s) => ['id' => $s->id, 'name' => $s->name])->values(),
            ],
            'isStay' => $isStay,
            'periods' => $isStay ? [] : collect(BookingPeriod::PERIODS)
                ->map(fn (array $m, string $k) => ['key' => $k, 'label' => $m['label']])
                ->values(),
            // أنواع المناسبات تخصّ القاعات وحدها
            'eventTypes' => $isStay ? [] : EventType::active()->forUnit($unit->id)->get(['id', 'name']),
            'maxDays' => BookingPeriod::MAX_DAYS,
            'maxNights' => StayPeriod::maxNights(),
            'today' => now()->toDateString(),
        ]);
    }

    /**
     * تسعيرة مبدئية دون حفظ — يستدعيها النموذج مع كل تغيير.
     */
    public function quote(Request $request, Unit $unit): JsonResponse
    {
        abort_unless($unit->is_active, 404);

        $data = $this->validateRequest($request, $unit, quoteOnly: true);

        $sectionIds = $data['scope'] === 'sections' ? $data['section_ids'] : [];

        $quote = $unit->type === 'chalet'
            ? $this->pricing->quoteStay($unit, $data['booking_date'], $data['check_out_date'], $sectionIds)
            : $this->pricing->quote(
                $unit,
                $data['scope'],
                $data['booking_date'],
                $data['period'],
                $sectionIds,
                eventTypeId: $data['event_type_id'] ?? null,
                days: $data['days_count'] ?? 1,
            );

        return response()->json([
            'total_amount' => $quote['total_amount'],
            'deposit_amount' => $quote['deposit_amount'],
            'nights' => $quote['nights'] ?? null,
            'days' => $quote['days'] ?? null,
            'lines' => array_map(
                fn (array $l) => ['label' => $l['label'], 'amount' => $l['amount']],
                $quote['lines'],
            ),
        ]);
    }

    /**
     * تسجيل طلب الحجز.
     */
    public function store(Request $request, Unit $unit): RedirectResponse
    {
        abort_unless($unit->is_active, 404);

        $data = $this->validateRequest($request, $unit);

        $client = $this->resolveClient($data['client_name'], $data['client_mobile']);

        $payload = [
            'unit_id' => $unit->id,
            'client_id' => $client->id,
            'scope' => $data['scope'],
            'section_ids' => $data['section_ids'] ?? [],
            'booking_date' => $data['booking_date'],
            'guests_count' => $data['guests_count'] ?? null,
            'notes' => $data['notes'] ?? null,
            'source' => 'online',
            'status' => 'pending_deposit',
        ];

        // التعارض يخرج من الخدمة كـValidationException برسالتها المفصّلة
        // («الوحدة محجوزة كاملة…»)، فتصل الزائر كخطأ حقل على النموذج بدل
        // صفحة عطل — وهي الرسالة التي تشرح له لماذا رُفض تاريخه.
        $booking = $unit->type === 'chalet'
            ? $this->stays->create([...$payload, 'check_out_date' => $data['check_out_date']])
            : $this->bookings->create([
                ...$payload,
                'period' => $data['period'],
                'days_count' => $data['days_count'] ?? 1,
                'event_type_id' => $data['event_type_id'] ?? null,
            ]);

        // إشعار التأكيد قائم أصلًا في النظام وتستعمله شاشات الإدارة — يُستدعى
        // هنا كما هو دون تغيير في سلوكه: لا يُرسل شيء ما لم تكن بوابة
        // الواتساب مهيّأة.
        $this->whatsapp->bookingConfirmed($booking);

        return redirect()->route('site.booking.show', $booking->reference);
    }

    /**
     * صفحة تأكيد الطلب — يصلها الزائر بمرجع حجزه.
     */
    public function show(string $reference): Response
    {
        $booking = Booking::with(['unit:id,name,type', 'client:id,name,mobile', 'sections:id,name'])
            ->where('reference', $reference)
            ->firstOrFail();

        // الصفحة عامة بلا تسجيل دخول، فلا تعرض إلا ما يعرفه صاحب الحجز
        // أصلًا: لا ملاحظات داخلية ولا بيانات موظفين ولا سجل دفعات.
        return Inertia::render('site/Confirmation', [
            'org' => SiteIdentity::current(),
            'booking' => [
                'reference' => $booking->reference,
                'unit_name' => $booking->unit?->name,
                'is_stay' => $booking->isStay(),
                'scope' => $booking->scope === 'whole'
                    ? 'الوحدة كاملة'
                    : $booking->sections->pluck('name')->implode('، '),
                'booking_date' => $booking->booking_date->toDateString(),
                'check_out_date' => $booking->isStay() ? $booking->checkOutDate() : null,
                'schedule' => $booking->scheduleLabel(),
                'status_label' => $booking->statusLabel(),
                'total_amount' => (float) $booking->total_amount,
                'deposit_amount' => (float) $booking->deposit_amount,
                'client_name' => $booking->client?->name,
            ],
        ]);
    }

    /**
     * عميل الموقع: يُطابَق برقم جواله إن كان قد حجز من قبل، وإلا يُنشأ.
     *
     * المطابقة بالجوال لا بالاسم: الأسماء تتكرر وتُكتب بصيغ، والرقم هو ما
     * يميّز الشخص فعلًا. وبلا مطابقة يمتلئ سجل العملاء بنسخٍ من الشخص
     * نفسه بعد كل حجز.
     */
    private function resolveClient(string $name, string $mobile): Client
    {
        $normalized = preg_replace('/\D+/', '', $mobile) ?? $mobile;

        $client = Client::whereRaw("REPLACE(REPLACE(REPLACE(mobile,' ',''),'-',''),'+','') = ?", [$normalized])
            ->first();

        if ($client) {
            return $client;
        }

        return Client::create([
            'name' => $name,
            'mobile' => $mobile,
            'is_active' => true,
        ]);
    }

    /**
     * قواعد النموذج — واحدة للتسعيرة وللحفظ، فلا يمرّ إلى الحفظ ما رفضته
     * التسعيرة أو العكس.
     *
     * @return array<string, mixed>
     */
    private function validateRequest(Request $request, Unit $unit, bool $quoteOnly = false): array
    {
        $isStay = $unit->type === 'chalet';

        $rules = [
            'scope' => ['required', Rule::in(['whole', 'sections'])],
            'section_ids' => ['array'],
            'section_ids.*' => ['integer', Rule::exists('unit_sections', 'id')->where('unit_id', $unit->id)],
            'booking_date' => ['required', 'date', 'after_or_equal:today'],
            'guests_count' => ['nullable', 'integer', 'min:1', 'max:10000'],
            'notes' => ['nullable', 'string', 'max:500'],
        ];

        if ($isStay) {
            $rules['check_out_date'] = ['required', 'date', 'after:booking_date'];
        } else {
            $rules['period'] = ['required', Rule::in(array_keys(BookingPeriod::PERIODS))];
            $rules['days_count'] = ['nullable', 'integer', 'min:1', 'max:'.BookingPeriod::MAX_DAYS];
            $rules['event_type_id'] = ['nullable', 'integer', 'exists:event_types,id'];
        }

        if (! $quoteOnly) {
            $rules['client_name'] = ['required', 'string', 'max:255'];
            $rules['client_mobile'] = ['required', 'string', 'max:30'];
            // إقرار الزائر بالشروط شرطٌ لتسجيل الطلب لا زينة في النموذج.
            $rules['agreed'] = ['accepted'];
        }

        $data = $request->validate($rules);

        // النطاق المطلوب يُفحص هنا لا في الخدمة وحدها: رسالة «هذه الوحدة
        // تُحجز كاملة» تصل الزائر أوضح من رفضٍ عام بعد ملء النموذج كله.
        if ($data['scope'] === 'whole' && ! $unit->allowsWholeBooking()) {
            throw ValidationException::withMessages(['scope' => 'هذه الوحدة تُحجز بالأقسام فقط.']);
        }

        if ($data['scope'] === 'sections') {
            if (! $unit->allowsSectionBooking()) {
                throw ValidationException::withMessages(['scope' => 'هذه الوحدة تُحجز كاملة فقط.']);
            }

            if (empty($data['section_ids'])) {
                throw ValidationException::withMessages(['section_ids' => 'اختر قسمًا واحدًا على الأقل.']);
            }
        }

        return $data;
    }
}
