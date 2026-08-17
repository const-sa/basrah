<?php

namespace App\Http\Controllers\Admin;

use App\Models\Booking;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * تقويم القاعات — شبكة يوم × (وحدة/قسم)، والخلية تحمل فترة المناسبة.
 *
 * الحجز هنا لا يتجاوز يومه، فالخلية وحدة العرض الطبيعية: كل حجز نقطة في
 * تقاطع صفٍّ ويوم، وقد تحمل الخلية أكثر من حجز (صباحي ومسائي في يوم واحد).
 */
class HallCalendarController extends BaseCalendarController
{
    protected function unitType(): string
    {
        return 'hall';
    }

    public function index(Request $request): Response
    {
        [$month, $start, $end] = $this->month($request);

        $units = $this->units($request);

        $bookings = Booking::query()
            ->visibleTo($request->user())
            ->blocking()
            ->events()
            ->whereIn('unit_id', $units->modelKeys())
            ->with(['client:id,name', 'sections:id,name', 'eventType:id,name,color'])
            // التقاطع مع الشهر لا البداية داخله: مناسبة تمتد أيامًا وتبدأ آخر
            // الشهر السابق تشغل أيامًا من هذا الشهر، وفلترة البداية تُخفيها.
            ->overlapping($start->startOfDay()->toDateTimeString(), $end->endOfDay()->toDateTimeString())
            ->when($request->integer('unit_id'), fn ($q, $id) => $q->where('unit_id', $id))
            ->orderBy('starts_at')
            ->get()
            ->map(fn (Booking $b) => [
                'id' => $b->id,
                'reference' => $b->reference,
                'unit_id' => $b->unit_id,
                'scope' => $b->scope,
                'section_ids' => $b->sections->pluck('id')->all(),
                'section_names' => $b->sections->pluck('name')->all(),
                'client_name' => $b->client?->name,
                'event_type' => $b->eventType?->name,
                'date' => $b->booking_date->toDateString(),
                // المناسبة الممتدة تشغل أيامها كلها، فتُرسل تواريخها لتظهر في
                // كل خلية منها بدل أن تظهر في يومها الأول وحده.
                'dates' => $b->dayDates(),
                'days_count' => $b->daysCount(),
                'period' => $b->period,
                'period_label' => $b->periodLabel(),
                'status' => $b->status,
                'status_label' => $b->statusLabel(),
                'color' => Booking::STATUS_COLORS[$b->status] ?? 'slate',
                'total_amount' => (float) $b->total_amount,
                'remaining_amount' => $b->remainingAmount(),
            ])
            ->values();

        return Inertia::render('admin/bookings/halls/Calendar', [
            'month' => $month,
            'days' => $this->days($start, $end),
            'units' => $this->unitRows($units),
            'bookings' => $bookings,
            'meta' => HallBookingsController::meta(),
            'filters' => ['unit_id' => $request->integer('unit_id') ?: null],
        ]);
    }
}
