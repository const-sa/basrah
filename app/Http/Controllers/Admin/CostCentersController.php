<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\CostCenter;
use App\Models\UnitSection;
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
        [$from, $to] = $this->window($request);
        $search = $request->string('search')->toString();
        $status = $request->string('status')->toString();
        $allowed = $request->user()?->accessibleCostCenterIds();

        // A room is never a row of its own: it is nested under its unit and its
        // figures are counted there. Listing both side by side would read the
        // same riyal twice for every hall let by room.
        $query = CostCenter::query()
            ->with(['unit:id,name,type', 'section:id,name', 'department:id,name'])
            ->whereNull('unit_section_id')
            // A hall supervisor reads their own hall's profit, not the
            // whole business's. Null means the user is unrestricted.
            ->when($allowed !== null, fn ($q) => $q->whereIn('id', $allowed))
            ->when($search !== '', fn ($q) => $q->where(
                fn ($sub) => $sub->where('name', 'like', "%{$search}%")
                    ->orWhere('code', 'like', "%{$search}%")
                    // Searching «قسم الرجال» finds the hall that holds it.
                    ->orWhereIn('unit_id', UnitSection::where('name', 'like', "%{$search}%")->select('unit_id')),
            ))
            ->when($status === 'active', fn ($q) => $q->where('is_active', true))
            ->when($status === 'inactive', fn ($q) => $q->where('is_active', false));

        // Totals cover every matching centre, not one page of them, so the
        // figure does not shift as the user pages through. Rolled up, and over
        // parents alone — the rooms are already inside those numbers.
        $ids = (clone $query)->pluck('id')->map(fn ($id) => (int) $id)->all();
        $profitability = $this->statements->profitabilityRolledUp($ids, $from, $to);
        $deposits = $this->statements->depositsHeldRolledUp($ids, $to);

        $page = $query->orderByDesc('is_active')
            ->orderBy('name')
            ->paginate(30)
            ->withQueryString();

        $rooms = $this->rooms($page->getCollection()->pluck('id')->map(fn ($id) => (int) $id)->all(), $from, $to);

        $centers = $page->through(fn (CostCenter $center) => [
            ...$this->row($center),
            ...($profitability[$center->id] ?? ['revenue' => 0.0, 'expense' => 0.0, 'profit' => 0.0]),
            'deposits' => $deposits[$center->id] ?? 0.0,
            'children' => $rooms[$center->id] ?? [],
        ]);

        return Inertia::render('admin/accounting/CostCenters', [
            'centers' => $centers,
            'filters' => ['from' => $from, 'to' => $to, 'search' => $search ?: null, 'status' => $status ?: null],
            // Money in the books and on no centre — a figure to drive to zero,
            // and only the whole business's reader may be shown it.
            'unattributed' => $allowed === null ? $this->statements->unattributed($from, $to) : null,
            'totals' => [
                'revenue' => round(collect($profitability)->sum('revenue'), 2),
                'expense' => round(collect($profitability)->sum('expense'), 2),
                'profit' => round(collect($profitability)->sum('profit'), 2),
                'deposits' => round(array_sum($deposits), 2),
            ],
        ]);
    }

    /**
     * The rooms nested under each unit centre on this page, with what each earned.
     *
     * @param  list<int>  $centerIds
     * @return array<int, list<array<string, mixed>>>
     */
    private function rooms(array $centerIds, string $from, string $to): array
    {
        $map = $this->statements->reportingMap($centerIds);
        $childIds = [];

        foreach ($map as $parent => $ids) {
            foreach ($ids as $id) {
                if ($id !== $parent) {
                    $childIds[] = $id;
                }
            }
        }

        if ($childIds === []) {
            return [];
        }

        $profitability = $this->statements->profitabilityForMany($childIds, $from, $to);
        $deposits = $this->statements->depositsHeldForMany($childIds, $to);
        $centers = CostCenter::with('section:id,name')->whereIn('id', $childIds)->get()->keyBy('id');

        $rows = [];

        foreach ($map as $parent => $ids) {
            foreach ($ids as $id) {
                $center = $id === $parent ? null : $centers->get($id);

                if (! $center) {
                    continue;
                }

                $rows[$parent][] = [
                    ...$this->row($center),
                    ...($profitability[$id] ?? ['revenue' => 0.0, 'expense' => 0.0, 'profit' => 0.0]),
                    'deposits' => $deposits[$id] ?? 0.0,
                ];
            }
        }

        return $rows;
    }

    /**
     * The report's window, validated.
     *
     * Unvalidated, a «to» before «from» rendered every figure as zero, which
     * reads on screen as the books being empty rather than the dates being
     * crossed — and that is indistinguishable from the bug being unfixed.
     *
     * @return array{0: string, 1: string}
     */
    private function window(Request $request): array
    {
        $data = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
        ], [
            'to.after_or_equal' => 'تاريخ «إلى» لا يسبق تاريخ «من».',
        ]);

        return [
            ($data['from'] ?? null) ?: now()->startOfYear()->toDateString(),
            ($data['to'] ?? null) ?: now()->toDateString(),
        ];
    }

    /** A centre outside the user's scope is not theirs to read. */
    private function authorizeCenter(Request $request, CostCenter $center): void
    {
        $allowed = $request->user()?->accessibleCostCenterIds();

        abort_if($allowed !== null && ! in_array($center->id, $allowed, true), 403, 'مركز التكلفة خارج نطاقك.');
    }

    /** @return array<string, mixed> */
    private function row(CostCenter $center): array
    {
        return [
            'id' => $center->id,
            'code' => $center->code,
            'name' => $center->name,
            'type_label' => $center->typeLabel(),
            'belongs_to' => $center->unit?->name ?? $center->section?->name ?? $center->department?->name,
            'is_active' => $center->is_active,
            'is_system' => $center->isSystem(),
            'is_section' => $center->unit_section_id !== null,
        ];
    }

    public function show(Request $request, CostCenter $costCenter): Response
    {
        $this->authorizeCenter($request, $costCenter);

        [$from, $to] = $this->window($request);

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
            // CC- is the system's own prefix: a hand-made CC-HALL-01-S3 would
            // collide with the centre a room creates on its first posting, and
            // the clash would abort a receipt mid-transaction.
            'code' => ['nullable', 'string', 'max:50', 'unique:cost_centers,code', 'not_regex:/^CC-/i'],
            'is_active' => ['boolean'],
        ], ['code.not_regex' => 'الأكواد التي تبدأ بـ CC- محجوزة لمراكز النظام.']);

        CostCenter::create([
            'name' => $data['name'],
            'code' => ($data['code'] ?? null) ?: $this->nextManualCode(),
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
        $this->authorizeCenter($request, $costCenter);

        [$from, $to] = $this->window($request);

        $statement = $this->statements->statementFor($costCenter, $from, $to);
        $filename = 'cost-center-'.$costCenter->code.'-'.now()->format('Y-m-d-His').'.csv';

        return response()->streamDownload(function () use ($statement) {
            $out = fopen('php://output', 'w');

            // BOM so Excel opens the Arabic in the right encoding.
            fwrite($out, "\xEF\xBB\xBF");

            fputcsv($out, ['التاريخ', 'القيد', 'المصدر', 'القسم', 'الحساب', 'البيان', 'مدين', 'دائن']);

            foreach ($statement['rows'] as $row) {
                fputcsv($out, [
                    $row['date'], $row['entry_number'], $row['source_label'], $row['centre'] ?? '',
                    $row['account'], $row['label'].($row['detail'] ? " — {$row['detail']}" : ''),
                    $row['debit'] ?: '', $row['credit'] ?: '',
                ]);
            }

            fputcsv($out, ['الإجمالي', '', '', '', '', '', $statement['total_debit'], $statement['total_credit']]);
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
