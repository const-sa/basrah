<script setup lang="ts">
import { StatPill, TableActionButton } from '@/components/data-table';
import { usePermissions } from '@/composables/usePermissions';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { AlertTriangle, Pencil, Plus, Search, Trash2, X } from 'lucide-vue-next';
import { ref } from 'vue';

interface Alert { type: string; label: string; date: string; days_left: number }
interface Employee {
    id: number; employee_no: string | null; name: string;
    national_id: string | null; nationality: string | null;
    phone: string | null; email: string | null; position: string | null;
    unit_id: number | null; unit_name: string | null;
    department_id: number | null; group_id: number | null;
    hired_on: string | null;
    basic_salary: number; housing_allowance: number; transport_allowance: number; other_allowance: number;
    gross_salary: number;
    iqama_expiry: string | null; passport_expiry: string | null; contract_expiry: string | null;
    bank_iban: string | null; is_active: boolean; alerts: Alert[];
}

const props = defineProps<{
    employees: { data: Employee[]; links: { url: string | null; label: string; active: boolean }[] };
    filters: Record<string, string | number | boolean | null>;
    units: { id: number; name: string }[];
    departments: { id: number; name: string }[];
    groups: { id: number; name: string }[];
    stats: { total: number; active: number; expiring: number; payroll_cost: number };
}>();

const { can } = usePermissions();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'لوحة التحكم', href: '/admin' },
    { title: 'ملفات الموظفين', href: '/admin/hr/staff' },
];

const money = (n: number) => new Intl.NumberFormat('ar-SA-u-nu-latn', { maximumFractionDigits: 2 }).format(n ?? 0);

const filters = ref({ ...props.filters });
const apply = () => router.get('/admin/hr/staff', filters.value, { preserveState: true, replace: true });

const showModal = ref(false);
const editingId = ref<number | null>(null);

const form = useForm({
    employee_no: '', name: '', national_id: '', nationality: '', phone: '', email: '', position: '',
    unit_id: null as number | null, department_id: null as number | null, group_id: null as number | null,
    hired_on: '', basic_salary: 0, housing_allowance: 0, transport_allowance: 0, other_allowance: 0,
    iqama_expiry: '', passport_expiry: '', contract_expiry: '', bank_iban: '', notes: '', is_active: true,
});

const openCreate = () => {
    editingId.value = null;
    form.reset();
    form.clearErrors();
    showModal.value = true;
};

const openEdit = (e: Employee) => {
    editingId.value = e.id;
    form.clearErrors();
    Object.assign(form, {
        employee_no: e.employee_no ?? '', name: e.name, national_id: e.national_id ?? '',
        nationality: e.nationality ?? '', phone: e.phone ?? '', email: e.email ?? '', position: e.position ?? '',
        unit_id: e.unit_id, department_id: e.department_id, group_id: e.group_id,
        hired_on: e.hired_on ?? '', basic_salary: e.basic_salary,
        housing_allowance: e.housing_allowance, transport_allowance: e.transport_allowance,
        other_allowance: e.other_allowance, iqama_expiry: e.iqama_expiry ?? '',
        passport_expiry: e.passport_expiry ?? '', contract_expiry: e.contract_expiry ?? '',
        bank_iban: e.bank_iban ?? '', is_active: e.is_active,
    });
    showModal.value = true;
};

const submit = () => {
    const opts = { preserveScroll: true, onSuccess: () => (showModal.value = false) };
    editingId.value ? form.put(`/admin/hr/staff/${editingId.value}`, opts) : form.post('/admin/hr/staff', opts);
};

const destroy = (e: Employee) => {
    if (confirm(`حذف الموظف «${e.name}»؟`)) router.delete(`/admin/hr/staff/${e.id}`, { preserveScroll: true });
};
</script>

