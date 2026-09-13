<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\BankStatementImport;
use App\Models\BankStatementLine;
use App\Models\Expense;
use App\Models\Treasury;
use App\Models\Voucher;
use App\Services\Accounting\BankReconciliationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

/**
 * التسوية البنكية: استيراد كشف بنك ومطابقته بالسندات والمصروفات المرحَّلة.
 */
class BankReconciliationController extends Controller
{
    public function __construct(private readonly BankReconciliationService $service) {}

    public function index(Request $request): Response
    {
        $treasuries = Treasury::where('type', 'bank')->where('is_active', true)->orderBy('name')->get(['id', 'name']);
        $treasuryId = $request->integer('treasury_id') ?: $treasuries->first()?->id;
        $treasury = $treasuryId ? Treasury::find($treasuryId) : null;

        $lines = collect();
        $unmatchedSystem = collect();
        $imports = collect();

        if ($treasury) {
            $lines = BankStatementLine::where('treasury_id', $treasury->id)
                ->with(['matchedVoucher:id,number', 'matchedExpense:id,number'])
                ->orderByDesc('txn_date')
                ->limit(200)
                ->get()
                ->map(fn (BankStatementLine $l) => [
                    'id' => $l->id,
                    'date' => $l->txn_date->toDateString(),
                    'description' => $l->description,
                    'amount' => (float) $l->amount,
                    'reference' => $l->reference,
                    'matched' => $l->isMatched(),
                    'matched_label' => $l->matchedVoucher?->number ?? $l->matchedExpense?->number,
                ])
                ->values();

            $matchedVoucherIds = BankStatementLine::whereNotNull('matched_voucher_id')->pluck('matched_voucher_id');
            $matchedExpenseIds = BankStatementLine::whereNotNull('matched_expense_id')->pluck('matched_expense_id');

            $unmatchedVouchers = Voucher::where('treasury_id', $treasury->id)
                ->where('status', 'posted')
                ->whereNotIn('id', $matchedVoucherIds)
                ->orderByDesc('voucher_date')
                ->limit(100)
                ->get()
                ->map(fn (Voucher $v) => [
                    'kind' => 'voucher',
                    'id' => $v->id,
                    'date' => $v->voucher_date->toDateString(),
                    'label' => "{$v->typeLabel()} {$v->number}",
                    'amount' => $v->increasesTreasury() ? (float) $v->amount : -(float) $v->amount,
                ]);

            $unmatchedExpenses = Expense::where('treasury_id', $treasury->id)
                ->posted()
                ->whereNotIn('id', $matchedExpenseIds)
                ->orderByDesc('expense_date')
                ->limit(100)
                ->get()
                ->map(fn (Expense $e) => [
                    'kind' => 'expense',
                    'id' => $e->id,
                    'date' => $e->expense_date->toDateString(),
                    'label' => "مصروف {$e->number}",
                    'amount' => -(float) $e->amount,
                ]);

            $unmatchedSystem = $unmatchedVouchers->concat($unmatchedExpenses)->sortByDesc('date')->values();

            $imports = BankStatementImport::where('treasury_id', $treasury->id)
                ->latest()
                ->limit(10)
                ->get(['id', 'original_filename', 'created_at']);
        }

        return Inertia::render('admin/accounting/BankReconciliation', [
            'treasuries' => $treasuries,
            'selectedTreasuryId' => $treasury?->id,
            'bookBalance' => $treasury?->balance(),
            'lines' => $lines,
            'unmatchedSystem' => $unmatchedSystem,
            'imports' => $imports,
        ]);
    }

    public function import(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'treasury_id' => ['required', 'exists:treasuries,id'],
            'file' => ['required', 'file', 'mimes:csv,txt', 'max:5120'],
        ]);

        $treasury = Treasury::findOrFail($data['treasury_id']);

        try {
            $import = $this->service->import($request->file('file'), $treasury, $request->user()?->id);
        } catch (RuntimeException $e) {
            return back()->with('warning', $e->getMessage());
        }

        return back()->with('success', 'تم استيراد '.$import->lines()->count().' حركة، طابَق النظام ما أمكنه تلقائيًا');
    }

    public function match(Request $request, BankStatementLine $bankStatementLine): RedirectResponse
    {
        $data = $request->validate([
            'voucher_id' => ['required_without:expense_id', 'nullable', 'exists:vouchers,id'],
            'expense_id' => ['required_without:voucher_id', 'nullable', 'exists:expenses,id'],
        ]);

        $target = isset($data['voucher_id'])
            ? Voucher::findOrFail($data['voucher_id'])
            : Expense::findOrFail($data['expense_id']);

        $this->service->match($bankStatementLine, $target, $request->user()?->id);

        return back()->with('success', 'تمت المطابقة');
    }

    public function unmatch(BankStatementLine $bankStatementLine): RedirectResponse
    {
        $this->service->unmatch($bankStatementLine);

        return back()->with('success', 'تم فك المطابقة');
    }

    public function destroyImport(BankStatementImport $bankStatementImport): RedirectResponse
    {
        if ($bankStatementImport->lines()->whereNotNull('matched_at')->exists()) {
            return back()->with('warning', 'لا يُحذف استيراد طُوبقت منه حركات — فُكّ مطابقتها أولًا.');
        }

        $bankStatementImport->lines()->delete();
        $bankStatementImport->delete();

        return back()->with('success', 'تم حذف الاستيراد');
    }
}
