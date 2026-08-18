<?php

namespace App\Support\Reports;

use App\Models\Booking;
use App\Models\BookingPayment;
use App\Models\CostCenter;
use App\Models\Expense;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\Sale;
use App\Models\User;
use App\Models\Voucher;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * التقارير المالية (§12 من العرض المعتمد).
 *
 * الإيراد والمصروف يُقرآن من الدفاتر لا من الشاشات: القيد المرحَّل هو
 * الحقيقة الوحيدة التي تتفق عليها الفاتورة والسند والحجز. أما التحصيل
 * والمتبقي فيُقرآن من مصدرهما التشغيلي (الدفعات والحجوزات) لأن السؤال
 * فيهما «من دفع وكم بقي عليه» لا «كيف تُصنَّف الحركة محاسبيًا».
 */
class FinanceReports
{
    /**
     * @return list<ReportDefinition>
     */
    public static function all(): array
    {
        return [
            self::byAccount('revenue', 'الإيرادات', 'الإيرادات المرحَّلة موزّعة على حساباتها.', 'revenue'),
            self::byAccount('expenses', 'المصروفات', 'المصروفات المرحَّلة موزّعة على حساباتها.', 'expense'),
            self::profitAndLoss(),
            self::collected(),
            self::outstanding(),
            self::expensesByCategory(),
            self::revenueByUnit(),
            self::revenueByEmployee(),
            self::revenueByPaymentMethod(),
            self::revenueByDepartment(),
            self::unitProfit(),
        ];
    }

    /**
     * الإيرادات حسب طريقة الدفع (§11).
     *
     * تُقرأ من المقبوض لا من قيمة الحجز: طريقة الدفع صفةُ المال الذي دخل،
     * والحجز غير المسدَّد لا طريقة دفع له بعد. والمصدران بابان — دفعات
     * الحجوزات وفواتير الكاشير — يجتمعان على الطريقة نفسها.
     */
    private static function revenueByPaymentMethod(): ReportDefinition
    {
        return new ReportDefinition(
            key: 'revenue-by-payment-method',
            label: 'الإيرادات حسب طريقة الدفع',
            description: 'ما حُصِّل بكل طريقة دفع: نقدًا، تحويلًا، شبكة، وغيرها.',
            group: 'المالية',
            filters: ['range'],
            columns: [
                ['key' => 'method', 'label' => 'طريقة الدفع'],
                ['key' => 'bookings', 'label' => 'دفعات حجوزات', 'type' => 'number'],
                ['key' => 'bookings_amount', 'label' => 'مبلغ الحجوزات', 'type' => 'currency'],
                ['key' => 'sales', 'label' => 'فواتير', 'type' => 'number'],
                ['key' => 'sales_amount', 'label' => 'مبلغ الفواتير', 'type' => 'currency'],
                ['key' => 'total', 'label' => 'الإجمالي', 'type' => 'currency'],
                ['key' => 'share', 'label' => 'النسبة %', 'type' => 'number'],
            ],
            builder: function (array $filters) {
                $payments = BookingPayment::query()
                    ->when($filters['from'] ?? null, fn ($q, $d) => $q->whereDate('paid_on', '>=', $d))
                    ->when($filters['to'] ?? null, fn ($q, $d) => $q->whereDate('paid_on', '<=', $d))
                    ->with('paymentMethod:id,name')
                    ->get(['payment_method_id', 'type', 'amount'])
                    ->groupBy(fn (BookingPayment $p) => $p->paymentMethod?->name ?? 'غير محددة');

                $sales = Sale::query()
                    ->sales()
                    ->when($filters['from'] ?? null, fn ($q, $d) => $q->whereDate('created_at', '>=', $d))
                    ->when($filters['to'] ?? null, fn ($q, $d) => $q->whereDate('created_at', '<=', $d))
                    ->with('paymentMethod:id,name')
                    ->get(['payment_method_id', 'paid_amount'])
                    ->groupBy(fn (Sale $s) => $s->paymentMethod?->name ?? 'غير محددة');

                $rows = $payments->keys()->merge($sales->keys())->unique()
                    ->map(function (string $method) use ($payments, $sales) {
                        $group = $payments->get($method, collect());

                        // الاسترداد يخرج بنفس الطريقة التي دخل بها، فيُطرح منها.
                        $bookingsAmount = round(
                            (float) $group->whereIn('type', ['deposit', 'payment'])->sum('amount')
                            - (float) $group->where('type', 'refund')->sum('amount'),
                            2,
                        );
                        $salesAmount = round((float) $sales->get($method, collect())->sum('paid_amount'), 2);

                        return [
                            'method' => $method,
                            'bookings' => $group->count(),
                            'bookings_amount' => $bookingsAmount,
                            'sales' => $sales->get($method, collect())->count(),
                            'sales_amount' => $salesAmount,
                            'total' => round($bookingsAmount + $salesAmount, 2),
                        ];
                    })
                    ->sortByDesc('total')
                    ->values();

                $total = round((float) $rows->sum('total'), 2);

                return [
                    'rows' => $rows->map(fn (array $r) => [
                        ...$r,
                        'share' => $total > 0 ? round($r['total'] / $total * 100, 1) : 0.0,
                    ])->all(),
                    'summary' => [
                        ['label' => 'إجمالي المحصَّل', 'value' => $total, 'type' => 'currency'],
                        ['label' => 'من الحجوزات', 'value' => round((float) $rows->sum('bookings_amount'), 2), 'type' => 'currency'],
                        ['label' => 'من الفواتير', 'value' => round((float) $rows->sum('sales_amount'), 2), 'type' => 'currency'],
                        ['label' => 'عدد الطرق المستعملة', 'value' => $rows->count(), 'type' => 'number'],
                    ],
                ];
            },
        );
    }

