<script setup lang="ts">
import { StatusBadge } from '@/components/data-table';
import SmallBox from '@/components/lte/SmallBox.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, router, useForm } from '@inertiajs/vue3';
import { Ban, Building2, Pencil, Plus, Search, Trash2, X } from 'lucide-vue-next';
import { computed, ref } from 'vue';

interface DepartmentRow {
    id: number;
    name: string;
    is_active: boolean;
    created_at: string | null;
}

interface DepartmentStats {
    total: number;
    active: number;
    inactive: number;
}

const props = defineProps<{
    departments: DepartmentRow[];
    stats: DepartmentStats;
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'لوحة التحكم', href: '/admin' },
    { title: 'الأقسام', href: '/admin/departments' },
];

// ===== بحث فوري في القائمة (على جانب العميل) =====
const search = ref('');
const filtered = computed(() => {
    const q = search.value.trim();
    if (!q) return props.departments;
    return props.departments.filter((d) => d.name.includes(q));
});

// ===== نموذج الإضافة/التعديل =====
const showModal = ref(false);
const editingId = ref<number | null>(null);
const form = useForm({
    name: '',
    is_active: true,
});

const openCreate = () => {
    editingId.value = null;
    form.reset();
    form.clearErrors();
    showModal.value = true;
};

const openEdit = (d: DepartmentRow) => {
    editingId.value = d.id;
    form.reset();
    form.clearErrors();
    form.name = d.name;
    form.is_active = d.is_active;
    showModal.value = true;
};

const submit = () => {
    if (editingId.value) {
        form.put(`/admin/departments/${editingId.value}`, { preserveScroll: true, onSuccess: () => (showModal.value = false) });
    } else {
        form.post('/admin/departments', { preserveScroll: true, onSuccess: () => (showModal.value = false) });
    }
};

const destroy = (d: DepartmentRow) => {
    if (confirm(`حذف القسم «${d.name}»؟`)) {
        router.delete(`/admin/departments/${d.id}`, { preserveScroll: true });
    }
};
</script>

