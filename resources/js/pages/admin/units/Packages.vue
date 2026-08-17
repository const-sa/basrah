<script setup lang="ts">
import { StatPill, TableActionButton } from '@/components/data-table';
import SearchableSelect from '@/components/SearchableSelect.vue';
import { usePermissions } from '@/composables/usePermissions';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { Building2, Copy, GripVertical, Package as PackageIcon, Pencil, Plus, Power, Trash2, X } from 'lucide-vue-next';
import { ref } from 'vue';

interface Item {
    id?: number;
    name: string;
    quantity: number;
    unit_label: string | null;
    notes: string | null;
    quantity_label?: string;
}

interface Pkg {
    id: number;
    name: string;
    description: string | null;
    unit_id: number | null;
    unit_name: string | null;
    is_general: boolean;
    price: number;
    is_active: boolean;
    items: Item[];
}

const props = defineProps<{
    packages: Pkg[];
    filters: { unit_id: number | null };
    halls: { id: number; name: string }[];
    stats: { total: number; general: number; inactive: number };
}>();

const { can } = usePermissions();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'لوحة التحكم', href: '/admin' },
    { title: 'القاعات', href: '/admin/units/halls' },
    { title: 'الباقات', href: '/admin/packages' },
];

const money = (n: number) => new Intl.NumberFormat('ar-SA-u-nu-latn', { maximumFractionDigits: 2 }).format(n ?? 0);

const unitFilter = ref<number | null>(props.filters.unit_id);
const applyFilter = () =>
    router.get('/admin/packages', { unit_id: unitFilter.value }, { preserveState: true, replace: true });

// ── النموذج ─────────────────────────────────────────────────
const showModal = ref(false);
const editingId = ref<number | null>(null);

const emptyItem = (): Item => ({ name: '', quantity: 1, unit_label: null, notes: null });

const form = useForm({
    name: '',
    description: '',
    unit_id: null as number | null,
    price: 0,
    is_active: true,
    items: [] as Item[],
});

/** بنود شائعة تُضاف بضغطة — تختصر كتابتها في كل باقة. */
const presets: Array<{ name: string; unit_label: string }> = [
    { name: 'عدد المعازيم رجال', unit_label: 'شخص' },
    { name: 'عدد المعازيم نساء', unit_label: 'شخص' },
    { name: 'صبّابين', unit_label: 'فرد' },
    { name: 'مشرفات', unit_label: 'فرد' },
    { name: 'وجبات عشاء', unit_label: 'وجبة' },
    { name: 'ضيافة قهوة وتمر', unit_label: 'شخص' },
];

const openCreate = () => {
    editingId.value = null;
    form.reset();
    form.clearErrors();
    form.items = [emptyItem()];
    showModal.value = true;
};

const openEdit = (p: Pkg) => {
    editingId.value = p.id;
    form.clearErrors();
    form.name = p.name;
    form.description = p.description ?? '';
    form.unit_id = p.unit_id;
    form.price = p.price;
    form.is_active = p.is_active;
    form.items = p.items.length ? p.items.map((i) => ({ ...i })) : [emptyItem()];
    showModal.value = true;
};

const addItem = (preset?: { name: string; unit_label: string }) => {
    form.items.push(preset
        ? { name: preset.name, quantity: 1, unit_label: preset.unit_label, notes: null }
        : emptyItem());
};

const removeItem = (i: number) => {
    form.items.splice(i, 1);
    if (!form.items.length) form.items.push(emptyItem());
};

const submit = () => {
    const opts = { preserveScroll: true, onSuccess: () => (showModal.value = false) };
    editingId.value ? form.put(`/admin/packages/${editingId.value}`, opts) : form.post('/admin/packages', opts);
};

const toggle = (p: Pkg) => router.patch(`/admin/packages/${p.id}/toggle`, {}, { preserveScroll: true });
const duplicate = (p: Pkg) => router.post(`/admin/packages/${p.id}/duplicate`, {}, { preserveScroll: true });

