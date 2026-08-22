<script setup lang="ts">
import { usePermissions } from '@/composables/usePermissions';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/vue3';
import { Download, FileBarChart2 } from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';

interface Column {
    key: string;
    label: string;
    type: string;
}

interface ReportMeta {
    key: string;
    label: string;
    description: string;
    group: string;
    filters: string[];
    columns: Column[];
}

type Row = Record<string, string | number | null>;

const props = defineProps<{
    report: ReportMeta;
    filters: Record<string, string | number | null>;
    options: {
        units?: { id: number; name: string }[];
        departments?: { id: number; name: string }[];
        statuses?: { key: string; label: string }[];
    };
    rows: Row[];
    summary: { label: string; value: number; type: string }[];
    groups: { group: string; reports: ReportMeta[] }[];
}>();

const { can } = usePermissions();

const breadcrumbs = computed<BreadcrumbItem[]>(() => [
    { title: 'لوحة التحكم', href: '/admin' },
    { title: 'التقارير', href: '/admin/reports' },
    { title: props.report.label, href: `/admin/reports/${props.report.key}` },
]);

const form = ref({
    from: (props.filters.from as string) ?? '',
    to: (props.filters.to as string) ?? '',
    unit_id: props.filters.unit_id ? String(props.filters.unit_id) : '',
    department_id: props.filters.department_id ? String(props.filters.department_id) : '',
    status: (props.filters.status as string) ?? '',
});

const has = (filter: string) => props.report.filters.includes(filter);

// المرشّح يُطبَّق فورًا — التقرير يُقرأ بتغيير مدّته أكثر مما يُقرأ مرة واحدة.
let timer: ReturnType<typeof setTimeout> | undefined;
watch(
    form,
    (value) => {
        clearTimeout(timer);
        timer = setTimeout(() => {
            router.get(`/admin/reports/${props.report.key}`, { ...value }, { preserveState: true, replace: true, preserveScroll: true });
        }, 300);
    },
    { deep: true },
);

const exportUrl = computed(() => {
    const params = new URLSearchParams(Object.entries(form.value).filter(([, v]) => v !== '' && v !== null) as [string, string][]);
    return `/admin/reports/${props.report.key}/export?${params.toString()}`;
});

