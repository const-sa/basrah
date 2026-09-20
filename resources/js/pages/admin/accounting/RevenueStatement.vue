<script setup lang="ts">
import { StatPill } from '@/components/data-table';
import { usePermissions } from '@/composables/usePermissions';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/vue3';
import { Download, Settings2 } from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';

interface AccountOption {
    id: number;
    code: string;
    name: string;
    streams: string[];
}

interface StatementRow {
    id: number;
    entry_id: number;
    date: string;
    number: string;
    source: string;
    source_label: string;
    center: string | null;
    segment_label: string;
    label: string;
    debit: number;
    credit: number;
    balance: number;
}

interface CenterRow {
    cost_center_id: number | null;
    name: string;
    count: number;
    amount: number;
    share: number;
}

interface Option {
    key: string;
    label: string;
}

const props = defineProps<{
    account: { id: number; code: string; name: string; streams: string[] } | null;
    accounts: AccountOption[];
    filters: {
        account_id: number | null;
        from: string;
        to: string;
        segment: string | null;
        cost_center_id: number | null;
        source: string | null;
    };
    statement: {
        opening: number;
        rows: StatementRow[];
        total_debit: number;
        total_credit: number;
        net: number;
        closing: number;
    };
    byCenter: CenterRow[];
    centers: { id: number; name: string | null; segment: string }[];
    segments: Option[];
    sources: Option[];
}>();

const { can } = usePermissions();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'لوحة التحكم', href: '/admin' },
    { title: 'الإيرادات', href: '/admin/accounting/revenues' },
    { title: 'كشف حساب الإيراد', href: '/admin/accounting/revenue-statement' },
];

