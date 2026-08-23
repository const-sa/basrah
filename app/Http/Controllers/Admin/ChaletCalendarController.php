<?php

namespace App\Http\Controllers\Admin;

use App\Models\Booking;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * تقويم الشاليهات — شريط إقامة يمتد من ليلة الدخول إلى ليلة المغادرة.
 *
 * الفرق عن تقويم القاعات: الوحدة المعروضة هنا ليست خلية بل مدى. الإقامة
 * التي تبدأ الخميس وتنتهي الأحد شيء واحد لا ثلاثة حجوزات، ورسمها ثلاث خلايا
 * منفصلة يُخفي أنها إقامة واحدة ويجعل عدّ الليالي بصريًا مستحيلًا.
 *
 * لذلك يحسب الخادم لكل إقامة موضعها في شبكة الشهر: عمود البداية وعدد
 * الأعمدة التي تمتد عليها، بعد قصّها على حدود الشهر المعروض. الإقامة العابرة
 * للشهر تظهر مقصوصة مع علامة استمرار، لا مبتورة بلا أثر.
 */
class ChaletCalendarController extends BaseCalendarController
{
    protected function unitType(): string
    {
        return 'chalet';
    }

    public function index(Request $request): Response
    {
        [$month, $start, $end] = $this->month($request);

        $units = $this->units($request);
        $daysInMonth = $start->diffInDays($end) + 1;

        $bookings = Booking::query()
            ->visibleTo($request->user())
            ->blocking()
            // Not narrowed to stays(): whereIn on chalet units already scopes
            // this, and a day-use chalet booking occupies the unit just as a
            // stay does — hiding it would show those days as free.
            ->whereIn('unit_id', $units->modelKeys())
            ->with(['client:id,name', 'sections:id,name'])
            // الإقامة تدخل الشهر إن تقاطعت معه، لا إن بدأت فيه: إقامة تبدأ
            // في الشهر الماضي وتنتهي في هذا الشهر تشغل ليالي هذا الشهر فعلًا.
            ->where('booking_date', '<=', $end->toDateString())
            ->where(function ($q) use ($start) {
                // A day-use booking stores no check-out date, so its tail is
                // read from ends_at instead — without this it would match
                // every month rather than the one it falls in.
                $q->where('check_out_date', '>', $start->toDateString())
                    ->orWhere(fn ($sub) => $sub->whereNull('check_out_date')
                        ->where('ends_at', '>=', $start->startOfDay()));
            })
            ->when($request->integer('unit_id'), fn ($q, $id) => $q->where('unit_id', $id))
            ->orderBy('booking_date')
            ->get()
            ->map(fn (Booking $b) => $this->presentStay($b, $start, $daysInMonth))
            ->filter()
            ->values();

        return Inertia::render('admin/bookings/chalets/Calendar', [
            'month' => $month,
            'days' => $this->days($start, $end),
            'units' => $this->unitRows($units),
            'bookings' => $bookings,
            'meta' => ChaletBookingsController::meta(),
            'filters' => ['unit_id' => $request->integer('unit_id') ?: null],
        ]);
    }

    /**
     * موضع الإقامة في شبكة الشهر بعد قصّها على حدوده.
     *
     * الليلة تُنسب ليوم الدخول لا ليوم الخروج: إقامة من 5 إلى 7 تشغل ليلتي
     * 5 و6، ويوم 7 حرٌّ لإقامة جديدة. هذا ما يجعل العمود الأخير قابلًا
     * لإعادة الحجز في اليوم نفسه.
     *
     * @return array<string, mixed>|null  null إن لم تشغل الإقامة ليلة داخل الشهر
     */
    private function presentStay(Booking $b, CarbonImmutable $monthStart, int $daysInMonth): ?array
    {
        $checkIn = CarbonImmutable::parse($b->booking_date->toDateString())->startOfDay();
        $checkOut = CarbonImmutable::parse($b->checkOutDate())->startOfDay();

        /*
         * The last day the booking actually holds the chalet.
         *
         * A stay is released on the morning of its check-out date, so its
         * last occupied night is the day before. A day-use booking holds its
         * own days instead — and deriving that from ends_at would be wrong
         * for a morning period, which closes at 17:00 the same day and would
         * come out as the day *before* it starts.
         */
        $lastDay = $b->isStay()
            ? $checkOut->subDay()
            : CarbonImmutable::parse($b->lastDayDate())->startOfDay();

        // أول ليلة مرئية وآخر ليلة مرئية، محصورتان داخل الشهر.
        $firstNight = $checkIn->lt($monthStart) ? $monthStart : $checkIn;
        $monthEnd = $monthStart->addDays($daysInMonth - 1);
        $lastNight = $lastDay->gt($monthEnd) ? $monthEnd : $lastDay;

        if ($lastNight->lt($firstNight)) {
            return null;
        }

        $startIndex = (int) $monthStart->diffInDays($firstNight);

        return [
            'id' => $b->id,
            'reference' => $b->reference,
            'unit_id' => $b->unit_id,
            'scope' => $b->scope,
            'section_ids' => $b->sections->pluck('id')->all(),
            'section_names' => $b->sections->pluck('name')->all(),
            'client_name' => $b->client?->name,
            'check_in' => $checkIn->toDateString(),
            // A day-use booking has no check-out; report the last day it holds.
            'check_out' => $b->isStay() ? $checkOut->toDateString() : $lastDay->toDateString(),
            'nights' => $b->nightsCount(),
            'schedule_label' => $b->scheduleLabel(),
            // موضع الشريط في الشبكة — الواجهة ترسم لا تحسب.
            'start_index' => $startIndex,
            'span' => (int) $firstNight->diffInDays($lastNight) + 1,
            'continues_before' => $checkIn->lt($monthStart),
            'continues_after' => $lastDay->gt($monthEnd),
            'status' => $b->status,
            'status_label' => $b->statusLabel(),
            'color' => Booking::STATUS_COLORS[$b->status] ?? 'slate',
            'total_amount' => (float) $b->total_amount,
            'remaining_amount' => $b->remainingAmount(),
        ];
    }
}
