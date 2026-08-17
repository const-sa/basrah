<?php

namespace App\Support\Reports;

use Illuminate\Support\Collection;

/**
 * دليل التقارير: ثلاث وعشرون تقريرًا كما نصّ عليها البند الثاني عشر من
 * العرض المعتمد — تقارير الحجوزات والتقارير المالية وتقارير الموظفين.
 *
 * المزوّدات ثلاثة بعدد المجموعات، والدليل يجمعها في قائمة واحدة تقرأها
 * الشاشة والمسارات والتصدير. فمن أراد تقريرًا جديدًا أضافه إلى مزوّده،
 * ولا يمسّ شاشةً ولا مسارًا.
 */
class ReportRegistry
{
    /**
     * @return Collection<string, ReportDefinition>
     */
    public static function all(): Collection
    {
        return collect([
            ...BookingReports::all(),
            ...FinanceReports::all(),
            ...StaffReports::all(),
        ])->keyBy(fn (ReportDefinition $report) => $report->key);
    }

    public static function find(string $key): ?ReportDefinition
    {
        return self::all()->get($key);
    }

    /**
     * التقارير مرتَّبةً في مجموعاتها — كما تُعرض في صفحة المركز.
     *
     * @return list<array{group: string, reports: list<array<string, mixed>>}>
     */
    public static function grouped(): array
    {
        return self::all()
            ->groupBy(fn (ReportDefinition $report) => $report->group)
            ->map(fn (Collection $reports, string $group) => [
                'group' => $group,
                'reports' => $reports->map(fn (ReportDefinition $r) => $r->meta())->values()->all(),
            ])
            ->values()
            ->all();
    }
}
