<?php

namespace App\Http\Controllers\Admin;

use App\Models\Booking;
use App\Models\Unit;
use App\Support\BookingPeriod;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

/**
 * التقويم الشهري للقاعات — شبكة أسابيع، والخلية يومٌ كامل بما فيه.
 *
 * تقويم الشبكة (وحدة × يوم) يجيب سؤال «متى تُحجز هذه القاعة؟»، وهذا يجيب
 * سؤال الموظف على الهاتف: «يوم كذا، ماذا عندنا؟» — فيرى في خلية اليوم كل
 * قاعة وما حُجز فيها وما بقي متاحًا، ويفتح الحجز من الخلية نفسها.
 *
 * الصفحتان تتكاملان ولا تُغني إحداهما عن الأخرى: الأولى تقرأ أفقيًا عبر
 * الزمن، وهذه تقرأ رأسيًا داخل اليوم الواحد.
 */
class HallMonthCalendarController extends BaseCalendarController
{
    protected function unitType(): string
    {
        return 'hall';
    }

    public function index(Request $request): Response
    {
        [$month, $start, $end] = $this->month($request);

        $units = $this->units($request);

        if ($unitId = $request->integer('unit_id')) {
            $units = $units->where('id', $unitId)->values();
        }

        $bookings = $this->monthBookings($request, $units, $start, $end);

        return Inertia::render('admin/bookings/halls/MonthCalendar', [
            'month' => $month,
            'weeks' => $this->weeks($start, $end, $units, $bookings),
            'units' => $this->calendarUnits($units),
            'periods' => BookingPeriod::hallPeriods(),
            'filters' => ['unit_id' => $unitId ?: null],
            'summary' => $this->summary($bookings),
        ]);
    }

    /**
     * حجوزات الشهر المعروض — الشاغلة وحدها، فالملغي لا يشغل يومًا.
     *
     * @param  Collection<int, Unit>  $units
     * @return Collection<int, Booking>
     */
    private function monthBookings(Request $request, Collection $units, CarbonImmutable $start, CarbonImmutable $end): Collection
    {
        return Booking::query()
            ->visibleTo($request->user())
            ->blocking()
            ->events()
            ->whereIn('unit_id', $units->pluck('id'))
            ->with(['client:id,name,mobile', 'sections:id,name', 'eventType:id,name,color'])
            // التقاطع مع الشهر لا البداية داخله: مناسبة تبدأ آخر الشهر السابق
            // وتمتد إلى هذا الشهر تشغل أيامًا منه، وفلترة البداية تُخفيها.
            ->overlapping($start->startOfDay()->toDateTimeString(), $end->endOfDay()->toDateTimeString())
            ->orderBy('starts_at')
            ->get();
    }

    /**
     * الشهر مقسومًا إلى أسابيع تبدأ بالسبت — أول أيام الأسبوع محليًا.
     *
     * الأيام السابقة لأول الشهر واللاحقة لآخره تُرسل فارغة (placeholder) لتملأ
     * الشبكة، فيبقى ترتيب الأعمدة تحت أسماء الأيام صحيحًا.
     *
     * @param  Collection<int, Unit>  $units
     * @param  Collection<int, Booking>  $bookings
     * @return list<list<array<string, mixed>|null>>
     */
    private function weeks(CarbonImmutable $start, CarbonImmutable $end, Collection $units, Collection $bookings): array
    {
        $cells = [];

        // خانات فارغة قبل أول الشهر: السبت = 6 في ترقيم Carbon، فتُزاح الأيام
        // لتقع تحت اسم يومها الصحيح.
        $lead = ($start->dayOfWeek + 1) % 7;

        for ($i = 0; $i < $lead; $i++) {
            $cells[] = null;
        }

        for ($day = $start; $day->lte($end); $day = $day->addDay()) {
            $cells[] = $this->day($day, $units, $bookings);
        }

        // إكمال الأسبوع الأخير حتى تبقى الشبكة مستطيلة
        while (count($cells) % 7 !== 0) {
            $cells[] = null;
        }

        return array_chunk($cells, 7);
    }

    /**
     * خلية يوم واحد: كل قاعة وما حُجز فيها وما بقي متاحًا.
     *
     * @param  Collection<int, Unit>  $units
     * @param  Collection<int, Booking>  $bookings
     * @return array<string, mixed>
     */
    private function day(CarbonImmutable $day, Collection $units, Collection $bookings): array
    {
        $date = $day->toDateString();

        // اليوم الماضي يُعرض بما جرى فيه ولا يُعرض عليه حجز: القاعة لا تُباع
        // بأثر رجعي، وإظهار زرّ «متاح» على يوم مضى دعوةٌ إلى رسالة رفض.
        $isPast = $day->isBefore(now()->startOfDay());

        $rows = $units->map(function (Unit $unit) use ($date, $bookings, $isPast) {
            $dayBookings = $bookings->filter(
                fn (Booking $b) => $b->unit_id === $unit->id && in_array($date, $b->dayDates(), true),
            )->values();

            $slots = $isPast ? [] : $this->slots($unit, $date, $bookings);

            return [
                'unit_id' => $unit->id,
                'unit_name' => $unit->name,
                'bookings' => $dayBookings->map(fn (Booking $b) => $this->presentBooking($b))->all(),
                'slots' => $slots,
                'state' => $this->rowState($dayBookings, $slots, $isPast),
            ];
        });

        return [
            'date' => $date,
            'day' => $day->day,
            'is_today' => $day->isToday(),
            'is_past' => $isPast,
            'is_weekend' => in_array($day->dayOfWeek, [CarbonImmutable::FRIDAY, CarbonImmutable::SATURDAY], true),
            'units' => $rows->values()->all(),
            'bookings_count' => $rows->sum(fn ($r) => count($r['bookings'])),
        ];
    }

