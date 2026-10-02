<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Concerns\StatesFilters;
use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\Contract;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Item;
use App\Models\StockIssue;
use App\Services\StockIssueService;
use App\Support\ActivitySegment;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

/**
 * أذونات صرف المخزن — كلور ومواد تعقيم تخرج مع الفني لصيانة المسابح.
 */
class StockIssuesController extends Controller
{
    use StatesFilters;

    /** حساب المصروف المقترح: «صيانة» — ما تُصرف له هذه المواد في الغالب. */
    private const DEFAULT_EXPENSE_ACCOUNT = '5330';

    public function __construct(private readonly StockIssueService $issues) {}

    public function index(Request $request): Response
    {
        $query = StockIssue::with(['employee:id,name', 'department:id,name', 'expenseAccount:id,code,name', 'contract:id,number', 'user:id,name', 'journalEntry:id,number'])
            ->withCount('items')
            ->when($request->string('status')->toString(), fn ($q, $s) => $q->where('status', $s))
            ->when($request->integer('employee_id'), fn ($q, $id) => $q->where('employee_id', $id))
            ->when($request->string('from')->toString(), fn ($q, $d) => $q->whereDate('issue_date', '>=', $d))
            ->when($request->string('to')->toString(), fn ($q, $d) => $q->whereDate('issue_date', '<=', $d))
            ->when($request->string('search')->toString(), fn ($q, $term) => $q->where(
                fn ($sub) => $sub->where('number', 'like', "%{$term}%")
                    ->orWhere('recipient_name', 'like', "%{$term}%")
                    ->orWhere('notes', 'like', "%{$term}%"),
            ));

        $poolsDepartment = Department::where('code', ActivitySegment::POOLS_DEPARTMENT)->first();
        $posted = fn () => StockIssue::where('status', 'posted');

        return Inertia::render('admin/items/Issues', [
            'issues' => $query->latest('id')->paginate(25)->withQueryString()
                ->through(fn (StockIssue $i) => [
                    'id' => $i->id,
                    'number' => $i->number,
                    'issue_date' => $i->issue_date->format('Y-m-d'),
                    'recipient' => $i->recipientLabel(),
                    'department' => $i->department?->name,
                    'contract' => $i->contract?->number,
                    'expense_account' => $i->expenseAccount ? "{$i->expenseAccount->code} — {$i->expenseAccount->name}" : null,
                    'items_count' => $i->items_count,
                    'total_cost' => (float) $i->total_cost,
                    'status' => $i->status,
                    'status_label' => StockIssue::STATUSES[$i->status] ?? $i->status,
                    'entry_number' => $i->journalEntry?->number,
                    'user_name' => $i->user?->name,
                    'notes' => $i->notes,
                ]),
            'filters' => $this->filterState($request, ['status', 'employee_id', 'from', 'to', 'search']),
            'stats' => [
                'count' => $posted()->count(),
                'month_cost' => round((float) $posted()
                    ->whereBetween('issue_date', [now()->startOfMonth()->toDateString(), now()->endOfMonth()->toDateString()])
                    ->sum('total_cost'), 2),
                'total_cost' => round((float) $posted()->sum('total_cost'), 2),
            ],
            'stockItems' => Item::whereIn('type', Item::STOCKED_TYPES)->where('is_active', true)
                ->with('measureUnit:id,name')
                ->orderBy('name')
                ->get()
                ->map(fn (Item $i) => [
                    'id' => $i->id,
                    'name' => $i->name,
                    'code' => $i->code,
                    'stock_qty' => (float) $i->stock_qty,
                    'cost' => (float) $i->cost,
                    'unit' => $i->unitLabel(),
                ])->values(),
            'employees' => Employee::where('is_active', true)->orderBy('name')->get(['id', 'name', 'position']),
            'departments' => Department::where('is_active', true)->orderBy('sort_order')->get(['id', 'name']),
            // عقود المسابح: ما لم يُكتب على حجز — الصيانة والتركيب وما جاء من عرض سعر.
            'contracts' => Contract::with('client:id,name')
                ->whereNull('booking_id')
                ->where('status', '!=', 'cancelled')
                ->latest('id')
                ->limit(300)
                ->get(['id', 'number', 'client_id'])
                ->map(fn (Contract $c) => ['id' => $c->id, 'label' => $this->contractLabel($c)])
                ->values(),
            'expenseAccounts' => Account::postable()->where('type', 'expense')->orderBy('code')->get(['id', 'code', 'name']),
            'defaults' => [
                'department_id' => $poolsDepartment?->id,
                'expense_account_id' => Account::where('code', self::DEFAULT_EXPENSE_ACCOUNT)->value('id'),
            ],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'issue_date' => ['required', 'date'],
            'department_id' => ['nullable', 'exists:departments,id'],
            'employee_id' => ['nullable', 'exists:employees,id', 'required_without:recipient_name'],
            'recipient_name' => ['nullable', 'string', 'max:255'],
            'contract_id' => ['nullable', 'exists:contracts,id'],
            'expense_account_id' => [
                'required',
                Rule::exists('accounts', 'id')->where('type', 'expense')->where('is_group', 0)->where('is_active', 1),
            ],
            'notes' => ['nullable', 'string', 'max:1000'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.item_id' => ['required', 'exists:items,id'],
            'items.*.quantity' => ['required', 'numeric', 'min:0.001'],
        ], [
            'employee_id.required_without' => 'حدّد الفني المستلم أو اكتب اسمه.',
        ], [
            'issue_date' => 'تاريخ الصرف',
            'expense_account_id' => 'حساب المصروف',
            'items' => 'المواد المصروفة',
            'items.*.item_id' => 'الصنف',
            'items.*.quantity' => 'الكمية',
        ]);

        try {
            $issue = $this->issues->create($data, $request->user()?->id);
        } catch (RuntimeException $e) {
            return back()->with('warning', $e->getMessage());
        }

        return back()->with('success', "تم حفظ إذن الصرف {$issue->number} وخصم المواد من المخزون وترحيل قيده");
    }

    public function show(StockIssue $stockIssue): Response
    {
        $stockIssue->load([
            'items.item:id,name,code,unit,measure_unit_id', 'items.item.measureUnit:id,name',
            'employee:id,name,position', 'department:id,name', 'contract:id,number,client_id', 'contract.client:id,name',
            'expenseAccount:id,code,name', 'journalEntry:id,number', 'user:id,name', 'canceller:id,name',
        ]);

        return Inertia::render('admin/items/IssueShow', [
            'issue' => [
                'id' => $stockIssue->id,
                'number' => $stockIssue->number,
                'issue_date' => $stockIssue->issue_date->format('Y-m-d'),
                'recipient' => $stockIssue->recipientLabel(),
                'position' => $stockIssue->employee?->position,
                'department' => $stockIssue->department?->name,
                'contract' => $stockIssue->contract ? $this->contractLabel($stockIssue->contract) : null,
                'expense_account' => $stockIssue->expenseAccount ? "{$stockIssue->expenseAccount->code} — {$stockIssue->expenseAccount->name}" : null,
                'total_cost' => (float) $stockIssue->total_cost,
                'status' => $stockIssue->status,
                'status_label' => StockIssue::STATUSES[$stockIssue->status] ?? $stockIssue->status,
                'entry_number' => $stockIssue->journalEntry?->number,
                'user_name' => $stockIssue->user?->name,
                'cancelled_by' => $stockIssue->canceller?->name,
                'cancelled_at' => $stockIssue->cancelled_at?->format('Y-m-d H:i'),
                'notes' => $stockIssue->notes,
                'created_at' => $stockIssue->created_at->format('Y-m-d H:i'),
                'items' => $stockIssue->items->map(fn ($l) => [
                    'id' => $l->id,
                    'name' => $l->item?->name,
                    'code' => $l->item?->code,
                    'unit' => $l->item?->unitLabel(),
                    'quantity' => (float) $l->quantity,
                    'unit_cost' => (float) $l->unit_cost,
                    'total_cost' => (float) $l->total_cost,
                ])->values(),
            ],
        ]);
    }

    public function cancel(Request $request, StockIssue $stockIssue): RedirectResponse
    {
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:500']]);

        try {
            $this->issues->cancel($stockIssue, $request->user()?->id, $data['reason'] ?? null);
        } catch (RuntimeException $e) {
            return back()->with('warning', $e->getMessage());
        }

        return back()->with('success', "تم إلغاء إذن الصرف {$stockIssue->number} وإعادة المواد للمخزون وعكس قيده");
    }

    private function contractLabel(Contract $contract): string
    {
        return $contract->client?->name ? "{$contract->number} — {$contract->client->name}" : (string) $contract->number;
    }
}
