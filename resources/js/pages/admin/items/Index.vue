<script setup lang="ts">
import { StatPill, TableActionButton } from '@/components/data-table';
import { usePermissions } from '@/composables/usePermissions';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { AlertTriangle, ClipboardList, History, Pencil, Plus, Power, Search, Trash2, X } from 'lucide-vue-next';
import { computed, ref } from 'vue';

interface Component { id: number; name: string; quantity: number }
interface Item {
    id: number; code: string; barcode: string | null; name: string;
    category: string | null; category_id: number | null;
    department: string | null; department_id: number | null;
    type: string; type_label: string;
    unit: string; measure_unit_id: number | null; unit_label: string;
    cost: number; price: number; tax_rate: number;
    stock_qty: number; reorder_point: number;
    tracks_stock: boolean; low_stock: boolean; is_active: boolean;
    components: Component[];
}
interface Option { key: string; label: string }

const props = defineProps<{
    items: { data: Item[]; links: { url: string | null; label: string; active: boolean }[] };
    filters: Record<string, string | null>;
    categories: { id: number; name: string }[];
    departments: { id: number; name: string }[];
    types: Option[];
    measureUnits: { id: number; name: string; symbol: string | null; allows_fraction: boolean }[];
    stockItems: { id: number; name: string; stock_qty: number }[];
    stats: { total: number; low_stock: number; stock_value: number; categories: number };
}>();

const { can } = usePermissions();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'لوحة التحكم', href: '/admin' },
    { title: 'الأصناف والمخزون', href: '/admin/items' },
];

const money = (n: number) => new Intl.NumberFormat('ar-SA-u-nu-latn', { maximumFractionDigits: 2 }).format(n ?? 0);

const filters = ref({ ...props.filters });
const applyFilters = () => router.get('/admin/items', filters.value, { preserveState: true, replace: true });

// ── نموذج الصنف ─────────────────────────────────────────────
const showModal = ref(false);
const editingId = ref<number | null>(null);

const form = useForm({
    code: '', barcode: '', name: '', item_category_id: null as number | null,
    department_id: null as number | null,
    type: 'stock', unit: 'piece', measure_unit_id: null as number | null,
    cost: 0, price: 0, tax_rate: 15, stock_qty: 0, reorder_point: 0,
    description: '', is_active: true,
    components: [] as { component_id: number | null; quantity: number }[],
});

const isBundle = computed(() => form.type === 'bundle');

const openCreate = () => {
    editingId.value = null;
    form.reset();
    form.clearErrors();
    showModal.value = true;
};

const openEdit = (i: Item) => {
    editingId.value = i.id;
    form.clearErrors();
    form.code = i.code;
    form.barcode = i.barcode ?? '';
    form.name = i.name;
    form.item_category_id = i.category_id;
    form.department_id = i.department_id;
    form.type = i.type;
    form.unit = i.unit;
    form.measure_unit_id = i.measure_unit_id;
    form.cost = i.cost;
    form.price = i.price;
    form.tax_rate = i.tax_rate;
    form.stock_qty = i.stock_qty;
    form.reorder_point = i.reorder_point;
    form.is_active = i.is_active;
    form.components = i.components.map((c) => ({ component_id: c.id, quantity: c.quantity }));
    showModal.value = true;
};

const addComponent = () => form.components.push({ component_id: null, quantity: 1 });
const removeComponent = (i: number) => form.components.splice(i, 1);

const submit = () => {
    const opts = { preserveScroll: true, onSuccess: () => (showModal.value = false) };
    editingId.value ? form.put(`/admin/items/${editingId.value}`, opts) : form.post('/admin/items', opts);
};

const toggle = (i: Item) => router.patch(`/admin/items/${i.id}/toggle`, {}, { preserveScroll: true });
const destroy = (i: Item) => {
    if (confirm(`حذف الصنف «${i.name}»؟`)) router.delete(`/admin/items/${i.id}`, { preserveScroll: true });
};

// ── الجرد ───────────────────────────────────────────────────
const showStocktake = ref(false);
const stocktake = useForm({
    adjustments: [] as { item_id: number; name: string; current: number; counted_qty: number }[],
    notes: '',
});

const openStocktake = () => {
    stocktake.reset();
    stocktake.adjustments = props.stockItems.map((i) => ({
        item_id: i.id, name: i.name, current: i.stock_qty, counted_qty: i.stock_qty,
    }));
    showStocktake.value = true;
};

