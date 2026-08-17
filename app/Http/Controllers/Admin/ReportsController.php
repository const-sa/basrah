<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\Department;
use App\Models\Unit;
use App\Support\Reports\ReportDefinition;
use App\Support\Reports\ReportRegistry;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * مركز التقارير (§12 من العرض المعتمد).
 *
 * شاشة واحدة تعرض أي تقرير من تعريفه في ReportRegistry: المرشّحات التي
 * يفهمها، والأعمدة التي يعرضها، والصفوف التي يبنيها. ثلاثة وعشرون تقريرًا
 * بشاشتين — صفحة مركز وصفحة عرض — لأن الاختلاف بينها في البيانات لا في
 * طريقة القراءة، وثلاث وعشرون شاشة متشابهة تتباعد بالصيانة حتى تتناقض.
 */
class ReportsController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('admin/reports/Index', [
            'groups' => ReportRegistry::grouped(),
        ]);
    }

    public function show(Request $request, string $report): Response
    {
        $definition = ReportRegistry::find($report);

        if (! $definition) {
            abort(404);
        }

        $filters = $this->filters($request, $definition);
        $result = $definition->run($filters);

        return Inertia::render('admin/reports/Show', [
            'report' => $definition->meta(),
            'filters' => $filters,
            'options' => $this->options($definition),
            'rows' => $result['rows'],
            'summary' => $result['summary'],
            // قائمة التقارير تُمرَّر ليُنتقل بينها دون العودة إلى المركز.
            'groups' => ReportRegistry::grouped(),
        ]);
    }

    /**
     * تصدير المعروض — بنفس مرشّحاته، لا التقرير كاملًا.
     */
    public function export(Request $request, string $report): StreamedResponse
    {
        $definition = ReportRegistry::find($report);

        if (! $definition) {
            abort(404);
        }

        $filters = $this->filters($request, $definition);
        $result = $definition->run($filters);
        $filename = $report.'-'.now()->format('Y-m-d-His').'.csv';

        return response()->streamDownload(function () use ($definition, $result, $filters) {
            $out = fopen('php://output', 'w');

            // BOM حتى يفتح إكسل العربية بترميزها الصحيح بدل رموز مبهمة.
            fwrite($out, "\xEF\xBB\xBF");

            fputcsv($out, [$definition->label]);

            if (in_array('range', $definition->filters, true)) {
                fputcsv($out, ['من', $filters['from'], 'إلى', $filters['to']]);
            }

            fputcsv($out, []);
            fputcsv($out, array_column($definition->columns, 'label'));

            foreach ($result['rows'] as $row) {
                fputcsv($out, array_map(
                    fn (array $column) => $row[$column['key']] ?? '',
                    $definition->columns,
                ));
            }

            fputcsv($out, []);

            foreach ($result['summary'] as $card) {
                fputcsv($out, [$card['label'], $card['value']]);
            }

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * المرشّحات بعد تطبيق افتراضات التقرير.
     *
     * @return array<string, mixed>
     */
    private function filters(Request $request, ReportDefinition $definition): array
    {
        [$from, $to] = $this->defaultRange($definition->defaultRange);

        return [
            'from' => $request->date('from')?->toDateString() ?? $from,
            'to' => $request->date('to')?->toDateString() ?? $to,
            'unit_id' => $request->integer('unit_id') ?: null,
            'department_id' => $request->integer('department_id') ?: null,
            'status' => $request->string('status')->toString() ?: null,
        ];
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function defaultRange(string $range): array
    {
        $to = now()->toDateString();

        $from = match ($range) {
            'quarter' => now()->subWeeks(12)->startOfWeek()->toDateString(),
            'year' => now()->startOfYear()->toDateString(),
            'years' => now()->subYears(4)->startOfYear()->toDateString(),
            default => now()->startOfMonth()->toDateString(),
        };

        return [$from, $to];
    }

    /**
     * خيارات المرشّحات التي يحتاجها هذا التقرير وحده — لا كلّها في كل شاشة.
     *
     * @return array<string, mixed>
     */
    private function options(ReportDefinition $definition): array
    {
        $options = [];

        if (in_array('unit', $definition->filters, true)) {
            $options['units'] = Unit::orderBy('name')->get(['id', 'name']);
        }

        if (in_array('department', $definition->filters, true)) {
            $options['departments'] = Department::orderBy('name')->get(['id', 'name']);
        }

        if (in_array('status', $definition->filters, true)) {
            $options['statuses'] = collect(Booking::STATUSES)
                ->map(fn (string $label, string $key) => ['key' => $key, 'label' => $label])
                ->values()
                ->all();
        }

        return $options;
    }
}
