<script setup lang="ts">
import { StatPill, TableActionButton } from '@/components/data-table';
import { usePermissions } from '@/composables/usePermissions';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, router, useForm } from '@inertiajs/vue3';
import { Pencil, Plus, Power, Search, Trash2, X } from 'lucide-vue-next';
import { ref } from 'vue';

interface Supplier {
    id: number; name: string; mobile: string | null; email: string | null;
    tax_number: string | null; address: string | null; notes: string | null;
    is_active: boolean; balance: number;
}

const props = defineProps<{
    suppliers: Supplier[];
    filters: Record<string, string | null>;
    stats: { total: number; active: number; payable: number };
}>();

const { can } = usePermissions();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'لوحة التحكم', href: '/admin' },
    { title: 'الموردون', href: '/admin/suppliers' },
];

const money = (n: number) => new Intl.NumberFormat('ar-SA-u-nu-latn', { maximumFractionDigits: 2 }).format(n ?? 0);

const filters = ref({ ...props.filters });
const apply = () => router.get('/admin/suppliers', filters.value, { preserveState: true, replace: true });

const showModal = ref(false);
const editingId = ref<number | null>(null);

const form = useForm({ name: '', mobile: '', email: '', tax_number: '', address: '', notes: '', is_active: true });

const openCreate = () => {
    editingId.value = null;
    form.reset();
    form.clearErrors();
    showModal.value = true;
};

const openEdit = (s: Supplier) => {
    editingId.value = s.id;
    form.clearErrors();
    Object.assign(form, {
        name: s.name, mobile: s.mobile ?? '', email: s.email ?? '',
        tax_number: s.tax_number ?? '', address: s.address ?? '', notes: s.notes ?? '', is_active: s.is_active,
    });
    showModal.value = true;
};

const submit = () => {
    const opts = { preserveScroll: true, onSuccess: () => (showModal.value = false) };
    editingId.value ? form.put(`/admin/suppliers/${editingId.value}`, opts) : form.post('/admin/suppliers', opts);
};

const toggle = (s: Supplier) => router.patch(`/admin/suppliers/${s.id}/toggle`, {}, { preserveScroll: true });
const destroy = (s: Supplier) => {
    if (confirm(`حذف المورد «${s.name}»؟`)) router.delete(`/admin/suppliers/${s.id}`, { preserveScroll: true });
};
</script>