const money = new Intl.NumberFormat('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const plain = new Intl.NumberFormat('en-US', { maximumFractionDigits: 2 });

const cell = (row: Row, column: Column): string => {
    const value = row[column.key];

    if (value === null || value === undefined || value === '') return '—';
    if (column.type === 'currency') return money.format(Number(value));
    if (column.type === 'number') return plain.format(Number(value));

    return String(value);
};

const summaryValue = (card: { value: number; type: string }): string =>
    card.type === 'currency' ? money.format(Number(card.value)) : plain.format(Number(card.value));

// السالب يُلوَّن: خسارةٌ بلون الربح تمرّ على العين دون أن تُقرأ.
const cellTone = (row: Row, column: Column): string => {
    if (column.type !== 'currency' && column.type !== 'number') return 'text-slate-700';

    return Number(row[column.key]) < 0 ? 'text-red-600' : 'text-slate-700';
};

const numeric = (type: string) => type === 'currency' || type === 'number';
</script>

<template>
    <Head :title="report.label" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="min-h-full space-y-4 bg-slate-100 p-5">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h1 class="flex items-center gap-2 text-2xl font-extrabold text-slate-900">
                        <FileBarChart2 class="h-6 w-6 text-slate-700" /> {{ report.label }}
                    </h1>
                    <p class="mt-1 text-sm font-medium text-slate-600">{{ report.description }}</p>
                </div>
                <a
                    v-if="can('reports.export')"
                    :href="exportUrl"
                    class="inline-flex items-center gap-1.5 rounded-md bg-slate-900 px-4 py-2 text-sm font-bold text-white hover:bg-slate-800"
                >
                    <Download class="h-4 w-4" /> تصدير المعروض
                </a>
            </div>

            <!-- التنقل بين التقارير دون العودة إلى المركز -->
            <div class="flex flex-wrap gap-1.5">
                <template v-for="group in groups" :key="group.group">
                    <Link
                        v-for="item in group.reports"
                        :key="item.key"
                        :href="`/admin/reports/${item.key}`"
                        class="rounded-lg px-2.5 py-1.5 text-[11px] font-bold"
                        :class="
                            item.key === report.key ? 'bg-slate-900 text-white' : 'bg-white text-slate-600 ring-1 ring-slate-200 hover:bg-slate-50'
                        "
                    >
                        {{ item.label }}
                    </Link>
                </template>
            </div>

            <!-- المؤشرات -->
            <div v-if="summary.length" class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <div v-for="card in summary" :key="card.label" class="rounded-2xl border border-slate-200 bg-white p-4">
                    <div class="text-xs font-bold text-slate-500">{{ card.label }}</div>
                    <div class="mt-1 text-2xl font-extrabold" :class="card.value < 0 ? 'text-red-600' : 'text-slate-900'" dir="ltr">
                        {{ summaryValue(card) }}
                    </div>
                </div>
            </div>

            <!-- المرشّحات -->
            <div v-if="report.filters.length" class="flex flex-wrap items-center gap-2 rounded-2xl border border-slate-200 bg-white p-3">
                <template v-if="has('range')">
                    <label class="text-xs font-bold text-slate-500">من</label>
                    <input v-model="form.from" type="date" class="rounded-xl border border-slate-200 px-2 py-2 text-xs" />
                    <label class="text-xs font-bold text-slate-500">إلى</label>
                    <input v-model="form.to" type="date" class="rounded-xl border border-slate-200 px-2 py-2 text-xs" />
                </template>

                <select v-if="has('unit')" v-model="form.unit_id" class="rounded-xl border border-slate-200 px-3 py-2 text-sm">
                    <option value="">كل الوحدات</option>
                    <option v-for="u in options.units ?? []" :key="u.id" :value="String(u.id)">{{ u.name }}</option>
                </select>

                <select v-if="has('department')" v-model="form.department_id" class="rounded-xl border border-slate-200 px-3 py-2 text-sm">
                    <option value="">كل الأقسام</option>
                    <option v-for="d in options.departments ?? []" :key="d.id" :value="String(d.id)">{{ d.name }}</option>
                </select>

                <select v-if="has('status')" v-model="form.status" class="rounded-xl border border-slate-200 px-3 py-2 text-sm">
                    <option value="">كل الحالات</option>
                    <option v-for="s in options.statuses ?? []" :key="s.key" :value="s.key">{{ s.label }}</option>
                </select>
            </div>

            <!-- الجدول -->
            <div class="overflow-x-auto rounded-2xl border border-slate-200 bg-white shadow-sm">
                <table class="w-full text-sm">
                    <thead class="bg-slate-100">
                        <tr>
                            <th
                                v-for="column in report.columns"
                                :key="column.key"
                                class="whitespace-nowrap px-4 py-3 text-xs font-extrabold text-[#1e3a8a]"
                                :class="numeric(column.type) ? 'text-center' : 'text-right'"
                            >
                                {{ column.label }}
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="(row, index) in rows" :key="index" class="border-t border-slate-100 hover:bg-slate-50">
                            <td
                                v-for="column in report.columns"
                                :key="column.key"
                                class="whitespace-nowrap px-4 py-2.5"
                                :class="[numeric(column.type) ? 'text-center font-bold' : 'text-right', cellTone(row, column)]"
                                :dir="numeric(column.type) || column.type === 'date' ? 'ltr' : undefined"
                            >
                                <span
                                    v-if="column.type === 'badge'"
                                    class="inline-flex rounded-md bg-slate-100 px-2 py-0.5 text-[11px] font-bold text-slate-700"
                                >
                                    {{ cell(row, column) }}
                                </span>
                                <template v-else>{{ cell(row, column) }}</template>
                            </td>
                        </tr>
                        <tr v-if="!rows.length">
                            <td :colspan="report.columns.length" class="px-4 py-12 text-center text-sm text-slate-500">لا بيانات في هذه المدة</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </AppLayout>
</template>
