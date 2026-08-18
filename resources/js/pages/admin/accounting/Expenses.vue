<script setup lang="ts">
import { usePermissions } from '@/composables/usePermissions';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem, type PaymentMethodOption } from '@/types';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { Ban, CheckCircle2, Download, PencilLine, Plus, Power, Receipt, Tags, Trash2, X } from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';

interface Expense {
    id: number;
    number: string;
    expense_date: string;
    amount: number;
    expense_category_id: number | null;
    category: string | null;
    cost_center_id: number | null;
    unit: string | null;
    treasury_id: number | null;
    treasury: string | null;
    supplier_id: number | null;
    supplier: string | null;
    payment_method_id: number | null;
    method_label: string | null;
    reference: string | null;
    description: string | null;
    status: string;
    status_label: string;
}

interface Category {
    id: number;
    code: string;
    name: string;
    account: string | null;
    account_id: number;
    cost_center_id: number | null;
    is_active: boolean;
    is_system: boolean;
    expenses_count: number;
}

const props = defineProps<{
    expenses: { data: Expense[]; links: { url: string | null; label: string; active: boolean }[] };
    filters: Record<string, string | number | null>;
    stats: { total: number; count: number; drafts: number; month: number };
    byCategory: { category: string; count: number; amount: number; share: number }[];
    categories: Category[];
    accounts: { id: number; code: string; name: string }[];
    costCenters: { id: number; name: string }[];
    treasuries: { id: number; name: string; balance: number }[];
    methods: PaymentMethodOption[];
    suppliers: { id: number; name: string }[];
    statuses: { key: string; label: string }[];
}>();

const { can } = usePermissions();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'لوحة التحكم', href: '/admin' },
    { title: 'المصروفات والتكاليف', href: '/admin/accounting/expenses' },
];

