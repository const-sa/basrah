<script setup lang="ts">
import { TableActionButton } from '@/components/data-table';
import { usePermissions } from '@/composables/usePermissions';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { Boxes, Pencil, Plus, Ruler, ShoppingCart, Trash2, X } from 'lucide-vue-next';
import { ref } from 'vue';

interface MeasureUnit {
    id: number; code: string; name: string; symbol: string | null;
    allows_fraction: boolean; is_active: boolean; items_count: number;
}
interface Dept {
    id: number; code: string | null; name: string; description: string | null;
    sells: boolean; is_active: boolean; items_count: number; stock_value: number;
}

defineProps<{ units: MeasureUnit[]; departments: Dept[] }>();

const { can } = usePermissions();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'لوحة التحكم', href: '/admin' },
    { title: 'الأصناف', href: '/admin/items' },
    { title: 'الأقسام ووحدات القياس', href: '/admin/inventory/units' },
];

const money = (n: number) => new Intl.NumberFormat('ar-SA-u-nu-latn', { maximumFractionDigits: 2 }).format(n ?? 0);

// ── وحدات القياس ────────────────────────────────────────────
const showUnit = ref(false);
const editingUnit = ref<number | null>(null);
const unitForm = useForm({ code: '', name: '', symbol: '', allows_fraction: false, is_active: true });

const openUnit = (u?: MeasureUnit) => {
    unitForm.reset();
    unitForm.clearErrors();
    editingUnit.value = u?.id ?? null;
    if (u) Object.assign(unitForm, { code: u.code, name: u.name, symbol: u.symbol ?? '', allows_fraction: u.allows_fraction, is_active: u.is_active });
    showUnit.value = true;
};

const submitUnit = () => {
    const opts = { preserveScroll: true, onSuccess: () => (showUnit.value = false) };
    editingUnit.value
        ? unitForm.put(`/admin/inventory/units/${editingUnit.value}`, opts)
        : unitForm.post('/admin/inventory/units', opts);
};

const destroyUnit = (u: MeasureUnit) => {
    if (confirm(`حذف وحدة «${u.name}»؟`)) router.delete(`/admin/inventory/units/${u.id}`, { preserveScroll: true });
};

// ── الأقسام ─────────────────────────────────────────────────
const showDept = ref(false);
const editingDept = ref<number | null>(null);
const deptForm = useForm({ code: '', name: '', description: '', sells: false, is_active: true });

const openDept = (d?: Dept) => {
    deptForm.reset();
    deptForm.clearErrors();
    editingDept.value = d?.id ?? null;
    if (d) Object.assign(deptForm, { code: d.code ?? '', name: d.name, description: d.description ?? '', sells: d.sells, is_active: d.is_active });
    showDept.value = true;
};

const submitDept = () => {
    const opts = { preserveScroll: true, onSuccess: () => (showDept.value = false) };
    editingDept.value
        ? deptForm.put(`/admin/inventory/departments/${editingDept.value}`, opts)
        : deptForm.post('/admin/inventory/departments', opts);
};

const destroyDept = (d: Dept) => {
    if (confirm(`حذف قسم «${d.name}»؟`)) router.delete(`/admin/inventory/departments/${d.id}`, { preserveScroll: true });
};
</script>