const destroy = (p: Pkg) => {
    if (confirm(`حذف الباقة «${p.name}»؟`)) {
        router.delete(`/admin/packages/${p.id}`, { preserveScroll: true });
    }
};
</script>

<template>
    <Head title="باقات القاعات" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="min-h-full space-y-5 bg-slate-100 p-5">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h1 class="text-2xl font-extrabold text-slate-900">باقات القاعات</h1>
                    <p class="mt-1 text-sm font-medium text-slate-600">اسم الباقة وبنودها بأعدادها — تُدرج في العرض والعقد</p>
                </div>
                <div class="flex flex-wrap gap-2">
                    <Link href="/admin/units/halls" class="rounded-md border border-slate-200 bg-white px-4 py-2 text-sm font-bold text-slate-700 hover:bg-slate-50">
                        القاعات
                    </Link>
                    <button v-if="can('packages.create')" type="button" @click="openCreate" class="inline-flex items-center gap-1.5 rounded-md bg-blue-600 px-4 py-2 text-sm font-bold text-white hover:bg-blue-700">
                        <Plus class="h-4 w-4" /> باقة جديدة
                    </button>
                </div>
            </div>

            <div class="grid grid-cols-3 gap-3">
                <StatPill label="الباقات" :value="stats.total" variant="primary" />
                <StatPill label="عامة لكل القاعات" :value="stats.general" variant="info" />
                <StatPill label="معطّلة" :value="stats.inactive" variant="danger" />
            </div>

            <div class="rounded-2xl border border-slate-200 bg-white p-3 shadow-sm">
                <label class="mb-1 block text-xs font-bold text-slate-600">تصفية بالقاعة</label>
                <div class="max-w-xs">
                    <SearchableSelect v-model="unitFilter" :options="halls" placeholder="— كل القاعات —" @change="applyFilter" />
                </div>
            </div>

            <div class="grid grid-cols-1 gap-4 lg:grid-cols-2 xl:grid-cols-3">
                <div
                    v-for="p in packages" :key="p.id"
                    class="flex flex-col overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm transition hover:shadow-lg"
                    :class="!p.is_active && 'opacity-60'"
                >
                    <div class="h-1.5 brand-gradient"></div>

                    <div class="flex flex-1 flex-col p-5">
                        <div class="mb-2 flex items-start justify-between gap-2">
                            <div class="flex min-w-0 items-center gap-3">
                                <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-2xl brand-gradient text-white shadow-md">
                                    <PackageIcon class="h-5 w-5" />
                                </span>
                                <div class="min-w-0">
                                    <div class="truncate text-base font-extrabold text-slate-900">{{ p.name }}</div>
                                    <div class="mt-0.5 flex flex-wrap items-center gap-1">
                                        <span v-if="p.is_general" class="rounded bg-sky-100 px-1.5 py-0.5 text-[10px] font-bold text-sky-700">كل القاعات</span>
                                        <span v-else class="inline-flex items-center gap-0.5 rounded bg-indigo-100 px-1.5 py-0.5 text-[10px] font-bold text-indigo-700">
                                            <Building2 class="h-2.5 w-2.5" /> {{ p.unit_name }}
                                        </span>
                                        <span v-if="!p.is_active" class="rounded bg-red-100 px-1.5 py-0.5 text-[10px] font-bold text-red-600">معطّلة</span>
                                    </div>
                                </div>
                            </div>
                            <div class="flex shrink-0 items-center gap-1">
                                <TableActionButton v-if="can('packages.edit')" variant="edit" :icon="Pencil" title="تعديل" @click="openEdit(p)" />
                                <TableActionButton v-if="can('packages.create')" variant="view" :icon="Copy" title="نسخ" @click="duplicate(p)" />
                                <TableActionButton v-if="can('packages.edit')" variant="warning" :icon="Power" title="تفعيل/تعطيل" @click="toggle(p)" />
                                <TableActionButton v-if="can('packages.delete')" variant="danger" :icon="Trash2" title="حذف" @click="destroy(p)" />
                            </div>
                        </div>

                        <p v-if="p.description" class="mb-2 text-xs font-medium text-slate-500">{{ p.description }}</p>

                        <!-- بنود الباقة -->
                        <div class="mb-3 flex-1">
                            <table v-if="p.items.length" class="w-full text-sm">
                                <tbody>
                                    <tr v-for="it in p.items" :key="it.id" class="border-b border-slate-50 last:border-0">
                                        <td class="py-1.5 font-bold text-slate-700">{{ it.name }}</td>
                                        <td class="py-1.5 text-left">
                                            <span class="rounded-md bg-emerald-50 px-2 py-0.5 font-extrabold text-emerald-700" dir="ltr">
                                                {{ it.quantity_label }}
                                            </span>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                            <p v-else class="py-3 text-center text-xs font-medium text-slate-400">لا بنود</p>
                        </div>

                        <div class="flex items-center justify-between border-t border-slate-100 pt-2.5">
                            <span class="text-xs font-bold text-slate-500">{{ p.items.length }} بند</span>
                            <span v-if="p.price > 0" class="text-base font-extrabold text-emerald-600">{{ money(p.price) }}</span>
                            <span v-else class="text-[11px] font-medium text-slate-400">بلا سعر محدد</span>
                        </div>
                    </div>
                </div>

                <p v-if="!packages.length" class="rounded-2xl bg-white py-12 text-center text-sm font-medium text-slate-500 lg:col-span-2 xl:col-span-3">
                    لا باقات بعد — أضف باقة وحدّد بنودها بأعدادها.
                </p>
            </div>
        </div>

        <!-- نموذج الباقة -->
        <div v-if="showModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4" @click.self="showModal = false">
            <div class="flex max-h-[92vh] w-full max-w-3xl flex-col rounded-2xl bg-white shadow-2xl">
                <div class="flex items-center justify-between border-b border-slate-100 px-6 py-4">
                    <h2 class="text-lg font-extrabold text-slate-900">{{ editingId ? 'تعديل باقة' : 'باقة جديدة' }}</h2>
                    <button type="button" @click="showModal = false" class="rounded-lg p-1 text-slate-400 hover:bg-slate-100"><X class="h-5 w-5" /></button>
                </div>

                <form @submit.prevent="submit" class="flex min-h-0 flex-1 flex-col">
                    <div class="min-h-0 flex-1 space-y-4 overflow-y-auto px-6 py-4">
                        <div class="grid gap-3 sm:grid-cols-[1fr_180px_140px]">
                            <div>
                                <label class="mb-1 block text-sm font-bold text-slate-700">اسم الباقة</label>
                                <input v-model="form.name" class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm focus:border-emerald-400 focus:outline-none focus:ring-2 focus:ring-emerald-100" placeholder="باقة الزفاف الفضية" />
                                <p v-if="form.errors.name" class="mt-1 text-xs text-red-500">{{ form.errors.name }}</p>
                            </div>
                            <div>
                                <label class="mb-1 block text-sm font-bold text-slate-700">القاعة</label>
                                <SearchableSelect v-model="form.unit_id" :options="halls" placeholder="— كل القاعات —" />
                            </div>
                            <div>
                                <label class="mb-1 block text-sm font-bold text-slate-700">السعر</label>
                                <input v-model.number="form.price" type="number" min="0" step="0.01" class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm" />
                            </div>
                        </div>

                        <div>
                            <label class="mb-1 block text-sm font-bold text-slate-700">الوصف</label>
                            <textarea v-model="form.description" rows="2" class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm"></textarea>
                        </div>

                        <!-- البنود -->
                        <div>
                            <div class="mb-2 flex flex-wrap items-center justify-between gap-2">
                                <label class="text-sm font-bold text-slate-700">بنود الباقة</label>
                                <div class="flex flex-wrap gap-1">
                                    <button
                                        v-for="ps in presets" :key="ps.name" type="button" @click="addItem(ps)"
                                        class="rounded-lg bg-slate-100 px-2 py-1 text-[10px] font-bold text-slate-600 transition hover:bg-emerald-100 hover:text-emerald-700"
                                    >+ {{ ps.name }}</button>
                                </div>
                            </div>

                            <div class="overflow-hidden rounded-xl border border-slate-200">
                                <table class="w-full text-sm">
                                    <thead class="bg-slate-100">
                                        <tr>
                                            <th class="w-8 px-2 py-2"></th>
                                            <th class="px-3 py-2 text-right text-xs font-extrabold text-slate-700">الصنف</th>
                                            <th class="w-28 px-3 py-2 text-center text-xs font-extrabold text-slate-700">العدد</th>
                                            <th class="w-28 px-3 py-2 text-center text-xs font-extrabold text-slate-700">الوحدة</th>
                                            <th class="w-12 px-2 py-2"></th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr v-for="(it, i) in form.items" :key="i" class="border-t border-slate-100">
                                            <td class="px-2 py-1.5 text-center text-slate-300"><GripVertical class="mx-auto h-3.5 w-3.5" /></td>
                                            <td class="px-3 py-1.5">
                                                <input v-model="it.name" class="w-full rounded-lg border border-slate-200 px-2.5 py-2 text-sm" placeholder="عدد المعازيم رجال" />
                                            </td>
                                            <td class="px-3 py-1.5">
                                                <input v-model.number="it.quantity" type="number" min="0" step="1" class="w-full rounded-lg border border-slate-200 px-2 py-2 text-center text-sm font-bold" />
                                            </td>
                                            <td class="px-3 py-1.5">
                                                <input v-model="it.unit_label" class="w-full rounded-lg border border-slate-200 px-2 py-2 text-center text-xs" placeholder="شخص" />
                                            </td>
                                            <td class="px-2 py-1.5 text-center">
                                                <button type="button" @click="removeItem(i)" class="rounded-lg p-1.5 text-red-400 transition hover:bg-red-50 hover:text-red-600">
                                                    <Trash2 class="h-4 w-4" />
                                                </button>
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>

                                <div class="border-t border-slate-100 p-2">
                                    <button type="button" @click="addItem()" class="inline-flex items-center gap-1.5 rounded-lg border border-dashed border-slate-300 px-3 py-1.5 text-xs font-bold text-slate-600 transition hover:border-emerald-400 hover:bg-emerald-50 hover:text-emerald-600">
                                        <Plus class="h-3.5 w-3.5" /> بند جديد
                                    </button>
                                </div>
                            </div>
                            <p class="mt-1 text-[11px] font-medium text-slate-500">
                                البند وصف يُكتب في العرض والعقد — لا يُخصم من المخزون.
                            </p>
                        </div>

                        <label class="flex cursor-pointer items-center gap-2 text-sm font-bold text-slate-700">
                            <input type="checkbox" v-model="form.is_active" class="h-4 w-4 rounded border-slate-300 text-emerald-600" />
                            الباقة فعّالة ومتاحة للعرض
                        </label>
                    </div>

                    <div class="flex justify-end gap-2 border-t border-slate-100 px-6 py-4">
                        <button type="button" @click="showModal = false" class="rounded-xl border border-slate-200 bg-white px-4 py-2 text-sm font-bold text-slate-600 hover:bg-slate-50">إلغاء</button>
                        <button type="submit" :disabled="form.processing" class="rounded-md bg-blue-600 px-5 py-2 text-sm font-bold text-white shadow-sm transition hover:bg-blue-700 disabled:opacity-60">حفظ</button>
                    </div>
                </form>
            </div>
        </div>
    </AppLayout>
</template>
