<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\CostCenter;
use App\Models\JournalEntry;
use App\Services\Accounting\RevenueAccounts;
use App\Services\Accounting\RevenueStatementService;
use App\Support\ActivitySegment;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * كشف حساب الإيراد — يُختار الإيراد من قائمة، فيُعرض حسابه سطرًا سطرًا.
 *
 * شاشة الإيرادات تعرض كل الحسابات مجتمعةً موزّعةً على النطاقات؛ وهذه تفتح
 * حسابًا واحدًا: ما كان عليه قبل الفترة، وكل حركة جرت عليه فيها، والرصيد بعد
 * كل حركة، وما استقرّ عليه في آخرها. هذا شكل الكشف الذي يُطابَق ويُسلَّم، ولا
 * تغني عنه شاشةُ مجاميع.
 *
 * والقائمة تُسمّي مصادر الدخل لا أكواد الحسابات: من يسأل عن «حجوزات القاعات»
 * لا يلزمه أن يعرف أنها ٤١١٠، والربط بينهما هو ما تحدّده شاشة إعدادات حسابات
 * الإيراد — فإن وُجِّه مصدران إلى حسابٍ واحد ظهرا معًا على اسمه.
 */
class RevenueStatementController extends Controller
{
    public function __construct(
        private readonly RevenueStatementService $statements,
        private readonly RevenueAccounts $revenues,
        private readonly ActivitySegment $segments,
    ) {}

    public function index(Request $request): Response
    {
        $account = $this->account($request);
        $filters = $this->filters($request, $account);

        return Inertia::render('admin/accounting/RevenueStatement', [
            'account' => $account ? [
                'id' => $account->id,
                'code' => $account->code,
                'name' => $account->name,
                'streams' => $this->streamLabels($account->id),
            ] : null,
            'accounts' => $this->accounts(),
            'filters' => $filters,
            'statement' => $account
                ? $this->statements->statementFor($account, $filters)
                : ['opening' => 0.0, 'rows' => [], 'total_debit' => 0.0, 'total_credit' => 0.0, 'net' => 0.0, 'closing' => 0.0],
            'byCenter' => $account ? $this->statements->byCenter($account, $filters) : [],
            'centers' => $this->centers(),
            'segments' => collect(ActivitySegment::ALL)->map(fn ($l, $k) => ['key' => $k, 'label' => $l])->values(),
            'sources' => collect(JournalEntry::SOURCES)->map(fn ($l, $k) => ['key' => $k, 'label' => $l])->values(),
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        $account = $this->account($request);
        $filters = $this->filters($request, $account);

        abort_if($account === null, 404, 'لا يوجد حساب إيراد لكشفه.');

        $statement = $this->statements->statementFor($account, $filters);
        $filename = 'revenue-statement-'.$account->code.'-'.now()->format('Y-m-d-His').'.csv';

        return response()->streamDownload(function () use ($account, $filters, $statement) {
            $out = fopen('php://output', 'w');

            // BOM حتى يفتح إكسل العربية بترميزها الصحيح بدل رموز مبهمة.
            fwrite($out, "\xEF\xBB\xBF");

            fputcsv($out, ['كشف حساب', $account->code.' — '.$account->name]);
            fputcsv($out, ['من', $filters['from'], 'إلى', $filters['to']]);
            fputcsv($out, []);

            fputcsv($out, ['التاريخ', 'رقم القيد', 'المصدر', 'النشاط', 'الوحدة / القسم', 'البيان', 'مدين', 'دائن', 'الرصيد']);
            fputcsv($out, ['', '', '', '', '', 'رصيد ما قبل الفترة', '', '', $statement['opening']]);

            foreach ($statement['rows'] as $row) {
                fputcsv($out, [
                    $row['date'],
                    $row['number'],
                    $row['source_label'],
                    $row['segment_label'],
                    $row['center'] ?? '—',
                    $row['label'] ?: '—',
                    $row['debit'] ?: '',
                    $row['credit'] ?: '',
                    $row['balance'],
                ]);
            }

            fputcsv($out, ['الإجمالي', '', '', '', '', '', $statement['total_debit'], $statement['total_credit'], '']);
            fputcsv($out, ['رصيد آخر الفترة', '', '', '', '', '', '', '', $statement['closing']]);

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * الحساب المعروض: المطلوب صراحةً، وإلا حساب أول مصادر الدخل.
     *
     * البدء بحسابٍ محدَّد لا بشاشةٍ فارغة: الكشف بلا حساب لا يقول شيئًا،
     * والحجوزات هي أكثر ما يُفتح في هذا النشاط.
     */
    private function account(Request $request): ?Account
    {
        $requested = $request->integer('account_id') ?: null;

        $query = Account::where('type', 'revenue')->postable();

        if ($requested) {
            $account = (clone $query)->find($requested);

            if ($account) {
                return $account;
            }
        }

        $stream = $request->string('stream')->toString();

        if (RevenueAccounts::isStream($stream)) {
            $account = (clone $query)->find($this->revenues->resolved()[$stream] ?? 0);

            if ($account) {
                return $account;
            }
        }

        $first = array_key_first(RevenueAccounts::STREAMS);

        return (clone $query)->find($this->revenues->resolved()[$first] ?? 0)
            ?? (clone $query)->orderBy('code')->first();
    }

    /**
     * @return array<string, mixed>
     */
    private function filters(Request $request, ?Account $account): array
    {
        $segment = $request->string('segment')->toString();

        return [
            'account_id' => $account?->id,
            'from' => $request->date('from')?->toDateString() ?? now()->startOfMonth()->toDateString(),
            'to' => $request->date('to')?->toDateString() ?? now()->toDateString(),
            'segment' => array_key_exists($segment, ActivitySegment::ALL) ? $segment : null,
            'cost_center_id' => $request->integer('cost_center_id') ?: null,
            'source' => $request->string('source')->toString() ?: null,
        ];
    }

    /**
     * قائمة «اختر الإيراد» — كل حساب إيرادي ومعه مصادر الدخل التي تهبط عليه.
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
            ->map(fn (Account $a) => [
                'id' => $a->id,
                'code' => $a->code,
                'name' => $a->name,
                'streams' => $this->streamLabels($a->id),
            ])
            ->all();
    }

    /**
     * @return list<string>
     */
    private function streamLabels(int $accountId): array
    {
        return array_map(
            fn (string $stream) => RevenueAccounts::label($stream),
            $this->revenues->streamsOn($accountId),
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function centers(): array
    {
        return CostCenter::with(['unit:id,name,type', 'section:id,name,unit_id', 'section.unit:id,name,type', 'department:id,name,code'])
            ->where('is_active', true)
            ->get()
            ->map(fn (CostCenter $c) => [
                'id' => $c->id,
                'name' => $this->segments->nameOf($c),
                'segment' => $this->segments->of($c->id),
            ])
            ->sortBy('name')
            ->values()
            ->all();
    }
}
