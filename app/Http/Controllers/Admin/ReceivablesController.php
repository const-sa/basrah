<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Services\Accounting\ClientStatementService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * ذمم العملاء: كشف حساب موحَّد لكل عميل عبر حجوزاته ومبيعاته وعقوده وسنداته.
 */
class ReceivablesController extends Controller
{
    public function __construct(private readonly ClientStatementService $statements) {}

    public function index(Request $request): Response
    {
        $search = $request->string('search')->toString();

        $clients = Client::query()
            ->where('is_walk_in', false)
            ->when($search !== '', fn ($q) => $q->where(
                fn ($sub) => $sub->where('name', 'like', "%{$search}%")
                    ->orWhere('mobile', 'like', "%{$search}%"),
            ))
            ->orderBy('name')
            ->paginate(30)
            ->withQueryString()
            ->through(fn (Client $client) => [
                'id' => $client->id,
                'name' => $client->name,
                'mobile' => $client->mobile,
                'type_label' => $client->typeLabel(),
                'outstanding' => $this->statements->outstandingFor($client),
            ]);

        return Inertia::render('admin/accounting/Receivables', [
            'clients' => $clients,
            'filters' => ['search' => $search ?: null],
            'totalOutstanding' => round(collect($clients->items())->sum('outstanding'), 2),
        ]);
    }

    public function show(Request $request, Client $client): Response
    {
        $from = $request->string('from')->toString() ?: null;
        $to = $request->string('to')->toString() ?: null;

        return Inertia::render('admin/accounting/ReceivableStatement', [
            'client' => ['id' => $client->id, 'name' => $client->name, 'mobile' => $client->mobile],
            'filters' => ['from' => $from, 'to' => $to],
            'statement' => $this->statements->statementFor($client, $from, $to),
            'security' => $this->statements->securityFor($client),
        ]);
    }

    public function export(Request $request, Client $client): StreamedResponse
    {
        $from = $request->string('from')->toString() ?: null;
        $to = $request->string('to')->toString() ?: null;

        $statement = $this->statements->statementFor($client, $from, $to);
        $filename = 'receivable-statement-'.$client->id.'-'.now()->format('Y-m-d-His').'.csv';

        return response()->streamDownload(function () use ($statement) {
            $out = fopen('php://output', 'w');

            // BOM حتى يفتح إكسل العربية بترميزها الصحيح بدل رموز مبهمة.
            fwrite($out, "\xEF\xBB\xBF");

            fputcsv($out, ['التاريخ', 'البيان', 'مدين', 'دائن', 'الرصيد']);
            fputcsv($out, ['', 'رصيد افتتاحي', '', '', $statement['opening_balance']]);

            foreach ($statement['rows'] as $row) {
                fputcsv($out, [$row['date'], $row['label'], $row['debit'] ?: '', $row['credit'] ?: '', $row['balance']]);
            }

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