<template>
    <Head title="الأقسام ووحدات القياس" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="min-h-full space-y-5 bg-slate-100 p-5">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h1 class="text-2xl font-extrabold text-slate-900">الأقسام ووحدات القياس</h1>
                    <p class="mt-1 text-sm font-medium text-slate-600">المستودع يُنظَّم بالأقسام، والقسم البائع تُفتح عليه شاشة الفواتير</p>
                </div>
                <Link href="/admin/items" class="rounded-md border border-slate-200 bg-white px-4 py-2 text-sm font-bold text-slate-700 hover:bg-slate-50">الأصناف</Link>
            </div>

            <!-- الأقسام -->
            <div class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                <div class="flex items-center justify-between border-b border-slate-100 px-4 py-3">
                    <h2 class="flex items-center gap-1.5 font-extrabold text-slate-800">
                        <Boxes class="h-4 w-4" /> أقسام المستودع
                    </h2>
                    <button v-if="can('items.create')" type="button" @click="openDept()" class="inline-flex items-center gap-1 rounded-md bg-blue-600 px-3 py-1.5 text-xs font-bold text-white hover:bg-blue-700">
                        <Plus class="h-3.5 w-3.5" /> قسم جديد
                    </button>
                </div>

                <table class="w-full text-sm">
                    <thead class="bg-slate-50">
                        <tr>
                            <th class="px-4 py-2.5 text-right text-xs font-extrabold text-[#1e3a8a]">القسم</th>
                            <th class="px-4 py-2.5 text-center text-xs font-extrabold text-[#1e3a8a]">مبيعات</th>
                            <th class="px-4 py-2.5 text-center text-xs font-extrabold text-[#1e3a8a]">الأصناف</th>
                            <th class="px-4 py-2.5 text-left text-xs font-extrabold text-[#1e3a8a]">قيمة المخزون</th>
                            <th class="px-4 py-2.5 text-center text-xs font-extrabold text-[#1e3a8a]">إجراءات</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="d in departments" :key="d.id" class="border-t border-slate-100 hover:bg-slate-50" :class="!d.is_active && 'opacity-50'">
                            <td class="px-4 py-2.5">
                                <div class="flex items-center gap-2">
                                    <span class="font-bold text-slate-800">{{ d.name }}</span>
                                    <span v-if="d.code" class="font-mono text-[10px] text-slate-400" dir="ltr">{{ d.code }}</span>
                                </div>
                                <div v-if="d.description" class="text-[11px] text-slate-500">{{ d.description }}</div>
                            </td>
                            <td class="px-4 py-2.5 text-center">
                                <span v-if="d.sells" class="inline-flex items-center gap-1 rounded-md bg-emerald-100 px-2 py-0.5 text-[11px] font-bold text-emerald-700">
                                    <ShoppingCart class="h-3 w-3" /> يبيع
                                </span>
                                <span v-else class="text-[11px] text-slate-400">—</span>
                            </td>
                            <td class="px-4 py-2.5 text-center font-bold text-slate-700">{{ d.items_count }}</td>
                            <td class="px-4 py-2.5 text-left font-extrabold text-slate-800" dir="ltr">{{ money(d.stock_value) }}</td>
                            <td class="px-4 py-2.5">
                                <div class="flex items-center justify-center gap-1">
                                    <TableActionButton v-if="can('items.edit')" variant="edit" :icon="Pencil" title="تعديل" @click="openDept(d)" />
                                    <TableActionButton v-if="can('items.delete')" variant="danger" :icon="Trash2" title="حذف" @click="destroyDept(d)" />
                                </div>
                            </td>
                        </tr>
                        <tr v-if="!departments.length"><td colspan="5" class="px-4 py-8 text-center text-sm text-slate-500">لا أقسام</td></tr>
                    </tbody>
                </table>
            </div>

            <!-- وحدات القياس -->
            <div class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                <div class="flex items-center justify-between border-b border-slate-100 px-4 py-3">
                    <h2 class="flex items-center gap-1.5 font-extrabold text-slate-800">
                        <Ruler class="h-4 w-4" /> وحدات القياس
                    </h2>
                    <button v-if="can('items.create')" type="button" @click="openUnit()" class="inline-flex items-center gap-1 rounded-md bg-blue-600 px-3 py-1.5 text-xs font-bold text-white hover:bg-blue-700">
                        <Plus class="h-3.5 w-3.5" /> وحدة جديدة
                    </button>
                </div>

                <table class="w-full text-sm">
                    <thead class="bg-slate-50">
                        <tr>
                            <th class="px-4 py-2.5 text-right text-xs font-extrabold text-[#1e3a8a]">الوحدة</th>
                            <th class="px-4 py-2.5 text-center text-xs font-extrabold text-[#1e3a8a]">الرمز</th>
                            <th class="px-4 py-2.5 text-center text-xs font-extrabold text-[#1e3a8a]">تقبل الكسور</th>
                            <th class="px-4 py-2.5 text-center text-xs font-extrabold text-[#1e3a8a]">الأصناف</th>
                            <th class="px-4 py-2.5 text-center text-xs font-extrabold text-[#1e3a8a]">إجراءات</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="u in units" :key="u.id" class="border-t border-slate-100 hover:bg-slate-50" :class="!u.is_active && 'opacity-50'">
                            <td class="px-4 py-2.5">
                                <span class="font-bold text-slate-800">{{ u.name }}</span>
                                <span class="ms-1.5 font-mono text-[10px] text-slate-400" dir="ltr">{{ u.code }}</span>
                            </td>
                            <td class="px-4 py-2.5 text-center text-slate-600">{{ u.symbol ?? '—' }}</td>
                            <td class="px-4 py-2.5 text-center">
                                <span class="rounded-md px-2 py-0.5 text-[11px] font-bold" :class="u.allows_fraction ? 'bg-teal-100 text-teal-700' : 'bg-slate-100 text-slate-600'">
                                    {{ u.allows_fraction ? 'نعم' : 'لا' }}
                                </span>
                            </td>
                            <td class="px-4 py-2.5 text-center font-bold text-slate-700">{{ u.items_count }}</td>
                            <td class="px-4 py-2.5">
                                <div class="flex items-center justify-center gap-1">
                                    <TableActionButton v-if="can('items.edit')" variant="edit" :icon="Pencil" title="تعديل" @click="openUnit(u)" />
                                    <TableActionButton v-if="can('items.delete')" variant="danger" :icon="Trash2" title="حذف" @click="destroyUnit(u)" />
                                </div>
                            </td>
                        </tr>
                        <tr v-if="!units.length"><td colspan="5" class="px-4 py-8 text-center text-sm text-slate-500">لا وحدات</td></tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- نموذج وحدة القياس -->
        <div v-if="showUnit" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4" @click.self="showUnit = false">
            <div class="w-full max-w-md rounded-2xl bg-white p-5 shadow-2xl">
                <div class="mb-3 flex items-center justify-between">
                    <h2 class="text-lg font-extrabold text-slate-900">{{ editingUnit ? 'تعديل وحدة قياس' : 'وحدة قياس جديدة' }}</h2>
                    <button type="button" @click="showUnit = false" class="text-slate-400 hover:text-slate-600"><X class="h-5 w-5" /></button>
                </div>
                <form @submit.prevent="submitUnit" class="space-y-3">
                    <div class="grid grid-cols-3 gap-2">
                        <div>
                            <label class="mb-1 block text-xs font-bold text-slate-600">الكود</label>
                            <input v-model="unitForm.code" dir="ltr" class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm" />
                            <p v-if="unitForm.errors.code" class="mt-1 text-[11px] text-red-500">{{ unitForm.errors.code }}</p>
                        </div>
                        <div class="col-span-2">
                            <label class="mb-1 block text-xs font-bold text-slate-600">الاسم</label>
                            <input v-model="unitForm.name" class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm" />
                            <p v-if="unitForm.errors.name" class="mt-1 text-[11px] text-red-500">{{ unitForm.errors.name }}</p>
                        </div>
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-bold text-slate-600">الرمز المختصر</label>
                        <input v-model="unitForm.symbol" class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm" placeholder="م² · كجم · ل" />
                    </div>
                    <label class="flex cursor-pointer items-start gap-2 rounded-xl bg-slate-50 p-3 text-sm font-bold text-slate-700">
                        <input type="checkbox" v-model="unitForm.allows_fraction" class="mt-0.5 h-4 w-4 rounded border-slate-300 text-teal-600" />
                        <span>
                            تقبل كميات كسرية
                            <span class="block text-[11px] font-medium text-slate-500">المتر المربع يقبل 12.5 والقطعة لا تقبل إلا أعدادًا صحيحة.</span>
                        </span>
                    </label>
                    <label class="flex cursor-pointer items-center gap-2 text-sm font-bold text-slate-700">
                        <input type="checkbox" v-model="unitForm.is_active" class="h-4 w-4 rounded border-slate-300 text-emerald-600" /> فعّالة
                    </label>
                    <button type="submit" :disabled="unitForm.processing" class="w-full rounded-md bg-blue-600 py-2.5 text-sm font-bold text-white hover:bg-blue-700 disabled:opacity-60">حفظ</button>
                </form>
            </div>
        </div>

        <!-- نموذج القسم -->
        <div v-if="showDept" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4" @click.self="showDept = false">
            <div class="w-full max-w-md rounded-2xl bg-white p-5 shadow-2xl">
                <div class="mb-3 flex items-center justify-between">
                    <h2 class="text-lg font-extrabold text-slate-900">{{ editingDept ? 'تعديل قسم' : 'قسم جديد' }}</h2>
                    <button type="button" @click="showDept = false" class="text-slate-400 hover:text-slate-600"><X class="h-5 w-5" /></button>
                </div>
                <form @submit.prevent="submitDept" class="space-y-3">
                    <div class="grid grid-cols-3 gap-2">
                        <div>
                            <label class="mb-1 block text-xs font-bold text-slate-600">الكود</label>
                            <input v-model="deptForm.code" dir="ltr" class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm" />
                        </div>
                        <div class="col-span-2">
                            <label class="mb-1 block text-xs font-bold text-slate-600">اسم القسم</label>
                            <input v-model="deptForm.name" class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm" />
                            <p v-if="deptForm.errors.name" class="mt-1 text-[11px] text-red-500">{{ deptForm.errors.name }}</p>
                        </div>
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-bold text-slate-600">الوصف</label>
                        <textarea v-model="deptForm.description" rows="2" class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm"></textarea>
                    </div>
                    <label class="flex cursor-pointer items-start gap-2 rounded-xl bg-emerald-50 p-3 text-sm font-bold text-slate-700">
                        <input type="checkbox" v-model="deptForm.sells" class="mt-0.5 h-4 w-4 rounded border-slate-300 text-emerald-600" />
                        <span>
                            قسم بائع
                            <span class="block text-[11px] font-medium text-slate-500">تظهر أصنافه في شاشة الفواتير، وتُنسب فواتيره لمركز تكلفته.</span>
                        </span>
                    </label>
                    <label class="flex cursor-pointer items-center gap-2 text-sm font-bold text-slate-700">
                        <input type="checkbox" v-model="deptForm.is_active" class="h-4 w-4 rounded border-slate-300 text-emerald-600" /> فعّال
                    </label>
                    <button type="submit" :disabled="deptForm.processing" class="w-full rounded-md bg-blue-600 py-2.5 text-sm font-bold text-white hover:bg-blue-700 disabled:opacity-60">حفظ</button>
                </form>
            </div>
        </div>
    </AppLayout>
</template>
