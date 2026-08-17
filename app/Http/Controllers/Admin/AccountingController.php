<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\CostCenter;
use App\Models\JournalEntry;
use App\Models\Supplier;
use App\Models\Treasury;
use App\Models\Voucher;
use App\Services\Accounting\Ledger;
use App\Services\Accounting\VoucherService;
use App\Models\Client;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

class AccountingController extends Controller
{
    public function __construct(
        private readonly Ledger $ledger,
        private readonly VoucherService $vouchers,
    ) {}

    /**
     * شجرة الحسابات بأرصدتها.
     */
    public function accounts(): Response
    {
        $accounts = Account::orderBy('code')->get();

        return Inertia::render('admin/accounting/Accounts', [
            'accounts' => $accounts->map(fn (Account $a) => [
                'id' => $a->id,
                'code' => $a->code,
                'name' => $a->name,
                'parent_id' => $a->parent_id,
                'type' => $a->type,
                'type_label' => $a->typeLabel(),
                'is_group' => $a->is_group,
                'is_active' => $a->is_active,
                'balance' => $a->is_group ? null : $a->balance(),
            ]),
            'types' => collect(Account::TYPES)->map(fn ($l, $k) => ['key' => $k, 'label' => $l])->values(),
        ]);
    }

    public function storeAccount(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:20', 'unique:accounts,code'],
            'name' => ['required', 'string', 'max:255'],
            'parent_id' => ['nullable', 'exists:accounts,id'],
            'type' => ['required', Rule::in(array_keys(Account::TYPES))],
            'is_group' => ['boolean'],
            'opening_balance' => ['nullable', 'numeric'],
        ]);

        Account::create([...$data, 'is_active' => true]);

        return back()->with('success', 'تم إضافة الحساب');
    }

    public function updateAccount(Request $request, Account $account): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'is_active' => ['boolean'],
            'opening_balance' => ['nullable', 'numeric'],
        ]);

        $account->update($data);

        return back()->with('success', 'تم تحديث الحساب');
    }

    public function destroyAccount(Account $account): RedirectResponse
    {
        if ($account->lines()->exists()) {
            return back()->with('warning', 'لا يُحذف حساب عليه قيود — عطّله بدل حذفه.');
        }

        if ($account->children()->exists()) {
            return back()->with('warning', 'لا يُحذف حساب له حسابات فرعية.');
        }

        $account->delete();

        return back()->with('success', 'تم حذف الحساب');
    }

    /**
     * دفتر اليومية.
     */
    public function journal(Request $request): Response
    {
        $query = JournalEntry::with('lines.account:id,code,name')
            ->when($request->string('status')->toString(), fn ($q, $s) => $q->where('status', $s))
            ->when($request->string('source')->toString(), fn ($q, $s) => $q->where('source', $s))
            ->when($request->string('from')->toString(), fn ($q, $d) => $q->whereDate('entry_date', '>=', $d))
            ->when($request->string('to')->toString(), fn ($q, $d) => $q->whereDate('entry_date', '<=', $d));

        return Inertia::render('admin/accounting/Journal', [
            'entries' => (clone $query)->latest('entry_date')->latest('id')->paginate(20)->withQueryString()
                ->through(fn (JournalEntry $e) => [
                    'id' => $e->id,
                    'number' => $e->number,
                    'entry_date' => $e->entry_date->toDateString(),
                    'description' => $e->description,
                    'status' => $e->status,
                    'status_label' => $e->statusLabel(),
                    'source' => $e->source,
                    'source_label' => $e->sourceLabel(),
                    'is_system' => $e->isSystemGenerated(),
                    'total_debit' => (float) $e->total_debit,
                    'total_credit' => (float) $e->total_credit,
                    'lines' => $e->lines->map(fn ($l) => [
                        'account' => $l->account?->code.' — '.$l->account?->name,
                        'debit' => (float) $l->debit,
                        'credit' => (float) $l->credit,
                        'description' => $l->description,
                    ])->values(),
                ]),
            'filters' => $request->only(['status', 'source', 'from', 'to']),
            'statuses' => collect(JournalEntry::STATUSES)->map(fn ($l, $k) => ['key' => $k, 'label' => $l])->values(),
            'sources' => collect(JournalEntry::SOURCES)->map(fn ($l, $k) => ['key' => $k, 'label' => $l])->values(),
            'accounts' => Account::postable()->orderBy('code')->get(['id', 'code', 'name']),
            'costCenters' => CostCenter::where('is_active', true)->get(['id', 'code', 'name']),
        ]);
    }

    public function storeEntry(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'entry_date' => ['required', 'date'],
            'description' => ['required', 'string', 'max:1000'],
            'lines' => ['required', 'array', 'min:2'],
            'lines.*.account_id' => ['required', 'exists:accounts,id'],
            'lines.*.cost_center_id' => ['nullable', 'exists:cost_centers,id'],
            'lines.*.debit' => ['nullable', 'numeric', 'min:0'],
            'lines.*.credit' => ['nullable', 'numeric', 'min:0'],
            'lines.*.description' => ['nullable', 'string', 'max:255'],
        ]);

        try {
            $this->ledger->post(
                $data['entry_date'],
                $data['description'],
                array_map(fn ($l) => [
                    'account' => (int) $l['account_id'],
                    'debit' => (float) ($l['debit'] ?? 0),
                    'credit' => (float) ($l['credit'] ?? 0),
                    'cost_center_id' => $l['cost_center_id'] ?? null,
                    'description' => $l['description'] ?? null,
                ], $data['lines']),
                'manual',
                null,
                $request->user()?->id,
            );
        } catch (RuntimeException $e) {
            return back()->with('warning', $e->getMessage());
        }

        return back()->with('success', 'تم ترحيل القيد');
    }

    public function reverseEntry(Request $request, JournalEntry $entry): RedirectResponse
    {
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:500']]);

        try {
            $this->ledger->reverse($entry, $data['reason'] ?? null, $request->user()?->id);
        } catch (RuntimeException $e) {
            return back()->with('warning', $e->getMessage());
        }

        return back()->with('success', 'تم عكس القيد بقيد مضاد');
    }

    /**
     * السندات والخزائن.
     */
    public function vouchers(Request $request): Response
    {
        $query = Voucher::with(['treasury:id,name', 'account:id,code,name', 'client:id,name', 'supplier:id,name'])
            ->when($request->string('type')->toString(), fn ($q, $t) => $q->where('type', $t))
            ->when($request->string('status')->toString(), fn ($q, $s) => $q->where('status', $s));

        return Inertia::render('admin/accounting/Vouchers', [
            'vouchers' => (clone $query)->latest('id')->paginate(20)->withQueryString()
                ->through(fn (Voucher $v) => [
                    'id' => $v->id,
                    'number' => $v->number,
                    'type' => $v->type,
                    'type_label' => $v->typeLabel(),
                    'voucher_date' => $v->voucher_date->toDateString(),
                    'amount' => (float) $v->amount,
                    'treasury' => $v->treasury?->name,
                    'account' => $v->account?->name,
                    'party' => $v->client?->name ?? $v->supplier?->name,
                    'description' => $v->description,
                    'status' => $v->status,
                    'status_label' => $v->statusLabel(),
                ]),
            'filters' => $request->only(['type', 'status']),
            'types' => collect(Voucher::TYPES)->map(fn ($l, $k) => ['key' => $k, 'label' => $l])->values(),
            'methods' => collect(Voucher::METHODS)->map(fn ($l, $k) => ['key' => $k, 'label' => $l])->values(),
            'treasuries' => Treasury::where('is_active', true)->get()->map(fn (Treasury $t) => [
                'id' => $t->id, 'name' => $t->name, 'type_label' => $t->typeLabel(), 'balance' => $t->balance(),
            ]),
            'accounts' => Account::postable()->orderBy('code')->get(['id', 'code', 'name', 'type']),
            'costCenters' => CostCenter::where('is_active', true)->get(['id', 'code', 'name']),
            'clients' => Client::orderBy('name')->limit(300)->get(['id', 'name']),
            'suppliers' => Supplier::where('is_active', true)->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function storeVoucher(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'type' => ['required', Rule::in(array_keys(Voucher::TYPES))],
            'voucher_date' => ['required', 'date'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'treasury_id' => ['required', 'exists:treasuries,id'],
            'account_id' => ['required', 'exists:accounts,id'],
            'cost_center_id' => ['nullable', 'exists:cost_centers,id'],
            'client_id' => ['nullable', 'exists:clients,id'],
            'supplier_id' => ['nullable', 'exists:suppliers,id'],
            'method' => ['required', Rule::in(array_keys(Voucher::METHODS))],
            'reference' => ['nullable', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:1000'],
            'post_now' => ['boolean'],
        ]);

        $voucher = $this->vouchers->create(
            collect($data)->except('post_now')->all(),
            $request->user()?->id,
        );

        if ($request->boolean('post_now')) {
            try {
                $this->vouchers->post($voucher, $request->user()?->id);
            } catch (RuntimeException $e) {
                return back()->with('warning', $e->getMessage());
            }
        }

        return back()->with('success', "تم حفظ السند {$voucher->number}");
    }

    public function postVoucher(Request $request, Voucher $voucher): RedirectResponse
    {
        try {
            $this->vouchers->post($voucher, $request->user()?->id);
        } catch (RuntimeException $e) {
            return back()->with('warning', $e->getMessage());
        }

        return back()->with('success', 'تم ترحيل السند');
    }

    public function cancelVoucher(Request $request, Voucher $voucher): RedirectResponse
    {
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:500']]);

        $this->vouchers->cancel($voucher, $data['reason'] ?? null, $request->user()?->id);

        return back()->with('success', 'تم إلغاء السند');
    }
}
