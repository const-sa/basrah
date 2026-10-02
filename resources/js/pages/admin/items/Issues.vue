<script setup lang="ts">
import { StatPill } from '@/components/data-table';
import { usePermissions } from '@/composables/usePermissions';
import AppLayout from '@/layouts/AppLayout.vue';
import { todayString } from '@/lib/dates';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { Ban, Eye, PackageMinus, Plus, Trash2, X } from 'lucide-vue-next';
import { computed, ref } from 'vue';

interface IssueRow {
    id: number; number: string; issue_date: string;
    recipient: string | null; department: string | null; contract: string | null;
    expense_account: string | null; items_count: number; total_cost: number;
    status: string; status_label: string; entry_number: string | null;
    user_name: string | null; notes: string | null;
}

interface StockItem { id: number; name: string; code: string | null; stock_qty: number; cost: number; unit: string }

const props = defineProps<{
    issues: { data: IssueRow[]; links: { url: string | null; label: string; active: boolean }[] };
    filters: { status: string | null; employee_id: number | null; from: string | null; to: string | null; search: string | null };
    stats: { count: number; month_cost: number; total_cost: number };
    stockItems: StockItem[];
    employees: { id: number; name: string; position: string | null }[];
    departments: { id: number; name: string }[];
    contracts: { id: number; label: string }[];
    expenseAccounts: { id: number; code: string; name: string }[];
    defaults: { department_id: number | null; expense_account_id: number | null };
}>();

const { can } = usePermissions();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'لوحة التحكم', href: '/admin' },
    { title: 'الأصناف', href: '/admin/items' },
    { title: 'أذونات الصرف', href: '/admin/inventory/issues' },
];

