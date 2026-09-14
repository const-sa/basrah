<script setup lang="ts">
import { StatPill } from '@/components/data-table';
import { usePermissions } from '@/composables/usePermissions';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, router } from '@inertiajs/vue3';
import { Download } from 'lucide-vue-next';
import { ref } from 'vue';

interface StatementRow {
    date: string | null;
    entry_number: string | null;
    source_label: string | null;
    label: string;
    account: string;
    account_type: string | null;
    debit: number;
    credit: number;
}

interface BreakdownRow {
    code: string;
    name: string;
    type: string;
    amount: number;
}

const props = defineProps<{
    center: { id: number; code: string; name: string; type_label: string; is_active: boolean };
    filters: { from: string; to: string };
    statement: { rows: StatementRow[]; total_debit: number; total_credit: number; revenue: number; expense: number; profit: number };
    breakdown: BreakdownRow[];
}>();

const { can } = usePermissions();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'لوحة التحكم', href: '/admin' },
    { title: 'مراكز التكلفة', href: '/admin/accounting/cost-centers' },
    { title: props.center.name, href: `/admin/accounting/cost-centers/${props.center.id}` },
];

const money = (n: number) => new Intl.NumberFormat('ar-SA-u-nu-latn', { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(n ?? 0);

const from = ref(props.filters.from);
const to = ref(props.filters.to);
const apply = () =>
    router.get(`/admin/accounting/cost-centers/${props.center.id}`, { from: from.value, to: to.value }, { preserveState: true, replace: true });

const exportCsv = () => {
    const params = new URLSearchParams({ from: from.value, to: to.value });
    window.location.href = `/admin/accounting/cost-centers/${props.center.id}/export?${params.toString()}`;
};
</script>

<template>
    <Head :title="`كشف مركز ${center.name}`" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="min-h-full space-y-4 bg-slate-100 p-5">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h1 class="text-2xl font-extrabold text-slate-900">{{ center.name }}</h1>
                    <p class="mt-1 text-sm font-medium text-slate-600">
                        <span dir="ltr">{{ center.code }}</span> — {{ center.type_label }}
                        <span v-if="!center.is_active" class="mr-1 rounded-md bg-slate-200 px-2 py-0.5 text-[11px] font-bold text-slate-600">موقوف</span>
                    </p>
                </div>
                <button v-if="can('cost_centers.export')" type="button" @click="exportCsv" class="inline-flex items-center gap-1.5 rounded-md bg-blue-600 px-4 py-2 text-sm font-bold text-white hover:bg-blue-700">
                    <Download class="h-4 w-4" /> تصدير CSV
                </button>
            </div>

            <div class="grid gap-3 sm:grid-cols-3">
                <StatPill label="الإيراد" :value="money(statement.revenue)" variant="success" />
                <StatPill label="المصروف" :value="money(statement.expense)" variant="danger" />
                <StatPill label="صافي الربح" :value="money(statement.profit)" :variant="statement.profit >= 0 ? 'info' : 'warning'" />
            </div>

            <div class="rounded-2xl border border-slate-200 bg-white p-3 shadow-sm">
                <div class="grid gap-2 sm:grid-cols-3">
                    <input v-model="from" type="date" class="rounded-xl border border-slate-200 px-3 py-2.5 text-sm" />
                    <input v-model="to" type="date" class="rounded-xl border border-slate-200 px-3 py-2.5 text-sm" />
                    <button type="button" @click="apply" class="rounded-xl bg-slate-800 px-4 py-2.5 text-sm font-bold text-white hover:bg-slate-900">تطبيق</button>
                </div>
            </div>

            <div v-if="breakdown.length" class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                <div class="border-b border-slate-100 px-4 py-3">
                    <h2 class="text-sm font-extrabold text-slate-800">من أين جاء الرقم — تفصيل على الحسابات</h2>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="bg-slate-100">
                            <tr>
                                <th class="px-4 py-3 text-right text-xs font-extrabold text-[#1e3a8a]">الحساب</th>
                                <th class="px-4 py-3 text-center text-xs font-extrabold text-[#1e3a8a]">النوع</th>
                                <th class="px-4 py-3 text-left text-xs font-extrabold text-[#1e3a8a]">المبلغ</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="row in breakdown" :key="row.code" class="border-t border-slate-100">
                                <td class="px-4 py-2.5 font-bold text-slate-700"><span dir="ltr">{{ row.code }}</span> — {{ row.name }}</td>
                                <td class="px-4 py-2.5 text-center">
                                    <span class="rounded-md px-2 py-0.5 text-[11px] font-bold" :class="row.type === 'revenue' ? 'bg-emerald-100 text-emerald-700' : 'bg-red-100 text-red-700'">{{ row.type === 'revenue' ? 'إيراد' : 'مصروف' }}</span>
                                </td>
                                <td class="px-4 py-2.5 text-left font-extrabold" :class="row.type === 'revenue' ? 'text-emerald-600' : 'text-red-600'" dir="ltr">{{ money(row.amount) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                <div class="border-b border-slate-100 px-4 py-3">
                    <h2 class="text-sm font-extrabold text-slate-800">حركة المركز — القيود المرحَّلة وحدها</h2>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="bg-slate-100">
                            <tr>
                                <th class="px-4 py-3 text-right text-xs font-extrabold text-[#1e3a8a]">التاريخ</th>
                                <th class="px-4 py-3 text-right text-xs font-extrabold text-[#1e3a8a]">القيد</th>
                                <th class="px-4 py-3 text-right text-xs font-extrabold text-[#1e3a8a]">الحساب</th>
                                <th class="px-4 py-3 text-right text-xs font-extrabold text-[#1e3a8a]">البيان</th>
                                <th class="px-4 py-3 text-left text-xs font-extrabold text-[#1e3a8a]">مدين</th>
                                <th class="px-4 py-3 text-left text-xs font-extrabold text-[#1e3a8a]">دائن</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="(row, i) in statement.rows" :key="i" class="border-t border-slate-100 hover:bg-slate-50">
                                <td class="px-4 py-3 text-right text-xs text-slate-600"><span dir="ltr">{{ row.date ?? '—' }}</span></td>
                                <td class="px-4 py-3 text-right text-xs font-bold text-slate-700">
                                    <span dir="ltr">{{ row.entry_number ?? '—' }}</span>
                                    <div class="text-[11px] font-medium text-slate-500">{{ row.source_label }}</div>
                                </td>
                                <td class="px-4 py-3 text-xs font-bold text-slate-600">{{ row.account }}</td>
                                <td class="px-4 py-3 text-slate-700">{{ row.label || '—' }}</td>
                                <td class="px-4 py-3 text-left text-red-600" dir="ltr">{{ row.debit ? money(row.debit) : '—' }}</td>
                                <td class="px-4 py-3 text-left text-emerald-600" dir="ltr">{{ row.credit ? money(row.credit) : '—' }}</td>
                            </tr>
                            <tr v-if="!statement.rows.length"><td colspan="6" class="px-4 py-10 text-center text-sm text-slate-500">لا حركات في هذه الفترة</td></tr>
                        </tbody>
                        <tfoot v-if="statement.rows.length">
                            <tr class="border-t-2 border-slate-200 bg-slate-50">
                                <td class="px-4 py-3 text-xs font-extrabold text-slate-700" colspan="4">الإجمالي</td>
                                <td class="px-4 py-3 text-left font-extrabold text-slate-900" dir="ltr">{{ money(statement.total_debit) }}</td>
                                <td class="px-4 py-3 text-left font-extrabold text-slate-900" dir="ltr">{{ money(statement.total_credit) }}</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