<template>
    <Head title="الموردون" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="min-h-full space-y-4 bg-slate-100 p-5">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h1 class="text-2xl font-extrabold text-slate-900">الموردون</h1>
                    <p class="mt-1 text-sm font-medium text-slate-600">الرصيد يُحتسب من سندات الصرف والقبض المرحَّلة</p>
                </div>
                <button v-if="can('suppliers.create')" type="button" @click="openCreate" class="inline-flex items-center gap-1.5 rounded-md bg-blue-600 px-4 py-2 text-sm font-bold text-white hover:bg-blue-700">
                    <Plus class="h-4 w-4" /> مورد جديد
                </button>
            </div>

            <div class="grid grid-cols-3 gap-3">
                <StatPill label="الموردون" :value="stats.total" variant="primary" />
                <StatPill label="فعّالون" :value="stats.active" variant="success" />
                <StatPill label="إجمالي المستحق" :value="money(stats.payable)" variant="warning" />
            </div>

            <div class="rounded-2xl border border-slate-200 bg-white p-3 shadow-sm">
                <div class="relative">
                    <Search class="absolute top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400 ltr:left-3 rtl:right-3" />
                    <input v-model="filters.search" @keyup.enter="apply" placeholder="اسم المورد أو جواله" class="w-full rounded-xl border border-slate-200 py-2.5 text-sm ltr:pl-9 ltr:pr-3 rtl:pl-3 rtl:pr-9" />
                </div>
            </div>

            <div class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                <table class="w-full text-sm">
                    <thead class="bg-slate-100">
                        <tr>
                            <th class="px-4 py-3 text-right text-xs font-extrabold text-slate-700">المورد</th>
                            <th class="px-4 py-3 text-right text-xs font-extrabold text-slate-700">التواصل</th>
                            <th class="px-4 py-3 text-right text-xs font-extrabold text-slate-700">الرقم الضريبي</th>
                            <th class="px-4 py-3 text-left text-xs font-extrabold text-slate-700">الرصيد</th>
                            <th class="px-4 py-3 text-center text-xs font-extrabold text-slate-700">إجراءات</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="s in suppliers" :key="s.id" class="border-t border-slate-100 hover:bg-slate-50" :class="!s.is_active && 'opacity-50'">
                            <td class="px-4 py-3">
                                <div class="font-bold text-slate-800">{{ s.name }}</div>
                                <div v-if="s.address" class="text-[11px] text-slate-500">{{ s.address }}</div>
                            </td>
                            <td class="px-4 py-3 text-xs">
                                <div class="text-slate-700" dir="ltr">{{ s.mobile ?? '—' }}</div>
                                <div class="text-slate-500" dir="ltr">{{ s.email ?? '' }}</div>
                            </td>
                            <td class="px-4 py-3 text-xs text-slate-600" dir="ltr">{{ s.tax_number ?? '—' }}</td>
                            <td class="px-4 py-3 text-left font-extrabold" :class="s.balance > 0 ? 'text-red-600' : 'text-slate-700'" dir="ltr">{{ money(s.balance) }}</td>
                            <td class="px-4 py-3">
                                <div class="flex items-center justify-center gap-1">
                                    <TableActionButton v-if="can('suppliers.edit')" variant="edit" :icon="Pencil" title="تعديل" @click="openEdit(s)" />
                                    <TableActionButton v-if="can('suppliers.edit')" variant="warning" :icon="Power" title="تفعيل/تعطيل" @click="toggle(s)" />
                                    <TableActionButton v-if="can('suppliers.delete')" variant="danger" :icon="Trash2" title="حذف" @click="destroy(s)" />
                                </div>
                            </td>
                        </tr>
                        <tr v-if="!suppliers.length"><td colspan="5" class="px-4 py-10 text-center text-sm text-slate-500">لا موردين</td></tr>
                    </tbody>
                </table>
            </div>
        </div>

        <div v-if="showModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4" @click.self="showModal = false">
            <div class="w-full max-w-lg rounded-2xl bg-white shadow-2xl">
                <div class="flex items-center justify-between border-b border-slate-100 px-6 py-4">
                    <h2 class="text-lg font-extrabold text-slate-900">{{ editingId ? 'تعديل مورد' : 'مورد جديد' }}</h2>
                    <button type="button" @click="showModal = false" class="text-slate-400 hover:text-slate-600"><X class="h-5 w-5" /></button>
                </div>

                <form @submit.prevent="submit" class="space-y-3 px-6 py-4">
                    <div>
                        <label class="mb-1 block text-sm font-bold text-slate-700">اسم المورد</label>
                        <input v-model="form.name" class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm" />
                        <p v-if="form.errors.name" class="mt-1 text-xs text-red-500">{{ form.errors.name }}</p>
                    </div>

                    <div class="grid gap-3 sm:grid-cols-2">
                        <div>
                            <label class="mb-1 block text-sm font-bold text-slate-700">الجوال</label>
                            <input v-model="form.mobile" dir="ltr" class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm" />
                        </div>
                        <div>
                            <label class="mb-1 block text-sm font-bold text-slate-700">البريد</label>
                            <input v-model="form.email" type="email" dir="ltr" class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm" />
                        </div>
                    </div>

                    <div class="grid gap-3 sm:grid-cols-2">
                        <div>
                            <label class="mb-1 block text-sm font-bold text-slate-700">الرقم الضريبي</label>
                            <input v-model="form.tax_number" dir="ltr" class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm" />
                        </div>
                        <div>
                            <label class="mb-1 block text-sm font-bold text-slate-700">العنوان</label>
                            <input v-model="form.address" class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm" />
                        </div>
                    </div>

                    <div>
                        <label class="mb-1 block text-sm font-bold text-slate-700">ملاحظات</label>
                        <textarea v-model="form.notes" rows="2" class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm"></textarea>
                    </div>

                    <label class="flex cursor-pointer items-center gap-2 text-sm font-bold text-slate-700">
                        <input type="checkbox" v-model="form.is_active" class="h-4 w-4 rounded border-slate-300 text-emerald-600" />
                        فعّال
                    </label>

                    <div class="flex justify-end gap-2 border-t border-slate-100 pt-4">
                        <button type="button" @click="showModal = false" class="rounded-xl border border-slate-200 px-4 py-2 text-sm font-bold text-slate-600">إلغاء</button>
                        <button type="submit" :disabled="form.processing" class="rounded-md bg-blue-600 px-5 py-2 text-sm font-bold text-white hover:bg-blue-700 disabled:opacity-60">حفظ</button>
                    </div>
                </form>
            </div>
        </div>
    </AppLayout>
</template>
