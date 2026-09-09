<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Concerns\AuthorizesActivities;
use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\CostCenter;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\PaymentMethod;
use App\Models\Supplier;
use App\Models\Treasury;
use App\Models\User;
use App\Services\Accounting\ExpenseService;
use App\Support\ActivityPermission;
use App\Support\ActivitySegment;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * المصروفات والتكاليف (§9 من العرض المعتمد).
 *
 * المصروف مستندٌ في جدوله كالفاتورة والمسيّر، ونوعه صفٌّ في جدول الأنواع
 * يعرف حسابه في الشجرة. فالموظف المالي يسجّل «فاتورة كهرباء» ولا يُسأل عن
 * حسابٍ محاسبي، والترحيل يترجم المستند إلى قيدٍ متوازن.
 */
class ExpensesController extends Controller
{
    use AuthorizesActivities;

    /** The actions the expenses screen can offer. */
    private const ACTIONS = ['view', 'create', 'edit', 'delete', 'approve'];

    public function __construct(
        private readonly ExpenseService $expenses,
        private readonly ActivitySegment $segments,
    ) {}

    public function hallExpenses(Request $request): Response
    {
        return $this->index($request, ActivitySegment::HALLS);
    }

    public function chaletExpenses(Request $request): Response
    {
        return $this->index($request, ActivitySegment::CHALETS);
    }

    public function poolExpenses(Request $request): Response
    {
        return $this->index($request, ActivitySegment::POOLS);
    }

