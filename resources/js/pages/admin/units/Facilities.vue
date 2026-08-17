<script setup lang="ts">
import { TableActionButton } from '@/components/data-table';
import { usePermissions } from '@/composables/usePermissions';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import * as icons from 'lucide-vue-next';
import { Pencil, Plus, Settings2, Trash2, X } from 'lucide-vue-next';
import { ref } from 'vue';

interface Facility {
    id: number; name: string; icon: string | null; description: string | null;
    is_active: boolean; sections_count: number; units_count: number;
}

const props = defineProps<{ facilities: Facility[]; icons: string[] }>();

const { can } = usePermissions();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'لوحة التحكم', href: '/admin' },
    { title: 'الوحدات والأقسام', href: '/admin/units' },
    { title: 'المرافق', href: '/admin/units-facilities' },
];

/** أيقونة lucide بالاسم — وإن لم توجد فأيقونة محايدة. */
const iconOf = (name: string | null) =>
    (name && (icons as Record<string, unknown>)[name]) || Settings2;

const showModal = ref(false);
const editingId = ref<number | null>(null);

const form = useForm({ name: '', icon: '' as string | null, description: '', is_active: true });

const openCreate = () => {
    editingId.value = null;
    form.reset();
    form.clearErrors();
    showModal.value = true;
};

const openEdit = (f: Facility) => {
    editingId.value = f.id;
    form.clearErrors();
    form.name = f.name;
    form.icon = f.icon;
    form.description = f.description ?? '';
    form.is_active = f.is_active;
    showModal.value = true;
};

const submit = () => {
    const opts = { preserveScroll: true, onSuccess: () => (showModal.value = false) };
    editingId.value
        ? form.put(`/admin/units-facilities/${editingId.value}`, opts)
        : form.post('/admin/units-facilities', opts);
};

const destroy = (f: Facility) => {
    if (confirm(`حذف المرفق «${f.name}»؟`)) {
        router.delete(`/admin/units-facilities/${f.id}`, { preserveScroll: true });
    }
};
</script>

<template>
    <Head title="المرافق" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="min-h-full space-y-5 bg-slate-100 p-5">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h1 class="text-2xl font-extrabold text-slate-900">مرافق الوحدات</h1>
                    <p class="mt-1 text-sm font-medium text-slate-600">تُسنَد لأقسام الوحدات، والمشترك منها يؤثر على قاعدة الخصوصية</p>
                </div>
                <div class="flex gap-2">
                    <Link href="/admin/units" class="rounded-md border border-slate-200 bg-white px-4 py-2 text-sm font-bold text-slate-700 hover:bg-slate-50">الوحدات</Link>
                    <button v-if="can('units.create')" type="button" @click="openCreate" class="inline-flex items-center gap-1.5 rounded-md bg-blue-600 px-4 py-2 text-sm font-bold text-white hover:bg-blue-700">
                        <Plus class="h-4 w-4" /> مرفق جديد
                    </button>
                </div>
            </div>

            <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3">
                <div
                    v-for="f in facilities" :key="f.id"
                    class="flex items-start gap-3 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm transition hover:shadow-md"
                    :class="!f.is_active && 'opacity-55'"
                >
                    <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-2xl bg-emerald-50 text-emerald-600">
                        <component :is="iconOf(f.icon)" class="h-5 w-5" />
                    </span>

                    <div class="min-w-0 flex-1">
                        <div class="flex items-center gap-1.5">
                            <span class="font-extrabold text-slate-900">{{ f.name }}</span>
                            <span v-if="!f.is_active" class="rounded bg-slate-200 px-1.5 py-0.5 text-[10px] font-bold text-slate-600">معطّل</span>
                        </div>
                        <p v-if="f.description" class="mt-0.5 text-[11px] font-medium text-slate-500">{{ f.description }}</p>
                        <div class="mt-1.5 flex flex-wrap gap-1 text-[11px] font-bold">
                            <span class="rounded bg-sky-100 px-1.5 py-0.5 text-sky-700">{{ f.units_count }} وحدة</span>
                            <span class="rounded bg-slate-100 px-1.5 py-0.5 text-slate-600">{{ f.sections_count }} قسم</span>
                        </div>
                    </div>

                    <div class="flex shrink-0 gap-1">
                        <TableActionButton v-if="can('units.edit')" variant="edit" :icon="Pencil" title="تعديل" @click="openEdit(f)" />
                        <TableActionButton v-if="can('units.delete')" variant="danger" :icon="Trash2" title="حذف" @click="destroy(f)" />
                    </div>
                </div>

                <p v-if="!facilities.length" class="rounded-2xl bg-white py-10 text-center text-sm font-medium text-slate-500 sm:col-span-2 lg:col-span-3">
                    لا مرافق بعد — أضف مرفقًا ليظهر في نموذج الوحدات.
                </p>
            </div>
        </div>

        <div v-if="showModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4" @click.self="showModal = false">
            <div class="w-full max-w-md rounded-2xl bg-white p-5 shadow-2xl">
                <div class="mb-3 flex items-center justify-between">
                    <h2 class="text-lg font-extrabold text-slate-900">{{ editingId ? 'تعديل مرفق' : 'مرفق جديد' }}</h2>
                    <button type="button" @click="showModal = false" class="text-slate-400 hover:text-slate-600"><X class="h-5 w-5" /></button>
                </div>

                <form @submit.prevent="submit" class="space-y-3">
                    <div>
                        <label class="mb-1 block text-sm font-bold text-slate-700">اسم المرفق</label>
                        <input v-model="form.name" class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm focus:border-emerald-400 focus:outline-none focus:ring-2 focus:ring-emerald-100" />
                        <p v-if="form.errors.name" class="mt-1 text-xs text-red-500">{{ form.errors.name }}</p>
                    </div>

                    <div>
                        <label class="mb-1.5 block text-sm font-bold text-slate-700">الأيقونة</label>
                        <div class="flex flex-wrap gap-1.5">
                            <button
                                v-for="ic in props.icons" :key="ic" type="button" @click="form.icon = ic"
                                class="flex h-9 w-9 items-center justify-center rounded-lg transition"
                                :class="form.icon === ic ? 'bg-emerald-600 text-white' : 'bg-slate-100 text-slate-600 hover:bg-slate-200'"
                                :title="ic"
                            >
                                <component :is="iconOf(ic)" class="h-4 w-4" />
                            </button>
                        </div>
                    </div>

                    <div>
                        <label class="mb-1 block text-sm font-bold text-slate-700">الوصف</label>
                        <textarea v-model="form.description" rows="2" class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm"></textarea>
                    </div>

                    <label class="flex cursor-pointer items-center gap-2 text-sm font-bold text-slate-700">
                        <input type="checkbox" v-model="form.is_active" class="h-4 w-4 rounded border-slate-300 text-emerald-600" />
                        فعّال ويظهر في نموذج الوحدات
                    </label>

                    <button type="submit" :disabled="form.processing" class="w-full rounded-md bg-blue-600 py-2.5 text-sm font-bold text-white hover:bg-blue-700 disabled:opacity-60">حفظ</button>
                </form>
            </div>
        </div>
    </AppLayout>
</template>