<template>
    <Head title="الأقسام" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="space-y-4 p-5">
            <h1 class="text-2xl font-extrabold text-slate-900">الأقسام</h1>

            <!-- مربّعات الإحصائيات -->
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                <SmallBox :value="stats.total" label="كل الأقسام" variant="info" :icon="Building2" />
                <SmallBox :value="stats.active" label="المفعّلة" variant="success" :icon="Building2" />
                <SmallBox :value="stats.inactive" label="الموقوفة" variant="danger" :icon="Ban" />
            </div>

            <!-- بطاقة الأقسام بنمط AdminLTE -->
            <div class="lte-card">
                <div class="lte-card-header">
                    <h3 class="lte-card-title">قائمة الأقسام</h3>
                    <div class="flex flex-wrap items-center gap-2">
                        <!-- بحث -->
                        <div class="flex items-stretch overflow-hidden rounded-md border border-slate-300">
                            <span class="flex items-center bg-slate-50 px-2.5 text-slate-400"><Search class="h-4 w-4" /></span>
                            <input v-model="search" type="search" placeholder="بحث عن قسم" class="w-44 border-0 px-3 py-2 text-sm focus:outline-none focus:ring-0" />
                        </div>
                        <!-- إضافة -->
                        <button type="button" @click="openCreate" class="inline-flex items-center gap-1.5 rounded-md bg-blue-600 px-4 py-2 text-sm font-bold text-white transition hover:bg-blue-700">
                            <Plus class="h-4 w-4" /> قسم جديد
                        </button>
                    </div>
                </div>

                <!-- الجدول -->
                <div class="overflow-x-auto">
                    <table class="w-full min-w-[520px] text-sm">
                        <thead>
                            <tr class="border-b-2 border-[#dee2e6] text-gray-500">
                                <th class="px-4 py-3 text-start font-semibold">القسم</th>
                                <th class="px-4 py-3 text-center font-semibold">الحالة</th>
                                <th class="px-4 py-3 text-center font-semibold">التاريخ</th>
                                <th class="px-4 py-3 text-end font-semibold">الإجراءات</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="d in filtered" :key="d.id" class="border-b border-slate-100 align-middle transition hover:bg-slate-50">
                                <td class="px-4 py-2.5">
                                    <div class="flex items-center gap-2 font-bold text-slate-800">
                                        <Building2 class="h-4 w-4 text-emerald-500" /> {{ d.name }}
                                    </div>
                                </td>
                                <td class="px-4 py-2.5 text-center">
                                    <StatusBadge :variant="d.is_active ? 'success' : 'danger'" :label="d.is_active ? 'مفعّل' : 'موقوف'" />
                                </td>
                                <td class="px-4 py-2.5 text-center text-slate-500" dir="ltr">{{ d.created_at || '—' }}</td>
                                <td class="px-4 py-2.5">
                                    <div class="flex justify-end">
                                        <div class="inline-flex divide-x divide-slate-300 overflow-hidden rounded-md border border-slate-300 rtl:divide-x-reverse">
                                            <button type="button" @click="openEdit(d)" title="تعديل" class="px-2.5 py-2 text-slate-600 transition hover:bg-slate-100"><Pencil class="h-4 w-4" /></button>
                                            <button type="button" @click="destroy(d)" title="حذف" class="px-2.5 py-2 text-red-600 transition hover:bg-red-50"><Trash2 class="h-4 w-4" /></button>
                                        </div>
                                    </div>
                                </td>
                            </tr>
                            <tr v-if="filtered.length === 0">
                                <td colspan="4" class="px-4 py-12 text-center text-sm text-slate-400">لا توجد أقسام مطابقة.</td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div class="lte-card-footer">
                    <div class="text-sm font-medium text-slate-500">إجمالي {{ filtered.length }} قسم</div>
                </div>
            </div>
        </div>

        <!-- نافذة النموذج -->
        <div v-if="showModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4" @click.self="showModal = false">
            <div class="w-full max-w-md rounded-2xl bg-white p-6 shadow-2xl">
                <div class="mb-4 flex items-center justify-between">
                    <h2 class="text-lg font-extrabold text-slate-900">{{ editingId ? 'تعديل قسم' : 'قسم جديد' }}</h2>
                    <button type="button" @click="showModal = false" class="rounded-lg p-1 text-slate-400 hover:bg-slate-100 hover:text-slate-600"><X class="h-5 w-5" /></button>
                </div>
                <form @submit.prevent="submit" class="space-y-3">
                    <div>
                        <label class="mb-1 block text-sm font-bold text-slate-700">اسم القسم</label>
                        <input v-model="form.name" type="text" class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm focus:border-emerald-400 focus:outline-none focus:ring-2 focus:ring-emerald-100" />
                        <p v-if="form.errors.name" class="mt-1 text-xs text-red-500">{{ form.errors.name }}</p>
                    </div>

                    <label class="flex items-center gap-2 text-sm font-bold text-slate-700">
                        <input v-model="form.is_active" type="checkbox" class="h-4 w-4 rounded border-slate-300 text-emerald-600 focus:ring-emerald-200" />
                        قسم مفعّل
                    </label>
                    <div class="flex justify-end gap-2 pt-2">
                        <button type="button" @click="showModal = false" class="rounded-xl border border-slate-200 bg-white px-4 py-2 text-sm font-bold text-slate-600 hover:bg-slate-50">إلغاء</button>
                        <button type="submit" :disabled="form.processing" class="rounded-xl brand-gradient px-5 py-2 text-sm font-bold text-white shadow-md transition hover:brightness-110 disabled:opacity-60">حفظ</button>
                    </div>
                </form>
            </div>
        </div>
    </AppLayout>
</template>
