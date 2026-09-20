<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\PaymentMethod;
use App\Services\Accounting\AccountingSettingsRemap;
use App\Services\Accounting\PaymentMethodAccounts;
use App\Services\Accounting\RevenueAccounts;
use App\Support\ActivitySegment;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class AccountingSettingsController extends Controller
{
    public function __construct(
        private readonly RevenueAccounts $revenues,
        private readonly PaymentMethodAccounts $paymentAccounts,
    ) {}

    public function edit(): Response
    {
        $storedRevenue = $this->revenues->stored();
        $resolvedRevenue = $this->revenues->resolved();
        $storedDeposits = $this->revenues->storedDeposits();
        $resolvedDeposits = $this->revenues->resolvedDeposits();
        $storedPayments = $this->paymentAccounts->stored();

        $methods = PaymentMethod::query()
            ->active()
            ->ordered()
            ->get(['id', 'code', 'name', 'deposits_to'])
            ->map(fn (PaymentMethod $m) => [
                'id' => $m->id,
                'code' => $m->code,
                'name' => $m->name,
                'default_account_code' => $m->ledgerAccount(),
            ])
            ->values()
            ->all();

        $defaults = Account::whereIn('code', collect(RevenueAccounts::STREAMS)->pluck('default')->unique())
            ->pluck('name', 'code');

        $sections = collect(ActivitySegment::activities())
            ->map(function (string $section) use ($storedRevenue, $resolvedRevenue, $storedDeposits, $resolvedDeposits, $storedPayments, $defaults) {
                $stream = RevenueAccounts::SECTION_STREAMS[$section];
                $meta = RevenueAccounts::STREAMS[$stream];

                return [
                    'key' => $section,
                    'label' => ActivitySegment::label($section),
                    'stream' => $stream,
                    'hint' => $meta['hint'],
                    'account_id' => $storedRevenue[$stream] ?? null,
                    'effective_account_id' => $resolvedRevenue[$stream] ?? null,
                    'default_code' => $meta['default'],
                    'default_name' => $defaults[$meta['default']] ?? null,
                    'deposit_account_id' => $storedDeposits[$stream] ?? null,
                    'effective_deposit_account_id' => $resolvedDeposits[$stream] ?? null,
                    'payment_accounts' => $storedPayments[$section] ?? [],
                ];
            })
            ->values()
            ->all();

        return Inertia::render('admin/accounting/AccountingSettings', [
            'sections' => $sections,
            'accounts' => $this->revenueAccountOptions(),
            'deposit_accounts' => $this->depositAccountOptions(),
            'payment_methods' => $methods,
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $sectionKeys = ActivitySegment::activities();
        $methodIds = PaymentMethod::query()->pluck('id')->all();

        $data = $request->validate([
            'sections' => ['required', 'array'],
            'sections.*.key' => ['required', 'string', Rule::in($sectionKeys)],
            'sections.*.account_id' => ['nullable', Rule::in($this->revenues->postableIds())],
            'sections.*.deposit_account_id' => ['nullable', Rule::in($this->revenues->depositableIds())],
            'sections.*.payment_accounts' => ['array'],
            'sections.*.payment_accounts.*.payment_method_id' => ['required', Rule::in($methodIds)],
            'sections.*.payment_accounts.*.account_id' => ['nullable', Rule::in($this->paymentAccounts->depositableIds())],
        ], [
            'sections.*.account_id.in' => 'اختر حسابًا إيراديًا فعّالًا غير تجميعي.',
            'sections.*.deposit_account_id.in' => 'اختر حساب أصول فعّالًا غير تجميعي — صندوقًا أو حسابًا بنكيًا.',
            'sections.*.payment_accounts.*.account_id.in' => 'اختر حساب أصول فعّالًا غير تجميعي لكل طريقة دفع.',
        ]);

        $revenueMap = [];
        $paymentMap = [];

        foreach ($data['sections'] as $row) {
            $stream = RevenueAccounts::SECTION_STREAMS[$row['key']];

            $revenueValues = [];

            foreach (['account_id', 'deposit_account_id'] as $column) {
                if (array_key_exists($column, $row)) {
                    $revenueValues[$column] = isset($row[$column]) ? (int) $row[$column] : null;
                }
            }

            $revenueMap[$stream] = $revenueValues;

            $paymentMap[$row['key']] = collect($row['payment_accounts'] ?? [])
                ->mapWithKeys(fn (array $p) => [
                    (int) $p['payment_method_id'] => isset($p['account_id']) ? (int) $p['account_id'] : null,
                ])
                ->all();
        }

        $this->revenues->save($revenueMap);
        $this->paymentAccounts->save($paymentMap);

        return back()->with('success', 'تم حفظ إعدادات المحاسبة — تسري على ما يُرحَّل بعد الآن');
    }

    public function remap(AccountingSettingsRemap $remap): RedirectResponse
    {
        $count = $remap->remapAll();

        return back()->with('success', $count > 0
            ? "تم تعديل {$count} سطر قيد لتطابق الحسابات الحالية"
            : 'كل القيود مطابقة للحسابات الحالية بالفعل — لا حاجة لتعديل');
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function revenueAccountOptions(): array
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
     * @return list<array<string, mixed>>
     */
    private function depositAccountOptions(): array
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