const money = (n: number) =>
    new Intl.NumberFormat('ar-SA-u-nu-latn', { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(n ?? 0);

const accountId = ref<number | null>(props.filters.account_id);
const from = ref(props.filters.from);
const to = ref(props.filters.to);
const segment = ref(props.filters.segment ?? '');
const costCenterId = ref<number | null>(props.filters.cost_center_id);
const source = ref(props.filters.source ?? '');

// Picking an activity narrows the centre list, so a centre left over from the
// previous activity does not sit there filtering everything out.
const centers = computed(() => (segment.value ? props.centers.filter((c) => c.segment === segment.value) : props.centers));

watch(segment, () => {
    if (costCenterId.value && !centers.value.some((c) => c.id === costCenterId.value)) {
        costCenterId.value = null;
    }
});

const params = () => ({
    account_id: accountId.value ?? undefined,
    from: from.value,
    to: to.value,
    segment: segment.value || undefined,
    cost_center_id: costCenterId.value ?? undefined,
    source: source.value || undefined,
});

const apply = () => router.get('/admin/accounting/revenue-statement', params(), { preserveState: true, replace: true });

// The account is the question the screen answers, so choosing one reloads at
// once rather than waiting behind a «تطبيق» the user has no reason to press.
watch(accountId, () => apply());

const exportCsv = () => {
    const query = new URLSearchParams(
        Object.entries(params())
            .filter(([, v]) => v !== undefined && v !== null && v !== '')
            .map(([k, v]) => [k, String(v)]),
    );

    window.location.href = `/admin/accounting/revenue-statement/export?${query.toString()}`;
};
</script>

<template>
    <Head title="كشف حساب الإيراد" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="min-h-full space-y-4 bg-slate-100 p-5">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h1 class="text-2xl font-extrabold text-slate-900">كشف حساب الإيراد</h1>
                    <p class="mt-1 text-sm font-medium text-slate-600">
                        اختر الإيراد لتُعرض حركة حسابه سطرًا سطرًا برصيدٍ متدرّج
                    </p>
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    <Link
                        href="/admin/accounting/revenue-accounts"
                        class="inline-flex items-center gap-1.5 rounded-md border border-slate-200 bg-white px-4 py-2 text-sm font-bold text-slate-600 hover:bg-slate-50"
                    >
                        <Settings2 class="h-4 w-4" /> حسابات الإيراد
                    </Link>
                    <button
                        v-if="can('revenues.export') && account"
                        type="button"
                        @click="exportCsv"
                        class="inline-flex items-center gap-1.5 rounded-md bg-blue-600 px-4 py-2 text-sm font-bold text-white hover:bg-blue-700"
                    >
                        <Download class="h-4 w-4" /> تصدير CSV
                    </button>
                </div>
            </div>

            <div v-if="!account" class="rounded-2xl border border-slate-200 bg-white px-4 py-10 text-center text-sm font-bold text-slate-500">
                لا توجد حسابات إيرادية قابلة للترحيل في شجرة الحسابات.
            </div>

            <template v-else>
                <!-- اختيار الإيراد — قائمة بأسماء المصادر لا بأكواد الحسابات وحدها -->
                <div class="rounded-2xl border border-slate-200 bg-white p-3 shadow-sm">
                    <label class="mb-1 block text-[11px] font-bold text-slate-600">الإيراد</label>
                    <select
                        v-model="accountId"
                        class="w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm font-bold focus:border-blue-400 focus:outline-none focus:ring-2 focus:ring-blue-100"
                    >
                        <option v-for="option in accounts" :key="option.id" :value="option.id">
                            {{ option.code }} — {{ option.name }}{{ option.streams.length ? ` (${option.streams.join('، ')})` : '' }}
                        </option>
                    </select>
                    <p v-if="account.streams.length" class="mt-1.5 text-[11px] font-medium text-slate-500">
                        يستقبل هذا الحساب: {{ account.streams.join('، ') }}.
                    </p>
                    <p v-else class="mt-1.5 text-[11px] font-medium text-slate-500">
                        لا يُوجَّه إليه أي مصدر دخل حاليًا — ما فيه قيودٌ أُدخلت يدويًا أو رُحِّلت قبل تغيير الإعداد.
                    </p>
                </div>

                <div class="grid gap-3 sm:grid-cols-4">
                    <StatPill label="رصيد ما قبل الفترة" :value="money(statement.opening)" variant="info" />
                    <StatPill label="دائن الفترة" :value="money(statement.total_credit)" variant="success" />
                    <StatPill label="مدين الفترة (مرتجعات واستردادات)" :value="money(statement.total_debit)" variant="danger" />
                    <StatPill label="رصيد آخر الفترة" :value="money(statement.closing)" :variant="statement.closing >= 0 ? 'success' : 'warning'" />
                </div>

                <div class="rounded-2xl border border-slate-200 bg-white p-3 shadow-sm">
                    <div class="grid gap-2 sm:grid-cols-2 lg:grid-cols-6">
                        <input v-model="from" type="date" class="rounded-xl border border-slate-200 px-3 py-2.5 text-sm" />
                        <input v-model="to" type="date" class="rounded-xl border border-slate-200 px-3 py-2.5 text-sm" />
                        <select v-model="segment" class="rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm">
                            <option value="">كل الأنشطة</option>
                            <option v-for="option in segments" :key="option.key" :value="option.key">{{ option.label }}</option>
                        </select>
                        <select v-model="costCenterId" class="rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm">
                            <option :value="null">كل الوحدات والأقسام</option>
                            <option v-for="center in centers" :key="center.id" :value="center.id">{{ center.name }}</option>
                        </select>
                        <select v-model="source" class="rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm">
                            <option value="">كل المصادر</option>
                            <option v-for="option in sources" :key="option.key" :value="option.key">{{ option.label }}</option>
                        </select>
                        <button type="button" @click="apply" class="rounded-xl bg-slate-800 px-4 py-2.5 text-sm font-bold text-white hover:bg-slate-900">
                            تطبيق
                        </button>
                    </div>
                </div>

                <div v-if="byCenter.length" class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                    <div class="border-b border-slate-100 px-4 py-3">
                        <h2 class="text-sm font-extrabold text-slate-800">من أين جاء الرقم — توزيع الحساب على الوحدات</h2>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead class="bg-slate-100">
                                <tr>
                                    <th class="px-4 py-3 text-right text-xs font-extrabold text-[#1e3a8a]">الوحدة / القسم</th>
                                    <th class="px-4 py-3 text-center text-xs font-extrabold text-[#1e3a8a]">الحركات</th>
                                    <th class="px-4 py-3 text-center text-xs font-extrabold text-[#1e3a8a]">النسبة</th>
                                    <th class="px-4 py-3 text-left text-xs font-extrabold text-[#1e3a8a]">الصافي</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="row in byCenter" :key="row.cost_center_id ?? 'none'" class="border-t border-slate-100">
                                    <td class="px-4 py-2.5 font-bold text-slate-700">{{ row.name }}</td>
                                    <td class="px-4 py-2.5 text-center text-xs font-bold text-slate-500" dir="ltr">{{ row.count }}</td>
                                    <td class="px-4 py-2.5 text-center text-xs font-bold text-slate-500" dir="ltr">{{ row.share }}%</td>
                                    <td class="px-4 py-2.5 text-left font-extrabold text-emerald-600" dir="ltr">{{ money(row.amount) }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                    <div class="border-b border-slate-100 px-4 py-3">
                        <h2 class="text-sm font-extrabold text-slate-800">
                            حركة الحساب <span dir="ltr" class="font-mono text-xs text-slate-500">{{ account.code }}</span> — القيود المرحَّلة وحدها
                        </h2>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead class="bg-slate-100">
                                <tr>
                                    <th class="px-4 py-3 text-right text-xs font-extrabold text-[#1e3a8a]">التاريخ</th>
                                    <th class="px-4 py-3 text-right text-xs font-extrabold text-[#1e3a8a]">القيد</th>
                                    <th class="px-4 py-3 text-right text-xs font-extrabold text-[#1e3a8a]">الوحدة / القسم</th>
                                    <th class="px-4 py-3 text-right text-xs font-extrabold text-[#1e3a8a]">البيان</th>
                                    <th class="px-4 py-3 text-left text-xs font-extrabold text-[#1e3a8a]">مدين</th>
                                    <th class="px-4 py-3 text-left text-xs font-extrabold text-[#1e3a8a]">دائن</th>
                                    <th class="px-4 py-3 text-left text-xs font-extrabold text-[#1e3a8a]">الرصيد</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr class="border-t border-slate-100 bg-slate-50/70">
                                    <td class="px-4 py-2.5 text-xs font-extrabold text-slate-600" colspan="6">رصيد ما قبل الفترة</td>
                                    <td class="px-4 py-2.5 text-left font-extrabold text-slate-800" dir="ltr">{{ money(statement.opening) }}</td>
                                </tr>
                                <tr v-for="row in statement.rows" :key="row.id" class="border-t border-slate-100 hover:bg-slate-50">
                                    <td class="px-4 py-3 text-right text-xs text-slate-600"><span dir="ltr">{{ row.date }}</span></td>
                                    <td class="px-4 py-3 text-right text-xs font-bold text-slate-700">
                                        <span dir="ltr">{{ row.number }}</span>
                                        <div class="text-[11px] font-medium text-slate-500">{{ row.source_label }}</div>
                                    </td>
                                    <td class="px-4 py-3 text-xs font-bold text-slate-600">
                                        {{ row.center ?? '—' }}
                                        <div class="text-[11px] font-medium text-slate-400">{{ row.segment_label }}</div>
                                    </td>
                                    <td class="px-4 py-3 text-slate-700">{{ row.label || '—' }}</td>
                                    <td class="px-4 py-3 text-left text-red-600" dir="ltr">{{ row.debit ? money(row.debit) : '—' }}</td>
                                    <td class="px-4 py-3 text-left text-emerald-600" dir="ltr">{{ row.credit ? money(row.credit) : '—' }}</td>
                                    <td class="px-4 py-3 text-left font-bold text-slate-800" dir="ltr">{{ money(row.balance) }}</td>
                                </tr>
                                <tr v-if="!statement.rows.length">
                                    <td colspan="7" class="px-4 py-10 text-center text-sm text-slate-500">لا حركات على هذا الحساب في الفترة</td>
                                </tr>
                            </tbody>
                            <tfoot>
                                <tr class="border-t-2 border-slate-200 bg-slate-50">
                                    <td class="px-4 py-3 text-xs font-extrabold text-slate-700" colspan="4">إجمالي الفترة</td>
                                    <td class="px-4 py-3 text-left font-extrabold text-slate-900" dir="ltr">{{ money(statement.total_debit) }}</td>
                                    <td class="px-4 py-3 text-left font-extrabold text-slate-900" dir="ltr">{{ money(statement.total_credit) }}</td>
                                    <td class="px-4 py-3 text-left font-extrabold text-slate-900" dir="ltr">{{ money(statement.closing) }}</td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>
            </template>
        </div>
    </AppLayout>
</template>