    /**
     * الإيرادات حسب القسم (§11).
     *
     * القسم نشاطٌ تجاري مستقل (المسابح، القاعات، المحل)، وفواتير الكاشير
     * تحمله. والحجوزات لا قسم لها فتُعرض في سطرٍ باسمها — إخفاؤها يجعل
     * مجموع «الإيرادات حسب القسم» أقلّ من إيراد المؤسسة بلا سبب ظاهر.
     */
    private static function revenueByDepartment(): ReportDefinition
    {
        return new ReportDefinition(
            key: 'revenue-by-department',
            label: 'الإيرادات حسب القسم',
            description: 'حصّة كل قسم من الإيراد: المسابح والمحل والقاعات والشاليهات.',
            group: 'المالية',
            filters: ['range'],
            columns: [
                ['key' => 'department', 'label' => 'القسم'],
                ['key' => 'count', 'label' => 'عدد المستندات', 'type' => 'number'],
                ['key' => 'amount', 'label' => 'الإيراد', 'type' => 'currency'],
                ['key' => 'collected', 'label' => 'المحصَّل منه', 'type' => 'currency'],
                ['key' => 'share', 'label' => 'النسبة %', 'type' => 'number'],
            ],
            builder: function (array $filters) {
                $sales = Sale::query()
                    ->sales()
                    ->when($filters['from'] ?? null, fn ($q, $d) => $q->whereDate('created_at', '>=', $d))
                    ->when($filters['to'] ?? null, fn ($q, $d) => $q->whereDate('created_at', '<=', $d))
                    ->with('department:id,name')
                    ->get(['department_id', 'total_amount', 'paid_amount'])
                    ->groupBy(fn (Sale $s) => $s->department?->name ?? 'مبيعات بلا قسم');

                $rows = $sales->map(fn (Collection $group, string $department) => [
                    'department' => $department,
                    'count' => $group->count(),
                    'amount' => round((float) $group->sum('total_amount'), 2),
                    'collected' => round((float) $group->sum('paid_amount'), 2),
                ]);

                $bookings = Booking::query()
                    ->when($filters['from'] ?? null, fn ($q, $d) => $q->whereDate('booking_date', '>=', $d))
                    ->when($filters['to'] ?? null, fn ($q, $d) => $q->whereDate('booking_date', '<=', $d))
                    ->where('status', '!=', 'cancelled')
                    ->get(['total_amount', 'paid_amount']);

                if ($bookings->isNotEmpty()) {
                    $rows = $rows->push([
                        'department' => 'الحجوزات (قاعات وشاليهات)',
                        'count' => $bookings->count(),
                        'amount' => round((float) $bookings->sum('total_amount'), 2),
                        'collected' => round((float) $bookings->sum('paid_amount'), 2),
                    ]);
                }

                $total = round((float) $rows->sum('amount'), 2);

                $rows = $rows
                    ->map(fn (array $r) => [...$r, 'share' => $total > 0 ? round($r['amount'] / $total * 100, 1) : 0.0])
                    ->sortByDesc('amount')
                    ->values();

                return [
                    'rows' => $rows->all(),
                    'summary' => [
                        ['label' => 'إجمالي الإيراد', 'value' => $total, 'type' => 'currency'],
                        ['label' => 'المحصَّل منه', 'value' => round((float) $rows->sum('collected'), 2), 'type' => 'currency'],
                        ['label' => 'عدد الأقسام', 'value' => $rows->count(), 'type' => 'number'],
                    ],
                ];
            },
        );
    }

