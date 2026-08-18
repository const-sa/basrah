<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\BookingPayment;
use App\Models\Client;
use App\Models\CostCenter;
use App\Models\Employee;
use App\Models\Item;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\Sale;
use App\Models\Unit;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * لوحة المؤشرات — تعرض ما يخصّ المستخدم فقط.
 *
 * كل رقم هنا محكوم بنطاق وحدات المستخدم وبصلاحياته، فلا يرى مشرف
 * الوحدة إيراد وحدة غيره ولا يرى الكاشير أرقام المحاسبة.
 */
class DashboardController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();

        $today = CarbonImmutable::now()->startOfDay();
        $monthStart = $today->startOfMonth();

        return Inertia::render('Dashboard', [
            'canSee' => [
                'bookings' => $user->hasPermission('bookings.view'),
                'pos' => $user->hasPermission('sales.view') || $user->hasPermission('pos.view'),
                'accounting' => $user->hasPermission('fin_reports.view'),
                'hr' => $user->hasPermission('staff.view'),
            ],
            'bookings' => $user->hasPermission('bookings.view') ? $this->bookingStats($user, $today, $monthStart) : null,
            'today' => $user->hasPermission('bookings.view') ? $this->todaySchedule($user, $today) : [],
            'pos' => ($user->hasPermission('sales.view') || $user->hasPermission('pos.view'))
                ? $this->posStats($user, $today, $monthStart) : null,
            'profitability' => $user->hasPermission('fin_reports.view')
                ? $this->profitability($monthStart, $today) : [],
            'finance' => $user->hasPermission('fin_reports.view')
                ? $this->monthFinance($monthStart, $today) : null,
            'clients' => $user->hasPermission('clients.view') ? [
                'total' => Client::where('is_walk_in', false)->count(),
                'new_this_month' => Client::where('is_walk_in', false)
                    ->whereDate('created_at', '>=', $monthStart->toDateString())
                    ->count(),
            ] : null,
            'hr' => $user->hasPermission('staff.view') ? $this->hrStats() : null,
            'alerts' => $this->alerts($user),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function bookingStats($user, CarbonImmutable $today, CarbonImmutable $monthStart): array
    {
        $base = fn () => Booking::query()->visibleTo($user);

        $monthly = $base()->blocking()
            ->whereBetween('booking_date', [$monthStart->toDateString(), $monthStart->endOfMonth()->toDateString()]);

        // الوحدات المشغولة اليوم تُحسب بالوحدات لا بالحجوزات: قاعةٌ فيها
        // حجزان صباحي ومسائي مشغولةٌ مرةً واحدة في نظر من يسأل «ما المتاح؟».
        $occupied = $base()->blocking()
            ->whereDate('booking_date', $today->toDateString())
            ->distinct()
            ->count('unit_id');

        $units = Unit::visibleTo($user)->where('is_active', true)->count();

        return [
            'today' => $base()->blocking()->whereDate('booking_date', $today->toDateString())->count(),
            'upcoming' => $base()->blocking()->whereDate('booking_date', '>', $today->toDateString())->count(),
            'tentative' => $base()->where('status', 'tentative')->count(),
            'month_count' => (clone $monthly)->count(),
            'month_value' => round((float) (clone $monthly)->sum('total_amount'), 2),
            'outstanding' => round(
                (float) $base()->blocking()->sum('total_amount') - (float) $base()->blocking()->sum('paid_amount'),
                2,
            ),
            // نسبة إشغال الشهر = ليالٍ محجوزة ÷ (عدد الوحدات × أيام الشهر)
            'occupancy' => $this->occupancy($user, $monthStart),

            // §13: المشغول والمتاح الآن، وإيراد اليوم، وملغى الشهر.
            'units_total' => $units,
            'units_occupied' => $occupied,
            'units_available' => max(0, $units - $occupied),
            'today_value' => round(
                (float) $base()->blocking()->whereDate('booking_date', $today->toDateString())->sum('total_amount'),
                2,
            ),
            'collected_today' => $this->collectedToday($user, $today),
            'cancelled_month' => $base()
                ->where('status', 'cancelled')
                ->whereBetween('booking_date', [$monthStart->toDateString(), $monthStart->endOfMonth()->toDateString()])
                ->count(),
        ];
    }

    /**
     * ما دخل الصندوق اليوم من دفعات الحجوزات — والاسترداد يُطرح لأنه خروج.
     */
    private function collectedToday($user, CarbonImmutable $today): float
    {
        $payments = BookingPayment::query()
            ->whereHas('booking', fn ($q) => $q->visibleTo($user))
            ->whereDate('paid_on', $today->toDateString())
            ->get(['type', 'amount']);

        return round(
            (float) $payments->whereIn('type', ['deposit', 'payment'])->sum('amount')
            - (float) $payments->where('type', 'refund')->sum('amount'),
            2,
        );
    }

    /**
     * إيراد الشهر ومصروفه وصافي ربحه (§13) — من الدفاتر لا من الشاشات.
     *
     * @return array<string, float>
     */
    private function monthFinance(CarbonImmutable $monthStart, CarbonImmutable $today): array
    {
        $totals = JournalLine::query()
            ->join('accounts', 'accounts.id', '=', 'journal_lines.account_id')
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->whereIn('journal_entries.status', JournalEntry::EFFECTIVE_STATUSES)
            ->whereIn('accounts.type', ['revenue', 'expense'])
            ->whereBetween('journal_entries.entry_date', [$monthStart->toDateString(), $today->toDateString()])
            ->groupBy('accounts.type')
            ->selectRaw('accounts.type, SUM(journal_lines.debit) AS debit, SUM(journal_lines.credit) AS credit')
            ->get();

        $revenue = (float) ($totals->firstWhere('type', 'revenue')?->credit ?? 0)
            - (float) ($totals->firstWhere('type', 'revenue')?->debit ?? 0);
        $expense = (float) ($totals->firstWhere('type', 'expense')?->debit ?? 0)
            - (float) ($totals->firstWhere('type', 'expense')?->credit ?? 0);

        return [
            'revenue' => round($revenue, 2),
            'expense' => round($expense, 2),
            'profit' => round($revenue - $expense, 2),
        ];
    }

    private function occupancy($user, CarbonImmutable $monthStart): float
    {
        $units = Unit::visibleTo($user)->where('is_active', true)->count();
        $days = $monthStart->daysInMonth;

        if ($units === 0 || $days === 0) {
            return 0.0;
        }

        // عدّ أزواج (وحدة، يوم) الفريدة بالتجميع — بديل محمول عن
        // COUNT(DISTINCT CONCAT(...)) التي لا تعمل على SQLite.
        $booked = Booking::visibleTo($user)->blocking()
            ->whereBetween('booking_date', [$monthStart->toDateString(), $monthStart->endOfMonth()->toDateString()])
            ->groupBy('unit_id', 'booking_date')
            ->select('unit_id', 'booking_date')
            ->get()
            ->count();

        return round($booked / ($units * $days) * 100, 1);
    }

    /**
     * حجوزات اليوم مرتّبة بوقت البداية — أول ما يحتاجه موظف الاستقبال.
     *
     * @return list<array<string, mixed>>
     */
    private function todaySchedule($user, CarbonImmutable $today): array
    {
        return Booking::visibleTo($user)->blocking()
            ->with(['unit:id,name', 'client:id,name,mobile', 'sections:id,name'])
            ->whereDate('booking_date', $today->toDateString())
            ->orderBy('starts_at')
            ->get()
            ->map(fn (Booking $b) => [
                'id' => $b->id,
                'reference' => $b->reference,
                'unit' => $b->unit?->name,
                'scope' => $b->scope === 'whole' ? 'الوحدة كاملة' : $b->sections->pluck('name')->implode('، '),
                'client' => $b->client?->name,
                'mobile' => $b->client?->mobile,
                'period' => $b->periodLabel(),
                'status' => $b->statusLabel(),
                'color' => Booking::STATUS_COLORS[$b->status] ?? 'slate',
                'remaining' => $b->remainingAmount(),
            ])->values()->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function posStats($user, CarbonImmutable $today, CarbonImmutable $monthStart): array
    {
        $todaySales = Sale::sales()->whereDate('created_at', $today->toDateString());
        $monthSales = Sale::sales()->whereDate('created_at', '>=', $monthStart->toDateString());

        return [
            'today_total' => round((float) (clone $todaySales)->sum('total_amount'), 2),
            'today_count' => (clone $todaySales)->count(),
            'month_total' => round((float) (clone $monthSales)->sum('total_amount'), 2),
            'month_profit' => round(
                (float) (clone $monthSales)->sum('total_amount') - (float) (clone $monthSales)->sum('cost_amount'),
                2,
            ),
            'low_stock' => Item::lowStock()->count(),
        ];
    }

    /**
     * ربحية الوحدات هذا الشهر — أعلى خمس.
     *
     * @return list<array<string, mixed>>
     */
    private function profitability(CarbonImmutable $from, CarbonImmutable $to): array
    {
        return CostCenter::with('unit:id,name')
            ->where('is_active', true)
            ->get()
            ->map(function (CostCenter $cc) use ($from, $to) {
                $p = $cc->profitability($from->toDateString(), $to->toDateString());

                return ['name' => $cc->name, 'revenue' => $p['revenue'], 'expense' => $p['expense'], 'profit' => $p['profit']];
            })
            ->filter(fn ($r) => $r['revenue'] > 0 || $r['expense'] > 0)
            ->sortByDesc('profit')
            ->take(5)
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function hrStats(): array
    {
        return [
            'employees' => Employee::where('is_active', true)->count(),
            'expiring_docs' => Employee::withExpiringDocuments((int) config('operations.hr.document_alert_days', 60))->count(),
            'monthly_cost' => round((float) Employee::where('is_active', true)
                ->selectRaw('SUM(basic_salary + housing_allowance + transport_allowance + other_allowance) AS t')
                ->value('t'), 2),
        ];
    }

    /**
     * تنبيهات تحتاج تصرّفًا — تُعرض أولًا لأنها ما يضيع بلا متابعة.
     *
     * @return list<array<string, string>>
     */
    private function alerts($user): array
    {
        $alerts = [];

        // التنبيه يُفصَل بحسب النوع لأن شاشتَي الحجز منفصلتان: تنبيهٌ واحد
        // يجمعهما يقود إلى شاشة لا تعرض نصف حجوزاته.
        if ($user->hasPermission('bookings.view')) {
            foreach ([
                ['hall', 'events', 'حجزًا في القاعات', 'halls'],
                ['chalet', 'stays', 'إقامةً في الشاليهات', 'chalets'],
            ] as [$type, $scope, $label, $screen]) {
                $unpaid = Booking::visibleTo($user)->blocking()->{$scope}()
                    ->whereHas('unit', fn ($q) => $q->where('type', $type))
                    ->whereColumn('paid_amount', '<', 'deposit_amount')
                    ->count();

                if ($unpaid > 0) {
                    $alerts[] = [
                        'type' => 'warning',
                        'text' => "{$unpaid} {$label} لم يُستوفَ عربونها",
                        'href' => "/admin/bookings/{$screen}",
                    ];
                }
            }
        }

        if ($user->hasPermission('items.view')) {
            $low = Item::lowStock()->count();

            if ($low > 0) {
                $alerts[] = [
                    'type' => 'danger',
                    'text' => "{$low} صنفًا بلغ حد إعادة الطلب",
                    'href' => '/admin/items?low_stock=1',
                ];
            }
        }

        if ($user->hasPermission('staff.view')) {
            $docs = Employee::withExpiringDocuments((int) config('operations.hr.document_alert_days', 60))->count();

            if ($docs > 0) {
                $alerts[] = [
                    'type' => 'warning',
                    'text' => "{$docs} موظفًا توشك وثائقه على الانتهاء",
                    'href' => '/admin/hr/staff?expiring=1',
                ];
            }
        }

        return $alerts;
    }
}