    /**
     * The whole book for the accountant, or one activity's when opened from
     * that activity's menu. Either way, only the user's own units.
     */
    public function index(Request $request, ?string $activity = null): Response
    {
        $user = $request->user();
        $filters = $this->filters($request, $activity);
        $query = $this->filtered($filters, $user);

        return Inertia::render('admin/accounting/Expenses', [
            'expenses' => (clone $query)
                ->with(['category:id,name', 'costCenter:id,name', 'treasury:id,name', 'supplier:id,name', 'paymentMethod:id,name'])
                ->orderByDesc('expense_date')
                ->orderByDesc('id')
                ->paginate(20)
                ->withQueryString()
                ->through(fn (Expense $e) => $this->row($e)),
            'filters' => $filters,
            'stats' => $this->stats($filters, $user),
            'byCategory' => $this->byCategory($filters, $user),
            'categories' => $this->categories(),
            'accounts' => Account::postable()
                ->where('type', 'expense')
                ->where('is_active', true)
                ->orderBy('code')
                ->get(['id', 'code', 'name']),
            'costCenters' => $this->costCenters($user, $filters['activity']),
            'treasuries' => Treasury::where('is_active', true)->get()->map(fn (Treasury $t) => [
                'id' => $t->id, 'name' => $t->name, 'balance' => $t->balance(),
            ]),
            'methods' => PaymentMethod::options(),
            'suppliers' => Supplier::where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'statuses' => collect(Expense::STATUSES)->map(fn ($l, $k) => ['key' => $k, 'label' => $l])->values(),
            // The pinned activity — the screen titles by it, and no filter widens it.
            'activity' => $filters['activity'],
            'activityLabel' => $filters['activity'] ? ActivitySegment::label($filters['activity']) : null,
            // A scoped user must name a centre, so the form may not offer «none».
            'scoped' => $user?->accessibleCostCenterIds() !== null,
            'can' => $this->activityAbilities($request, 'expenses', $filters['activity'], self::ACTIONS),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);

        // The activity is the centre's, so it is read from what was submitted.
        $this->authorizeActivity($request, 'expenses', 'create', ActivityPermission::ofCostCenter(
            $data['cost_center_id'] ?? null,
        ));

        $expense = $this->expenses->create(
            collect($data)->except('post_now')->all(),
            $request->user()?->id,
        );

        // التسجيل والترحيل في خطوة واحدة هو الحالة الغالبة: مصروف اليوم
        // يُدفع نقدًا ويُقيَّد فورًا. والمسوّدة تبقى لمن يحتاج مراجعةً قبله.
        // Posting is the approver's: whoever may only record leaves a draft,
        // or the box would hand him the ledger his permission withholds.
        if ($request->boolean('post_now')
            && ActivityPermission::allows($request->user(), 'expenses', 'approve', ActivityPermission::ofExpense($expense))) {
            try {
                $this->expenses->post($expense, $request->user()?->id);
            } catch (RuntimeException $e) {
                return back()->with('warning', $e->getMessage());
            }
        }

        return back()->with('success', "تم تسجيل المصروف {$expense->number}");
    }

    /**
     * التعديل على المسوّدة وحدها — المرحَّل له قيدٌ في الدفاتر، وتعديله
     * تحت الطاولة يجعل الدفتر يقول غير ما تقوله الشاشة. يُلغى ويُعاد.
     */
    public function update(Request $request, Expense $expense): RedirectResponse
    {
        $this->authorizeScope($request, $expense, 'edit');

        if (! $expense->isDraft()) {
            return back()->with('warning', 'المصروف المرحَّل لا يُعدَّل — ألغِه وسجّله من جديد.');
        }

        $expense->update(collect($this->validated($request))->except('post_now')->all());

        return back()->with('success', 'تم تحديث المصروف');
    }

    public function post(Request $request, Expense $expense): RedirectResponse
    {
        $this->authorizeScope($request, $expense, 'approve');

        try {
            $this->expenses->post($expense, $request->user()?->id);
        } catch (RuntimeException $e) {
            return back()->with('warning', $e->getMessage());
        }

        return back()->with('success', 'تم ترحيل المصروف إلى الدفاتر');
    }

    public function cancel(Request $request, Expense $expense): RedirectResponse
    {
        $this->authorizeScope($request, $expense, 'approve');

        $data = $request->validate(['reason' => ['nullable', 'string', 'max:500']]);

        $this->expenses->cancel($expense, $data['reason'] ?? null, $request->user()?->id);

        return back()->with('success', 'تم إلغاء المصروف وعكس قيده');
    }

    public function destroy(Request $request, Expense $expense): RedirectResponse
    {
        $this->authorizeScope($request, $expense, 'delete');

        if ($expense->isPosted()) {
            return back()->with('warning', 'المصروف المرحَّل لا يُحذف — ألغِه ليُعكس قيده.');
        }

        $expense->delete();

        return back()->with('success', 'تم حذف المصروف — تجده في الأرشيف');
    }

    /**
     * أنواع المصروف تُدار من الشاشة نفسها: من يصرف يعرف ما ينقص القائمة.
     */
    public function storeCategory(Request $request): RedirectResponse
    {
        // The list belongs to no activity, so spending anywhere may extend it.
        $this->authorizeAnyActivity($request, 'expenses', 'create');

        $category = ExpenseCategory::create($this->validatedCategory($request));

        return back()->with('success', "تمت إضافة نوع المصروف «{$category->name}»");
    }

    public function updateCategory(Request $request, ExpenseCategory $category): RedirectResponse
    {
        $this->authorizeAnyActivity($request, 'expenses', 'edit');

        $category->update($this->validatedCategory($request, $category));

        return back()->with('success', 'تم تحديث نوع المصروف');
    }

    /**
     * النوع المستعمل لا يُحذف: حذفه يُيتّم مصروفات سُجّلت عليه. يُعطَّل
     * فيختفي من نموذج التسجيل ويبقى في تقارير ما مضى.
     */
    public function destroyCategory(Request $request, ExpenseCategory $category): RedirectResponse
    {
        $this->authorizeAnyActivity($request, 'expenses', 'delete');

        if ($category->is_system) {
            return back()->with('warning', 'نوعٌ أساسي في النظام لا يُحذف — عطّله بدل حذفه.');
        }

        if ($category->expenses()->exists()) {
            return back()->with('warning', 'النوع مستعمل في مصروفات مسجّلة — عطّله بدل حذفه.');
        }

        $category->delete();

        return back()->with('success', 'تم حذف نوع المصروف');
    }

    public function toggleCategory(Request $request, ExpenseCategory $category): RedirectResponse
    {
        $this->authorizeAnyActivity($request, 'expenses', 'edit');

        $category->update(['is_active' => ! $category->is_active]);

        return back()->with('success', $category->is_active ? 'تم تفعيل النوع' : 'تم إيقاف النوع');
    }

    /**
     * تصدير المعروض بمرشّحاته.
     */
    public function export(Request $request): StreamedResponse
    {
        $filters = $this->filters($request);

        // Exporting one activity's register needs that activity's key; exporting
        // the whole book needs the global one.
        $this->authorizeActivity($request, 'expenses', 'view', $filters['activity']);

        $user = $request->user();
        $filename = 'expenses-'.now()->format('Y-m-d-His').'.csv';

        return response()->streamDownload(function () use ($filters, $user) {
            $out = fopen('php://output', 'w');

            // BOM حتى يفتح إكسل العربية بترميزها الصحيح بدل رموز مبهمة.
            fwrite($out, "\xEF\xBB\xBF");

            fputcsv($out, ['رقم المصروف', 'التاريخ', 'النوع', 'الوحدة', 'المورّد', 'الوصف', 'طريقة الدفع', 'الخزينة', 'المبلغ', 'الحالة']);

            $this->filtered($filters, $user)
                ->with(['category:id,name', 'costCenter:id,name', 'treasury:id,name', 'supplier:id,name', 'paymentMethod:id,name'])
                ->orderByDesc('expense_date')
                ->chunk(500, function ($chunk) use ($out) {
                    foreach ($chunk as $expense) {
                        fputcsv($out, [
                            $expense->number,
                            $expense->expense_date?->toDateString(),
                            $expense->category?->name,
                            $expense->costCenter?->name,
                            $expense->supplier?->name,
                            $expense->description,
                            $expense->paymentMethod?->name,
                            $expense->treasury?->name,
                            (float) $expense->amount,
                            $expense->statusLabel(),
                        ]);
                    }
                });

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * @param  string|null  $activity  an activity the screen is pinned to, which no filter widens
     * @return array<string, mixed>
     */
    private function filters(Request $request, ?string $activity = null): array
    {
        $requested = $request->string('activity')->toString();

        return [
            'from' => $request->date('from')?->toDateString() ?? now()->startOfMonth()->toDateString(),
            'to' => $request->date('to')?->toDateString() ?? now()->toDateString(),
            'expense_category_id' => $request->integer('expense_category_id') ?: null,
            'cost_center_id' => $request->integer('cost_center_id') ?: null,
            'status' => $request->string('status')->toString() ?: null,
            'search' => $request->string('search')->toString() ?: null,
            // Read from the query too, so the export carries the pinned register.
            'activity' => $activity ?? (ActivitySegment::isActivity($requested) ? $requested : null),
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Builder<Expense>
     */
    private function filtered(array $filters, ?User $user): Builder
    {
        return Expense::query()
            ->visibleTo($user)
            ->when(
                $filters['activity'] ?? null,
                fn ($q, string $activity) => $q->whereIn('cost_center_id', $this->segments->centerIds($activity)),
            )
            ->between($filters['from'] ?? null, $filters['to'] ?? null)
            ->when($filters['expense_category_id'] ?? null, fn ($q, $id) => $q->where('expense_category_id', $id))
            ->when($filters['cost_center_id'] ?? null, fn ($q, $id) => $q->where('cost_center_id', $id))
            ->when($filters['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->when($filters['search'] ?? null, fn ($q, $term) => $q->where(
                fn ($sub) => $sub->where('description', 'like', "%{$term}%")
                    ->orWhere('number', 'like', "%{$term}%")
                    ->orWhere('reference', 'like', "%{$term}%"),
            ));
    }

    /**
     * @return array<string, mixed>
     */
    private function row(Expense $expense): array
    {
        return [
            'id' => $expense->id,
            'number' => $expense->number,
            'expense_date' => $expense->expense_date?->toDateString(),
            'amount' => (float) $expense->amount,
            'expense_category_id' => $expense->expense_category_id,
            'category' => $expense->category?->name,
            'cost_center_id' => $expense->cost_center_id,
            'unit' => $expense->costCenter?->name,
            'treasury_id' => $expense->treasury_id,
            'treasury' => $expense->treasury?->name,
            'supplier_id' => $expense->supplier_id,
            'supplier' => $expense->supplier?->name,
            'payment_method_id' => $expense->payment_method_id,
            'method_label' => $expense->paymentMethod?->name,
            'reference' => $expense->reference,
            'description' => $expense->description,
            'status' => $expense->status,
            'status_label' => $expense->statusLabel(),
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    private function stats(array $filters, ?User $user): array
    {
        $posted = (clone $this->filtered($filters, $user))->posted();

        return [
            // المرحَّل وحده مصروفٌ فعلي: المسوّدة نيّة، والملغى رُدَّ.
            'total' => round((float) (clone $posted)->sum('amount'), 2),
            'count' => (clone $posted)->count(),
            'drafts' => (clone $this->filtered($filters, $user))->where('status', 'draft')->count(),
            // The month ignores the dates but not the scope, or the tile would
            // report the whole business to an employee of one activity.
            'month' => round((float) $this->filtered(
                ['activity' => $filters['activity'] ?? null],
                $user,
            )
                ->posted()
                ->whereDate('expense_date', '>=', now()->startOfMonth()->toDateString())
                ->sum('amount'), 2),
        ];
    }

    /**
     * توزيع المصروف على أنواعه — الشاشة تجيب «فيمَ صُرف» لا «كم صُرف» فقط.
     *
     * @param  array<string, mixed>  $filters
     * @return list<array<string, mixed>>
     */
    private function byCategory(array $filters, ?User $user): array
    {
        $rows = (clone $this->filtered($filters, $user))
            ->posted()
            ->with('category:id,name')
            ->get(['expense_category_id', 'amount'])
            ->groupBy(fn (Expense $e) => $e->category?->name ?? 'بلا نوع');

        $total = round((float) $rows->flatten()->sum('amount'), 2);

        return $rows
            ->map(fn ($group, string $category) => [
                'category' => $category,
                'count' => $group->count(),
                'amount' => round((float) $group->sum('amount'), 2),
                'share' => $total > 0 ? round((float) $group->sum('amount') / $total * 100, 1) : 0.0,
            ])
            ->sortByDesc('amount')
            ->values()
            ->all();
    }

    /**
     * The centres the form may offer: the user's own, narrowed further to the
     * pinned activity. A centre absent here cannot be spent on either.
     *
     * @return list<array<string, mixed>>
     */
    private function costCenters(?User $user, ?string $activity): array
    {
        $allowed = $user?->accessibleCostCenterIds();

        return CostCenter::with(['unit:id,name', 'section:id,name,unit_id', 'section.unit:id,name', 'department:id,name'])
            ->where('is_active', true)
            ->when($allowed !== null, fn ($q) => $q->whereIn('id', $allowed ?? []))
            ->when($activity, fn ($q, string $a) => $q->whereIn('id', $this->segments->centerIds($a)))
            ->get()
            ->map(fn (CostCenter $c) => [
                'id' => $c->id,
                'name' => $this->segments->nameOf($c) ?? $c->name,
                'segment' => $this->segments->of($c->id),
            ])
            ->sortBy('name')
            ->values()
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function categories(): array
    {
        return ExpenseCategory::with(['account:id,code,name', 'costCenter:id,name'])
            ->ordered()
            ->withCount('expenses')
            ->get()
            ->map(fn (ExpenseCategory $c) => [
                'id' => $c->id,
                'code' => $c->code,
                'name' => $c->name,
                'account' => $c->account?->name,
                'account_id' => $c->account_id,
                'cost_center_id' => $c->cost_center_id,
                'is_active' => $c->is_active,
                'is_system' => $c->is_system,
                'expenses_count' => $c->expenses_count,
            ])
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        return $request->validate([
            'expense_date' => ['required', 'date'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'expense_category_id' => ['required', Rule::exists('expense_categories', 'id')->whereNull('deleted_at')],
            'treasury_id' => ['required', 'exists:treasuries,id'],
            'cost_center_id' => $this->costCenterRules($request),
            'supplier_id' => ['nullable', 'exists:suppliers,id'],
            'payment_method_id' => ['required', Rule::exists('payment_methods', 'id')->where('is_active', true)],
            'reference' => ['nullable', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:1000'],
            'post_now' => ['boolean'],
        ], [
            'expense_category_id.required' => 'اختر نوع المصروف.',
            'expense_category_id.exists' => 'نوع المصروف غير موجود.',
            'cost_center_id.required' => 'اختر الوحدة التي حُمِّل عليها المصروف.',
            'cost_center_id.in' => 'هذه الوحدة خارج نطاق عملك.',
        ]);
    }

    /**
     * A scoped user charges the expense to one of their own centres and may
     * not leave it blank — an unattributed one escapes every register.
     *
     * @return list<mixed>
     */
    private function costCenterRules(Request $request): array
    {
        $allowed = $request->user()?->accessibleCostCenterIds();

        return $allowed === null
            ? ['nullable', 'exists:cost_centers,id']
            : ['required', Rule::in($allowed)];
    }

    /**
     * A type may carry no default centre, but a scoped user may not point one
     * at an activity they do not work in.
     *
     * @return list<mixed>
     */
    private function categoryCenterRules(Request $request): array
    {
        $allowed = $request->user()?->accessibleCostCenterIds();

        return $allowed === null
            ? ['nullable', 'exists:cost_centers,id']
            : ['nullable', Rule::in($allowed)];
    }

    /**
     * The list hides what is outside the user's units, and an id in the URL
     * must not be the way round that.
     */
    private function authorizeScope(Request $request, Expense $expense, string $action): void
    {
        if (! $request->user()?->canSpendOn($expense->cost_center_id)) {
            abort(403, 'هذا المصروف خارج نطاق وحداتك.');
        }

        // Two separate fences: the units the user may spend on, and the activity
        // whose register this spend belongs to.
        $this->authorizeActivity($request, 'expenses', $action, ActivityPermission::ofExpense($expense));
    }

    /**
     * @return array<string, mixed>
     */
    private function validatedCategory(Request $request, ?ExpenseCategory $category = null): array
    {
        return $request->validate([
            'code' => ['required', 'string', 'max:50', Rule::unique('expense_categories', 'code')->ignore($category?->id)],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:255'],
            // الحساب لا بدّ منه: نوعٌ بلا حساب مصروفٌ لا يصل إلى الدفاتر.
            'account_id' => ['required', Rule::exists('accounts', 'id')->where('type', 'expense')],
            // The type's default centre is a centre to be spent on like any other.
            'cost_center_id' => $this->categoryCenterRules($request),
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'is_active' => ['boolean'],
        ], [
            'account_id.exists' => 'الحساب المحاسبي يجب أن يكون من حسابات المصروفات.',
            'cost_center_id.in' => 'هذه الوحدة خارج نطاق عملك.',
        ]);
    }
}
