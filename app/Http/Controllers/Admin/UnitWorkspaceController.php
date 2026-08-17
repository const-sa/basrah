<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\CostCenter;
use App\Models\Sale;
use App\Models\Unit;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * مساحة عمل وحدة واحدة.
 *
 * كل قاعة وشاليه نشاط قائم بذاته له حجوزاته وفواتيره وربحيته، فتُجمَّع
 * هنا في شاشة واحدة بدل تصفيتها يدويًا من الشاشات العامة في كل مرة.
 */
class UnitWorkspaceController extends Controller
{
    public function show(Request $request, Unit $unit): Response
    {
        // نطاق الوحدات يُطبَّق هنا أيضًا: العنوان المباشر ليس ثغرة.
        if (! $request->user()?->canAccessUnit($unit)) {
            abort(403, 'ليس لديك صلاحية الوصول إلى هذه الوحدة.');
        }

        $unit->loadMissing(['manager:id,name,phone', 'sections.facilities:id,name,icon']);

        $today = CarbonImmutable::now()->startOfDay();
        $monthStart = $today->startOfMonth();
        $monthEnd = $monthStart->endOfMonth();

        return Inertia::render('admin/units/Workspace', [
            'unit' => [
                'id' => $unit->id,
                'code' => $unit->code,
                'name' => $unit->name,
                'type' => $unit->type,
                'type_label' => $unit->type === 'hall' ? 'قاعة' : 'شاليه',
                'logo_url' => $unit->logoUrl(),
                'capacity' => $unit->capacity,
                'is_active' => $unit->is_active,
                'privacy_mode' => $unit->privacy_mode,
                'bookable_mode' => $unit->bookable_mode,
                'manager' => $unit->manager ? [
                    'name' => $unit->manager->name,
                    'phone' => $unit->manager->phone,
                ] : null,
                'notes' => $unit->notes,
                'sections' => $unit->sections->map(fn ($s) => [
                    'id' => $s->id,
                    'name' => $s->name,
                    'gender' => $s->gender,
                    'is_active' => $s->is_active,
                    'facilities' => $s->facilities->pluck('name')->values(),
                ])->values(),
            ],
            'stats' => $this->stats($unit, $today, $monthStart, $monthEnd),
            'upcoming' => $this->upcoming($unit, $today),
            'invoices' => $this->invoices($unit),
            'canSeeMoney' => $request->user()->hasPermission('fin_reports.view'),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function stats(Unit $unit, CarbonImmutable $today, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $base = fn () => Booking::where('unit_id', $unit->id);

        $month = $base()->blocking()
            ->whereBetween('booking_date', [$from->toDateString(), $to->toDateString()]);

        $days = $from->daysInMonth;

        $bookedDays = $base()->blocking()
            ->whereBetween('booking_date', [$from->toDateString(), $to->toDateString()])
            ->groupBy('booking_date')
            ->select('booking_date')
            ->get()
            ->count();

        $profit = CostCenter::forUnit($unit)->profitability($from->toDateString(), $to->toDateString());

        return [
            'today' => $base()->blocking()->whereDate('booking_date', $today->toDateString())->count(),
            'upcoming' => $base()->blocking()->whereDate('booking_date', '>', $today->toDateString())->count(),
            'month_count' => (clone $month)->count(),
            'month_value' => round((float) (clone $month)->sum('total_amount'), 2),
            'outstanding' => round(
                (float) $base()->blocking()->sum('total_amount') - (float) $base()->blocking()->sum('paid_amount'),
                2,
            ),
            'occupancy' => $days > 0 ? round($bookedDays / $days * 100, 1) : 0.0,
            'revenue' => $profit['revenue'],
            'expense' => $profit['expense'],
            'profit' => $profit['profit'],
            'invoices_total' => round(
                (float) Sale::sales()->where('unit_id', $unit->id)->sum('total_amount'),
                2,
            ),
        ];
    }

    /**
     * الحجوزات القادمة لهذه الوحدة — اليوم وما بعده.
     *
     * @return list<array<string, mixed>>
     */
    private function upcoming(Unit $unit, CarbonImmutable $today): array
    {
        return Booking::where('unit_id', $unit->id)
            ->blocking()
            ->with(['client:id,name,mobile', 'sections:id,name'])
            ->whereDate('booking_date', '>=', $today->toDateString())
            ->orderBy('starts_at')
            ->limit(15)
            ->get()
            ->map(fn (Booking $b) => [
                'id' => $b->id,
                'reference' => $b->reference,
                'client' => $b->client?->name,
                'mobile' => $b->client?->mobile,
                'date' => $b->booking_date->toDateString(),
                // مساحة العمل تخدم النوعين: القاعة تُقرأ بفترتها والشاليه
                // بعدد لياليه، وscheduleLabel هو ما يصوغ الاثنين.
                'period' => $b->scheduleLabel(),
                'scope' => $b->scope === 'whole' ? 'الوحدة كاملة' : $b->sections->pluck('name')->implode('، '),
                'status' => $b->statusLabel(),
                'color' => Booking::STATUS_COLORS[$b->status] ?? 'slate',
                'total' => (float) $b->total_amount,
                'remaining' => $b->remainingAmount(),
            ])->values()->all();
    }

    /**
     * فواتير هذه الوحدة.
     *
     * @return list<array<string, mixed>>
     */
    private function invoices(Unit $unit): array
    {
        return Sale::where('unit_id', $unit->id)
            ->with(['client:id,name', 'paymentMethod:id,name'])
            ->latest('id')
            ->limit(15)
            ->get()
            ->map(fn (Sale $s) => [
                'id' => $s->id,
                'number' => $s->number,
                'type' => $s->type,
                'client' => $s->client?->name,
                'total' => (float) $s->total_amount,
                'method' => $s->methodLabel(),
                'date' => $s->created_at->toDateString(),
            ])->values()->all();
    }
}