    /**
     * حال كل فترة في هذا اليوم لهذه القاعة.
     *
     * الفترة لا تُقاس بيومها بل بمداها: «المسائي» يمتد إلى ما بعد منتصف الليل،
     * فحجزٌ ليلة أمس قد يشغل صباح اليوم. ولذلك يُشتق مدى كل فترة ويُقاطَع
     * بالحجوزات كما يفعل كشف التعارض نفسه.
     *
     * @param  Collection<int, Booking>  $bookings
     * @return list<array<string, mixed>>
     */
    private function slots(Unit $unit, string $date, Collection $bookings): array
    {
        $sections = $unit->sections->where('is_active', true);

        // خانة لكل فترة تُباع بها القاعة — وهي اليوم الكامل وحده.
        return collect(BookingPeriod::periodsFor($unit))->only(BookingPeriod::hallKeys())->map(function ($meta, $period) use ($unit, $date, $bookings, $sections) {
            [$startsAt, $endsAt] = BookingPeriod::range($date, $period, 1, $unit);

            $clashing = $bookings->filter(fn (Booking $b) => $b->unit_id === $unit->id
                && $b->starts_at->lt($endsAt)
                && $b->ends_at->gt($startsAt));

            // حجز الوحدة كاملة يقفل الفترة بأقسامها — القاعدة 1 في BookingAvailability
            if ($clashing->contains(fn (Booking $b) => $b->scope === 'whole')) {
                return ['period' => $period, 'label' => $meta['label'], 'state' => 'taken', 'free_sections' => []];
            }

            $bookedSectionIds = $clashing->flatMap(fn (Booking $b) => $b->sections->pluck('id'))->unique();
            $freeSections = $sections->whereNotIn('id', $bookedSectionIds);

            $state = match (true) {
                $clashing->isEmpty() => 'free',
                $freeSections->isEmpty() => 'taken',
                default => 'partial',
            };

            // حجز الوحدة كاملة لا يُعرض إلا إذا قبِلته الوحدة ولم يشغلها شيء —
            // القاعدتان 2 و«نمط الحجز» في BookingAvailability. وعرض زرٍّ يقود
            // إلى رفعٍ مؤكد أسوأ من عدم عرضه.
            $canBookWhole = $state === 'free' && $unit->allowsWholeBooking();

            // الأقسام تُسرد حين تكون هي السبيل الوحيد للحجز: إما لأن الوحدة
            // مشغولة جزئيًا، أو لأنها لا تُحجز كاملة أصلًا.
            $offerSections = $unit->allowsSectionBooking()
                && ($state === 'partial' || ($state === 'free' && ! $canBookWhole));

            return [
                'period' => $period,
                'label' => $meta['label'],
                'state' => $state,
                'free_sections' => $offerSections
                    ? $freeSections->map(fn ($s) => ['id' => $s->id, 'name' => $s->name])->values()->all()
                    : [],
                'can_book_whole' => $canBookWhole,
            ];
        })->values()->all();
    }

    /**
     * حال القاعة في اليوم كله — لتلوين سطرها في الخلية بنظرة.
     *
     * @param  Collection<int, Booking>  $dayBookings
     * @param  list<array<string, mixed>>  $slots
     */
    private function rowState(Collection $dayBookings, array $slots, bool $isPast): string
    {
        if ($isPast) {
            return $dayBookings->isEmpty() ? 'past' : 'booked';
        }

        $states = array_column($slots, 'state');

        return match (true) {
            $states === [] => 'booked',
            ! in_array('free', $states, true) && ! in_array('partial', $states, true) => 'taken',
            $dayBookings->isNotEmpty() => 'partial',
            default => 'free',
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function presentBooking(Booking $booking): array
    {
        return [
            'id' => $booking->id,
            'reference' => $booking->reference,
            'client_name' => $booking->client?->name,
            'client_mobile' => $booking->client?->mobile,
            'event_type' => $booking->eventType?->name,
            'event_color' => $booking->eventType?->color,
            'period_label' => $booking->periodLabel(),
            'scope' => $booking->scope,
            'section_names' => $booking->sections->pluck('name')->all(),
            'days_count' => $booking->daysCount(),
            'status' => $booking->status,
            'status_label' => $booking->statusLabel(),
            'color' => Booking::STATUS_COLORS[$booking->status] ?? 'slate',
            'total_amount' => (float) $booking->total_amount,
            'paid_amount' => (float) $booking->paid_amount,
            'remaining_amount' => $booking->remainingAmount(),
            'notes' => $booking->notes,
        ];
    }

    /**
     * @param  Collection<int, Unit>  $units
     * @return list<array<string, mixed>>
     */
    private function calendarUnits(Collection $units): array
    {
        return $units->map(fn (Unit $u) => [
            'id' => $u->id,
            'name' => $u->name,
            'code' => $u->code,
        ])->values()->all();
    }

    /**
     * حصيلة الشهر — تُقرأ فوق الشبكة قبل النزول إلى تفاصيل الأيام.
     *
     * @param  Collection<int, Booking>  $bookings
     * @return array<string, mixed>
     */
    private function summary(Collection $bookings): array
    {
        return [
            'bookings' => $bookings->count(),
            'paid_in_full' => $bookings->where('status', 'paid_in_full')->count(),
            'total_amount' => round((float) $bookings->sum('total_amount'), 2),
            'remaining_amount' => round($bookings->sum(fn (Booking $b) => $b->remainingAmount()), 2),
        ];
    }
}
