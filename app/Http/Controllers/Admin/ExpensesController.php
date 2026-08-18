<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\CostCenter;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\PaymentMethod;
use App\Models\Supplier;
use App\Models\Treasury;
use App\Services\Accounting\ExpenseService;
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
    public function __construct(private readonly ExpenseService $expenses) {}

    public function index(Request $request): Response
    {
        $filters = $this->filters($request);
        $query = $this->filtered($filters);

        return Inertia::render('admin/accounting/Expenses', [
            'expenses' => (clone $query)
                ->with(['category:id,name', 'costCenter:id,name', 'treasury:id,name', 'supplier:id,name', 'paymentMethod:id,name'])
                ->orderByDesc('expense_date')
                ->orderByDesc('id')
                ->paginate(20)
                ->withQueryString()
                ->through(fn (Expense $e) => $this->row($e)),
            'filters' => $filters,
            'stats' => $this->stats($filters),
            'byCategory' => $this->byCategory($filters),
            'categories' => $this->categories(),
            'accounts' => Account::postable()
                ->where('type', 'expense')
                ->where('is_active', true)
                ->orderBy('code')
                ->get(['id', 'code', 'name']),
            'costCenters' => CostCenter::with('unit:id,name')
                ->where('is_active', true)
                ->get()
                ->map(fn (CostCenter $c) => ['id' => $c->id, 'name' => $c->unit?->name ?? $c->name]),
            'treasuries' => Treasury::where('is_active', true)->get()->map(fn (Treasury $t) => [
                'id' => $t->id, 'name' => $t->name, 'balance' => $t->balance(),
            ]),
            'methods' => PaymentMethod::options(),
            'suppliers' => Supplier::where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'statuses' => collect(Expense::STATUSES)->map(fn ($l, $k) => ['key' => $k, 'label' => $l])->values(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);

        $expense = $this->expenses->create(
            collect($data)->except('post_now')->all(),
            $request->user()?->id,
        );

        // التسجيل والترحيل في خطوة واحدة هو الحالة الغالبة: مصروف اليوم
        // يُدفع نقدًا ويُقيَّد فورًا. والمسوّدة تبقى لمن يحتاج مراجعةً قبله.
        if ($request->boolean('post_now')) {
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
        if (! $expense->isDraft()) {
            return back()->with('warning', 'المصروف المرحَّل لا يُعدَّل — ألغِه وسجّله من جديد.');
        }

        $expense->update(collect($this->validated($request))->except('post_now')->all());

        return back()->with('success', 'تم تحديث المصروف');
    }

    public function post(Request $request, Expense $expense): RedirectResponse
    {
        try {
            $this->expenses->post($expense, $request->user()?->id);
        } catch (RuntimeException $e) {
            return back()->with('warning', $e->getMessage());
        }

        return back()->with('success', 'تم ترحيل المصروف إلى الدفاتر');
    }

    public function cancel(Request $request, Expense $expense): RedirectResponse
    {
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:500']]);

        $this->expenses->cancel($expense, $data['reason'] ?? null, $request->user()?->id);

        return back()->with('success', 'تم إلغاء المصروف وعكس قيده');
    }

    public function destroy(Expense $expense): RedirectResponse
    {
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
        $category = ExpenseCategory::create($this->validatedCategory($request));

        return back()->with('success', "تمت إضافة نوع المصروف «{$category->name}»");
    }

    public function updateCategory(Request $request, ExpenseCategory $category): RedirectResponse
    {
        $category->update($this->validatedCategory($request, $category));

        return back()->with('success', 'تم تحديث نوع المصروف');
    }

    /**
     * النوع المستعمل لا يُحذف: حذفه يُيتّم مصروفات سُجّلت عليه. يُعطَّل
     * فيختفي من نموذج التسجيل ويبقى في تقارير ما مضى.
     */
    public function destroyCategory(ExpenseCategory $category): RedirectResponse
    {
        if ($category->is_system) {
            return back()->with('warning', 'نوعٌ أساسي في النظام لا يُحذف — عطّله بدل حذفه.');
        }

        if ($category->expenses()->exists()) {
            return back()->with('warning', 'النوع مستعمل في مصروفات مسجّلة — عطّله بدل حذفه.');
        }

        $category->delete();

        return back()->with('success', 'تم حذف نوع المصروف');
    }

    public function toggleCategory(ExpenseCategory $category): RedirectResponse
    {
        $category->update(['is_active' => ! $category->is_active]);

        return back()->with('success', $category->is_active ? 'تم تفعيل النوع' : 'تم إيقاف النوع');
    }

    /**
     * تصدير المعروض بمرشّحاته.
     */
    public function export(Request $request): StreamedResponse
    {
        $filters = $this->filters($request);
        $filename = 'expenses-'.now()->format('Y-m-d-His').'.csv';

        return response()->streamDownload(function () use ($filters) {
            $out = fopen('php://output', 'w');

            // BOM حتى يفتح إكسل العربية بترميزها الصحيح بدل رموز مبهمة.
            fwrite($out, "\xEF\xBB\xBF");

            fputcsv($out, ['رقم المصروف', 'التاريخ', 'النوع', 'الوحدة', 'المورّد', 'الوصف', 'طريقة الدفع', 'الخزينة', 'المبلغ', 'الحالة']);

            $this->filtered($filters)
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
     * @return array<string, mixed>
     */
    private function filters(Request $request): array
    {
        return [
            'from' => $request->date('from')?->toDateString() ?? now()->startOfMonth()->toDateString(),
            'to' => $request->date('to')?->toDateString() ?? now()->toDateString(),
            'expense_category_id' => $request->integer('expense_category_id') ?: null,
            'cost_center_id' => $request->integer('cost_center_id') ?: null,
            'status' => $request->string('status')->toString() ?: null,
            'search' => $request->string('search')->toString() ?: null,
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Builder<Expense>
     */
    private function filtered(array $filters): Builder
    {
        return Expense::query()
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
    private function stats(array $filters): array
    {
        $posted = (clone $this->filtered($filters))->posted();

        return [
            // المرحَّل وحده مصروفٌ فعلي: المسوّدة نيّة، والملغى رُدَّ.
            'total' => round((float) (clone $posted)->sum('amount'), 2),
            'count' => (clone $posted)->count(),
            'drafts' => (clone $this->filtered($filters))->where('status', 'draft')->count(),
            'month' => round((float) Expense::query()
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
    private function byCategory(array $filters): array
    {
        $rows = (clone $this->filtered($filters))
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
            'cost_center_id' => ['nullable', 'exists:cost_centers,id'],
            'supplier_id' => ['nullable', 'exists:suppliers,id'],
            'payment_method_id' => ['required', Rule::exists('payment_methods', 'id')->where('is_active', true)],
            'reference' => ['nullable', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:1000'],
            'post_now' => ['boolean'],
        ], [
            'expense_category_id.required' => 'اختر نوع المصروف.',
            'expense_category_id.exists' => 'نوع المصروف غير موجود.',
        ]);
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
            'cost_center_id' => ['nullable', 'exists:cost_centers,id'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'is_active' => ['boolean'],
        ], [
            'account_id.exists' => 'الحساب المحاسبي يجب أن يكون من حسابات المصروفات.',
        ]);
    }
}
