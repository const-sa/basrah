<script setup lang="ts">
import { StatPill } from '@/components/data-table';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, router } from '@inertiajs/vue3';
import { AlertTriangle, ArrowLeftRight, CheckCircle2, Download } from 'lucide-vue-next';
import { computed, ref } from 'vue';

interface Row { code: string; name: string; type?: string; type_label?: string; debit?: number; credit?: number; balance?: number; amount?: number }
interface UnitProfit { id: number; code: string; name: string; unit_type: string | null; revenue: number; expense: number; profit: number; margin: number }
interface VatMonth { month: string; output_net: number; output_tax: number; input_net: number; input_tax: number; net_due: number }
interface FlowRow { code: string; name: string; amount: number }
interface FlowSection { key: string; label: string; inflows: FlowRow[]; outflows: FlowRow[]; net: number }
interface FlowSource { key: string; label: string; inflow: number; outflow: number }
interface CashAccount { id: number; code: string; name: string; treasuries: string[]; opening: number; inflow: number; outflow: number; closing: number }
interface CashMovement { date: string; entry_number: string; description: string; source_label: string; account_id: number; account: string; inflow: number; outflow: number }

const props = defineProps<{
    filters: { from: string; to: string };
    trialBalance: { rows: Row[]; total_debit: number; total_credit: number; balanced: boolean };
    incomeStatement: { revenue: Row[]; expense: Row[]; total_revenue: number; total_expense: number; net_income: number };
    balanceSheet: {
        assets: Row[]; liabilities: Row[]; equity: Row[];
        total_assets: number; total_liabilities: number; total_equity: number;
        retained_earnings: number; balanced: boolean;
    };
    unitProfitability: UnitProfit[];
    vatReturn: {
        rate: number;
        output: { taxable_amount: number; tax: number };
        input: { taxable_amount: number; tax: number };
        net_due: number;
        months: VatMonth[];
    };
    cashFlow: {
        sections: FlowSection[];
        sources: FlowSource[];
        accounts: CashAccount[];
        movements: CashMovement[];
        movements_truncated: boolean;
        transfers: number;
        totals: { opening: number; inflow: number; outflow: number; net: number; closing: number };
    };
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'لوحة التحكم', href: '/admin' },
    { title: 'التقارير المالية', href: '/admin/accounting/reports' },
];