const money = (n: number) => new Intl.NumberFormat('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(n ?? 0);

const activeCategories = computed(() => props.categories.filter((c) => c.is_active));

const filters = ref({
    from: (props.filters.from as string) ?? '',
    to: (props.filters.to as string) ?? '',
    expense_category_id: props.filters.expense_category_id ? String(props.filters.expense_category_id) : '',
    cost_center_id: props.filters.cost_center_id ? String(props.filters.cost_center_id) : '',
    status: (props.filters.status as string) ?? '',
    search: (props.filters.search as string) ?? '',
});

let timer: ReturnType<typeof setTimeout> | undefined;
watch(
    filters,
    (value) => {
        clearTimeout(timer);
        timer = setTimeout(() => {
            router.get('/admin/accounting/expenses', { ...value }, { preserveState: true, replace: true, preserveScroll: true });
        }, 300);
    },
    { deep: true },
);

const exportUrl = computed(() => {
    const params = new URLSearchParams(Object.entries(filters.value).filter(([, v]) => v !== '' && v !== null) as [string, string][]);
    return `/admin/accounting/expenses/export?${params.toString()}`;
});

/* ---------- المصروف ---------- */

const showModal = ref(false);
const editing = ref<Expense | null>(null);

const form = useForm({
    expense_date: new Date().toISOString().slice(0, 10),
    amount: 0,
    expense_category_id: null as number | null,
    treasury_id: null as number | null,
    cost_center_id: null as number | null,
    supplier_id: null as number | null,
    payment_method_id: props.methods[0]?.id ?? null,
    reference: '',
    description: '',
    post_now: true,
});

// النوع يحمل مركز تكلفته الافتراضي: إيجار قاعةٍ بعينها يقع عليها دائمًا.
watch(
    () => form.expense_category_id,
    (id) => {
        if (editing.value) return;

        const category = props.categories.find((c) => c.id === id);
        if (category?.cost_center_id) form.cost_center_id = category.cost_center_id;
    },
);

const openCreate = () => {
    editing.value = null;
    form.reset();
    form.clearErrors();
    form.treasury_id = props.treasuries[0]?.id ?? null;
    form.payment_method_id = props.methods[0]?.id ?? null;
    showModal.value = true;
};

// المسوّدة وحدها تُفتح للتعديل — والمرحَّل يُلغى ويُعاد.
const openEdit = (expense: Expense) => {
    editing.value = expense;
    form.clearErrors();
    form.expense_date = expense.expense_date;
    form.amount = expense.amount;
    form.expense_category_id = expense.expense_category_id;
    form.treasury_id = expense.treasury_id;
    form.cost_center_id = expense.cost_center_id;
    form.supplier_id = expense.supplier_id;
    form.payment_method_id = expense.payment_method_id;
    form.reference = expense.reference ?? '';
    form.description = expense.description ?? '';
    form.post_now = false;
    showModal.value = true;
};

const submit = () => {
    const done = { preserveScroll: true, onSuccess: () => (showModal.value = false) };

    editing.value ? form.put(`/admin/accounting/expenses/${editing.value.id}`, done) : form.post('/admin/accounting/expenses', done);
};

const post = (expense: Expense) => {
    if (!confirm(`ترحيل المصروف ${expense.number} إلى الدفاتر؟`)) return;

    router.post(`/admin/accounting/expenses/${expense.id}/post`, {}, { preserveScroll: true });
};

const cancel = (expense: Expense) => {
    const reason = prompt(`سبب إلغاء المصروف ${expense.number}:`);
    if (reason !== null) router.post(`/admin/accounting/expenses/${expense.id}/cancel`, { reason }, { preserveScroll: true });
};

const remove = (expense: Expense) => {
    if (!confirm(`حذف المصروف ${expense.number}؟ يبقى في الأرشيف ويمكن استرجاعه.`)) return;

    router.delete(`/admin/accounting/expenses/${expense.id}`, { preserveScroll: true });
};

const statusClass = (status: string) =>
    ({ draft: 'bg-slate-100 text-slate-700', posted: 'bg-emerald-100 text-emerald-700', cancelled: 'bg-red-100 text-red-700' })[status] ??
    'bg-slate-100 text-slate-700';

/* ---------- أنواع المصروف ---------- */

const showCategories = ref(false);
const editingCategory = ref<Category | null>(null);

const categoryForm = useForm({
    code: '',
    name: '',
    description: '',
    account_id: null as number | null,
    cost_center_id: null as number | null,
    sort_order: 0,
    is_active: true,
});

const resetCategoryForm = () => {
    editingCategory.value = null;
    categoryForm.reset();
    categoryForm.clearErrors();
};

const editCategory = (category: Category) => {
    editingCategory.value = category;
    categoryForm.clearErrors();
    categoryForm.code = category.code;
    categoryForm.name = category.name;
    categoryForm.account_id = category.account_id;
    categoryForm.cost_center_id = category.cost_center_id;
    categoryForm.is_active = category.is_active;
};

const submitCategory = () => {
    const done = { preserveScroll: true, onSuccess: () => resetCategoryForm() };

    editingCategory.value
        ? categoryForm.put(`/admin/accounting/expense-categories/${editingCategory.value.id}`, done)
        : categoryForm.post('/admin/accounting/expense-categories', done);
};

const toggleCategory = (category: Category) =>
    router.patch(`/admin/accounting/expense-categories/${category.id}/toggle`, {}, { preserveScroll: true });

const removeCategory = (category: Category) => {
    if (!confirm(`حذف نوع المصروف «${category.name}»؟`)) return;

    router.delete(`/admin/accounting/expense-categories/${category.id}`, { preserveScroll: true });
};
</script>

<template>
    <Head title="المصروفات والتكاليف" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="min-h-full space-y-4 bg-slate-100 p-5">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h1 class="flex items-center gap-2 text-2xl font-extrabold text-slate-900">
                        <Receipt class="h-6 w-6 text-slate-700" /> المصروفات والتكاليف
                    </h1>
                    <p class="mt-1 text-sm font-medium text-slate-600">
                        الكهرباء والمياه والصيانة والنظافة والمشتريات والإيجارات — كل مصروف مرحَّل يخصم خزينته ويُحمَّل على وحدته.
                    </p>
                </div>
                <div class="flex items-center gap-2">
                    <button
                        v-if="can('expenses.edit')"
                        type="button"
                        @click="showCategories = true"
                        class="inline-flex items-center gap-1.5 rounded-md border border-slate-200 bg-white px-3 py-2 text-sm font-bold text-slate-700 hover:bg-slate-50"
                    >
                        <Tags class="h-4 w-4" /> أنواع المصروف
                    </button>
                    <a
                        :href="exportUrl"
                        class="inline-flex items-center gap-1.5 rounded-md border border-slate-200 bg-white px-3 py-2 text-sm font-bold text-slate-700 hover:bg-slate-50"
                    >
                        <Download class="h-4 w-4" /> تصدير
                    </a>
                    <button
                        v-if="can('expenses.create')"
                        type="button"
                        @click="openCreate"
                        class="inline-flex items-center gap-1.5 rounded-md bg-blue-600 px-4 py-2 text-sm font-bold text-white hover:bg-blue-700"
                    >
                        <Plus class="h-4 w-4" /> مصروف جديد
                    </button>
                </div>
            </div>

            <!-- المؤشرات -->
            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <div class="rounded-2xl border border-slate-200 bg-white p-4">
                    <div class="text-xs font-bold text-slate-500">مصروف المدة (مرحَّل)</div>
                    <div class="mt-1 text-2xl font-extrabold text-red-700" dir="ltr">{{ money(stats.total) }}</div>
                </div>
                <div class="rounded-2xl border border-slate-200 bg-white p-4">
                    <div class="text-xs font-bold text-slate-500">عدد المصروفات</div>
                    <div class="mt-1 text-2xl font-extrabold text-slate-900" dir="ltr">{{ stats.count }}</div>
                </div>
                <div class="rounded-2xl border border-slate-200 bg-white p-4">
                    <div class="text-xs font-bold text-slate-500">مصروف الشهر الحالي</div>
                    <div class="mt-1 text-2xl font-extrabold text-slate-900" dir="ltr">{{ money(stats.month) }}</div>
                </div>
                <div class="rounded-2xl border border-slate-200 bg-white p-4">
                    <div class="text-xs font-bold text-slate-500">مسوّدات بانتظار الترحيل</div>
                    <div class="mt-1 text-2xl font-extrabold" :class="stats.drafts ? 'text-amber-600' : 'text-slate-900'" dir="ltr">
                        {{ stats.drafts }}
                    </div>
                </div>
            </div>

            <!-- توزيع المصروف على أنواعه -->
            <div v-if="byCategory.length" class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
                <h2 class="mb-3 text-sm font-extrabold text-slate-700">فيمَ صُرف؟</h2>
                <div class="space-y-2">
                    <div v-for="row in byCategory" :key="row.category" class="flex items-center gap-3">
                        <span class="w-40 shrink-0 truncate text-xs font-bold text-slate-700">{{ row.category }}</span>
                        <div class="h-2.5 flex-1 overflow-hidden rounded-full bg-slate-100">
                            <div class="h-full rounded-full bg-red-400" :style="{ width: `${row.share}%` }" />
                        </div>
                        <span class="w-14 shrink-0 text-left text-[11px] font-bold text-slate-500" dir="ltr">{{ row.share }}%</span>
                        <span class="w-28 shrink-0 text-left text-xs font-extrabold text-slate-800" dir="ltr">{{ money(row.amount) }}</span>
                    </div>
                </div>
            </div>

            <!-- المرشّحات -->
            <div class="grid gap-2 rounded-2xl border border-slate-200 bg-white p-3 md:grid-cols-6">
                <input
                    v-model="filters.search"
                    type="search"
                    placeholder="بحث بالوصف أو رقم المصروف"
                    class="rounded-xl border border-slate-200 px-3 py-2.5 text-sm md:col-span-2"
                />
                <select v-model="filters.expense_category_id" class="rounded-xl border border-slate-200 px-3 py-2.5 text-sm">
                    <option value="">كل الأنواع</option>
                    <option v-for="c in categories" :key="c.id" :value="String(c.id)">{{ c.name }}</option>
                </select>
                <select v-model="filters.cost_center_id" class="rounded-xl border border-slate-200 px-3 py-2.5 text-sm">
                    <option value="">كل الوحدات</option>
                    <option v-for="c in costCenters" :key="c.id" :value="String(c.id)">{{ c.name }}</option>
                </select>
                <select v-model="filters.status" class="rounded-xl border border-slate-200 px-3 py-2.5 text-sm">
                    <option value="">كل الحالات</option>
                    <option v-for="s in statuses" :key="s.key" :value="s.key">{{ s.label }}</option>
                </select>
                <div class="flex gap-2">
                    <input v-model="filters.from" type="date" class="w-full rounded-xl border border-slate-200 px-2 py-2.5 text-xs" />
                    <input v-model="filters.to" type="date" class="w-full rounded-xl border border-slate-200 px-2 py-2.5 text-xs" />
                </div>
            </div>

            <!-- الجدول -->
            <div class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="bg-slate-100">
                            <tr>
                                <th class="px-4 py-3 text-right text-xs font-extrabold text-slate-700">المصروف</th>
                                <th class="px-4 py-3 text-right text-xs font-extrabold text-slate-700">النوع</th>
                                <th class="px-4 py-3 text-right text-xs font-extrabold text-slate-700">الوحدة</th>
                                <th class="px-4 py-3 text-right text-xs font-extrabold text-slate-700">الوصف</th>
                                <th class="px-4 py-3 text-right text-xs font-extrabold text-slate-700">الدفع</th>
                                <th class="px-4 py-3 text-left text-xs font-extrabold text-slate-700">المبلغ</th>
                                <th class="px-4 py-3 text-center text-xs font-extrabold text-slate-700">الحالة</th>
                                <th class="px-4 py-3 text-center text-xs font-extrabold text-slate-700">إجراءات</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="e in expenses.data" :key="e.id" class="border-t border-slate-100 hover:bg-slate-50">
                                <td class="px-4 py-2.5">
                                    <div class="font-bold text-slate-800">{{ e.number }}</div>
                                    <div class="text-[11px] text-slate-500" dir="ltr">{{ e.expense_date }}</div>
                                </td>
                                <td class="px-4 py-2.5 text-xs font-bold text-slate-700">{{ e.category ?? '—' }}</td>
                                <td class="px-4 py-2.5 text-xs text-slate-600">{{ e.unit ?? '—' }}</td>
                                <td class="px-4 py-2.5 text-xs text-slate-600">
                                    {{ e.description ?? '—' }}
                                    <span v-if="e.supplier" class="mr-1 text-[11px] text-slate-400">({{ e.supplier }})</span>
                                </td>
                                <td class="px-4 py-2.5 text-xs text-slate-600">
                                    {{ e.method_label ?? '—' }}
                                    <div class="text-[11px] text-slate-400">{{ e.treasury ?? '—' }}</div>
                                </td>
                                <td class="px-4 py-2.5 text-left font-extrabold text-red-700" dir="ltr">{{ money(e.amount) }}</td>
                                <td class="px-4 py-2.5 text-center">
                                    <span class="inline-flex rounded-md px-2 py-0.5 text-[11px] font-bold" :class="statusClass(e.status)">
                                        {{ e.status_label }}
                                    </span>
                                </td>
                                <td class="px-4 py-2.5">
                                    <div class="flex items-center justify-center gap-1">
                                        <button
                                            v-if="e.status === 'draft' && can('expenses.approve')"
                                            type="button"
                                            @click="post(e)"
                                            title="ترحيل"
                                            class="rounded-lg bg-emerald-50 p-1.5 text-emerald-700 hover:bg-emerald-100"
                                        >
                                            <CheckCircle2 class="h-4 w-4" />
                                        </button>
                                        <button
                                            v-if="e.status === 'draft' && can('expenses.edit')"
                                            type="button"
                                            @click="openEdit(e)"
                                            title="تعديل"
                                            class="rounded-lg bg-slate-100 p-1.5 text-slate-600 hover:bg-slate-200"
                                        >
                                            <PencilLine class="h-4 w-4" />
                                        </button>
                                        <button
                                            v-if="e.status !== 'cancelled' && can('expenses.approve')"
                                            type="button"
                                            @click="cancel(e)"
                                            title="إلغاء"
                                            class="rounded-lg bg-amber-50 p-1.5 text-amber-700 hover:bg-amber-100"
                                        >
                                            <Ban class="h-4 w-4" />
                                        </button>
                                        <button
                                            v-if="e.status === 'draft' && can('expenses.delete')"
                                            type="button"
                                            @click="remove(e)"
                                            title="حذف"
                                            class="rounded-lg bg-red-50 p-1.5 text-red-700 hover:bg-red-100"
                                        >
                                            <Trash2 class="h-4 w-4" />
                                        </button>
                                    </div>
                                </td>
                            </tr>
                            <tr v-if="!expenses.data.length">
                                <td colspan="8" class="px-4 py-12 text-center text-sm text-slate-500">لا مصروفات في هذه المدة</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <div v-if="expenses.links.length > 3" class="flex flex-wrap justify-center gap-1">
                <Link
                    v-for="link in expenses.links"
                    :key="link.label"
                    :href="link.url ?? '#'"
                    preserve-scroll
                    class="rounded-lg px-3 py-1.5 text-xs font-bold"
                    :class="
                        link.active
                            ? 'bg-slate-900 text-white'
                            : link.url
                              ? 'bg-white text-slate-600 ring-1 ring-slate-200 hover:bg-slate-50'
                              : 'cursor-default bg-white text-slate-300'
                    "
                    v-html="link.label"
                />
            </div>
        </div>

        <!-- نموذج تسجيل المصروف وتعديله -->
        <div v-if="showModal" class="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-slate-900/40 p-4">
            <div class="mt-10 w-full max-w-2xl rounded-2xl bg-white p-5 shadow-xl">
                <div class="mb-4 flex items-center justify-between">
                    <h2 class="text-lg font-extrabold text-slate-900">{{ editing ? `تعديل المصروف ${editing.number}` : 'تسجيل مصروف' }}</h2>
                    <button type="button" @click="showModal = false" class="rounded-lg p-1 text-slate-400 hover:bg-slate-100">
                        <X class="h-5 w-5" />
                    </button>
                </div>

                <form @submit.prevent="submit" class="grid gap-3 sm:grid-cols-2">
                    <div>
                        <label class="mb-1 block text-xs font-bold text-slate-600">نوع المصروف</label>
                        <select v-model="form.expense_category_id" class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm">
                            <option :value="null">اختر النوع</option>
                            <option v-for="c in activeCategories" :key="c.id" :value="c.id">{{ c.name }}</option>
                        </select>
                        <p v-if="form.errors.expense_category_id" class="mt-1 text-xs font-bold text-red-600">
                            {{ form.errors.expense_category_id }}
                        </p>
                    </div>

                    <div>
                        <label class="mb-1 block text-xs font-bold text-slate-600">المبلغ</label>
                        <input
                            v-model.number="form.amount"
                            type="number"
                            step="0.01"
                            min="0.01"
                            class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm"
                            dir="ltr"
                        />
                        <p v-if="form.errors.amount" class="mt-1 text-xs font-bold text-red-600">{{ form.errors.amount }}</p>
                    </div>

                    <div>
                        <label class="mb-1 block text-xs font-bold text-slate-600">التاريخ</label>
                        <input v-model="form.expense_date" type="date" class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm" />
                        <p v-if="form.errors.expense_date" class="mt-1 text-xs font-bold text-red-600">{{ form.errors.expense_date }}</p>
                    </div>

                    <div>
                        <label class="mb-1 block text-xs font-bold text-slate-600">الوحدة أو الفرع (اختياري)</label>
                        <select v-model="form.cost_center_id" class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm">
                            <option :value="null">مصروف عام</option>
                            <option v-for="c in costCenters" :key="c.id" :value="c.id">{{ c.name }}</option>
                        </select>
                    </div>

                    <div>
                        <label class="mb-1 block text-xs font-bold text-slate-600">طريقة الدفع</label>
                        <select v-model="form.payment_method_id" class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm">
                            <option v-for="m in methods" :key="m.id" :value="m.id">{{ m.label }}</option>
                        </select>
                        <p v-if="form.errors.payment_method_id" class="mt-1 text-xs font-bold text-red-600">
                            {{ form.errors.payment_method_id }}
                        </p>
                    </div>

                    <div>
                        <label class="mb-1 block text-xs font-bold text-slate-600">الخزينة</label>
                        <select v-model="form.treasury_id" class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm">
                            <option :value="null">اختر الخزينة</option>
                            <option v-for="t in treasuries" :key="t.id" :value="t.id">{{ t.name }}</option>
                        </select>
                        <p v-if="form.errors.treasury_id" class="mt-1 text-xs font-bold text-red-600">{{ form.errors.treasury_id }}</p>
                    </div>

                    <div>
                        <label class="mb-1 block text-xs font-bold text-slate-600">المورّد (اختياري)</label>
                        <select v-model="form.supplier_id" class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm">
                            <option :value="null">بلا مورّد</option>
                            <option v-for="s in suppliers" :key="s.id" :value="s.id">{{ s.name }}</option>
                        </select>
                    </div>

                    <div>
                        <label class="mb-1 block text-xs font-bold text-slate-600">المرجع (رقم فاتورة أو عدّاد)</label>
                        <input v-model="form.reference" type="text" class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm" />
                    </div>

                    <div class="sm:col-span-2">
                        <label class="mb-1 block text-xs font-bold text-slate-600">الوصف</label>
                        <textarea v-model="form.description" rows="2" class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm" />
                    </div>

                    <label v-if="!editing" class="flex items-center gap-2 text-sm font-bold text-slate-700 sm:col-span-2">
                        <input v-model="form.post_now" type="checkbox" class="h-4 w-4 rounded border-slate-300" />
                        ترحيل فوري إلى الدفاتر
                    </label>

                    <div class="flex justify-end gap-2 sm:col-span-2">
                        <button
                            type="button"
                            @click="showModal = false"
                            class="rounded-xl border border-slate-200 px-4 py-2 text-sm font-bold text-slate-600 hover:bg-slate-50"
                        >
                            إلغاء
                        </button>
                        <button
                            type="submit"
                            :disabled="form.processing"
                            class="rounded-xl bg-blue-600 px-5 py-2 text-sm font-bold text-white hover:bg-blue-700 disabled:opacity-50"
                        >
                            حفظ
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- أنواع المصروف -->
        <div v-if="showCategories" class="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-slate-900/40 p-4">
            <div class="mt-10 w-full max-w-3xl rounded-2xl bg-white p-5 shadow-xl">
                <div class="mb-4 flex items-center justify-between">
                    <div>
                        <h2 class="text-lg font-extrabold text-slate-900">أنواع المصروف</h2>
                        <p class="mt-0.5 text-xs font-medium text-slate-500">
                            كل نوع يعرف حسابه في شجرة الحسابات — فمن يسجّل المصروف لا يُسأل عن حسابٍ محاسبي.
                        </p>
                    </div>
                    <button type="button" @click="showCategories = false" class="rounded-lg p-1 text-slate-400 hover:bg-slate-100">
                        <X class="h-5 w-5" />
                    </button>
                </div>

                <form @submit.prevent="submitCategory" class="mb-4 grid gap-2 rounded-xl bg-slate-50 p-3 sm:grid-cols-5">
                    <div class="sm:col-span-1">
                        <input
                            v-model="categoryForm.code"
                            type="text"
                            placeholder="الرمز (gas)"
                            dir="ltr"
                            class="w-full rounded-xl border border-slate-200 px-3 py-2 text-sm"
                        />
                        <p v-if="categoryForm.errors.code" class="mt-1 text-[11px] font-bold text-red-600">{{ categoryForm.errors.code }}</p>
                    </div>
                    <div class="sm:col-span-1">
                        <input
                            v-model="categoryForm.name"
                            type="text"
                            placeholder="الاسم (غاز)"
                            class="w-full rounded-xl border border-slate-200 px-3 py-2 text-sm"
                        />
                        <p v-if="categoryForm.errors.name" class="mt-1 text-[11px] font-bold text-red-600">{{ categoryForm.errors.name }}</p>
                    </div>
                    <div class="sm:col-span-1">
                        <select v-model="categoryForm.account_id" class="w-full rounded-xl border border-slate-200 px-3 py-2 text-sm">
                            <option :value="null">الحساب المحاسبي</option>
                            <option v-for="a in accounts" :key="a.id" :value="a.id">{{ a.name }}</option>
                        </select>
                        <p v-if="categoryForm.errors.account_id" class="mt-1 text-[11px] font-bold text-red-600">
                            {{ categoryForm.errors.account_id }}
                        </p>
                    </div>
                    <div class="sm:col-span-1">
                        <select v-model="categoryForm.cost_center_id" class="w-full rounded-xl border border-slate-200 px-3 py-2 text-sm">
                            <option :value="null">بلا وحدة افتراضية</option>
                            <option v-for="c in costCenters" :key="c.id" :value="c.id">{{ c.name }}</option>
                        </select>
                    </div>
                    <div class="flex gap-2 sm:col-span-1">
                        <button
                            type="submit"
                            :disabled="categoryForm.processing"
                            class="flex-1 rounded-xl bg-blue-600 px-3 py-2 text-sm font-bold text-white hover:bg-blue-700 disabled:opacity-50"
                        >
                            {{ editingCategory ? 'تحديث' : 'إضافة' }}
                        </button>
                        <button
                            v-if="editingCategory"
                            type="button"
                            @click="resetCategoryForm"
                            class="rounded-xl border border-slate-200 px-3 py-2 text-sm font-bold text-slate-600 hover:bg-white"
                        >
                            إلغاء
                        </button>
                    </div>
                </form>

                <div class="max-h-96 overflow-y-auto rounded-xl border border-slate-200">
                    <table class="w-full text-sm">
                        <thead class="sticky top-0 bg-slate-100">
                            <tr>
                                <th class="px-3 py-2 text-right text-xs font-extrabold text-slate-700">النوع</th>
                                <th class="px-3 py-2 text-right text-xs font-extrabold text-slate-700">الحساب المحاسبي</th>
                                <th class="px-3 py-2 text-center text-xs font-extrabold text-slate-700">مصروفات</th>
                                <th class="px-3 py-2 text-center text-xs font-extrabold text-slate-700">الحالة</th>
                                <th class="px-3 py-2 text-center text-xs font-extrabold text-slate-700"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="c in categories" :key="c.id" class="border-t border-slate-100">
                                <td class="px-3 py-2">
                                    <div class="font-bold text-slate-800">{{ c.name }}</div>
                                    <div class="text-[11px] text-slate-400" dir="ltr">{{ c.code }}</div>
                                </td>
                                <td class="px-3 py-2 text-xs text-slate-600">{{ c.account ?? '—' }}</td>
                                <td class="px-3 py-2 text-center text-xs font-bold text-slate-600" dir="ltr">{{ c.expenses_count }}</td>
                                <td class="px-3 py-2 text-center">
                                    <span
                                        class="inline-flex rounded-md px-2 py-0.5 text-[11px] font-bold"
                                        :class="c.is_active ? 'bg-emerald-100 text-emerald-700' : 'bg-slate-100 text-slate-500'"
                                    >
                                        {{ c.is_active ? 'مفعّل' : 'موقوف' }}
                                    </span>
                                </td>
                                <td class="px-3 py-2">
                                    <div class="flex items-center justify-center gap-1">
                                        <button
                                            type="button"
                                            @click="editCategory(c)"
                                            title="تعديل"
                                            class="rounded-lg bg-slate-100 p-1.5 text-slate-600 hover:bg-slate-200"
                                        >
                                            <PencilLine class="h-3.5 w-3.5" />
                                        </button>
                                        <button
                                            type="button"
                                            @click="toggleCategory(c)"
                                            title="تفعيل/إيقاف"
                                            class="rounded-lg bg-amber-50 p-1.5 text-amber-700 hover:bg-amber-100"
                                        >
                                            <Power class="h-3.5 w-3.5" />
                                        </button>
                                        <button
                                            v-if="!c.is_system && !c.expenses_count && can('expenses.delete')"
                                            type="button"
                                            @click="removeCategory(c)"
                                            title="حذف"
                                            class="rounded-lg bg-red-50 p-1.5 text-red-700 hover:bg-red-100"
                                        >
                                            <Trash2 class="h-3.5 w-3.5" />
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
