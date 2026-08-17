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
use App\Support\StayPeriod;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * حجوزات الشاليهات — إقامة ممتدة بتاريخ دخول وتاريخ خروج.
 *
 * ما يميّز هذه الشاشة عن شاشة القاعات: لا فترة تُختار ولا باقة ولا نوع
 * مناسبة؛ بل ليالٍ تُحسب بين تاريخين، وسعرٌ يُجمع ليلةً ليلة.
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

    protected function unitType(): string
    {
        return 'chalet';
    }

    protected function scopeToType(Builder $query): Builder
    {
        return $query->stays();
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
            'filters' => $request->only(['status', 'unit_id', 'from', 'to', 'search']),
            'units' => $this->unitOptions($user),
            'meta' => self::meta(),
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
            'meta' => self::meta(),
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
            'section_ids' => ['array'],
            'section_ids.*' => ['integer', 'exists:unit_sections,id'],
            'booking_date' => ['required', 'date'],
            'check_out_date' => ['required', 'date', 'after:booking_date'],
            'client_id' => ['nullable', 'exists:clients,id'],
            'addons' => ['array'],
            'discount_amount' => ['nullable', 'numeric', 'min:0'],
            'ignore_booking_id' => ['nullable', 'integer'],
        ]);

        $unit = Unit::with('sections')->findOrFail($data['unit_id']);
        $sectionIds = $data['scope'] === 'sections' ? array_map('intval', $data['section_ids'] ?? []) : [];
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

        [$startsAt, $endsAt] = StayPeriod::range($data['booking_date'], $data['check_out_date']);

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
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        return $request->validate([
            'unit_id' => ['required', 'exists:units,id'],
            'client_id' => ['nullable', 'exists:clients,id'],
            'scope' => ['required', Rule::in(['whole', 'sections'])],
            'section_ids' => ['array', Rule::requiredIf(fn () => $request->input('scope') === 'sections')],
            'section_ids.*' => ['integer', 'exists:unit_sections,id'],
            // booking_date هو تاريخ الدخول؛ سُمّي كذلك ليبقى عمودًا واحدًا
            // يفهمه التقويم والتقارير في النوعين.
            'booking_date' => ['required', 'date'],
            'check_out_date' => ['required', 'date', 'after:booking_date'],
            'status' => ['nullable', Rule::in(array_keys(Booking::STATUSES))],
            'addons' => ['array'],
            'discount_amount' => ['nullable', 'numeric', 'min:0'],
            'guests_count' => ['nullable', 'integer', 'min:1'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);
    }
}