const money = (n: number) => new Intl.NumberFormat('ar-SA-u-nu-latn', { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(n ?? 0);

const filters = ref({ ...props.filters });
const apply = () => router.get('/admin/accounting/reports', filters.value, { preserveState: true, replace: true });

type Tab = 'profitability' | 'income' | 'trial' | 'balance' | 'cashflow' | 'vat';

const tabs = [
    { key: 'profitability', label: 'ربحية الوحدات' },
    { key: 'income', label: 'قائمة الدخل' },
    { key: 'trial', label: 'ميزان المراجعة' },
    { key: 'balance', label: 'الميزانية' },
    { key: 'cashflow', label: 'التدفقات النقدية' },
    { key: 'vat', label: 'الإقرار الضريبي' },
] as const;

// A link may open a given tab directly (?tab=cashflow).
const requested = typeof window === 'undefined' ? null : new URLSearchParams(window.location.search).get('tab');
const tab = ref<Tab>(tabs.some((t) => t.key === requested) ? (requested as Tab) : 'profitability');

// Movements narrowed to one treasury account; empty means all of them.
const movementAccount = ref<number | ''>('');
const movements = computed(() =>
    movementAccount.value === '' ? props.cashFlow.movements : props.cashFlow.movements.filter((m) => m.account_id === movementAccount.value),
);

const exportCashFlow = () => {
    const params = new URLSearchParams({ from: filters.value.from, to: filters.value.to });
    window.location.href = `/admin/accounting/reports/cash-flow/export?${params.toString()}`;
};

const exportVat = () => {
    const params = new URLSearchParams({ from: filters.value.from, to: filters.value.to });
    window.location.href = `/admin/accounting/reports/vat/export?${params.toString()}`;
};
</script>

<template>
    <Head title="التقارير المالية" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="min-h-full space-y-4 bg-slate-100 p-5">
            <div>
                <h1 class="text-2xl font-extrabold text-slate-900">التقارير المالية</h1>
                <p class="mt-1 text-sm font-medium text-slate-600">من القيود المرحَّلة فقط — المسوّدات لا تدخل التقارير</p>
            </div>

            <div class="flex flex-wrap items-center gap-2 rounded-2xl border border-slate-200 bg-white p-3 shadow-sm">
                <label class="text-sm font-bold text-slate-700">من</label>
                <input v-model="filters.from" @change="apply" type="date" class="rounded-xl border border-slate-200 px-3 py-2 text-sm" />
                <label class="text-sm font-bold text-slate-700">إلى</label>
                <input v-model="filters.to" @change="apply" type="date" class="rounded-xl border border-slate-200 px-3 py-2 text-sm" />
            </div>

            <div class="flex flex-wrap gap-1.5">
                <button
                    v-for="t in tabs" :key="t.key" type="button" @click="tab = t.key"
                    class="rounded-xl px-4 py-2 text-sm font-bold transition"
                    :class="tab === t.key ? 'bg-blue-600 text-white shadow-sm' : 'bg-white text-slate-600 ring-1 ring-slate-200 hover:bg-slate-50'"
                >{{ t.label }}</button>
            </div>

            <!-- ربحية الوحدات -->
            <div v-if="tab === 'profitability'" class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                <div class="border-b border-slate-100 px-4 py-3">
                    <h2 class="font-extrabold text-slate-800">ربحية كل وحدة</h2>
                    <p class="text-xs font-medium text-slate-500">مركز تكلفة مستقل لكل قاعة وشاليه وللمحل</p>
                </div>
                <table class="w-full text-sm">
                    <thead class="bg-slate-50">
                        <tr>
                            <th class="px-4 py-2.5 text-right text-xs font-extrabold text-[#1e3a8a]">مركز التكلفة</th>
                            <th class="px-4 py-2.5 text-left text-xs font-extrabold text-[#1e3a8a]">الإيراد</th>
                            <th class="px-4 py-2.5 text-left text-xs font-extrabold text-[#1e3a8a]">المصروف</th>
                            <th class="px-4 py-2.5 text-left text-xs font-extrabold text-[#1e3a8a]">الربح</th>
                            <th class="px-4 py-2.5 text-center text-xs font-extrabold text-[#1e3a8a]">الهامش</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="u in unitProfitability" :key="u.id" class="border-t border-slate-100 hover:bg-slate-50">
                            <td class="px-4 py-2.5">
                                <span class="font-bold text-slate-800">{{ u.name }}</span>
                                <span v-if="u.unit_type" class="ms-1.5 rounded bg-slate-100 px-1.5 py-0.5 text-[10px] font-bold text-slate-600">
                                    {{ u.unit_type === 'hall' ? 'قاعة' : 'شاليه' }}
                                </span>
                            </td>
                            <td class="px-4 py-2.5 text-left font-bold text-emerald-600" dir="ltr">{{ money(u.revenue) }}</td>
                            <td class="px-4 py-2.5 text-left font-bold text-red-600" dir="ltr">{{ money(u.expense) }}</td>
                            <td class="px-4 py-2.5 text-left font-extrabold" :class="u.profit >= 0 ? 'text-slate-900' : 'text-red-600'" dir="ltr">{{ money(u.profit) }}</td>
                            <td class="px-4 py-2.5 text-center">
                                <span class="rounded-md px-2 py-0.5 text-[11px] font-bold" :class="u.margin >= 0 ? 'bg-emerald-100 text-emerald-700' : 'bg-red-100 text-red-700'" dir="ltr">
                                    {{ u.margin }}%
                                </span>
                            </td>
                        </tr>
                        <tr v-if="!unitProfitability.length"><td colspan="5" class="px-4 py-10 text-center text-sm text-slate-500">لا بيانات</td></tr>
                    </tbody>
                </table>
            </div>

            <!-- قائمة الدخل -->
            <div v-if="tab === 'income'" class="grid gap-4 lg:grid-cols-2">
                <div class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                    <h2 class="border-b border-slate-100 bg-emerald-50 px-4 py-3 font-extrabold text-emerald-800">الإيرادات</h2>
                    <table class="w-full text-sm">
                        <tbody>
                            <tr v-for="r in incomeStatement.revenue" :key="r.code" class="border-b border-slate-50">
                                <td class="px-4 py-2 text-slate-700"><span class="font-mono text-[11px] text-slate-400" dir="ltr">{{ r.code }}</span> {{ r.name }}</td>
                                <td class="px-4 py-2 text-left font-bold text-slate-800" dir="ltr">{{ money(r.amount!) }}</td>
                            </tr>
                            <tr class="bg-emerald-50 font-extrabold">
                                <td class="px-4 py-2 text-emerald-800">إجمالي الإيرادات</td>
                                <td class="px-4 py-2 text-left text-emerald-800" dir="ltr">{{ money(incomeStatement.total_revenue) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                    <h2 class="border-b border-slate-100 bg-red-50 px-4 py-3 font-extrabold text-red-800">المصروفات</h2>
                    <table class="w-full text-sm">
                        <tbody>
                            <tr v-for="r in incomeStatement.expense" :key="r.code" class="border-b border-slate-50">
                                <td class="px-4 py-2 text-slate-700"><span class="font-mono text-[11px] text-slate-400" dir="ltr">{{ r.code }}</span> {{ r.name }}</td>
                                <td class="px-4 py-2 text-left font-bold text-slate-800" dir="ltr">{{ money(r.amount!) }}</td>
                            </tr>
                            <tr class="bg-red-50 font-extrabold">
                                <td class="px-4 py-2 text-red-800">إجمالي المصروفات</td>
                                <td class="px-4 py-2 text-left text-red-800" dir="ltr">{{ money(incomeStatement.total_expense) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div class="rounded-2xl border-2 p-5 text-center lg:col-span-2" :class="incomeStatement.net_income >= 0 ? 'border-emerald-200 bg-emerald-50' : 'border-red-200 bg-red-50'">
                    <div class="text-sm font-bold" :class="incomeStatement.net_income >= 0 ? 'text-emerald-700' : 'text-red-700'">
                        {{ incomeStatement.net_income >= 0 ? 'صافي الربح' : 'صافي الخسارة' }}
                    </div>
                    <div class="mt-1 text-3xl font-extrabold" :class="incomeStatement.net_income >= 0 ? 'text-emerald-700' : 'text-red-700'" dir="ltr">
                        {{ money(incomeStatement.net_income) }}
                    </div>
                </div>
            </div>

            <!-- ميزان المراجعة -->
            <div v-if="tab === 'trial'" class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                <div class="flex items-center justify-between border-b border-slate-100 px-4 py-3">
                    <h2 class="font-extrabold text-slate-800">ميزان المراجعة</h2>
                    <span class="inline-flex items-center gap-1 rounded-md px-2.5 py-1 text-xs font-bold" :class="trialBalance.balanced ? 'bg-emerald-100 text-emerald-700' : 'bg-red-100 text-red-700'">
                        <component :is="trialBalance.balanced ? CheckCircle2 : AlertTriangle" class="h-3.5 w-3.5" />
                        {{ trialBalance.balanced ? 'متوازن' : 'غير متوازن — راجع الدفاتر' }}
                    </span>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="bg-slate-50">
                            <tr>
                                <th class="px-4 py-2.5 text-right text-xs font-extrabold text-[#1e3a8a]">الحساب</th>
                                <th class="px-4 py-2.5 text-center text-xs font-extrabold text-[#1e3a8a]">النوع</th>
                                <th class="px-4 py-2.5 text-left text-xs font-extrabold text-[#1e3a8a]">مدين</th>
                                <th class="px-4 py-2.5 text-left text-xs font-extrabold text-[#1e3a8a]">دائن</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="r in trialBalance.rows" :key="r.code" class="border-t border-slate-100">
                                <td class="px-4 py-2 text-slate-700"><span class="font-mono text-[11px] text-slate-400" dir="ltr">{{ r.code }}</span> {{ r.name }}</td>
                                <td class="px-4 py-2 text-center text-[11px] text-slate-500">{{ r.type_label }}</td>
                                <td class="px-4 py-2 text-left font-bold text-slate-800" dir="ltr">{{ r.debit ? money(r.debit) : '' }}</td>
                                <td class="px-4 py-2 text-left font-bold text-slate-800" dir="ltr">{{ r.credit ? money(r.credit) : '' }}</td>
                            </tr>
                            <tr class="border-t-2 border-slate-300 bg-slate-50 font-extrabold">
                                <td colspan="2" class="px-4 py-2.5 text-slate-800">الإجمالي</td>
                                <td class="px-4 py-2.5 text-left text-slate-900" dir="ltr">{{ money(trialBalance.total_debit) }}</td>
                                <td class="px-4 py-2.5 text-left text-slate-900" dir="ltr">{{ money(trialBalance.total_credit) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- الميزانية -->
            <div v-if="tab === 'balance'" class="space-y-4">
                <div v-if="!balanceSheet.balanced" class="flex items-center gap-2 rounded-xl bg-red-50 px-4 py-3 text-sm font-bold text-red-700">
                    <AlertTriangle class="h-4 w-4" /> الميزانية غير متوازنة — راجع القيود
                </div>

                <div class="grid gap-4 lg:grid-cols-2">
                    <div class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                        <h2 class="border-b border-slate-100 bg-sky-50 px-4 py-3 font-extrabold text-sky-800">الأصول</h2>
                        <table class="w-full text-sm">
                            <tbody>
                                <tr v-for="r in balanceSheet.assets" :key="r.code" class="border-b border-slate-50">
                                    <td class="px-4 py-2 text-slate-700">{{ r.name }}</td>
                                    <td class="px-4 py-2 text-left font-bold" dir="ltr">{{ money(r.amount!) }}</td>
                                </tr>
                                <tr class="bg-sky-50 font-extrabold">
                                    <td class="px-4 py-2 text-sky-800">إجمالي الأصول</td>
                                    <td class="px-4 py-2 text-left text-sky-800" dir="ltr">{{ money(balanceSheet.total_assets) }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <div class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                        <h2 class="border-b border-slate-100 bg-amber-50 px-4 py-3 font-extrabold text-amber-800">الالتزامات وحقوق الملكية</h2>
                        <table class="w-full text-sm">
                            <tbody>
                                <tr v-for="r in balanceSheet.liabilities" :key="r.code" class="border-b border-slate-50">
                                    <td class="px-4 py-2 text-slate-700">{{ r.name }}</td>
                                    <td class="px-4 py-2 text-left font-bold" dir="ltr">{{ money(r.amount!) }}</td>
                                </tr>
                                <tr v-for="r in balanceSheet.equity" :key="r.code" class="border-b border-slate-50">
                                    <td class="px-4 py-2 text-slate-700">{{ r.name }}</td>
                                    <td class="px-4 py-2 text-left font-bold" dir="ltr">{{ money(r.amount!) }}</td>
                                </tr>
                                <tr class="border-b border-slate-50">
                                    <td class="px-4 py-2 text-slate-700">الأرباح المحتجزة للفترة</td>
                                    <td class="px-4 py-2 text-left font-bold" dir="ltr">{{ money(balanceSheet.retained_earnings) }}</td>
                                </tr>
                                <tr class="bg-amber-50 font-extrabold">
                                    <td class="px-4 py-2 text-amber-800">الإجمالي</td>
                                    <td class="px-4 py-2 text-left text-amber-800" dir="ltr">{{ money(balanceSheet.total_liabilities + balanceSheet.total_equity) }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- التدفقات النقدية -->
            <div v-if="tab === 'cashflow'" class="space-y-4">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <p class="text-xs font-medium text-slate-500">كل مبلغ دخل الصندوق أو البنك أو خرج منهما — من سندات القبض والصرف ودفعات الحجوزات والكاشير والمصروفات والرواتب</p>
                    <button type="button" @click="exportCashFlow" class="inline-flex items-center gap-1.5 rounded-md bg-blue-600 px-4 py-2 text-sm font-bold text-white hover:bg-blue-700">
                        <Download class="h-4 w-4" /> تصدير CSV
                    </button>
                </div>

                <div class="flex flex-wrap gap-2">
                    <StatPill label="رصيد أول المدة" :value="money(cashFlow.totals.opening)" variant="dark" />
                    <StatPill label="إجمالي الداخل" :value="money(cashFlow.totals.inflow)" variant="success" />
                    <StatPill label="إجمالي الخارج" :value="money(cashFlow.totals.outflow)" variant="danger" />
                    <StatPill label="صافي التدفق" :value="money(cashFlow.totals.net)" :variant="cashFlow.totals.net >= 0 ? 'info' : 'warning'" />
                    <StatPill label="رصيد آخر المدة" :value="money(cashFlow.totals.closing)" variant="primary" />
                </div>

                <!-- حسب الخزينة -->
                <div class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                    <div class="border-b border-slate-100 px-4 py-3">
                        <h2 class="font-extrabold text-slate-800">حركة الخزائن</h2>
                        <p class="text-xs font-medium text-slate-500">رصيد آخر المدة هو رصيد الحساب نفسه في شجرة الحسابات</p>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead class="bg-slate-50">
                                <tr>
                                    <th class="px-4 py-2.5 text-right text-xs font-extrabold text-[#1e3a8a]">الخزينة</th>
                                    <th class="px-4 py-2.5 text-left text-xs font-extrabold text-[#1e3a8a]">أول المدة</th>
                                    <th class="px-4 py-2.5 text-left text-xs font-extrabold text-[#1e3a8a]">داخل</th>
                                    <th class="px-4 py-2.5 text-left text-xs font-extrabold text-[#1e3a8a]">خارج</th>
                                    <th class="px-4 py-2.5 text-left text-xs font-extrabold text-[#1e3a8a]">آخر المدة</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="a in cashFlow.accounts" :key="a.id" class="border-t border-slate-100">
                                    <td class="px-4 py-2.5">
                                        <span class="font-mono text-[11px] text-slate-400" dir="ltr">{{ a.code }}</span>
                                        <span class="ms-1 font-bold text-slate-800">{{ a.name }}</span>
                                        <span v-if="a.treasuries.length" class="ms-1.5 text-[11px] text-slate-500">({{ a.treasuries.join('، ') }})</span>
                                    </td>
                                    <td class="px-4 py-2.5 text-left" dir="ltr">{{ money(a.opening) }}</td>
                                    <td class="px-4 py-2.5 text-left font-bold text-emerald-700" dir="ltr">{{ money(a.inflow) }}</td>
                                    <td class="px-4 py-2.5 text-left font-bold text-red-600" dir="ltr">{{ money(a.outflow) }}</td>
                                    <td class="px-4 py-2.5 text-left font-extrabold text-slate-900" dir="ltr">{{ money(a.closing) }}</td>
                                </tr>
                                <tr v-if="!cashFlow.accounts.length"><td colspan="5" class="px-4 py-10 text-center text-sm text-slate-500">لا توجد حسابات نقدية</td></tr>
                            </tbody>
                        </table>
                    </div>
                    <div v-if="cashFlow.transfers > 0" class="flex items-center gap-2 border-t border-slate-100 bg-slate-50 px-4 py-2.5 text-xs font-bold text-slate-600">
                        <ArrowLeftRight class="h-3.5 w-3.5" />
                        تحويلات بين الخزائن: <span dir="ltr">{{ money(cashFlow.transfers) }}</span> — تظهر في حركة كل خزينة ولا تدخل إجمالي الداخل والخارج
                    </div>
                </div>

                <div class="grid gap-4 lg:grid-cols-2">
                    <!-- حسب المصدر -->
                    <div class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                        <h2 class="border-b border-slate-100 px-4 py-3 font-extrabold text-slate-800">حسب المصدر</h2>
                        <table class="w-full text-sm">
                            <thead class="bg-slate-50">
                                <tr>
                                    <th class="px-4 py-2.5 text-right text-xs font-extrabold text-[#1e3a8a]">المصدر</th>
                                    <th class="px-4 py-2.5 text-left text-xs font-extrabold text-[#1e3a8a]">داخل</th>
                                    <th class="px-4 py-2.5 text-left text-xs font-extrabold text-[#1e3a8a]">خارج</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="s in cashFlow.sources" :key="s.key" class="border-t border-slate-100">
                                    <td class="px-4 py-2 font-bold text-slate-700">{{ s.label }}</td>
                                    <td class="px-4 py-2 text-left font-bold text-emerald-700" dir="ltr">{{ s.inflow ? money(s.inflow) : '' }}</td>
                                    <td class="px-4 py-2 text-left font-bold text-red-600" dir="ltr">{{ s.outflow ? money(s.outflow) : '' }}</td>
                                </tr>
                                <tr v-if="!cashFlow.sources.length"><td colspan="3" class="px-4 py-10 text-center text-sm text-slate-500">لا حركة في هذه الفترة</td></tr>
                            </tbody>
                        </table>
                    </div>

                    <!-- قائمة التدفقات حسب النشاط -->
                    <div class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                        <h2 class="border-b border-slate-100 px-4 py-3 font-extrabold text-slate-800">قائمة التدفقات النقدية</h2>
                        <table class="w-full text-sm">
                            <tbody>
                                <template v-for="sec in cashFlow.sections" :key="sec.key">
                                    <tr class="bg-slate-50">
                                        <td colspan="2" class="px-4 py-2 font-extrabold text-[#1e3a8a]">{{ sec.label }}</td>
                                    </tr>
                                    <tr v-for="r in sec.inflows" :key="'in-' + r.code" class="border-t border-slate-50">
                                        <td class="px-4 py-1.5 text-slate-700">
                                            <span class="font-mono text-[11px] text-slate-400" dir="ltr">{{ r.code }}</span> مقبوض — {{ r.name }}
                                        </td>
                                        <td class="px-4 py-1.5 text-left font-bold text-emerald-700" dir="ltr">{{ money(r.amount) }}</td>
                                    </tr>
                                    <tr v-for="r in sec.outflows" :key="'out-' + r.code" class="border-t border-slate-50">
                                        <td class="px-4 py-1.5 text-slate-700">
                                            <span class="font-mono text-[11px] text-slate-400" dir="ltr">{{ r.code }}</span> مدفوع — {{ r.name }}
                                        </td>
                                        <td class="px-4 py-1.5 text-left font-bold text-red-600" dir="ltr">({{ money(r.amount) }})</td>
                                    </tr>
                                    <tr v-if="!sec.inflows.length && !sec.outflows.length" class="border-t border-slate-50">
                                        <td colspan="2" class="px-4 py-1.5 text-xs text-slate-400">لا حركة</td>
                                    </tr>
                                    <tr class="border-t border-slate-200 font-extrabold">
                                        <td class="px-4 py-2 text-slate-800">صافي {{ sec.label }}</td>
                                        <td class="px-4 py-2 text-left" :class="sec.net >= 0 ? 'text-slate-900' : 'text-red-600'" dir="ltr">{{ money(sec.net) }}</td>
                                    </tr>
                                </template>
                                <tr class="border-t-2 border-slate-300 bg-slate-50 font-extrabold">
                                    <td class="px-4 py-2 text-slate-800">صافي التغير في النقدية</td>
                                    <td class="px-4 py-2 text-left text-slate-900" dir="ltr">{{ money(cashFlow.totals.net) }}</td>
                                </tr>
                                <tr class="font-bold">
                                    <td class="px-4 py-1.5 text-slate-600">رصيد النقدية أول المدة</td>
                                    <td class="px-4 py-1.5 text-left text-slate-700" dir="ltr">{{ money(cashFlow.totals.opening) }}</td>
                                </tr>
                                <tr class="bg-blue-50 font-extrabold">
                                    <td class="px-4 py-2 text-blue-800">رصيد النقدية آخر المدة</td>
                                    <td class="px-4 py-2 text-left text-blue-800" dir="ltr">{{ money(cashFlow.totals.closing) }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- تفصيل الحركة -->
                <div class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                    <div class="flex flex-wrap items-center justify-between gap-2 border-b border-slate-100 px-4 py-3">
                        <h2 class="font-extrabold text-slate-800">تفصيل حركة الخزائن</h2>
                        <select v-model="movementAccount" class="rounded-xl border border-slate-200 px-3 py-1.5 text-sm">
                            <option value="">كل الخزائن</option>
                            <option v-for="a in cashFlow.accounts" :key="a.id" :value="a.id">{{ a.name }}</option>
                        </select>
                    </div>
                    <p v-if="cashFlow.movements_truncated" class="bg-amber-50 px-4 py-2 text-xs font-bold text-amber-700">
                        الحركة أكثر من المعروض — ضيّق الفترة أو صدّر CSV لرؤيتها كاملة. الإجماليات أعلاه محسوبة على الفترة كلها.
                    </p>
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead class="bg-slate-50">
                                <tr>
                                    <th class="px-4 py-2.5 text-right text-xs font-extrabold text-[#1e3a8a]">التاريخ</th>
                                    <th class="px-4 py-2.5 text-right text-xs font-extrabold text-[#1e3a8a]">القيد</th>
                                    <th class="px-4 py-2.5 text-right text-xs font-extrabold text-[#1e3a8a]">البيان</th>
                                    <th class="px-4 py-2.5 text-right text-xs font-extrabold text-[#1e3a8a]">المصدر</th>
                                    <th class="px-4 py-2.5 text-right text-xs font-extrabold text-[#1e3a8a]">الخزينة</th>
                                    <th class="px-4 py-2.5 text-left text-xs font-extrabold text-[#1e3a8a]">داخل</th>
                                    <th class="px-4 py-2.5 text-left text-xs font-extrabold text-[#1e3a8a]">خارج</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="(m, i) in movements" :key="i" class="border-t border-slate-100">
                                    <td class="whitespace-nowrap px-4 py-2 text-slate-600" dir="ltr">{{ m.date }}</td>
                                    <td class="whitespace-nowrap px-4 py-2 font-mono text-[11px] text-slate-500" dir="ltr">{{ m.entry_number }}</td>
                                    <td class="px-4 py-2 text-slate-700">{{ m.description }}</td>
                                    <td class="whitespace-nowrap px-4 py-2 text-xs text-slate-500">{{ m.source_label }}</td>
                                    <td class="whitespace-nowrap px-4 py-2 text-xs font-bold text-slate-600">{{ m.account }}</td>
                                    <td class="px-4 py-2 text-left font-bold text-emerald-700" dir="ltr">{{ m.inflow ? money(m.inflow) : '' }}</td>
                                    <td class="px-4 py-2 text-left font-bold text-red-600" dir="ltr">{{ m.outflow ? money(m.outflow) : '' }}</td>
                                </tr>
                                <tr v-if="!movements.length"><td colspan="7" class="px-4 py-10 text-center text-sm text-slate-500">لا حركة في هذه الفترة</td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- الإقرار الضريبي -->
            <div v-if="tab === 'vat'" class="space-y-4">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <p class="text-xs font-medium text-slate-500" dir="ltr">نسبة الضريبة: {{ vatReturn.rate }}%</p>
                    <button type="button" @click="exportVat" class="inline-flex items-center gap-1.5 rounded-md bg-blue-600 px-4 py-2 text-sm font-bold text-white hover:bg-blue-700">
                        <Download class="h-4 w-4" /> تصدير CSV
                    </button>
                </div>

                <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                    <StatPill label="ضريبة المخرجات (مبيعات)" :value="money(vatReturn.output.tax)" variant="success" />
                    <StatPill label="ضريبة المدخلات (مشتريات)" :value="money(vatReturn.input.tax)" variant="warning" />
                    <StatPill label="صافي الضريبة المستحقة" :value="money(vatReturn.net_due)" :variant="vatReturn.net_due >= 0 ? 'danger' : 'success'" />
                </div>

                <div class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                    <div class="border-b border-slate-100 px-4 py-3">
                        <h2 class="font-extrabold text-slate-800">التفصيل الشهري</h2>
                        <p class="text-xs font-medium text-slate-500">من المستندات مباشرة (مبيعات، حجوزات، مشتريات) — لا من القيود</p>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead class="bg-slate-50">
                                <tr>
                                    <th class="px-4 py-2.5 text-right text-xs font-extrabold text-[#1e3a8a]">الشهر</th>
                                    <th class="px-4 py-2.5 text-left text-xs font-extrabold text-[#1e3a8a]">مبيعات خاضعة</th>
                                    <th class="px-4 py-2.5 text-left text-xs font-extrabold text-[#1e3a8a]">ضريبة مخرجات</th>
                                    <th class="px-4 py-2.5 text-left text-xs font-extrabold text-[#1e3a8a]">مشتريات خاضعة</th>
                                    <th class="px-4 py-2.5 text-left text-xs font-extrabold text-[#1e3a8a]">ضريبة مدخلات</th>
                                    <th class="px-4 py-2.5 text-left text-xs font-extrabold text-[#1e3a8a]">صافي المستحق</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="m in vatReturn.months" :key="m.month" class="border-t border-slate-100">
                                    <td class="px-4 py-2.5 font-bold text-slate-700" dir="ltr">{{ m.month }}</td>
                                    <td class="px-4 py-2.5 text-left" dir="ltr">{{ money(m.output_net) }}</td>
                                    <td class="px-4 py-2.5 text-left font-bold text-emerald-700" dir="ltr">{{ money(m.output_tax) }}</td>
                                    <td class="px-4 py-2.5 text-left" dir="ltr">{{ money(m.input_net) }}</td>
                                    <td class="px-4 py-2.5 text-left font-bold text-amber-700" dir="ltr">{{ money(m.input_tax) }}</td>
                                    <td class="px-4 py-2.5 text-left font-extrabold text-slate-900" dir="ltr">{{ money(m.net_due) }}</td>
                                </tr>
                                <tr v-if="!vatReturn.months.length"><td colspan="6" class="px-4 py-10 text-center text-sm text-slate-500">لا بيانات في هذه الفترة</td></tr>
                                <tr class="border-t-2 border-slate-300 bg-slate-50 font-extrabold">
                                    <td class="px-4 py-2.5 text-slate-800">الإجمالي</td>
                                    <td class="px-4 py-2.5 text-left text-slate-900" dir="ltr">{{ money(vatReturn.output.taxable_amount) }}</td>
                                    <td class="px-4 py-2.5 text-left text-slate-900" dir="ltr">{{ money(vatReturn.output.tax) }}</td>
                                    <td class="px-4 py-2.5 text-left text-slate-900" dir="ltr">{{ money(vatReturn.input.taxable_amount) }}</td>
                                    <td class="px-4 py-2.5 text-left text-slate-900" dir="ltr">{{ money(vatReturn.input.tax) }}</td>
                                    <td class="px-4 py-2.5 text-left text-slate-900" dir="ltr">{{ money(vatReturn.net_due) }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
