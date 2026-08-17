<?php

namespace App\Support\Reports;

use App\Models\Booking;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * تقارير الحجوزات (§12 من العرض المعتمد).
 *
 * التجميع الزمني يقع في PHP لا في SQL: دوال التاريخ تختلف بين MySQL
 * وSQLite، وتقريرٌ يعمل في الإنتاج ويسقط في الاختبارات ليس تقريرًا. والمدى
 * محصور بمرشّح التاريخ فالصفوف المقروءة محدودة بما يُعرض.
 */
class BookingReports
{
    /**
     * @return list<ReportDefinition>
     */
    public static function all(): array
    {
        return [
            self::periodic('bookings-daily', 'الحجوزات اليومية', 'الحجوزات مجمَّعة بيومها، بعددها ومبالغها.', 'day', 'month'),
            self::periodic('bookings-weekly', 'الحجوزات الأسبوعية', 'الحجوزات مجمَّعة بأسبوعها من السبت إلى الجمعة.', 'week', 'quarter'),
            self::periodic('bookings-monthly', 'الحجوزات الشهرية', 'الحجوزات مجمَّعة بشهرها خلال المدة.', 'month', 'year'),
            self::periodic('bookings-yearly', 'الحجوزات السنوية', 'الحجوزات مجمَّعة بسنتها للمقارنة بين المواسم.', 'year', 'years'),
            self::listing(
                'bookings-cancelled',
                'الحجوزات الملغاة',
                'ما أُلغي من حجوزات خلال المدة وسبب الإلغاء.',
                fn (Builder $q) => $q->where('status', 'cancelled'),
                cancellation: true,
            ),
            self::listing(
                'bookings-postponed',
                'الحجوزات المؤجلة',
                'ما أُجّل من حجوزات — فترته حُرّرت وتُباع من جديد.',
                fn (Builder $q) => $q->where('status', 'postponed'),
            ),
            self::upcoming(),
            self::byUnit(),
        ];
    }

    /**
     * تقرير دوري: صفٌّ لكل فترة (يوم/أسبوع/شهر/سنة).
     */
    private static function periodic(string $key, string $label, string $description, string $unit, string $range): ReportDefinition
    {
        return new ReportDefinition(
            key: $key,
            label: $label,
            description: $description,
            group: 'الحجوزات',
            filters: ['range', 'unit', 'status'],
            columns: [
                ['key' => 'period', 'label' => 'الفترة'],
                ['key' => 'count', 'label' => 'عدد الحجوزات', 'type' => 'number'],
                ['key' => 'confirmed', 'label' => 'مؤكدة', 'type' => 'number'],
                ['key' => 'cancelled', 'label' => 'ملغاة', 'type' => 'number'],
                ['key' => 'guests', 'label' => 'الضيوف', 'type' => 'number'],
                ['key' => 'total', 'label' => 'إجمالي المبالغ', 'type' => 'currency'],
                ['key' => 'paid', 'label' => 'المحصَّل', 'type' => 'currency'],
                ['key' => 'remaining', 'label' => 'المتبقي', 'type' => 'currency'],
            ],
            builder: function (array $filters) use ($unit) {
                $bookings = self::query($filters)
                    ->get(['booking_date', 'status', 'guests_count', 'total_amount', 'paid_amount']);

                $rows = $bookings
                    ->groupBy(fn (Booking $b) => self::periodKey($b->booking_date, $unit))
                    ->map(fn (Collection $group, string $period) => [
                        'period' => self::periodLabel($period, $unit),
                        'sort' => $period,
                        'count' => $group->count(),
                        'confirmed' => $group->whereIn('status', ['confirmed', 'checked_in', 'checked_out'])->count(),
                        'cancelled' => $group->where('status', 'cancelled')->count(),
                        'guests' => (int) $group->sum('guests_count'),
                        'total' => round((float) $group->sum('total_amount'), 2),
                        'paid' => round((float) $group->sum('paid_amount'), 2),
                        'remaining' => round((float) $group->sum('total_amount') - (float) $group->sum('paid_amount'), 2),
                    ])
                    ->sortByDesc('sort')
                    ->values()
                    ->map(fn (array $row) => collect($row)->except('sort')->all())
                    ->all();

                return [
                    'rows' => $rows,
                    'summary' => self::totals($bookings),
                ];
            },
            defaultRange: $range,
        );
    }

