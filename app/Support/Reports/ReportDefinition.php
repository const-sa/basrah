<?php

namespace App\Support\Reports;

use Closure;

/**
 * تعريف تقرير واحد: ما اسمه، وبأي مرشّحات يُقرأ، وأي أعمدة يعرض، ومن أين
 * تأتي صفوفه.
 *
 * التقرير هنا بيانٌ لا شاشة: شاشة واحدة عامة تعرض أي تقرير من تعريفه،
 * فإضافة تقرير سطرٌ في مزوّد لا ملفَّي واجهة ومسارَين. وهذا ما يجعل ثلاثة
 * وعشرين تقريرًا في العرض المعتمد قابلةً للتنفيذ دون ثلاث وعشرين شاشة.
 */
final class ReportDefinition
{
    /**
     * @param  list<string>  $filters  المرشّحات التي يفهمها هذا التقرير
     * @param  list<array{key: string, label: string, type?: string}>  $columns
     * @param  Closure(array<string, mixed>): array{rows: list<array<string, mixed>>, summary?: list<array<string, mixed>>}  $builder
     */
    public function __construct(
        public string $key,
        public string $label,
        public string $description,
        public string $group,
        public array $filters,
        public array $columns,
        public Closure $builder,
        /**
         * المدى الزمني الافتراضي حين يُفتح التقرير بلا مرشّح.
         *
         * تقرير سنوي يُفتح على شهرٍ واحد يعرض سطرًا واحدًا فيبدو معطّلًا،
         * فلكل تقرير مداه الذي يُقرأ فيه: month / year / years.
         */
        public string $defaultRange = 'month',
    ) {}

    /**
     * @param  array<string, mixed>  $filters
     * @return array{rows: list<array<string, mixed>>, summary: list<array<string, mixed>>}
     */
    public function run(array $filters): array
    {
        $result = ($this->builder)($filters);

        return [
            'rows' => $result['rows'] ?? [],
            'summary' => $result['summary'] ?? [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function meta(): array
    {
        return [
            'key' => $this->key,
            'label' => $this->label,
            'description' => $this->description,
            'group' => $this->group,
            'filters' => $this->filters,
            'default_range' => $this->defaultRange,
            'columns' => array_map(
                fn (array $c) => ['key' => $c['key'], 'label' => $c['label'], 'type' => $c['type'] ?? 'text'],
                $this->columns,
            ),
        ];
    }
}