<template>
    <Head title="ملفات الموظفين" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="min-h-full space-y-4 bg-slate-100 p-5">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h1 class="text-2xl font-extrabold text-slate-900">ملفات الموظفين</h1>
                    <p class="mt-1 text-sm font-medium text-slate-600">البيانات والرواتب وتنبيهات انتهاء الوثائق</p>
                </div>
                <div class="flex flex-wrap gap-2">
                    <Link href="/admin/hr/attendance" class="rounded-md border border-slate-200 bg-white px-4 py-2 text-sm font-bold text-slate-700 hover:bg-slate-50">الحضور</Link>
                    <Link href="/admin/hr/leaves" class="rounded-md border border-slate-200 bg-white px-4 py-2 text-sm font-bold text-slate-700 hover:bg-slate-50">الإجازات والسلف</Link>
                    <Link href="/admin/hr/payroll" class="rounded-md border border-slate-200 bg-white px-4 py-2 text-sm font-bold text-slate-700 hover:bg-slate-50">الرواتب</Link>
                    <button v-if="can('staff.create')" type="button" @click="openCreate" class="inline-flex items-center gap-1.5 rounded-md bg-blue-600 px-4 py-2 text-sm font-bold text-white hover:bg-blue-700">
                        <Plus class="h-4 w-4" /> موظف جديد
                    </button>
                </div>
            </div>

            <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
                <StatPill label="الموظفون" :value="stats.total" variant="primary" />
                <StatPill label="على رأس العمل" :value="stats.active" variant="success" />
                <StatPill label="وثائق توشك" :value="stats.expiring" variant="danger" />
                <StatPill label="كلفة الرواتب" :value="money(stats.payroll_cost)" variant="dark" />
            </div>

            <div class="rounded-2xl border border-slate-200 bg-white p-3 shadow-sm">
                <div class="grid gap-2 sm:grid-cols-3">
                    <div class="relative">
                        <Search class="absolute top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400 ltr:left-3 rtl:right-3" />
                        <input v-model="filters.search" @keyup.enter="apply" placeholder="اسم أو رقم أو جوال" class="w-full rounded-xl border border-slate-200 py-2.5 text-sm ltr:pl-9 ltr:pr-3 rtl:pl-3 rtl:pr-9" />
                    </div>
                    <select v-model="filters.unit_id" @change="apply" class="rounded-xl border border-slate-200 px-3 py-2.5 text-sm">
                        <option :value="null">كل الوحدات</option>
                        <option v-for="u in units" :key="u.id" :value="u.id">{{ u.name }}</option>
                    </select>
                    <label class="flex cursor-pointer items-center gap-2 rounded-xl border border-slate-200 px-3 py-2.5 text-sm font-bold text-slate-700">
                        <input type="checkbox" v-model="filters.expiring" @change="apply" class="h-4 w-4 rounded border-slate-300 text-red-600" />
                        وثائق توشك على الانتهاء
                    </label>
                </div>
            </div>

            <div class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="bg-slate-100">
                            <tr>
                                <th class="px-4 py-3 text-right text-xs font-extrabold text-slate-700">الموظف</th>
                                <th class="px-4 py-3 text-right text-xs font-extrabold text-slate-700">الوحدة والوظيفة</th>
                                <th class="px-4 py-3 text-left text-xs font-extrabold text-slate-700">الراتب</th>
                                <th class="px-4 py-3 text-right text-xs font-extrabold text-slate-700">الوثائق</th>
                                <th class="px-4 py-3 text-center text-xs font-extrabold text-slate-700">إجراءات</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="e in employees.data" :key="e.id" class="border-t border-slate-100 hover:bg-slate-50" :class="!e.is_active && 'opacity-50'">
                                <td class="px-4 py-3">
                                    <div class="font-bold text-slate-800">{{ e.name }}</div>
                                    <div class="text-[11px] text-slate-500" dir="ltr">{{ e.employee_no }}{{ e.phone ? ` · ${e.phone}` : '' }}</div>
                                </td>
                                <td class="px-4 py-3 text-xs">
                                    <div class="font-bold text-slate-700">{{ e.unit_name ?? '—' }}</div>
                                    <div class="text-slate-500">{{ e.position ?? '' }}</div>
                                </td>
                                <td class="px-4 py-3 text-left">
                                    <div class="font-extrabold text-slate-800" dir="ltr">{{ money(e.gross_salary) }}</div>
                                    <div class="text-[10px] text-slate-500" dir="ltr">{{ money(e.basic_salary) }} + {{ money(e.gross_salary - e.basic_salary) }}</div>
                                </td>
                                <td class="px-4 py-3">
                                    <div v-if="e.alerts.length" class="flex flex-wrap gap-1">
                                        <span
                                            v-for="a in e.alerts" :key="a.type"
                                            class="inline-flex items-center gap-1 rounded px-1.5 py-0.5 text-[10px] font-bold"
                                            :class="a.days_left < 0 ? 'bg-red-100 text-red-700' : 'bg-amber-100 text-amber-700'"
                                        >
                                            <AlertTriangle class="h-2.5 w-2.5" />
                                            {{ a.label }} {{ a.days_left < 0 ? `منتهية منذ ${Math.abs(a.days_left)} يوم` : `خلال ${a.days_left} يوم` }}
                                        </span>
                                    </div>
                                    <span v-else class="text-[11px] text-slate-400">سارية</span>
                                </td>
                                <td class="px-4 py-3">
                                    <div class="flex items-center justify-center gap-1">
                                        <TableActionButton v-if="can('staff.edit')" variant="edit" :icon="Pencil" title="تعديل" @click="openEdit(e)" />
                                        <TableActionButton v-if="can('staff.delete')" variant="danger" :icon="Trash2" title="حذف" @click="destroy(e)" />
                                    </div>
                                </td>
                            </tr>
                            <tr v-if="!employees.data.length"><td colspan="5" class="px-4 py-10 text-center text-sm text-slate-500">لا موظفين</td></tr>
                        </tbody>
                    </table>
                </div>

                <div v-if="employees.links.length > 3" class="flex flex-wrap justify-center gap-1 border-t border-slate-100 p-3">
                    <Link v-for="l in employees.links" :key="l.label" :href="l.url ?? '#'"
                        :class="['rounded-lg px-3 py-1.5 text-xs font-bold', l.active ? 'bg-blue-600 text-white' : l.url ? 'bg-white text-slate-600 ring-1 ring-slate-200' : 'text-slate-300']"
                        v-html="l.label" />
                </div>
            </div>
        </div>

        <div v-if="showModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4" @click.self="showModal = false">
            <div class="flex max-h-[92vh] w-full max-w-3xl flex-col rounded-2xl bg-white shadow-2xl">
                <div class="flex items-center justify-between border-b border-slate-100 px-6 py-4">
                    <h2 class="text-lg font-extrabold text-slate-900">{{ editingId ? 'تعديل موظف' : 'موظف جديد' }}</h2>
                    <button type="button" @click="showModal = false" class="text-slate-400 hover:text-slate-600"><X class="h-5 w-5" /></button>
                </div>

                <form @submit.prevent="submit" class="flex min-h-0 flex-1 flex-col">
                    <div class="min-h-0 flex-1 space-y-4 overflow-y-auto px-6 py-4">
                        <div class="grid gap-3 sm:grid-cols-3">
                            <div>
                                <label class="mb-1 block text-sm font-bold text-slate-700">الرقم الوظيفي</label>
                                <input v-model="form.employee_no" dir="ltr" class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm" />
                            </div>
                            <div class="sm:col-span-2">
                                <label class="mb-1 block text-sm font-bold text-slate-700">الاسم</label>
                                <input v-model="form.name" class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm" />
                                <p v-if="form.errors.name" class="mt-1 text-xs text-red-500">{{ form.errors.name }}</p>
                            </div>
                        </div>

                        <div class="grid gap-3 sm:grid-cols-3">
                            <div>
                                <label class="mb-1 block text-sm font-bold text-slate-700">رقم الهوية</label>
                                <input v-model="form.national_id" dir="ltr" class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm" />
                            </div>
                            <div>
                                <label class="mb-1 block text-sm font-bold text-slate-700">الجنسية</label>
                                <input v-model="form.nationality" class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm" />
                            </div>
                            <div>
                                <label class="mb-1 block text-sm font-bold text-slate-700">الجوال</label>
                                <input v-model="form.phone" dir="ltr" class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm" />
                            </div>
                        </div>

                        <div class="grid gap-3 sm:grid-cols-3">
                            <div>
                                <label class="mb-1 block text-sm font-bold text-slate-700">الوظيفة</label>
                                <input v-model="form.position" class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm" />
                            </div>
                            <div>
                                <label class="mb-1 block text-sm font-bold text-slate-700">الوحدة (مركز التكلفة)</label>
                                <select v-model="form.unit_id" class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm">
                                    <option :value="null">المحل</option>
                                    <option v-for="u in units" :key="u.id" :value="u.id">{{ u.name }}</option>
                                </select>
                            </div>
                            <div>
                                <label class="mb-1 block text-sm font-bold text-slate-700">تاريخ التعيين</label>
                                <input v-model="form.hired_on" type="date" class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm" />
                            </div>
                        </div>

                        <div class="rounded-xl border border-emerald-200 bg-emerald-50 p-3">
                            <h3 class="mb-2 text-sm font-extrabold text-emerald-800">مكوّنات الراتب</h3>
                            <div class="grid gap-2 sm:grid-cols-4">
                                <div>
                                    <label class="mb-1 block text-[11px] font-bold text-slate-600">الأساسي</label>
                                    <input v-model.number="form.basic_salary" type="number" min="0" step="0.01" class="w-full rounded-lg border border-slate-200 px-2 py-2 text-sm" />
                                </div>
                                <div>
                                    <label class="mb-1 block text-[11px] font-bold text-slate-600">بدل سكن</label>
                                    <input v-model.number="form.housing_allowance" type="number" min="0" step="0.01" class="w-full rounded-lg border border-slate-200 px-2 py-2 text-sm" />
                                </div>
                                <div>
                                    <label class="mb-1 block text-[11px] font-bold text-slate-600">بدل نقل</label>
                                    <input v-model.number="form.transport_allowance" type="number" min="0" step="0.01" class="w-full rounded-lg border border-slate-200 px-2 py-2 text-sm" />
                                </div>
                                <div>
                                    <label class="mb-1 block text-[11px] font-bold text-slate-600">بدلات أخرى</label>
                                    <input v-model.number="form.other_allowance" type="number" min="0" step="0.01" class="w-full rounded-lg border border-slate-200 px-2 py-2 text-sm" />
                                </div>
                            </div>
                            <p class="mt-2 text-xs font-extrabold text-emerald-800">
                                الإجمالي: {{ money(form.basic_salary + form.housing_allowance + form.transport_allowance + form.other_allowance) }}
                            </p>
                        </div>

                        <div class="rounded-xl border border-amber-200 bg-amber-50 p-3">
                            <h3 class="mb-2 text-sm font-extrabold text-amber-800">انتهاء الوثائق (للتنبيه)</h3>
                            <div class="grid gap-2 sm:grid-cols-3">
                                <div>
                                    <label class="mb-1 block text-[11px] font-bold text-slate-600">الإقامة</label>
                                    <input v-model="form.iqama_expiry" type="date" class="w-full rounded-lg border border-slate-200 px-2 py-2 text-sm" />
                                </div>
                                <div>
                                    <label class="mb-1 block text-[11px] font-bold text-slate-600">الجواز</label>
                                    <input v-model="form.passport_expiry" type="date" class="w-full rounded-lg border border-slate-200 px-2 py-2 text-sm" />
                                </div>
                                <div>
                                    <label class="mb-1 block text-[11px] font-bold text-slate-600">العقد</label>
                                    <input v-model="form.contract_expiry" type="date" class="w-full rounded-lg border border-slate-200 px-2 py-2 text-sm" />
                                </div>
                            </div>
                        </div>

                        <div>
                            <label class="mb-1 block text-sm font-bold text-slate-700">الآيبان</label>
                            <input v-model="form.bank_iban" dir="ltr" class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm" />
                        </div>

                        <label class="flex cursor-pointer items-center gap-2 text-sm font-bold text-slate-700">
                            <input type="checkbox" v-model="form.is_active" class="h-4 w-4 rounded border-slate-300 text-emerald-600" />
                            على رأس العمل
                        </label>
                    </div>

                    <div class="flex justify-end gap-2 border-t border-slate-100 px-6 py-4">
                        <button type="button" @click="showModal = false" class="rounded-xl border border-slate-200 px-4 py-2 text-sm font-bold text-slate-600">إلغاء</button>
                        <button type="submit" :disabled="form.processing" class="rounded-md bg-blue-600 px-5 py-2 text-sm font-bold text-white hover:bg-blue-700 disabled:opacity-60">حفظ</button>
                    </div>
                </form>
            </div>
        </div>
    </AppLayout>
</template>
