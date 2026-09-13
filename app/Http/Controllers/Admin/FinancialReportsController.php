<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\Booking;
use App\Models\CostCenter;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\Purchase;
use App\Models\Sale;
use App\Support\Vat;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * التقارير المالية: ميزان المراجعة، قائمة الدخل، الميزانية، ربحية الوحدات،
 * والإقرار الضريبي.
 */
class FinancialReportsController extends Controller
{
    public function index(Request $request): Response
    {
        $from = $request->string('from')->toString() ?: now()->startOfYear()->toDateString();
        $to = $request->string('to')->toString() ?: now()->toDateString();

        return Inertia::render('admin/accounting/Reports', [
            'filters' => ['from' => $from, 'to' => $to],
            'trialBalance' => $this->trialBalance($from, $to),
            'incomeStatement' => $this->incomeStatement($from, $to),
            'balanceSheet' => $this->balanceSheet($to),
            'unitProfitability' => $this->unitProfitability($from, $to),
            'vatReturn' => $this->vatReturn($from, $to),
        ]);
    }

    /**
     * تصدير تفصيل الإقرار الضريبي الشهري لنفس الفترة المعروضة.
     */
    public function exportVatReturn(Request $request): StreamedResponse
    {
        $from = $request->string('from')->toString() ?: now()->startOfYear()->toDateString();
        $to = $request->string('to')->toString() ?: now()->toDateString();

        $vat = $this->vatReturn($from, $to);
        $filename = 'vat-return-'.$from.'-to-'.$to.'.csv';

        return response()->streamDownload(function () use ($vat) {
            $out = fopen('php://output', 'w');

            // BOM حتى يفتح إكسل العربية بترميزها الصحيح بدل رموز مبهمة.
            fwrite($out, "\xEF\xBB\xBF");

            fputcsv($out, ['الشهر', 'مبيعات خاضعة', 'ضريبة مخرجات', 'مشتريات خاضعة', 'ضريبة مدخلات', 'صافي المستحق']);

            foreach ($vat['months'] as $row) {
                fputcsv($out, [$row['month'], $row['output_net'], $row['output_tax'], $row['input_net'], $row['input_tax'], $row['net_due']]);
            }

            fputcsv($out, ['الإجمالي', $vat['output']['taxable_amount'], $vat['output']['tax'], $vat['input']['taxable_amount'], $vat['input']['tax'], $vat['net_due']]);

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * ميزان المراجعة: مجاميع المدين والدائن لكل حساب مُرحَّل عليه.
     *
     * @return array{rows: list<array<string, mixed>>, total_debit: float, total_credit: float, balanced: bool}
     */
    private function trialBalance(string $from, string $to): array
    {
        $rows = JournalLine::query()
            ->join('accounts', 'accounts.id', '=', 'journal_lines.account_id')
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->whereIn('journal_entries.status', JournalEntry::EFFECTIVE_STATUSES)
            ->whereBetween('journal_entries.entry_date', [$from, $to])
            ->groupBy('accounts.id', 'accounts.code', 'accounts.name', 'accounts.type')
            ->orderBy('accounts.code')
            ->selectRaw('accounts.code, accounts.name, accounts.type,
                SUM(journal_lines.debit) AS debit, SUM(journal_lines.credit) AS credit')
            ->get()
            ->map(fn ($r) => [
                'code' => $r->code,
                'name' => $r->name,
                'type' => $r->type,
                'type_label' => Account::TYPES[$r->type] ?? $r->type,
                'debit' => round((float) $r->debit, 2),
                'credit' => round((float) $r->credit, 2),
                'balance' => round((float) $r->debit - (float) $r->credit, 2),
            ]);

        $totalDebit = round((float) $rows->sum('debit'), 2);
        $totalCredit = round((float) $rows->sum('credit'), 2);

        return [
            'rows' => $rows->values()->all(),
            'total_debit' => $totalDebit,
            'total_credit' => $totalCredit,
            // الميزان يجب أن يتوازن دائمًا — عدم توازنه يعني خللًا في الدفاتر.
            'balanced' => abs($totalDebit - $totalCredit) < 0.01,
        ];
    }

    /**
     * قائمة الدخل: الإيرادات − المصروفات.
     *
     * @return array<string, mixed>
     */
    private function incomeStatement(string $from, string $to): array
    {
        $revenue = $this->typeTotals('revenue', $from, $to);
        $expense = $this->typeTotals('expense', $from, $to);

        $totalRevenue = round((float) collect($revenue)->sum('amount'), 2);
        $totalExpense = round((float) collect($expense)->sum('amount'), 2);

        return [
            'revenue' => $revenue,
            'expense' => $expense,
            'total_revenue' => $totalRevenue,
            'total_expense' => $totalExpense,
            'net_income' => round($totalRevenue - $totalExpense, 2),
        ];
    }

    /**
     * الميزانية العمومية حتى تاريخ.
     *
     * @return array<string, mixed>
     */
    private function balanceSheet(string $to): array
    {
        $assets = $this->typeTotals('asset', null, $to);
        $liabilities = $this->typeTotals('liability', null, $to);
        $equity = $this->typeTotals('equity', null, $to);

        $totalAssets = round((float) collect($assets)->sum('amount'), 2);
        $totalLiabilities = round((float) collect($liabilities)->sum('amount'), 2);
        $totalEquity = round((float) collect($equity)->sum('amount'), 2);

        // الأرباح المحتجزة للفترة تُقفل ضمن حقوق الملكية لتوازن المعادلة.
        $retained = round(
            (float) collect($this->typeTotals('revenue', null, $to))->sum('amount')
            - (float) collect($this->typeTotals('expense', null, $to))->sum('amount'),
            2,
        );

        return [
            'assets' => $assets,
            'liabilities' => $liabilities,
            'equity' => $equity,
            'total_assets' => $totalAssets,
            'total_liabilities' => $totalLiabilities,
            'total_equity' => round($totalEquity + $retained, 2),
            'retained_earnings' => $retained,
            'balanced' => abs($totalAssets - ($totalLiabilities + $totalEquity + $retained)) < 0.01,
        ];
    }

    /**
     * مجاميع حسابات نوع معيّن بإشارة طبيعتها.
     *
     * @return list<array<string, mixed>>
     */
    private function typeTotals(string $type, ?string $from, string $to): array
    {
        $isDebitNature = in_array($type, Account::DEBIT_NATURE, true);

        return JournalLine::query()
            ->join('accounts', 'accounts.id', '=', 'journal_lines.account_id')
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->whereIn('journal_entries.status', JournalEntry::EFFECTIVE_STATUSES)
            ->where('accounts.type', $type)
            ->when($from, fn ($q) => $q->whereDate('journal_entries.entry_date', '>=', $from))
            ->whereDate('journal_entries.entry_date', '<=', $to)
            ->groupBy('accounts.id', 'accounts.code', 'accounts.name')
            ->orderBy('accounts.code')
            ->selectRaw('accounts.code, accounts.name,
                SUM(journal_lines.debit) AS debit, SUM(journal_lines.credit) AS credit')
            ->get()
            ->map(fn ($r) => [
                'code' => $r->code,
                'name' => $r->name,
                'amount' => round(
                    $isDebitNature
                        ? (float) $r->debit - (float) $r->credit
                        : (float) $r->credit - (float) $r->debit,
                    2,
                ),
            ])
            ->filter(fn ($r) => abs($r['amount']) >= 0.01)
            ->values()
            ->all();
    }

    /**
     * ربحية كل وحدة — الغاية من مراكز التكلفة (§الطبقة أ - بند 4).
     *
     * @return list<array<string, mixed>>
     */
    private function unitProfitability(string $from, string $to): array
    {
        return CostCenter::with('unit:id,name,type')
            ->where('is_active', true)
            ->get()
            ->map(function (CostCenter $cc) use ($from, $to) {
                $p = $cc->profitability($from, $to);

                return [
                    'id' => $cc->id,
                    'code' => $cc->code,
                    'name' => $cc->name,
                    'unit_type' => $cc->unit?->type,
                    'revenue' => $p['revenue'],
                    'expense' => $p['expense'],
                    'profit' => $p['profit'],
                    'margin' => $p['revenue'] > 0 ? round($p['profit'] / $p['revenue'] * 100, 1) : 0.0,
                ];
            })
            ->sortByDesc('profit')
            ->values()
            ->all();
    }

    /**
     * تقرير الإقرار الضريبي: مبيعات وحجوزات خاضعة (ضريبة مخرجات) مقابل
     * مشتريات خاضعة (ضريبة مدخلات)، وصافي الضريبة المستحقة.
     *
     * يُبنى من المستندات المصدر لا من الدفاتر: لا حساب "ضريبة مستحقة" في
     * شجرة الحسابات اليوم، والمشتريات لا تُرحَّل للدفاتر أصلًا (انظر
     * PurchaseController) — فقراءة القيود هنا تُسقط المشتريات كلها.
     *
     * @return array{
     *     rate: float,
     *     output: array{taxable_amount: float, tax: float},
     *     input: array{taxable_amount: float, tax: float},
     *     net_due: float,
     *     months: list<array<string, mixed>>,
     * }
     */
    private function vatReturn(string $from, string $to): array
    {
        $months = $this->vatMonthly($from, $to);

        $outputNet = round((float) collect($months)->sum('output_net'), 2);
        $outputTax = round((float) collect($months)->sum('output_tax'), 2);
        $inputNet = round((float) collect($months)->sum('input_net'), 2);
        $inputTax = round((float) collect($months)->sum('input_tax'), 2);

        return [
            'rate' => Vat::rate(),
            'output' => ['taxable_amount' => $outputNet, 'tax' => $outputTax],
            'input' => ['taxable_amount' => $inputNet, 'tax' => $inputTax],
            'net_due' => round($outputTax - $inputTax, 2),
            'months' => $months,
        ];
    }

    /**
     * تفصيل الضريبة شهرًا بشهر — أساس بطاقتَي الإجمالي في vatReturn().
     *
     * التجميع بالشهر يجري في PHP لا بدالة قاعدة بيانات (DATE_FORMAT وما
     * شابهها ليست محمولة بين محركات قواعد البيانات)، على نمط باقي هذا
     * الملف الذي يُحمّل صفوفًا محدودة ثم يحسب عليها.
     *
     * @return list<array<string, mixed>>
     */
    private function vatMonthly(string $from, string $to): array
    {
        $range = ["{$from} 00:00:00", "{$to} 23:59:59"];

        $sales = Sale::query()
            ->where('is_taxable', true)
            ->whereBetween('created_at', $range)
            ->get(['type', 'created_at', 'subtotal', 'tax_amount'])
            ->groupBy(fn (Sale $s) => $s->created_at->format('Y-m'))
            ->map(fn (Collection $rows) => [
                'net' => round((float) $rows->sum(fn (Sale $s) => $s->isReturn() ? -$s->subtotal : $s->subtotal), 2),
                'tax' => round((float) $rows->sum(fn (Sale $s) => $s->isReturn() ? -$s->tax_amount : $s->tax_amount), 2),
            ]);

        $bookings = Booking::query()
            ->where('is_taxable', true)
            ->where('status', '!=', 'cancelled')
            ->whereBetween('created_at', $range)
            ->get(['created_at', 'base_amount', 'package_amount', 'event_fee_amount', 'addons_amount', 'discount_amount', 'total_amount'])
            ->groupBy(fn (Booking $b) => $b->created_at->format('Y-m'))
            ->map(fn (Collection $rows) => [
                'net' => round((float) $rows->sum(fn (Booking $b) => $b->netAmount()), 2),
                'tax' => round((float) $rows->sum(fn (Booking $b) => $b->taxAmount()), 2),
            ]);

        $purchases = Purchase::query()
            ->where('is_taxable', true)
            ->whereBetween('created_at', $range)
            ->get(['created_at', 'subtotal', 'tax_amount'])
            ->groupBy(fn (Purchase $p) => $p->created_at->format('Y-m'))
            ->map(fn (Collection $rows) => [
                'net' => round((float) $rows->sum('subtotal'), 2),
                'tax' => round((float) $rows->sum('tax_amount'), 2),
            ]);

        return collect()
            ->merge($sales->keys())
            ->merge($bookings->keys())
            ->merge($purchases->keys())
            ->unique()
            ->sort()
            ->values()
            ->map(function (string $month) use ($sales, $bookings, $purchases) {
                $outputNet = round((float) ($sales[$month]['net'] ?? 0) + (float) ($bookings[$month]['net'] ?? 0), 2);
                $outputTax = round((float) ($sales[$month]['tax'] ?? 0) + (float) ($bookings[$month]['tax'] ?? 0), 2);
                $inputNet = round((float) ($purchases[$month]['net'] ?? 0), 2);
                $inputTax = round((float) ($purchases[$month]['tax'] ?? 0), 2);

                return [
                    'month' => $month,
                    'output_net' => $outputNet,
                    'output_tax' => $outputTax,
                    'input_net' => $inputNet,
                    'input_tax' => $inputTax,
                    'net_due' => round($outputTax - $inputTax, 2),
                ];
            })
            ->values()
            ->all();
    }
}
