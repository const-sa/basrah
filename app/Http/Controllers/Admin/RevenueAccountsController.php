<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Services\Accounting\RevenueAccounts;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * إعدادات حسابات الإيراد — أين يُسجَّل كل مصدر دخل في الدفاتر.
 *
 * شاشة صغيرة بأثر كبير: ما يُختار هنا هو الحساب الذي يُرحَّل عليه كل حجز
 * وكل فاتورة بعد الحفظ. ولذلك لا يمسّ الحفظ ما رُحِّل من قبل — القيد المرحَّل
 * لا يُعاد كتابته بأثر رجعي، وإلا اختلف كشفُ شهرٍ أُقفل وقُدِّم إقراره.
 *
 * ولكل مصدر طرفان: حساب الإيراد الذي يُقيَّد دائنًا، وحساب الأصول الذي يُودع
 * فيه المقبوض مدينًا. الثاني اختياري: فارغًا يبقى المقبوض تابعًا لطريقة الدفع
 * — نقدًا في الصندوق وما سواه في البنك — كما كان قبل هذه الشاشة.
 */
class RevenueAccountsController extends Controller
{
    public function __construct(private readonly RevenueAccounts $revenues) {}

    public function edit(): Response
    {
        $stored = $this->revenues->stored();
        $resolved = $this->revenues->resolved();
        $deposits = $this->revenues->storedDeposits();
        $resolvedDeposits = $this->revenues->resolvedDeposits();
        $accounts = $this->accounts();
        $defaults = Account::whereIn('code', collect(RevenueAccounts::STREAMS)->pluck('default')->unique())
            ->pluck('name', 'code');

        return Inertia::render('admin/accounting/RevenueAccounts', [
            'streams' => collect(RevenueAccounts::STREAMS)
                ->map(fn (array $meta, string $key) => [
                    'key' => $key,
                    'label' => $meta['label'],
                    'hint' => $meta['hint'],
                    'account_id' => $stored[$key] ?? null,
                    // ما يُرحَّل عليه فعلًا الآن — يختلف عن المختار متى حُذف
                    // الحساب أو أُوقف، فيعود المصدر إلى حسابه الافتراضي.
                    'effective_account_id' => $resolved[$key] ?? null,
                    'default_code' => $meta['default'],
                    'default_name' => $defaults[$meta['default']] ?? null,
                    'deposit_account_id' => $deposits[$key] ?? null,
                    // ما يُودع فيه فعلًا — فارغٌ يعني أن طريقة الدفع هي التي
                    // تقرّر، وهو ما يُعرض للمستخدم بهذه العبارة لا بخانة خالية.
                    'effective_deposit_account_id' => $resolvedDeposits[$key] ?? null,
                ])
                ->values()
                ->all(),
            'accounts' => $accounts,
            'deposit_accounts' => $this->depositAccounts(),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'streams' => ['required', 'array'],
            'streams.*.key' => ['required', 'string', Rule::in(array_keys(RevenueAccounts::STREAMS))],
            // الحساب التجميعي والموقوف وغير الإيرادي مرفوضون هنا لا عند
            // الترحيل: رفضُه وقت الحفظ رسالةٌ للمحاسب، ورفضُه وقت البيع عطل.
            //
            // والقائمة المقبولة هي عينها التي يقبلها المحلّل وقت الترحيل، لا
            // شروطٌ مكتوبة مرتين قد تفترقان فيُحفظ ما لا يُرحَّل عليه.
            'streams.*.account_id' => ['nullable', Rule::in($this->revenues->postableIds())],
            // الإيداع في حساب أصول: صندوقٍ أو بنك أو ما يفتحه المحاسب بجانبهما.
            // والخانة الفارغة اختيارٌ قائم بذاته — «بحسب طريقة الدفع».
            'streams.*.deposit_account_id' => ['nullable', Rule::in($this->revenues->depositableIds())],
        ], [
            'streams.*.account_id.in' => 'اختر حسابًا إيراديًا فعّالًا غير تجميعي.',
            'streams.*.deposit_account_id.in' => 'اختر حساب أصول فعّالًا غير تجميعي — صندوقًا أو حسابًا بنكيًا.',
        ]);

        $map = [];

        foreach ($data['streams'] as $row) {
            // ما لم يُرسَل لا يُكتب: الشاشة ترسل الطرفين، وطلبٌ يرسل أحدهما
            // وحده لا يجوز أن يمسح الآخر وهما في صفٍّ واحد.
            $values = [];

            foreach (['account_id', 'deposit_account_id'] as $column) {
                if (array_key_exists($column, $row)) {
                    $values[$column] = isset($row[$column]) ? (int) $row[$column] : null;
                }
            }

            $map[$row['key']] = $values;
        }

        $this->revenues->save($map);

        return back()->with('success', 'تم حفظ حسابات الإيراد — تسري على ما يُرحَّل بعد الآن');
    }

    /**
     * الحسابات التي يجوز أن يهبط عليها إيراد.
     *
     * @return list<array<string, mixed>>
     */
    private function accounts(): array
    {
        return Account::query()
            ->where('type', 'revenue')
            ->postable()
            ->orderBy('code')
            ->get(['id', 'code', 'name'])
            ->map(fn (Account $a) => ['id' => $a->id, 'code' => $a->code, 'name' => $a->name])
            ->all();
    }

    /**
     * الحسابات التي يجوز أن يُودع فيها مقبوض — كل حساب أصول قابل للترحيل.
     *
     * والقائمة تُعلِّم كلَّ حساب أهو من «النقدية وما في حكمها» (١١xx) أم من
     * أصولٍ أخرى، فتُعرض المجموعتان منفصلتين: الخزائن والبنوك أولًا لأنها
     * الجواب في كل الحالات تقريبًا، وما عداها مرئيٌّ لمن يحتاجه ولا يسبقه.
     *
     * @return list<array<string, mixed>>
     */
    private function depositAccounts(): array
    {
        return Account::query()
            ->where('type', 'asset')
            ->postable()
            ->orderBy('code')
            ->get(['id', 'code', 'name'])
            ->map(fn (Account $a) => [
                'id' => $a->id,
                'code' => $a->code,
                'name' => $a->name,
                'is_cash_family' => str_starts_with((string) $a->code, '11'),
            ])
            ->all();
    }
}