const money = (n: number) => new Intl.NumberFormat('ar-SA-u-nu-latn', { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(n ?? 0);

const filters = ref({ ...props.filters });
const apply = () => router.get('/admin/inventory/issues', filters.value, { preserveState: true, replace: true });

// ── نموذج إذن صرف جديد ──────────────────────────────────────────
const showForm = ref(false);

const form = useForm({
    issue_date: todayString(),
    department_id: props.defaults.department_id,
    employee_id: null as number | null,
    recipient_name: '',
    contract_id: null as number | null,
    expense_account_id: props.defaults.expense_account_id,
    notes: '',
    items: [{ item_id: null as number | null, quantity: 1 as number | string }],
});

const itemById = computed(() => new Map(props.stockItems.map((i) => [i.id, i])));

const addLine = () => form.items.push({ item_id: null, quantity: 1 });
const removeLine = (index: number) => {
    form.items.splice(index, 1);
    if (!form.items.length) addLine();
};

const lineCost = (line: { item_id: number | null; quantity: number | string }) => {
    const item = line.item_id ? itemById.value.get(line.item_id) : null;
    return item ? Math.round(Number(line.quantity || 0) * item.cost * 100) / 100 : 0;
};

const totalCost = computed(() => form.items.reduce((sum, l) => sum + lineCost(l), 0));

const exceeds = (line: { item_id: number | null; quantity: number | string }) => {
    const item = line.item_id ? itemById.value.get(line.item_id) : null;
    return !!item && Number(line.quantity || 0) > item.stock_qty;
};

const canSubmit = computed(
    () => form.items.some((l) => l.item_id && Number(l.quantity) > 0) && (form.employee_id || form.recipient_name.trim()) && form.expense_account_id,
);

const openForm = () => {
    form.reset();
    form.clearErrors();
    showForm.value = true;
};

const submit = () => {
    form.transform((data) => ({
        ...data,
        items: data.items.filter((l) => l.item_id && Number(l.quantity) > 0),
    })).post('/admin/inventory/issues', {
        preserveScroll: true,
        onSuccess: () => {
            showForm.value = false;
            form.reset();
        },
    });
};

const cancelIssue = (issue: IssueRow) => {
    const reason = window.prompt(`إلغاء إذن الصرف ${issue.number}؟\nستعود المواد للمخزون ويُعكس القيد.\n\nسبب الإلغاء (اختياري):`);
    if (reason === null) return;
    router.post(`/admin/inventory/issues/${issue.id}/cancel`, { reason }, { preserveScroll: true });
};

const errorFor = (key: string) => (form.errors as Record<string, string>)[key];
</script>

<template>
    <Head title="أذونات الصرف" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="min-h-full space-y-4 bg-slate-100 p-5">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h1 class="text-2xl font-extrabold text-slate-900">أذونات الصرف</h1>
                    <p class="mt-1 text-sm font-medium text-slate-600">
                        مواد تخرج من المخزن مع الفني للصيانة (كلور، مواد تعقيم…) — تُخصم من الرصيد وتُقيَّد مصروفًا تلقائيًا
                    </p>
                </div>
                <div class="flex flex-wrap gap-2">
                    <Link href="/admin/inventory/movements" class="rounded-md border border-slate-200 bg-white px-4 py-2 text-sm font-bold text-slate-700 hover:bg-slate-50">
                        حركات المخزون
                    </Link>
                    <button
                        v-if="can('stock_issues.create')"
                        type="button"
                        class="inline-flex items-center gap-1.5 rounded-md bg-blue-600 px-4 py-2 text-sm font-bold text-white hover:bg-blue-700"
                        @click="openForm"
                    >
                        <Plus class="h-4 w-4" /> إذن صرف جديد
                    </button>
                </div>
            </div>

            <div class="flex flex-wrap gap-2">
                <StatPill label="أذونات معتمدة" :value="String(stats.count)" />
                <StatPill label="تكلفة الشهر" :value="money(stats.month_cost)" variant="info" />
                <StatPill label="إجمالي المصروف" :value="money(stats.total_cost)" variant="success" />
            </div>

            <!-- نموذج الإذن -->
            <div v-if="showForm" class="rounded-2xl border border-blue-200 bg-white p-4 shadow-sm">
                <div class="mb-3 flex items-center justify-between">
                    <h2 class="flex items-center gap-2 text-lg font-extrabold text-slate-900">
                        <PackageMinus class="h-5 w-5 text-blue-600" /> إذن صرف جديد
                    </h2>
                    <button type="button" class="rounded-md p-1.5 text-slate-500 hover:bg-slate-100" @click="showForm = false">
                        <X class="h-5 w-5" />
                    </button>
                </div>

                <form class="space-y-4" @submit.prevent="submit">
                    <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                        <label class="block text-sm">
                            <span class="mb-1 block font-bold text-slate-700">تاريخ الصرف</span>
                            <input v-model="form.issue_date" type="date" class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm" />
                            <span v-if="form.errors.issue_date" class="mt-1 block text-xs text-red-600">{{ form.errors.issue_date }}</span>
                        </label>
                        <label class="block text-sm">
                            <span class="mb-1 block font-bold text-slate-700">الفني المستلم</span>
                            <select v-model="form.employee_id" class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm">
                                <option :value="null">— اختر موظفًا —</option>
                                <option v-for="e in employees" :key="e.id" :value="e.id">{{ e.name }}{{ e.position ? ` (${e.position})` : '' }}</option>
                            </select>
                            <span v-if="form.errors.employee_id" class="mt-1 block text-xs text-red-600">{{ form.errors.employee_id }}</span>
                        </label>
                        <label v-if="!form.employee_id" class="block text-sm">
                            <span class="mb-1 block font-bold text-slate-700">أو اسم المستلم</span>
                            <input v-model="form.recipient_name" type="text" placeholder="إن لم يكن موظفًا مسجلًا" class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm" />
                        </label>
                        <label class="block text-sm">
                            <span class="mb-1 block font-bold text-slate-700">القسم</span>
                            <select v-model="form.department_id" class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm">
                                <option :value="null">عام</option>
                                <option v-for="d in departments" :key="d.id" :value="d.id">{{ d.name }}</option>
                            </select>
                        </label>
                        <label class="block text-sm">
                            <span class="mb-1 block font-bold text-slate-700">عقد الصيانة (اختياري)</span>
                            <select v-model="form.contract_id" class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm">
                                <option :value="null">— بدون عقد —</option>
                                <option v-for="c in contracts" :key="c.id" :value="c.id">{{ c.label }}</option>
                            </select>
                        </label>
                        <label class="block text-sm">
                            <span class="mb-1 block font-bold text-slate-700">حساب المصروف (الطرف المدين)</span>
                            <select v-model="form.expense_account_id" class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm">
                                <option :value="null">— اختر —</option>
                                <option v-for="a in expenseAccounts" :key="a.id" :value="a.id">{{ a.code }} — {{ a.name }}</option>
                            </select>
                            <span v-if="form.errors.expense_account_id" class="mt-1 block text-xs text-red-600">{{ form.errors.expense_account_id }}</span>
                        </label>
                    </div>

                    <!-- المواد -->
                    <div class="overflow-x-auto rounded-xl border border-slate-200">
                        <table class="w-full text-sm">
                            <thead class="bg-slate-100">
                                <tr>
                                    <th class="px-3 py-2 text-right text-xs font-extrabold text-[#1e3a8a]">الصنف</th>
                                    <th class="px-3 py-2 text-center text-xs font-extrabold text-[#1e3a8a]">المتاح</th>
                                    <th class="px-3 py-2 text-center text-xs font-extrabold text-[#1e3a8a]">الكمية</th>
                                    <th class="px-3 py-2 text-center text-xs font-extrabold text-[#1e3a8a]">التكلفة</th>
                                    <th class="w-10"></th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="(line, index) in form.items" :key="index" class="border-t border-slate-100">
                                    <td class="px-3 py-2">
                                        <select v-model="line.item_id" class="w-full min-w-48 rounded-lg border border-slate-200 px-2 py-2 text-sm">
                                            <option :value="null">— اختر الصنف —</option>
                                            <option v-for="i in stockItems" :key="i.id" :value="i.id">{{ i.name }}{{ i.code ? ` (${i.code})` : '' }}</option>
                                        </select>
                                        <span v-if="errorFor(`items.${index}.item_id`)" class="mt-1 block text-xs text-red-600">{{ errorFor(`items.${index}.item_id`) }}</span>
                                    </td>
                                    <td class="px-3 py-2 text-center text-xs font-bold text-slate-600">
                                        <template v-if="line.item_id && itemById.get(line.item_id)">
                                            {{ itemById.get(line.item_id)!.stock_qty }} {{ itemById.get(line.item_id)!.unit }}
                                        </template>
                                        <template v-else>—</template>
                                    </td>
                                    <td class="px-3 py-2">
                                        <input
                                            v-model="line.quantity"
                                            type="number"
                                            min="0.001"
                                            step="any"
                                            class="w-28 rounded-lg border px-2 py-2 text-center text-sm"
                                            :class="exceeds(line) ? 'border-red-400 bg-red-50' : 'border-slate-200'"
                                        />
                                        <span v-if="exceeds(line)" class="mt-1 block text-[11px] font-bold text-red-600">أكبر من المتاح</span>
                                    </td>
                                    <td class="px-3 py-2 text-center font-bold text-slate-700" dir="ltr">{{ money(lineCost(line)) }}</td>
                                    <td class="px-2 py-2 text-center">
                                        <button type="button" class="rounded-md p-1.5 text-red-500 hover:bg-red-50" @click="removeLine(index)">
                                            <Trash2 class="h-4 w-4" />
                                        </button>
                                    </td>
                                </tr>
                            </tbody>
                            <tfoot class="border-t border-slate-200 bg-slate-50">
                                <tr>
                                    <td colspan="3" class="px-3 py-2">
                                        <button type="button" class="inline-flex items-center gap-1 text-sm font-bold text-blue-600 hover:text-blue-700" @click="addLine">
                                            <Plus class="h-4 w-4" /> إضافة صنف
                                        </button>
                                    </td>
                                    <td class="px-3 py-2 text-center font-extrabold text-slate-900" dir="ltr">{{ money(totalCost) }}</td>
                                    <td></td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                    <span v-if="form.errors.items" class="block text-xs text-red-600">{{ form.errors.items }}</span>

                    <label class="block text-sm">
                        <span class="mb-1 block font-bold text-slate-700">ملاحظات</span>
                        <textarea v-model="form.notes" rows="2" placeholder="مثال: صيانة مسبح العميل فلان" class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm"></textarea>
                    </label>

                    <div class="rounded-xl bg-blue-50 px-4 py-3 text-xs font-medium leading-6 text-blue-900">
                        عند الحفظ: تُخصم الكميات من رصيد الأصناف، ويُرحَّل قيد تلقائي —
                        <b>مدين</b> حساب المصروف المختار و<b>دائن</b> مخزون البضاعة — بقيمة {{ money(totalCost) }} على مركز تكلفة القسم.
                    </div>

                    <div class="flex justify-end gap-2">
                        <button type="button" class="rounded-md border border-slate-200 bg-white px-4 py-2 text-sm font-bold text-slate-700 hover:bg-slate-50" @click="showForm = false">
                            إلغاء
                        </button>
                        <button
                            type="submit"
                            :disabled="form.processing || !canSubmit"
                            class="rounded-md bg-blue-600 px-5 py-2 text-sm font-bold text-white hover:bg-blue-700 disabled:opacity-50"
                        >
                            حفظ الإذن وخصم المخزون
                        </button>
                    </div>
                </form>
            </div>

            <!-- الفلاتر -->
            <div class="rounded-2xl border border-slate-200 bg-white p-3 shadow-sm">
                <div class="grid gap-2 sm:grid-cols-2 lg:grid-cols-5">
                    <input v-model="filters.search" type="search" placeholder="بحث برقم الإذن أو المستلم" class="rounded-xl border border-slate-200 px-3 py-2.5 text-sm" @keyup.enter="apply" />
                    <select v-model="filters.employee_id" class="rounded-xl border border-slate-200 px-3 py-2.5 text-sm" @change="apply">
                        <option :value="null">كل الفنيين</option>
                        <option v-for="e in employees" :key="e.id" :value="e.id">{{ e.name }}</option>
                    </select>
                    <select v-model="filters.status" class="rounded-xl border border-slate-200 px-3 py-2.5 text-sm" @change="apply">
                        <option :value="null">كل الحالات</option>
                        <option value="posted">معتمد</option>
                        <option value="cancelled">ملغى</option>
                    </select>
                    <input v-model="filters.from" type="date" class="rounded-xl border border-slate-200 px-3 py-2.5 text-sm" @change="apply" />
                    <input v-model="filters.to" type="date" class="rounded-xl border border-slate-200 px-3 py-2.5 text-sm" @change="apply" />
                </div>
            </div>

            <!-- السجل -->
            <div class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="bg-slate-100">
                            <tr>
                                <th class="px-4 py-3 text-right text-xs font-extrabold text-[#1e3a8a]">رقم الإذن</th>
                                <th class="px-4 py-3 text-right text-xs font-extrabold text-[#1e3a8a]">التاريخ</th>
                                <th class="px-4 py-3 text-right text-xs font-extrabold text-[#1e3a8a]">المستلم</th>
                                <th class="px-4 py-3 text-right text-xs font-extrabold text-[#1e3a8a]">العقد</th>
                                <th class="px-4 py-3 text-center text-xs font-extrabold text-[#1e3a8a]">الأصناف</th>
                                <th class="px-4 py-3 text-left text-xs font-extrabold text-[#1e3a8a]">التكلفة</th>
                                <th class="px-4 py-3 text-center text-xs font-extrabold text-[#1e3a8a]">القيد</th>
                                <th class="px-4 py-3 text-center text-xs font-extrabold text-[#1e3a8a]">الحالة</th>
                                <th class="px-4 py-3"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="i in issues.data" :key="i.id" class="border-t border-slate-100 hover:bg-slate-50" :class="{ 'opacity-60': i.status === 'cancelled' }">
                                <td class="px-4 py-2.5 font-bold text-slate-800" dir="ltr">{{ i.number }}</td>
                                <td class="px-4 py-2.5 text-xs text-slate-600" dir="ltr">{{ i.issue_date }}</td>
                                <td class="px-4 py-2.5">
                                    <div class="font-bold text-slate-800">{{ i.recipient ?? '—' }}</div>
                                    <div v-if="i.notes" class="max-w-56 truncate text-[11px] text-slate-500">{{ i.notes }}</div>
                                </td>
                                <td class="px-4 py-2.5 text-xs text-slate-600">{{ i.contract ?? '—' }}</td>
                                <td class="px-4 py-2.5 text-center font-bold text-slate-700">{{ i.items_count }}</td>
                                <td class="px-4 py-2.5 text-left font-extrabold text-slate-800" dir="ltr">{{ money(i.total_cost) }}</td>
                                <td class="px-4 py-2.5 text-center text-xs text-slate-600" dir="ltr">{{ i.entry_number ?? '—' }}</td>
                                <td class="px-4 py-2.5 text-center">
                                    <span
                                        class="rounded-md px-2 py-0.5 text-[11px] font-bold"
                                        :class="i.status === 'cancelled' ? 'bg-red-100 text-red-700' : 'bg-emerald-100 text-emerald-700'"
                                    >
                                        {{ i.status_label }}
                                    </span>
                                </td>
                                <td class="px-4 py-2.5">
                                    <div class="flex justify-end gap-1">
                                        <Link :href="`/admin/inventory/issues/${i.id}`" class="rounded-md p-1.5 text-slate-600 hover:bg-slate-100" title="عرض وطباعة">
                                            <Eye class="h-4 w-4" />
                                        </Link>
                                        <button
                                            v-if="can('stock_issues.delete') && i.status !== 'cancelled'"
                                            type="button"
                                            class="rounded-md p-1.5 text-red-500 hover:bg-red-50"
                                            title="إلغاء الإذن"
                                            @click="cancelIssue(i)"
                                        >
                                            <Ban class="h-4 w-4" />
                                        </button>
                                    </div>
                                </td>
                            </tr>
                            <tr v-if="!issues.data.length">
                                <td colspan="9" class="px-4 py-10 text-center text-sm text-slate-500">لا أذونات صرف</td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div v-if="issues.links.length > 3" class="flex flex-wrap justify-center gap-1 border-t border-slate-100 p-3">
                    <Link
                        v-for="l in issues.links"
                        :key="l.label"
                        :href="l.url ?? '#'"
                        :class="['rounded-lg px-3 py-1.5 text-xs font-bold', l.active ? 'bg-blue-600 text-white' : l.url ? 'bg-white text-slate-600 ring-1 ring-slate-200' : 'text-slate-300']"
                        v-html="l.label"
                    />
                </div>
            </div>
        </div>
    </AppLayout>
</template>