    /**
     * تقرير سردي: صفٌّ لكل حجز.
     */
    private static function listing(string $key, string $label, string $description, callable $scope, bool $cancellation = false): ReportDefinition
    {
        $columns = [
            ['key' => 'reference', 'label' => 'رقم الحجز'],
            ['key' => 'booking_date', 'label' => 'تاريخ الحجز', 'type' => 'date'],
            ['key' => 'unit', 'label' => 'الوحدة'],
            ['key' => 'client', 'label' => 'العميل'],
            ['key' => 'total', 'label' => 'الإجمالي', 'type' => 'currency'],
            ['key' => 'paid', 'label' => 'المحصَّل', 'type' => 'currency'],
            ['key' => 'remaining', 'label' => 'المتبقي', 'type' => 'currency'],
        ];

        if ($cancellation) {
            $columns[] = ['key' => 'cancelled_at', 'label' => 'تاريخ الإلغاء', 'type' => 'date'];
            $columns[] = ['key' => 'reason', 'label' => 'السبب'];
        }

        return new ReportDefinition(
            key: $key,
            label: $label,
            description: $description,
            group: 'الحجوزات',
            filters: ['range', 'unit'],
            columns: $columns,
            builder: function (array $filters) use ($scope, $cancellation) {
                $bookings = self::query($filters, withStatus: false)
                    ->tap($scope)
                    ->with(['unit:id,name', 'client:id,name'])
                    ->orderByDesc('booking_date')
                    ->get();

                return [
                    'rows' => $bookings->map(fn (Booking $b) => array_filter([
                        'reference' => $b->reference,
                        'booking_date' => $b->booking_date?->format('Y-m-d'),
                        'unit' => $b->unit?->name ?? '—',
                        'client' => $b->client?->name ?? '—',
                        'total' => round((float) $b->total_amount, 2),
                        'paid' => round((float) $b->paid_amount, 2),
                        'remaining' => round((float) $b->total_amount - (float) $b->paid_amount, 2),
                        'cancelled_at' => $cancellation ? $b->cancelled_at?->format('Y-m-d') : null,
                        'reason' => $cancellation ? ($b->cancellation_reason ?: '—') : null,
                    ], fn ($v) => $v !== null))->all(),
                    'summary' => self::totals($bookings),
                ];
            },
        );
    }

    /**
     * الحجوزات القادمة — من اليوم فصاعدًا، فلا يحكمها مرشّح المدة.
     */
    private static function upcoming(): ReportDefinition
    {
        return new ReportDefinition(
            key: 'bookings-upcoming',
            label: 'الحجوزات القادمة',
            description: 'ما هو آتٍ من اليوم فصاعدًا، مرتَّبًا بالأقرب موعدًا.',
            group: 'الحجوزات',
            filters: ['unit'],
            columns: [
                ['key' => 'days_left', 'label' => 'بعد (يوم)', 'type' => 'number'],
                ['key' => 'booking_date', 'label' => 'التاريخ', 'type' => 'date'],
                ['key' => 'reference', 'label' => 'رقم الحجز'],
                ['key' => 'unit', 'label' => 'الوحدة'],
                ['key' => 'client', 'label' => 'العميل'],
                ['key' => 'status', 'label' => 'الحالة', 'type' => 'badge'],
                ['key' => 'total', 'label' => 'الإجمالي', 'type' => 'currency'],
                ['key' => 'remaining', 'label' => 'المتبقي', 'type' => 'currency'],
            ],
            builder: function (array $filters) {
                $bookings = Booking::query()
                    ->whereDate('booking_date', '>=', now()->toDateString())
                    ->whereIn('status', Booking::BLOCKING_STATUSES)
                    ->when($filters['unit_id'] ?? null, fn ($q, $id) => $q->where('unit_id', $id))
                    ->with(['unit:id,name', 'client:id,name'])
                    ->orderBy('booking_date')
                    ->get();

                return [
                    'rows' => $bookings->map(fn (Booking $b) => [
                        'days_left' => (int) now()->startOfDay()->diffInDays($b->booking_date, absolute: true),
                        'booking_date' => $b->booking_date?->format('Y-m-d'),
                        'reference' => $b->reference,
                        'unit' => $b->unit?->name ?? '—',
                        'client' => $b->client?->name ?? '—',
                        'status' => Booking::STATUSES[$b->status] ?? $b->status,
                        'total' => round((float) $b->total_amount, 2),
                        'remaining' => round((float) $b->total_amount - (float) $b->paid_amount, 2),
                    ])->all(),
                    'summary' => self::totals($bookings),
                ];
            },
        );
    }