    /**
     * الإيرادات أو المصروفات موزّعة على حساباتها.
     */
    private static function byAccount(string $key, string $label, string $description, string $type): ReportDefinition
    {
        return new ReportDefinition(
            key: $key,
            label: $label,
            description: $description,
            group: 'المالية',
            filters: ['range'],
            columns: [
                ['key' => 'code', 'label' => 'رمز الحساب'],
                ['key' => 'account', 'label' => 'الحساب'],
                ['key' => 'entries', 'label' => 'عدد الحركات', 'type' => 'number'],
                ['key' => 'amount', 'label' => 'المبلغ', 'type' => 'currency'],
                ['key' => 'share', 'label' => 'النسبة %', 'type' => 'number'],
            ],
            builder: function (array $filters) use ($type, $label) {
                $rows = self::lines($filters)
                    ->where('accounts.type', $type)
                    ->groupBy('accounts.id', 'accounts.code', 'accounts.name')
                    ->selectRaw('accounts.code, accounts.name,
                        COUNT(*) AS entries,
                        SUM(journal_lines.debit) AS debit,
                        SUM(journal_lines.credit) AS credit')
                    ->get()
                    ->map(fn ($r) => [
                        'code' => $r->code,
                        'account' => $r->name,
                        'entries' => (int) $r->entries,
                        'amount' => round(self::signed($type, (float) $r->debit, (float) $r->credit), 2),
                    ])
                    ->filter(fn (array $r) => abs($r['amount']) >= 0.01)
                    ->sortByDesc('amount')
                    ->values();

                $total = round((float) $rows->sum('amount'), 2);

                return [
                    'rows' => $rows->map(fn (array $r) => [
                        ...$r,
                        'share' => $total > 0 ? round($r['amount'] / $total * 100, 1) : 0.0,
                    ])->all(),
                    'summary' => [
                        ['label' => 'إجمالي '.$label, 'value' => $total, 'type' => 'currency'],
                        ['label' => 'عدد الحسابات', 'value' => $rows->count(), 'type' => 'number'],
                    ],
                ];
            },
            defaultRange: 'year',
        );
    }

    /**
     * الأرباح والخسائر شهرًا بشهر.
     */
    private static function profitAndLoss(): ReportDefinition
    {
        return new ReportDefinition(
            key: 'profit-loss',
            label: 'الأرباح والخسائر',
            description: 'الإيراد ناقص المصروف لكل شهر، وهامش الربح.',
            group: 'المالية',
            filters: ['range'],
            columns: [
                ['key' => 'month', 'label' => 'الشهر'],
                ['key' => 'revenue', 'label' => 'الإيرادات', 'type' => 'currency'],
                ['key' => 'expense', 'label' => 'المصروفات', 'type' => 'currency'],
                ['key' => 'profit', 'label' => 'الربح', 'type' => 'currency'],
                ['key' => 'margin', 'label' => 'الهامش %', 'type' => 'number'],
            ],
            builder: function (array $filters) {
                $lines = self::lines($filters)
                    ->whereIn('accounts.type', ['revenue', 'expense'])
                    ->selectRaw('accounts.type, journal_entries.entry_date,
                        journal_lines.debit, journal_lines.credit')
                    ->get();

                $rows = $lines
                    ->groupBy(fn ($l) => substr((string) $l->entry_date, 0, 7))
                    ->map(function (Collection $group, string $month) {
                        $revenue = self::sum($group->where('type', 'revenue'), 'revenue');
                        $expense = self::sum($group->where('type', 'expense'), 'expense');

                        return [
                            'month' => $month,
                            'revenue' => $revenue,
                            'expense' => $expense,
                            'profit' => round($revenue - $expense, 2),
                            'margin' => $revenue > 0 ? round(($revenue - $expense) / $revenue * 100, 1) : 0.0,
                        ];
                    })
                    ->sortByDesc('month')
                    ->values();

                $revenue = round((float) $rows->sum('revenue'), 2);
                $expense = round((float) $rows->sum('expense'), 2);

                return [
                    'rows' => $rows->all(),
                    'summary' => [
                        ['label' => 'الإيرادات', 'value' => $revenue, 'type' => 'currency'],
                        ['label' => 'المصروفات', 'value' => $expense, 'type' => 'currency'],
                        ['label' => 'صافي الربح', 'value' => round($revenue - $expense, 2), 'type' => 'currency'],
                        ['label' => 'الهامش %', 'value' => $revenue > 0 ? round(($revenue - $expense) / $revenue * 100, 1) : 0.0, 'type' => 'number'],
                    ],
                ];
            },
            defaultRange: 'year',
        );
    }

    /**
     * المبالغ المحصلة — من دفعات الحجوزات وفواتير الكاشير.
     */
    private static function collected(): ReportDefinition
    {
        return new ReportDefinition(
            key: 'collected',
            label: 'المبالغ المحصلة',
            description: 'ما دخل الصندوق فعلًا: دفعات الحجوزات وتحصيل الفواتير، يومًا بيوم.',
            group: 'المالية',
            filters: ['range'],
            columns: [
                ['key' => 'date', 'label' => 'التاريخ', 'type' => 'date'],
                ['key' => 'bookings', 'label' => 'دفعات الحجوزات', 'type' => 'currency'],
                ['key' => 'refunds', 'label' => 'المرتجع للعملاء', 'type' => 'currency'],
                ['key' => 'sales', 'label' => 'تحصيل الفواتير', 'type' => 'currency'],
                ['key' => 'total', 'label' => 'الإجمالي', 'type' => 'currency'],
            ],
            builder: function (array $filters) {
                $payments = BookingPayment::query()
                    ->when($filters['from'] ?? null, fn ($q, $d) => $q->whereDate('paid_on', '>=', $d))
                    ->when($filters['to'] ?? null, fn ($q, $d) => $q->whereDate('paid_on', '<=', $d))
                    ->get(['type', 'amount', 'paid_on'])
                    ->groupBy(fn (BookingPayment $p) => $p->paid_on?->format('Y-m-d') ?? '—');

                $sales = Sale::query()
                    ->sales()
                    ->when($filters['from'] ?? null, fn ($q, $d) => $q->whereDate('created_at', '>=', $d))
                    ->when($filters['to'] ?? null, fn ($q, $d) => $q->whereDate('created_at', '<=', $d))
                    ->get(['paid_amount', 'created_at'])
                    ->groupBy(fn (Sale $s) => $s->created_at?->format('Y-m-d') ?? '—');

                $dates = $payments->keys()->merge($sales->keys())->unique()->sortDesc();

                $rows = $dates->map(function (string $date) use ($payments, $sales) {
                    $day = $payments->get($date, collect());

                    // الاسترداد يخرج من الصندوق لا يدخله، فيُطرح ولا يُجمع.
                    $in = round((float) $day->whereIn('type', ['deposit', 'payment'])->sum('amount'), 2);
                    $out = round((float) $day->where('type', 'refund')->sum('amount'), 2);
                    $invoices = round((float) $sales->get($date, collect())->sum('paid_amount'), 2);

                    return [
                        'date' => $date,
                        'bookings' => $in,
                        'refunds' => $out,
                        'sales' => $invoices,
                        'total' => round($in - $out + $invoices, 2),
                    ];
                })->values();

                return [
                    'rows' => $rows->all(),
                    'summary' => [
                        ['label' => 'دفعات الحجوزات', 'value' => round((float) $rows->sum('bookings'), 2), 'type' => 'currency'],
                        ['label' => 'المرتجع للعملاء', 'value' => round((float) $rows->sum('refunds'), 2), 'type' => 'currency'],
                        ['label' => 'تحصيل الفواتير', 'value' => round((float) $rows->sum('sales'), 2), 'type' => 'currency'],
                        ['label' => 'صافي المحصَّل', 'value' => round((float) $rows->sum('total'), 2), 'type' => 'currency'],
                    ],
                ];
            },
        );
    }

    /**
     * المبالغ المتبقية — ذمم العملاء على الحجوزات والفواتير.
     */
    private static function outstanding(): ReportDefinition
    {
        return new ReportDefinition(
            key: 'outstanding',
            label: 'المبالغ المتبقية',
            description: 'ما لم يُحصَّل بعد من الحجوزات القائمة، مرتَّبًا بالأكبر.',
            group: 'المالية',
            filters: ['range', 'unit'],
            columns: [
                ['key' => 'reference', 'label' => 'رقم الحجز'],
                ['key' => 'booking_date', 'label' => 'التاريخ', 'type' => 'date'],
                ['key' => 'unit', 'label' => 'الوحدة'],
                ['key' => 'client', 'label' => 'العميل'],
                ['key' => 'status', 'label' => 'الحالة', 'type' => 'badge'],
                ['key' => 'total', 'label' => 'الإجمالي', 'type' => 'currency'],
                ['key' => 'paid', 'label' => 'المدفوع', 'type' => 'currency'],
                ['key' => 'remaining', 'label' => 'المتبقي', 'type' => 'currency'],
            ],
            builder: function (array $filters) {
                $bookings = Booking::query()
                    ->when($filters['from'] ?? null, fn ($q, $d) => $q->whereDate('booking_date', '>=', $d))
                    ->when($filters['to'] ?? null, fn ($q, $d) => $q->whereDate('booking_date', '<=', $d))
                    ->when($filters['unit_id'] ?? null, fn ($q, $id) => $q->where('unit_id', $id))
                    // الملغي لا يُطالَب به، فلا موضع له في كشف الذمم.
                    ->where('status', '!=', 'cancelled')
                    ->whereColumn('paid_amount', '<', 'total_amount')
                    ->with(['unit:id,name', 'client:id,name'])
                    ->get();

                $rows = $bookings
                    ->map(fn (Booking $b) => [
                        'reference' => $b->reference,
                        'booking_date' => $b->booking_date?->format('Y-m-d'),
                        'unit' => $b->unit?->name ?? '—',
                        'client' => $b->client?->name ?? '—',
                        'status' => Booking::STATUSES[$b->status] ?? $b->status,
                        'total' => round((float) $b->total_amount, 2),
                        'paid' => round((float) $b->paid_amount, 2),
                        'remaining' => round((float) $b->total_amount - (float) $b->paid_amount, 2),
                    ])
                    ->sortByDesc('remaining')
                    ->values();

                return [
                    'rows' => $rows->all(),
                    'summary' => [
                        ['label' => 'عدد الحجوزات', 'value' => $rows->count(), 'type' => 'number'],
                        ['label' => 'إجمالي المستحق', 'value' => round((float) $rows->sum('total'), 2), 'type' => 'currency'],
                        ['label' => 'المحصَّل منه', 'value' => round((float) $rows->sum('paid'), 2), 'type' => 'currency'],
                        ['label' => 'المتبقي', 'value' => round((float) $rows->sum('remaining'), 2), 'type' => 'currency'],
                    ],
                ];
            },
        );
    }

    /**
     * المصروفات حسب التصنيف — من سندات الصرف والمصروف المرحَّلة.
     */
    private static function expensesByCategory(): ReportDefinition
    {
        return new ReportDefinition(
            key: 'expenses-by-category',
            label: 'المصروفات حسب التصنيف',
            description: 'المصروفات المرحَّلة مجمَّعة بنوعها، وسندات الصرف بحسابها.',
            group: 'المالية',
            filters: ['range'],
            columns: [
                ['key' => 'category', 'label' => 'التصنيف'],
                ['key' => 'count', 'label' => 'عدد المستندات', 'type' => 'number'],
                ['key' => 'amount', 'label' => 'المبلغ', 'type' => 'currency'],
                ['key' => 'share', 'label' => 'النسبة %', 'type' => 'number'],
            ],
            builder: function (array $filters) {
                // المصروف بابان: مستند مصروف (§9) بنوعه، وسند صرف للموردين
                // بحسابه. والتقرير يجمعهما لأن السؤال «فيمَ صُرف» لا «من أي
                // شاشة سُجّل».
                $expenses = Expense::query()
                    ->posted()
                    ->between($filters['from'] ?? null, $filters['to'] ?? null)
                    ->with('category:id,name')
                    ->get(['expense_category_id', 'amount'])
                    ->groupBy(fn (Expense $e) => $e->category?->name ?? 'بلا نوع');

                $vouchers = Voucher::query()
                    ->where('type', 'payment')
                    ->where('status', 'posted')
                    ->when($filters['from'] ?? null, fn ($q, $d) => $q->whereDate('voucher_date', '>=', $d))
                    ->when($filters['to'] ?? null, fn ($q, $d) => $q->whereDate('voucher_date', '<=', $d))
                    ->with('account:id,name')
                    ->get(['account_id', 'amount'])
                    ->groupBy(fn (Voucher $v) => $v->account?->name ?? 'بلا تصنيف');

                $total = round((float) ($expenses->flatten()->sum('amount') + $vouchers->flatten()->sum('amount')), 2);
                $documents = $expenses->flatten()->count() + $vouchers->flatten()->count();

                $rows = $expenses->keys()->merge($vouchers->keys())->unique()
                    ->map(function (string $category) use ($expenses, $vouchers, $total) {
                        $amount = round(
                            (float) $expenses->get($category, collect())->sum('amount')
                            + (float) $vouchers->get($category, collect())->sum('amount'),
                            2,
                        );

                        return [
                            'category' => $category,
                            'count' => $expenses->get($category, collect())->count() + $vouchers->get($category, collect())->count(),
                            'amount' => $amount,
                            'share' => $total > 0 ? round($amount / $total * 100, 1) : 0.0,
                        ];
                    })
                    ->sortByDesc('amount')
                    ->values();

                return [
                    'rows' => $rows->all(),
                    'summary' => [
                        ['label' => 'إجمالي المصروف', 'value' => $total, 'type' => 'currency'],
                        ['label' => 'عدد المستندات', 'value' => $documents, 'type' => 'number'],
                        ['label' => 'عدد التصنيفات', 'value' => $rows->count(), 'type' => 'number'],
                    ],
                ];
            },
            defaultRange: 'year',
        );
    }

    /**
     * الإيرادات حسب الوحدة — من الحجوزات وفواتير الوحدة.
     */
    private static function revenueByUnit(): ReportDefinition
    {
        return new ReportDefinition(
            key: 'revenue-by-unit',
            label: 'الإيرادات حسب الوحدة',
            description: 'ما حقّقته كل وحدة من حجوزات وفواتير خلال المدة.',
            group: 'المالية',
            filters: ['range'],
            columns: [
                ['key' => 'unit', 'label' => 'الوحدة'],
                ['key' => 'bookings', 'label' => 'عدد الحجوزات', 'type' => 'number'],
                ['key' => 'bookings_amount', 'label' => 'إيراد الحجوزات', 'type' => 'currency'],
                ['key' => 'sales_amount', 'label' => 'إيراد الفواتير', 'type' => 'currency'],
                ['key' => 'total', 'label' => 'الإجمالي', 'type' => 'currency'],
            ],
            builder: function (array $filters) {
                $bookings = Booking::query()
                    ->when($filters['from'] ?? null, fn ($q, $d) => $q->whereDate('booking_date', '>=', $d))
                    ->when($filters['to'] ?? null, fn ($q, $d) => $q->whereDate('booking_date', '<=', $d))
                    ->where('status', '!=', 'cancelled')
                    ->with('unit:id,name')
                    ->get()
                    ->groupBy(fn (Booking $b) => $b->unit?->name ?? '—');

                $sales = Sale::query()
                    ->sales()
                    ->when($filters['from'] ?? null, fn ($q, $d) => $q->whereDate('created_at', '>=', $d))
                    ->when($filters['to'] ?? null, fn ($q, $d) => $q->whereDate('created_at', '<=', $d))
                    ->with('unit:id,name')
                    ->get()
                    ->groupBy(fn (Sale $s) => $s->unit?->name ?? '—');

                $rows = $bookings->keys()->merge($sales->keys())->unique()
                    ->map(function (string $unit) use ($bookings, $sales) {
                        $b = $bookings->get($unit, collect());
                        $s = $sales->get($unit, collect());

                        $bookingsAmount = round((float) $b->sum('total_amount'), 2);
                        $salesAmount = round((float) $s->sum('total_amount'), 2);

                        return [
                            'unit' => $unit,
                            'bookings' => $b->count(),
                            'bookings_amount' => $bookingsAmount,
                            'sales_amount' => $salesAmount,
                            'total' => round($bookingsAmount + $salesAmount, 2),
                        ];
                    })
                    ->sortByDesc('total')
                    ->values();

                return [
                    'rows' => $rows->all(),
                    'summary' => [
                        ['label' => 'إيراد الحجوزات', 'value' => round((float) $rows->sum('bookings_amount'), 2), 'type' => 'currency'],
                        ['label' => 'إيراد الفواتير', 'value' => round((float) $rows->sum('sales_amount'), 2), 'type' => 'currency'],
                        ['label' => 'الإجمالي', 'value' => round((float) $rows->sum('total'), 2), 'type' => 'currency'],
                    ],
                ];
            },
        );
    }

    /**
     * الإيرادات حسب الموظف — من فتح الحجز ومن أصدر الفاتورة.
     */
    private static function revenueByEmployee(): ReportDefinition
    {
        return new ReportDefinition(
            key: 'revenue-by-employee',
            label: 'الإيرادات حسب الموظف',
            description: 'ما أدخله كل موظف من حجوزات وفواتير خلال المدة.',
            group: 'المالية',
            filters: ['range'],
            columns: [
                ['key' => 'employee', 'label' => 'الموظف'],
                ['key' => 'bookings', 'label' => 'حجوزات', 'type' => 'number'],
                ['key' => 'bookings_amount', 'label' => 'إيراد الحجوزات', 'type' => 'currency'],
                ['key' => 'sales', 'label' => 'فواتير', 'type' => 'number'],
                ['key' => 'sales_amount', 'label' => 'إيراد الفواتير', 'type' => 'currency'],
                ['key' => 'total', 'label' => 'الإجمالي', 'type' => 'currency'],
            ],
            builder: fn (array $filters) => self::perUser($filters),
        );
    }

    /**
     * ربحية كل وحدة — من مراكز التكلفة، وهي غايتها.
     */
    private static function unitProfit(): ReportDefinition
    {
        return new ReportDefinition(
            key: 'unit-profit',
            label: 'تقرير الأرباح لكل وحدة',
            description: 'إيراد كل وحدة ومصروفها وصافي ربحها من مركز تكلفتها.',
            group: 'المالية',
            filters: ['range'],
            columns: [
                ['key' => 'unit', 'label' => 'مركز التكلفة'],
                ['key' => 'revenue', 'label' => 'الإيراد', 'type' => 'currency'],
                ['key' => 'expense', 'label' => 'المصروف', 'type' => 'currency'],
                ['key' => 'profit', 'label' => 'صافي الربح', 'type' => 'currency'],
                ['key' => 'margin', 'label' => 'الهامش %', 'type' => 'number'],
            ],
            builder: function (array $filters) {
                $rows = CostCenter::with('unit:id,name')
                    ->where('is_active', true)
                    ->get()
                    ->map(function (CostCenter $center) use ($filters) {
                        $p = $center->profitability($filters['from'] ?? null, $filters['to'] ?? null);

                        return [
                            'unit' => $center->unit?->name ?? $center->name,
                            'revenue' => $p['revenue'],
                            'expense' => $p['expense'],
                            'profit' => $p['profit'],
                            'margin' => $p['revenue'] > 0 ? round($p['profit'] / $p['revenue'] * 100, 1) : 0.0,
                        ];
                    })
                    ->sortByDesc('profit')
                    ->values();

                return [
                    'rows' => $rows->all(),
                    'summary' => [
                        ['label' => 'الإيراد', 'value' => round((float) $rows->sum('revenue'), 2), 'type' => 'currency'],
                        ['label' => 'المصروف', 'value' => round((float) $rows->sum('expense'), 2), 'type' => 'currency'],
                        ['label' => 'صافي الربح', 'value' => round((float) $rows->sum('profit'), 2), 'type' => 'currency'],
                    ],
                ];
            },
            defaultRange: 'year',
        );
    }

    /**
     * حجوزات كل مستخدم وفواتيره — يخدم تقرير الإيراد بالموظف وتقرير الأداء.
     *
     * @param  array<string, mixed>  $filters
     * @return array{rows: list<array<string, mixed>>, summary: list<array<string, mixed>>}
     */
    public static function perUser(array $filters): array
    {
        $users = User::query()->get(['id', 'name'])->keyBy('id');

        $bookings = Booking::query()
            ->when($filters['from'] ?? null, fn ($q, $d) => $q->whereDate('booking_date', '>=', $d))
            ->when($filters['to'] ?? null, fn ($q, $d) => $q->whereDate('booking_date', '<=', $d))
            ->where('status', '!=', 'cancelled')
            ->get(['created_by', 'total_amount'])
            ->groupBy('created_by');

        $sales = Sale::query()
            ->sales()
            ->when($filters['from'] ?? null, fn ($q, $d) => $q->whereDate('created_at', '>=', $d))
            ->when($filters['to'] ?? null, fn ($q, $d) => $q->whereDate('created_at', '<=', $d))
            ->get(['user_id', 'total_amount'])
            ->groupBy('user_id');

        $rows = collect($bookings->keys())->merge($sales->keys())->unique()
            ->map(function ($userId) use ($users, $bookings, $sales) {
                $b = $bookings->get($userId, collect());
                $s = $sales->get($userId, collect());

                $bookingsAmount = round((float) $b->sum('total_amount'), 2);
                $salesAmount = round((float) $s->sum('total_amount'), 2);

                return [
                    'employee' => $users->get($userId)?->name ?? 'غير محدد',
                    'bookings' => $b->count(),
                    'bookings_amount' => $bookingsAmount,
                    'sales' => $s->count(),
                    'sales_amount' => $salesAmount,
                    'total' => round($bookingsAmount + $salesAmount, 2),
                ];
            })
            ->sortByDesc('total')
            ->values();

        return [
            'rows' => $rows->all(),
            'summary' => [
                ['label' => 'عدد الموظفين', 'value' => $rows->count(), 'type' => 'number'],
                ['label' => 'إيراد الحجوزات', 'value' => round((float) $rows->sum('bookings_amount'), 2), 'type' => 'currency'],
                ['label' => 'إيراد الفواتير', 'value' => round((float) $rows->sum('sales_amount'), 2), 'type' => 'currency'],
                ['label' => 'الإجمالي', 'value' => round((float) $rows->sum('total'), 2), 'type' => 'currency'],
            ],
        ];
    }

    /**
     * بنود القيود المرحَّلة داخل المدة، موصولةً بحساباتها.
     *
     * @param  array<string, mixed>  $filters
     * @return Builder<JournalLine>
     */
    private static function lines(array $filters): Builder
    {
        return JournalLine::query()
            ->join('accounts', 'accounts.id', '=', 'journal_lines.account_id')
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->whereIn('journal_entries.status', JournalEntry::EFFECTIVE_STATUSES)
            ->when($filters['from'] ?? null, fn ($q, $d) => $q->whereDate('journal_entries.entry_date', '>=', $d))
            ->when($filters['to'] ?? null, fn ($q, $d) => $q->whereDate('journal_entries.entry_date', '<=', $d));
    }

    /**
     * الإيراد يزيد بالدائن والمصروف يزيد بالمدين.
     */
    private static function signed(string $type, float $debit, float $credit): float
    {
        return $type === 'expense' ? $debit - $credit : $credit - $debit;
    }

    /**
     * @param  Collection<int, object>  $lines
     */
    private static function sum(Collection $lines, string $type): float
    {
        return round(self::signed($type, (float) $lines->sum('debit'), (float) $lines->sum('credit')), 2);
    }
}
