<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\CostCenter;
use App\Services\Accounting\CostCenterStatementService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Cost centres — the profit of each hall, unit and department on its own.
 *
 * Most centres are created by the system with their unit or department and
 * cannot be deleted, so this screen lists, renames, deactivates and reports.
 */
class CostCentersController extends Controller
{
    public function __construct(private readonly CostCenterStatementService $statements) {}

    public function index(Request $request): Response
    {
        $from = $request->string('from')->toString() ?: now()->startOfYear()->toDateString();
        $to = $request->string('to')->toString() ?: now()->toDateString();
        $search = $request->string('search')->toString();
        $status = $request->string('status')->toString();

        $query = CostCenter::query()
            ->with(['unit:id,name,type', 'section:id,name', 'department:id,name'])
            ->when($search !== '', fn ($q) => $q->where(
                fn ($sub) => $sub->where('name', 'like', "%{$search}%")
                    ->orWhere('code', 'like', "%{$search}%"),
            ))
            ->when($status === 'active', fn ($q) => $q->where('is_active', true))
            ->when($status === 'inactive', fn ($q) => $q->where('is_active', false));

        // Totals cover every matching centre, not one page of them, so the
        // figure does not shift as the user pages through.
        $profitability = $this->statements->profitabilityForMany(
            (clone $query)->pluck('id')->map(fn ($id) => (int) $id)->all(),
            $from,
            $to,
        );

        $centers = $query->orderByDesc('is_active')
            ->orderBy('name')
            ->paginate(30)
            ->withQueryString()
            ->through(fn (CostCenter $center) => [
                'id' => $center->id,
                'code' => $center->code,
                'name' => $center->name,
                'type_label' => $center->typeLabel(),
                'belongs_to' => $center->unit?->name ?? $center->section?->name ?? $center->department?->name,
                'is_active' => $center->is_active,
                'is_system' => $center->isSystem(),
                ...($profitability[$center->id] ?? ['revenue' => 0.0, 'expense' => 0.0, 'profit' => 0.0]),
            ]);

        return Inertia::render('admin/accounting/CostCenters', [
            'centers' => $centers,
            'filters' => ['from' => $from, 'to' => $to, 'search' => $search ?: null, 'status' => $status ?: null],
            'totals' => [
                'revenue' => round(collect($profitability)->sum('revenue'), 2),
                'expense' => round(collect($profitability)->sum('expense'), 2),
                'profit' => round(collect($profitability)->sum('profit'), 2),
            ],
        ]);
    }

    public function show(Request $request, CostCenter $costCenter): Response
    {
        $from = $request->string('from')->toString() ?: now()->startOfYear()->toDateString();
        $to = $request->string('to')->toString() ?: now()->toDateString();

        return Inertia::render('admin/accounting/CostCenterStatement', [
            'center' => [
                'id' => $costCenter->id,
                'code' => $costCenter->code,
                'name' => $costCenter->name,
                'type_label' => $costCenter->typeLabel(),
                'is_active' => $costCenter->is_active,
            ],
            'filters' => ['from' => $from, 'to' => $to],
            'statement' => $this->statements->statementFor($costCenter, $from, $to),
            'breakdown' => $this->statements->breakdownFor($costCenter, $from, $to),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'code' => ['nullable', 'string', 'max:50', 'unique:cost_centers,code'],
            'is_active' => ['boolean'],
        ]);

        CostCenter::create([
            'name' => $data['name'],
            'code' => $data['code'] ?: $this->nextManualCode(),
            'is_active' => $data['is_active'] ?? true,
        ]);

        return back()->with('success', 'تم إنشاء مركز التكلفة');
    }

    public function update(Request $request, CostCenter $costCenter): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:50', Rule::unique('cost_centers', 'code')->ignore($costCenter->id)],
            'is_active' => ['boolean'],
        ]);

        // A system centre's code is how it is found again (CC-GEN), so changing
        // it makes a second centre for the same thing and splits its figures.
        if ($costCenter->isSystem() && $data['code'] !== $costCenter->code) {
            return back()->with('warning', 'كود مركز يتبع وحدة أو قسمًا لا يُغيَّر — غيّر الاسم إن أردت.');
        }

        $costCenter->update($data);

        return back()->with('success', 'تم تحديث مركز التكلفة');
    }

    public function destroy(CostCenter $costCenter): RedirectResponse
    {
        if ($costCenter->isSystem()) {
            return back()->with('warning', 'مركز يتبع وحدة أو قسمًا لا يُحذف — يُنشئه النظام من جديد. أوقفه بدل حذفه.');
        }

        if ($costCenter->lines()->exists()) {
            return back()->with('warning', 'لا يُحذف مركز رُحِّلت عليه قيود — أوقفه بدل حذفه.');
        }

        $costCenter->delete();

        return back()->with('success', 'تم حذف مركز التكلفة');
    }

    public function export(Request $request, CostCenter $costCenter): StreamedResponse
    {
        $from = $request->string('from')->toString() ?: now()->startOfYear()->toDateString();
        $to = $request->string('to')->toString() ?: now()->toDateString();

        $statement = $this->statements->statementFor($costCenter, $from, $to);
        $filename = 'cost-center-'.$costCenter->code.'-'.now()->format('Y-m-d-His').'.csv';

        return response()->streamDownload(function () use ($statement) {
            $out = fopen('php://output', 'w');

            // BOM so Excel opens the Arabic in the right encoding.
            fwrite($out, "\xEF\xBB\xBF");

            fputcsv($out, ['التاريخ', 'القيد', 'المصدر', 'الحساب', 'البيان', 'مدين', 'دائن']);

            foreach ($statement['rows'] as $row) {
                fputcsv($out, [
                    $row['date'], $row['entry_number'], $row['source_label'],
                    $row['account'], $row['label'],
                    $row['debit'] ?: '', $row['credit'] ?: '',
                ]);
            }

            fputcsv($out, ['الإجمالي', '', '', '', '', $statement['total_debit'], $statement['total_credit']]);
            fputcsv($out, ['الإيراد', $statement['revenue'], 'المصروف', $statement['expense'], 'الربح', $statement['profit']]);

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * A code for a hand-made centre that cannot collide with the unit codes.
     */
    private function nextManualCode(): string
    {
        $n = CostCenter::where('code', 'like', 'CC-M%')->count() + 1;

        while (CostCenter::where('code', 'CC-M'.$n)->exists()) {
            $n++;
        }

        return 'CC-M'.$n;
    }
}