const submitStocktake = () => {
    stocktake
        .transform((d) => ({
            notes: d.notes,
            // لا تُرسل إلا الأصناف التي اختلف معدودها عن الدفتري
            adjustments: d.adjustments
                .filter((a) => a.counted_qty !== a.current)
                .map((a) => ({ item_id: a.item_id, counted_qty: a.counted_qty })),
        }))
        .post('/admin/inventory/adjust', { preserveScroll: true, onSuccess: () => (showStocktake.value = false) });
};

const changedCount = computed(() => stocktake.adjustments.filter((a) => a.counted_qty !== a.current).length);

const typeClass = (t: string) =>
    ({
        stock: 'bg-sky-100 text-sky-700',
        service: 'bg-violet-100 text-violet-700',
        bundle: 'bg-amber-100 text-amber-700',
        measured: 'bg-teal-100 text-teal-700',
    })[t] ?? 'bg-slate-100 text-slate-700';
</script>

<template>
    <Head title="الأصناف والمخزون" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="min-h-full space-y-4 bg-slate-100 p-5">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h1 class="text-2xl font-extrabold text-slate-900">الأصناف والمخزون</h1>
                    <p class="mt-1 text-sm font-medium text-slate-600">مخزني · خدمي · حزمة تخصم مكوّناتها · بالقياس</p>
                </div>
                <div class="flex flex-wrap gap-2">
                    <Link href="/admin/inventory/movements" class="inline-flex items-center gap-1.5 rounded-md border border-slate-200 bg-white px-4 py-2 text-sm font-bold text-slate-700 hover:bg-slate-50">
                        <History class="h-4 w-4" /> حركات المخزون
                    </Link>
                    <button v-if="can('inventory.approve')" type="button" @click="openStocktake" class="inline-flex items-center gap-1.5 rounded-md bg-amber-500 px-4 py-2 text-sm font-bold text-white hover:bg-amber-600">
                        <ClipboardList class="h-4 w-4" /> جرد وتسوية
                    </button>
                    <button v-if="can('items.create')" type="button" @click="openCreate" class="inline-flex items-center gap-1.5 rounded-md bg-blue-600 px-4 py-2 text-sm font-bold text-white hover:bg-blue-700">
                        <Plus class="h-4 w-4" /> صنف جديد
                    </button>
                </div>
            </div>

            <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
                <StatPill label="الأصناف" :value="stats.total" variant="primary" />
                <StatPill label="تحت حد الطلب" :value="stats.low_stock" variant="danger" />
                <StatPill label="قيمة المخزون" :value="money(stats.stock_value)" variant="success" />
                <StatPill label="التصنيفات" :value="stats.categories" variant="dark" />
            </div>

            <div class="rounded-2xl border border-slate-200 bg-white p-3 shadow-sm">
                <div class="grid gap-2 sm:grid-cols-2 lg:grid-cols-5">
                    <div class="relative">
                        <Search class="absolute top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400 ltr:left-3 rtl:right-3" />
                        <input v-model="filters.search" @keyup.enter="applyFilters" placeholder="اسم أو كود أو باركود" class="w-full rounded-xl border border-slate-200 py-2.5 text-sm ltr:pl-9 ltr:pr-3 rtl:pl-3 rtl:pr-9" />
                    </div>
                    <select v-model="filters.type" @change="applyFilters" class="rounded-xl border border-slate-200 px-3 py-2.5 text-sm">
                        <option :value="null">كل الأنواع</option>
                        <option v-for="t in types" :key="t.key" :value="t.key">{{ t.label }}</option>
                    </select>
                    <select v-model="filters.department_id" @change="applyFilters" class="rounded-xl border border-slate-200 px-3 py-2.5 text-sm">
                        <option :value="null">كل الأقسام</option>
                        <option v-for="d in departments" :key="d.id" :value="d.id">{{ d.name }}</option>
                    </select>
                    <select v-model="filters.category_id" @change="applyFilters" class="rounded-xl border border-slate-200 px-3 py-2.5 text-sm">
                        <option :value="null">كل التصنيفات</option>
                        <option v-for="c in categories" :key="c.id" :value="c.id">{{ c.name }}</option>
                    </select>
                    <label class="flex cursor-pointer items-center gap-2 rounded-xl border border-slate-200 px-3 py-2.5 text-sm font-bold text-slate-700">
                        <input type="checkbox" v-model="filters.low_stock" @change="applyFilters" class="h-4 w-4 rounded border-slate-300 text-red-600" />
                        تحت حد الطلب فقط
                    </label>
                </div>
            </div>

            <div class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="bg-slate-100">
                            <tr>
                                <th class="px-4 py-3 text-right text-xs font-extrabold text-[#1e3a8a]">الصنف</th>
                                <th class="px-4 py-3 text-center text-xs font-extrabold text-[#1e3a8a]">القسم</th>
                                <th class="px-4 py-3 text-center text-xs font-extrabold text-[#1e3a8a]">النوع</th>
                                <th class="px-4 py-3 text-right text-xs font-extrabold text-[#1e3a8a]">التكلفة / السعر</th>
                                <th class="px-4 py-3 text-center text-xs font-extrabold text-[#1e3a8a]">الرصيد</th>
                                <th class="px-4 py-3 text-center text-xs font-extrabold text-[#1e3a8a]">إجراءات</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="i in items.data" :key="i.id" class="border-t border-slate-100 hover:bg-slate-50" :class="!i.is_active && 'opacity-50'">
                                <td class="px-4 py-3">
                                    <div class="font-bold text-slate-800">{{ i.name }}</div>
                                    <div class="text-[11px] text-slate-500" dir="ltr">{{ i.code }}{{ i.barcode ? ` · ${i.barcode}` : '' }}</div>
                                    <div v-if="i.components.length" class="mt-0.5 text-[10px] font-bold text-amber-700">
                                        مكوّنات: {{ i.components.map((c) => `${c.name} ×${c.quantity}`).join('، ') }}
                                    </div>
                                </td>
                                <td class="px-4 py-3 text-center">
                                    <span v-if="i.department" class="rounded-md bg-indigo-100 px-2 py-0.5 text-[11px] font-bold text-indigo-700">{{ i.department }}</span>
                                    <span v-else class="text-[11px] text-slate-400">—</span>
                                </td>
                                <td class="px-4 py-3 text-center">
                                    <span class="rounded-md px-2 py-0.5 text-[11px] font-bold" :class="typeClass(i.type)">{{ i.type_label }}</span>
                                </td>
                                <td class="px-4 py-3">
                                    <div class="text-xs text-slate-500">{{ money(i.cost) }}</div>
                                    <div class="font-extrabold text-emerald-600">{{ money(i.price) }}</div>
                                </td>
                                <td class="px-4 py-3 text-center">
                                    <template v-if="i.tracks_stock">
                                        <span class="font-extrabold" :class="i.low_stock ? 'text-red-600' : 'text-slate-800'">{{ i.stock_qty }}</span>
                                        <div class="text-[10px] text-slate-500">{{ i.unit_label }} · حد {{ i.reorder_point }}</div>
                                        <span v-if="i.low_stock" class="mt-0.5 inline-flex items-center gap-0.5 rounded bg-red-100 px-1.5 text-[10px] font-bold text-red-700">
                                            <AlertTriangle class="h-2.5 w-2.5" /> أعد الطلب
                                        </span>
                                    </template>
                                    <span v-else class="text-[11px] text-slate-400">—</span>
                                </td>
                                <td class="px-4 py-3">
                                    <div class="flex items-center justify-center gap-1">
                                        <TableActionButton v-if="can('items.edit')" variant="edit" :icon="Pencil" title="تعديل" @click="openEdit(i)" />
                                        <TableActionButton v-if="can('items.edit')" variant="warning" :icon="Power" title="تفعيل/تعطيل" @click="toggle(i)" />
                                        <TableActionButton v-if="can('items.delete')" variant="danger" :icon="Trash2" title="حذف" @click="destroy(i)" />
                                    </div>
                                </td>
                            </tr>
                            <tr v-if="!items.data.length"><td colspan="6" class="px-4 py-10 text-center text-sm text-slate-500">لا أصناف</td></tr>
                        </tbody>
                    </table>
                </div>

                <div v-if="items.links.length > 3" class="flex flex-wrap justify-center gap-1 border-t border-slate-100 p-3">
                    <Link v-for="l in items.links" :key="l.label" :href="l.url ?? '#'"
                        :class="['rounded-lg px-3 py-1.5 text-xs font-bold', l.active ? 'bg-blue-600 text-white' : l.url ? 'bg-white text-slate-600 ring-1 ring-slate-200' : 'text-slate-300']"
                        v-html="l.label" />
                </div>
            </div>
        </div>

        <!-- نموذج الصنف -->
        <div v-if="showModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4" @click.self="showModal = false">
            <div class="flex max-h-[92vh] w-full max-w-2xl flex-col rounded-2xl bg-white shadow-2xl">
                <div class="flex items-center justify-between border-b border-slate-100 px-6 py-4">
                    <h2 class="text-lg font-extrabold text-slate-900">{{ editingId ? 'تعديل صنف' : 'صنف جديد' }}</h2>
                    <button type="button" @click="showModal = false" class="text-slate-400 hover:text-slate-600"><X class="h-5 w-5" /></button>
                </div>

                <form @submit.prevent="submit" class="flex min-h-0 flex-1 flex-col">
                    <div class="min-h-0 flex-1 space-y-3 overflow-y-auto px-6 py-4">
                        <div class="grid gap-3 sm:grid-cols-3">
                            <div>
                                <label class="mb-1 block text-sm font-bold text-slate-700">الكود</label>
                                <input v-model="form.code" dir="ltr" class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm" />
                                <p v-if="form.errors.code" class="mt-1 text-xs text-red-500">{{ form.errors.code }}</p>
                            </div>
                            <div class="sm:col-span-2">
                                <label class="mb-1 block text-sm font-bold text-slate-700">اسم الصنف</label>
                                <input v-model="form.name" class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm" />
                                <p v-if="form.errors.name" class="mt-1 text-xs text-red-500">{{ form.errors.name }}</p>
                            </div>
                        </div>

                        <div class="grid gap-3 sm:grid-cols-3">
                            <div>
                                <label class="mb-1 block text-sm font-bold text-slate-700">النوع</label>
                                <select v-model="form.type" class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm">
                                    <option v-for="t in types" :key="t.key" :value="t.key">{{ t.label }}</option>
                                </select>
                            </div>
                            <div>
                                <label class="mb-1 block text-sm font-bold text-slate-700">وحدة القياس</label>
                                <select v-model="form.measure_unit_id" class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm">
                                    <option :value="null">—</option>
                                    <option v-for="u in measureUnits" :key="u.id" :value="u.id">{{ u.name }}{{ u.symbol ? ` (${u.symbol})` : '' }}</option>
                                </select>
                            </div>
                            <div>
                                <label class="mb-1 block text-sm font-bold text-slate-700">القسم</label>
                                <select v-model="form.department_id" class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm">
                                    <option :value="null">—</option>
                                    <option v-for="d in departments" :key="d.id" :value="d.id">{{ d.name }}</option>
                                </select>
                            </div>
                            <div>
                                <label class="mb-1 block text-sm font-bold text-slate-700">التصنيف</label>
                                <select v-model="form.item_category_id" class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm">
                                    <option :value="null">—</option>
                                    <option v-for="c in categories" :key="c.id" :value="c.id">{{ c.name }}</option>
                                </select>
                            </div>
                        </div>

                        <div class="grid gap-3 sm:grid-cols-4">
                            <div>
                                <label class="mb-1 block text-sm font-bold text-slate-700">التكلفة</label>
                                <input v-model.number="form.cost" type="number" min="0" step="0.01" class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm" />
                            </div>
                            <div>
                                <label class="mb-1 block text-sm font-bold text-slate-700">سعر البيع</label>
                                <input v-model.number="form.price" type="number" min="0" step="0.01" class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm" />
                            </div>
                            <div>
                                <label class="mb-1 block text-sm font-bold text-slate-700">الضريبة %</label>
                                <input v-model.number="form.tax_rate" type="number" min="0" max="100" step="0.01" class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm" />
                            </div>
                            <div>
                                <label class="mb-1 block text-sm font-bold text-slate-700">حد إعادة الطلب</label>
                                <input v-model.number="form.reorder_point" type="number" min="0" step="0.001" class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm" />
                            </div>
                        </div>

                        <div v-if="!editingId && form.type !== 'service' && form.type !== 'bundle'">
                            <label class="mb-1 block text-sm font-bold text-slate-700">الرصيد الافتتاحي</label>
                            <input v-model.number="form.stock_qty" type="number" min="0" step="0.001" class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm" />
                        </div>
                        <p v-else-if="editingId" class="rounded-xl bg-slate-50 px-3 py-2 text-[11px] font-medium text-slate-500">
                            الرصيد لا يُعدَّل من هنا — يتغيّر بالبيع والشراء والجرد فقط.
                        </p>

                        <!-- مكوّنات الحزمة -->
                        <div v-if="isBundle" class="rounded-xl border border-amber-200 bg-amber-50 p-3">
                            <div class="mb-2 flex items-center justify-between">
                                <label class="text-sm font-bold text-amber-900">مكوّنات الحزمة (تُخصم من المخزون عند البيع)</label>
                                <button type="button" @click="addComponent" class="rounded-lg bg-white px-2 py-1 text-[11px] font-bold text-amber-700 ring-1 ring-amber-200">+ مكوّن</button>
                            </div>
                            <div class="space-y-1.5">
                                <div v-for="(c, i) in form.components" :key="i" class="flex items-center gap-2">
                                    <select v-model="c.component_id" class="flex-1 rounded-lg border border-slate-200 px-2 py-1.5 text-xs">
                                        <option :value="null">— اختر صنفًا —</option>
                                        <option v-for="s in stockItems" :key="s.id" :value="s.id">{{ s.name }}</option>
                                    </select>
                                    <input v-model.number="c.quantity" type="number" min="0.001" step="0.001" class="w-20 rounded-lg border border-slate-200 px-2 py-1.5 text-xs" />
                                    <button type="button" @click="removeComponent(i)" class="text-red-500 hover:text-red-700"><Trash2 class="h-3.5 w-3.5" /></button>
                                </div>
                                <p v-if="!form.components.length" class="text-[11px] text-amber-700">أضف المكوّنات وإلا لن تُخصم أي كمية عند بيع الحزمة.</p>
                            </div>
                        </div>
                    </div>

                    <div class="flex justify-end gap-2 border-t border-slate-100 px-6 py-4">
                        <button type="button" @click="showModal = false" class="rounded-xl border border-slate-200 px-4 py-2 text-sm font-bold text-slate-600">إلغاء</button>
                        <button type="submit" :disabled="form.processing" class="rounded-md bg-blue-600 px-5 py-2 text-sm font-bold text-white hover:bg-blue-700 disabled:opacity-60">حفظ</button>
                    </div>
                </form>
            </div>
        </div>

        <!-- الجرد -->
        <div v-if="showStocktake" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4" @click.self="showStocktake = false">
            <div class="flex max-h-[92vh] w-full max-w-2xl flex-col rounded-2xl bg-white shadow-2xl">
                <div class="flex items-center justify-between border-b border-slate-100 px-6 py-4">
                    <h2 class="text-lg font-extrabold text-slate-900">جرد وتسوية</h2>
                    <button type="button" @click="showStocktake = false" class="text-slate-400 hover:text-slate-600"><X class="h-5 w-5" /></button>
                </div>

                <form @submit.prevent="submitStocktake" class="flex min-h-0 flex-1 flex-col">
                    <div class="min-h-0 flex-1 overflow-y-auto px-6 py-4">
                        <table class="w-full text-sm">
                            <thead class="bg-slate-50">
                                <tr>
                                    <th class="px-2 py-2 text-right text-xs font-extrabold text-[#1e3a8a]">الصنف</th>
                                    <th class="px-2 py-2 text-center text-xs font-extrabold text-[#1e3a8a]">الدفتري</th>
                                    <th class="px-2 py-2 text-center text-xs font-extrabold text-[#1e3a8a]">المعدود</th>
                                    <th class="px-2 py-2 text-center text-xs font-extrabold text-[#1e3a8a]">الفرق</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="a in stocktake.adjustments" :key="a.item_id" class="border-t border-slate-100">
                                    <td class="px-2 py-1.5 font-bold text-slate-700">{{ a.name }}</td>
                                    <td class="px-2 py-1.5 text-center text-slate-600">{{ a.current }}</td>
                                    <td class="px-2 py-1.5 text-center">
                                        <input v-model.number="a.counted_qty" type="number" min="0" step="0.001" class="w-24 rounded-lg border border-slate-200 px-2 py-1 text-center text-xs" />
                                    </td>
                                    <td class="px-2 py-1.5 text-center text-xs font-bold" :class="a.counted_qty === a.current ? 'text-slate-400' : a.counted_qty > a.current ? 'text-emerald-600' : 'text-red-600'">
                                        {{ a.counted_qty === a.current ? '—' : (a.counted_qty > a.current ? '+' : '') + (a.counted_qty - a.current).toFixed(3) }}
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <div class="flex items-center justify-between gap-2 border-t border-slate-100 px-6 py-4">
                        <span class="text-xs font-bold" :class="changedCount ? 'text-amber-700' : 'text-slate-400'">{{ changedCount }} صنف مختلف</span>
                        <div class="flex gap-2">
                            <button type="button" @click="showStocktake = false" class="rounded-xl border border-slate-200 px-4 py-2 text-sm font-bold text-slate-600">إلغاء</button>
                            <button type="submit" :disabled="stocktake.processing || !changedCount" class="rounded-md bg-amber-500 px-5 py-2 text-sm font-bold text-white hover:bg-amber-600 disabled:opacity-50">اعتماد التسوية</button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </AppLayout>
</template>
