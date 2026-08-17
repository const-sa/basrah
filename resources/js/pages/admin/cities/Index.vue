<script setup lang="ts">
import { StatusBadge } from '@/components/data-table';
import SmallBox from '@/components/lte/SmallBox.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, router, useForm } from '@inertiajs/vue3';
import { MapPin, MapPinned, MapPinOff, Pencil, Plus, Power, Search, Trash2, X } from 'lucide-vue-next';
import { computed, ref } from 'vue';

interface CityRow {
    id: number;
    name: string;
    is_active: boolean;
    clients_count: number;
    created_at: string | null;
}

interface CityStats {
    total: number;
    active: number;
    inactive: number;
}

const props = defineProps<{
    cities: CityRow[];
    stats: CityStats;
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'لوحة التحكم', href: '/admin' },
    { title: 'المدن', href: '/admin/cities' },
];

// ===== بحث فوري في القائمة (على جانب العميل) =====
const search = ref('');
const filtered = computed(() => {
    const q = search.value.trim();
    if (!q) return props.cities;
    return props.cities.filter((c) => c.name.includes(q));
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

const openEdit = (c: CityRow) => {
    editingId.value = c.id;
    form.reset();
    form.clearErrors();
    form.name = c.name;
    form.is_active = c.is_active;
    showModal.value = true;
};

const submit = () => {
    if (editingId.value) {
        form.put(`/admin/cities/${editingId.value}`, { preserveScroll: true, onSuccess: () => (showModal.value = false) });
    } else {
        form.post('/admin/cities', { preserveScroll: true, onSuccess: () => (showModal.value = false) });
    }
};

const toggle = (c: CityRow) => router.patch(`/admin/cities/${c.id}/toggle`, {}, { preserveScroll: true });

const destroy = (c: CityRow) => {
    if (c.clients_count > 0) {
        alert(`لا يمكن حذف «${c.name}» لأنها مرتبطة بـ ${c.clients_count} عميل.`);
        return;
    }
    if (confirm(`حذف المدينة «${c.name}»؟`)) {
        router.delete(`/admin/cities/${c.id}`, { preserveScroll: true });
    }
};
</script>

<template>
    <Head title="المدن" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="space-y-4 p-5">
            <h1 class="text-2xl font-extrabold text-slate-900">المدن</h1>

            <!-- مربّعات الإحصائيات -->
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                <SmallBox :value="stats.total" label="كل المدن" variant="info" :icon="MapPinned" />
                <SmallBox :value="stats.active" label="المفعّلة" variant="success" :icon="MapPin" />
                <SmallBox :value="stats.inactive" label="الموقوفة" variant="danger" :icon="MapPinOff" />
            </div>

            <!-- بطاقة المدن بنمط AdminLTE -->
            <div class="lte-card">
                <div class="lte-card-header">
                    <h3 class="lte-card-title">قائمة المدن</h3>
                    <div class="flex flex-wrap items-center gap-2">
                        <!-- بحث -->
                        <div class="flex items-stretch overflow-hidden rounded-md border border-slate-300">
                            <span class="flex items-center bg-slate-50 px-2.5 text-slate-400"><Search class="h-4 w-4" /></span>
                            <input v-model="search" type="search" placeholder="بحث عن مدينة" class="w-44 border-0 px-3 py-2 text-sm focus:outline-none focus:ring-0" />
                        </div>
                        <!-- إضافة -->
                        <button type="button" @click="openCreate" class="inline-flex items-center gap-1.5 rounded-md bg-blue-600 px-4 py-2 text-sm font-bold text-white transition hover:bg-blue-700">
                            <Plus class="h-4 w-4" /> مدينة جديدة
                        </button>
                    </div>
                </div>

                <!-- الجدول -->
                <div class="overflow-x-auto">
                    <table class="w-full min-w-[560px] text-sm">
                        <thead>
                            <tr class="border-b-2 border-[#dee2e6] text-gray-500">
                                <th class="px-4 py-3 text-start font-semibold">المدينة</th>
                                <th class="px-4 py-3 text-center font-semibold">عدد العملاء</th>
                                <th class="px-4 py-3 text-center font-semibold">الحالة</th>
                                <th class="px-4 py-3 text-center font-semibold">التاريخ</th>
                                <th class="px-4 py-3 text-end font-semibold">الإجراءات</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="c in filtered" :key="c.id" class="border-b border-slate-100 align-middle transition hover:bg-slate-50">
                                <td class="px-4 py-2.5">
                                    <div class="flex items-center gap-2 font-bold text-slate-800">
                                        <MapPin class="h-4 w-4 text-emerald-500" /> {{ c.name }}
                                    </div>
                                </td>
                                <td class="px-4 py-2.5 text-center font-bold text-slate-900">{{ c.clients_count }}</td>
                                <td class="px-4 py-2.5 text-center">
                                    <StatusBadge :variant="c.is_active ? 'success' : 'danger'" :label="c.is_active ? 'مفعّلة' : 'موقوفة'" />
                                </td>
                                <td class="px-4 py-2.5 text-center text-slate-500" dir="ltr">{{ c.created_at || '—' }}</td>
                                <td class="px-4 py-2.5">
                                    <div class="flex justify-end">
                                        <div class="inline-flex divide-x divide-slate-300 overflow-hidden rounded-md border border-slate-300 rtl:divide-x-reverse">
                                            <button type="button" @click="openEdit(c)" title="تعديل" class="px-2.5 py-2 text-slate-600 transition hover:bg-slate-100"><Pencil class="h-4 w-4" /></button>
                                            <button type="button" @click="toggle(c)" title="تفعيل/إيقاف" class="px-2.5 py-2 text-emerald-600 transition hover:bg-emerald-50"><Power class="h-4 w-4" /></button>
                                            <button type="button" @click="destroy(c)" title="حذف" class="px-2.5 py-2 text-red-600 transition hover:bg-red-50"><Trash2 class="h-4 w-4" /></button>
                                        </div>
                                    </div>
                                </td>
                            </tr>
                            <tr v-if="filtered.length === 0">
                                <td colspan="5" class="px-4 py-12 text-center text-sm text-slate-400">لا توجد مدن مطابقة.</td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div class="lte-card-footer">
                    <div class="text-sm font-medium text-slate-500">إجمالي {{ filtered.length }} مدينة</div>
                </div>
            </div>
        </div>

        <!-- نافذة النموذج -->
        <div v-if="showModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4" @click.self="showModal = false">
            <div class="w-full max-w-md rounded-2xl bg-white p-6 shadow-2xl">
                <div class="mb-4 flex items-center justify-between">
                    <h2 class="text-lg font-extrabold text-slate-900">{{ editingId ? 'تعديل مدينة' : 'مدينة جديدة' }}</h2>
                    <button type="button" @click="showModal = false" class="rounded-lg p-1 text-slate-400 hover:bg-slate-100 hover:text-slate-600"><X class="h-5 w-5" /></button>
                </div>
                <form @submit.prevent="submit" class="space-y-3">
                    <div>
                        <label class="mb-1 block text-sm font-bold text-slate-700">اسم المدينة</label>
                        <input v-model="form.name" type="text" class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm focus:border-emerald-400 focus:outline-none focus:ring-2 focus:ring-emerald-100" />
                        <p v-if="form.errors.name" class="mt-1 text-xs text-red-500">{{ form.errors.name }}</p>
                    </div>

                    <label class="flex items-center gap-2 text-sm font-bold text-slate-700">
                        <input v-model="form.is_active" type="checkbox" class="h-4 w-4 rounded border-slate-300 text-emerald-600 focus:ring-emerald-200" />
                        مدينة مفعّلة
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
