<script setup lang="ts">
import { StatPill } from '@/components/data-table';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, router } from '@inertiajs/vue3';
import { Download } from 'lucide-vue-next';
import { ref } from 'vue';

interface StatementRow {
    date: string;
    type: string;
    label: string;
    debit: number;
    credit: number;
    balance: number;
}

interface SecurityRow {
    date: string;
    booking_reference: string | null;
    type: string;
    type_label: string;
    amount: number;
}

const props = defineProps<{
    client: { id: number; name: string; mobile: string | null };
    filters: { from: string | null; to: string | null };
    statement: { rows: StatementRow[]; opening_balance: number; total_debit: number; total_credit: number; closing_balance: number };
    security: SecurityRow[];
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'لوحة التحكم', href: '/admin' },
    { title: 'ذمم العملاء', href: '/admin/accounting/receivables' },
    { title: props.client.name, href: `/admin/accounting/receivables/${props.client.id}` },
];

const money = (n: number) => new Intl.NumberFormat('ar-SA-u-nu-latn', { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(n ?? 0);

const from = ref(props.filters.from ?? '');
const to = ref(props.filters.to ?? '');
const apply = () =>
    router.get(`/admin/accounting/receivables/${props.client.id}`, { from: from.value || null, to: to.value || null }, { preserveState: true, replace: true });

const exportUrl = () => {
    const params = new URLSearchParams();
    if (from.value) params.set('from', from.value);
    if (to.value) params.set('to', to.value);
    window.location.href = `/admin/accounting/receivables/${props.client.id}/export?${params.toString()}`;
};
</script>

<template>
    <Head :title="`كشف حساب ${client.name}`" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="min-h-full space-y-4 bg-slate-100 p-5">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h1 class="text-2xl font-extrabold text-slate-900">كشف حساب — {{ client.name }}</h1>
                    <p class="mt-1 text-sm font-medium text-slate-600" dir="ltr">{{ client.mobile }}</p>
                </div>
                <button type="button" @click="exportUrl" class="inline-flex items-center gap-1.5 rounded-md bg-blue-600 px-4 py-2 text-sm font-bold text-white hover:bg-blue-700">
                    <Download class="h-4 w-4" /> تصدير CSV
                </button>
            </div>

            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <StatPill label="رصيد افتتاحي" :value="money(statement.opening_balance)" />
                <StatPill label="إجمالي مدين" :value="money(statement.total_debit)" variant="danger" />
                <StatPill label="إجمالي دائن" :value="money(statement.total_credit)" variant="success" />
                <StatPill label="الرصيد الحالي" :value="money(statement.closing_balance)" :variant="statement.closing_balance > 0 ? 'danger' : 'success'" />
            </div>

            <div class="rounded-2xl border border-slate-200 bg-white p-3 shadow-sm">
                <div class="grid gap-2 sm:grid-cols-3">
                    <input v-model="from" type="date" class="rounded-xl border border-slate-200 px-3 py-2.5 text-sm" />
                    <input v-model="to" type="date" class="rounded-xl border border-slate-200 px-3 py-2.5 text-sm" />
                    <button type="button" @click="apply" class="rounded-xl bg-slate-800 px-4 py-2.5 text-sm font-bold text-white hover:bg-slate-900">تطبيق</button>
                </div>
            </div>

            <div class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="bg-slate-100">
                            <tr>
                                <th class="px-4 py-3 text-right text-xs font-extrabold text-[#1e3a8a]">التاريخ</th>
                                <th class="px-4 py-3 text-right text-xs font-extrabold text-[#1e3a8a]">البيان</th>
                                <th class="px-4 py-3 text-left text-xs font-extrabold text-[#1e3a8a]">مدين</th>
                                <th class="px-4 py-3 text-left text-xs font-extrabold text-[#1e3a8a]">دائن</th>
                                <th class="px-4 py-3 text-left text-xs font-extrabold text-[#1e3a8a]">الرصيد</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr class="border-t border-slate-100 bg-slate-50/60">
                                <td class="px-4 py-2.5 text-xs text-slate-500" colspan="4">رصيد افتتاحي</td>
                                <td class="px-4 py-2.5 text-left font-extrabold text-slate-700" dir="ltr">{{ money(statement.opening_balance) }}</td>
                            </tr>
                            <tr v-for="(row, i) in statement.rows" :key="i" class="border-t border-slate-100 hover:bg-slate-50">
                                <td class="px-4 py-3 text-xs text-slate-600" dir="ltr">{{ row.date }}</td>
                                <td class="px-4 py-3 font-bold text-slate-700">{{ row.label }}</td>
                                <td class="px-4 py-3 text-left text-red-600" dir="ltr">{{ row.debit ? money(row.debit) : '—' }}</td>
                                <td class="px-4 py-3 text-left text-emerald-600" dir="ltr">{{ row.credit ? money(row.credit) : '—' }}</td>
                                <td class="px-4 py-3 text-left font-extrabold text-slate-800" dir="ltr">{{ money(row.balance) }}</td>
                            </tr>
                            <tr v-if="!statement.rows.length"><td colspan="5" class="px-4 py-10 text-center text-sm text-slate-500">لا حركات في هذه الفترة</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <div v-if="security.length" class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                <div class="border-b border-slate-100 px-4 py-3">
                    <h2 class="text-sm font-extrabold text-slate-800">التأمينات (مبالغ بأمانة — خارج المديونية)</h2>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="bg-slate-100">
                            <tr>
                                <th class="px-4 py-3 text-right text-xs font-extrabold text-[#1e3a8a]">التاريخ</th>
                                <th class="px-4 py-3 text-right text-xs font-extrabold text-[#1e3a8a]">الحجز</th>
                                <th class="px-4 py-3 text-right text-xs font-extrabold text-[#1e3a8a]">الحركة</th>
                                <th class="px-4 py-3 text-left text-xs font-extrabold text-[#1e3a8a]">المبلغ</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="(row, i) in security" :key="i" class="border-t border-slate-100">
                                <td class="px-4 py-3 text-xs text-slate-600" dir="ltr">{{ row.date }}</td>
                                <td class="px-4 py-3 text-xs font-bold text-slate-700" dir="ltr">{{ row.booking_reference ?? '—' }}</td>
                                <td class="px-4 py-3 text-xs font-bold text-slate-700">{{ row.type_label }}</td>
                                <td class="px-4 py-3 text-left font-extrabold text-slate-800" dir="ltr">{{ money(row.amount) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