    /**
     * الحجوزات حسب الوحدة — أي قاعة أو شاليه يحمل الحركة.
     */
    private static function byUnit(): ReportDefinition
    {
        return new ReportDefinition(
            key: 'bookings-by-unit',
            label: 'الحجوزات حسب الوحدة',
            description: 'حصّة كل قاعة وشاليه من الحجوزات ومبالغها خلال المدة.',
            group: 'الحجوزات',
            filters: ['range', 'status'],
            columns: [
                ['key' => 'unit', 'label' => 'الوحدة'],
                ['key' => 'count', 'label' => 'عدد الحجوزات', 'type' => 'number'],
                ['key' => 'cancelled', 'label' => 'ملغاة', 'type' => 'number'],
                ['key' => 'guests', 'label' => 'الضيوف', 'type' => 'number'],
                ['key' => 'total', 'label' => 'الإجمالي', 'type' => 'currency'],
                ['key' => 'paid', 'label' => 'المحصَّل', 'type' => 'currency'],
                ['key' => 'remaining', 'label' => 'المتبقي', 'type' => 'currency'],
                ['key' => 'average', 'label' => 'متوسط الحجز', 'type' => 'currency'],
            ],
            builder: function (array $filters) {
                $bookings = self::query($filters)->with('unit:id,name')->get();

                $rows = $bookings
                    ->groupBy(fn (Booking $b) => $b->unit?->name ?? '—')
                    ->map(fn (Collection $group, string $unit) => [
                        'unit' => $unit,
                        'count' => $group->count(),
                        'cancelled' => $group->where('status', 'cancelled')->count(),
                        'guests' => (int) $group->sum('guests_count'),
                        'total' => round((float) $group->sum('total_amount'), 2),
                        'paid' => round((float) $group->sum('paid_amount'), 2),
                        'remaining' => round((float) $group->sum('total_amount') - (float) $group->sum('paid_amount'), 2),
                        'average' => round((float) $group->avg('total_amount'), 2),
                    ])
                    ->sortByDesc('total')
                    ->values()
                    ->all();

                return ['rows' => $rows, 'summary' => self::totals($bookings)];
            },
        );
    }

    /**
     * أساس كل تقارير الحجوزات: المدة والوحدة والحالة.
     *
     * @param  array<string, mixed>  $filters
     * @return Builder<Booking>
     */
    private static function query(array $filters, bool $withStatus = true): Builder
    {
        return Booking::query()
            ->when($filters['from'] ?? null, fn ($q, $d) => $q->whereDate('booking_date', '>=', $d))
            ->when($filters['to'] ?? null, fn ($q, $d) => $q->whereDate('booking_date', '<=', $d))
            ->when($filters['unit_id'] ?? null, fn ($q, $id) => $q->where('unit_id', $id))
            ->when($withStatus && ($filters['status'] ?? null), fn ($q, $s) => $q->where('status', $s));
    }

    /**
     * @param  Collection<int, Booking>  $bookings
     * @return list<array<string, mixed>>
     */
    private static function totals(Collection $bookings): array
    {
        $total = round((float) $bookings->sum('total_amount'), 2);
        $paid = round((float) $bookings->sum('paid_amount'), 2);

        return [
            ['label' => 'عدد الحجوزات', 'value' => $bookings->count(), 'type' => 'number'],
            ['label' => 'إجمالي المبالغ', 'value' => $total, 'type' => 'currency'],
            ['label' => 'المحصَّل', 'value' => $paid, 'type' => 'currency'],
            ['label' => 'المتبقي', 'value' => round($total - $paid, 2), 'type' => 'currency'],
        ];
    }

    private static function periodKey(?Carbon $date, string $unit): string
    {
        $date ??= now();

        return match ($unit) {
            'day' => $date->format('Y-m-d'),
            'week' => $date->copy()->startOfWeek(Carbon::SATURDAY)->format('Y-m-d'),
            'month' => $date->format('Y-m'),
            default => $date->format('Y'),
        };
    }

    private static function periodLabel(string $key, string $unit): string
    {
        return match ($unit) {
            // الأسبوع يُعرَّف بطرفيه لا برقمه: «الأسبوع 34» لا يقول متى.
            'week' => $key.' — '.Carbon::parse($key)->addDays(6)->format('Y-m-d'),
            default => $key,
        };
    }
}
