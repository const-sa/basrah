<script setup lang="ts">
import { StatusBadge } from '@/components/data-table';
import SmallBox from '@/components/lte/SmallBox.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { FlaskConical, Lock, Pencil, Plus, Power, Search, Trash2, UserCheck, Users, UserX, X } from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';

interface Role { id: number; name: string; slug: string; }
interface UnitOption { id: number; name: string; code: string; type: string }
interface EmployeeOption { id: number; name: string }
interface UserRow {
    id: number; name: string; email: string;
    role: string | null; role_id: number | null;
    employee_id: number | null; employee_name: string | null;
    is_active: boolean; is_demo: boolean;
    has_all_units: boolean; is_super_admin: boolean;
    unit_ids: number[]; unit_names: string[];
    systems: string[];
    created_at: string | null;
}
interface Paginated<T> {
    data: T[];
    links: { url: string | null; label: string; active: boolean }[];
    from: number | null;
    to: number | null;
    total: number;
}

interface EmployeeStats {
    total: number;
    active: number;
    inactive: number;
    scoped: number;
}

interface DemoPreset { key: string; label: string; name: string; email: string; exists: boolean; }
interface DemoAccount { id: number; name: string; email: string; role: string | null; is_active: boolean; }
interface DemoPanel { password: string; presets: DemoPreset[]; accounts: DemoAccount[]; }

const props = defineProps<{
    users: Paginated<UserRow>;
    roles: Role[];
    units: UnitOption[];
    employees: EmployeeOption[];
    systemLabels: { key: string; label: string }[];
    filters: { q: string };
    stats: EmployeeStats;
    demo: DemoPanel;
}>();

const systemLabel = (key: string) => props.systemLabels.find((s) => s.key === key)?.label ?? key;

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'لوحة التحكم', href: '/admin' },
    { title: 'الموظفين', href: '/admin/employees' },
];

// ===== بحث =====
const search = ref(props.filters.q ?? '');
let timer: ReturnType<typeof setTimeout> | undefined;
watch(search, (val) => {
    clearTimeout(timer);
    timer = setTimeout(() => {
        router.get('/admin/employees', { q: val }, { preserveState: true, replace: true, preserveScroll: true });
    }, 350);
});

// ===== نموذج الإضافة/التعديل =====
const showModal = ref(false);
const editingId = ref<number | null>(null);
const form = useForm({
    name: '',
    email: '',
    password: '',
    password_confirmation: '',
    role_id: '' as number | string,
    employee_id: null as number | null,
    is_active: true,
    has_all_units: false,
    unit_ids: [] as number[],
});

/** المالك يرى كل الوحدات بحكم مجموعته، فلا معنى لتقييده. */
const selectedRoleIsSuperAdmin = computed(
    () => props.roles.find((r) => r.id === Number(form.role_id))?.slug === 'super-admin',
);

const openCreate = () => {
    editingId.value = null;
    form.reset();
    form.clearErrors();
    showModal.value = true;
};

const openEdit = (u: UserRow) => {
    editingId.value = u.id;
    form.reset();
    form.clearErrors();
    form.name = u.name;
    form.email = u.email;
    form.is_active = u.is_active;
    form.role_id = u.role_id ?? '';
    form.employee_id = u.employee_id;
    form.has_all_units = u.has_all_units;
    form.unit_ids = [...u.unit_ids];
    showModal.value = true;
};

const toggleUnit = (id: number) => {
    const i = form.unit_ids.indexOf(id);
    i === -1 ? form.unit_ids.push(id) : form.unit_ids.splice(i, 1);
};

const toggleScope = (u: UserRow) =>
    router.patch(`/admin/employees/${u.id}/scope`, {}, { preserveScroll: true });

const submit = () => {
    if (editingId.value) {
        form.put(`/admin/employees/${editingId.value}`, { preserveScroll: true, onSuccess: () => (showModal.value = false) });
    } else {
        form.post('/admin/employees', { preserveScroll: true, onSuccess: () => (showModal.value = false) });
    }
};

const toggle = (u: UserRow) => router.patch(`/admin/employees/${u.id}/toggle`, {}, { preserveScroll: true });

const destroy = (u: UserRow) => {
    // Demo accounts are protected: they can only be removed from the trial accounts panel.
    if (u.is_demo) {
        showDemo.value = true;
        return;
    }
    if (confirm(`حذف الموظف «${u.name}»؟`)) {
        router.delete(`/admin/employees/${u.id}`, { preserveScroll: true });
    }
};

// ===== حسابات التجربة =====
const showDemo = ref(false);
const demoBusy = ref(false);
const createSelection = ref<string[]>([]);
const deleteSelection = ref<number[]>([]);

const creatablePresets = computed(() => props.demo.presets.filter((p) => !p.exists));

const openDemo = () => {
    createSelection.value = creatablePresets.value.map((p) => p.key);
    deleteSelection.value = [];
    showDemo.value = true;
};

const activateDemo = () => {
    if (createSelection.value.length === 0) return;
    demoBusy.value = true;
    router.post('/admin/employees/demo', { accounts: createSelection.value }, {
        preserveScroll: true,
        onFinish: () => {
            demoBusy.value = false;
            createSelection.value = [];
        },
    });
};

const deleteDemo = () => {
    if (deleteSelection.value.length === 0) return;
    if (!confirm(`حذف ${deleteSelection.value.length} حساب تجربة؟`)) return;
    demoBusy.value = true;
    router.delete('/admin/employees/demo', {
        data: { ids: deleteSelection.value },
        preserveScroll: true,
        onFinish: () => {
            demoBusy.value = false;
            deleteSelection.value = [];
        },
    });
};
</script>

<template>
    <Head title="الموظفين" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="space-y-4 p-5">
            <h1 class="text-2xl font-extrabold text-slate-900">الموظفين</h1>

            <!-- مربّعات الإحصائيات -->
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                <SmallBox :value="stats.total" label="كل الموظفين" variant="info" :icon="Users" />
                <SmallBox :value="stats.active" label="المفعّلون" variant="success" :icon="UserCheck" />
                <SmallBox :value="stats.inactive" label="الموقوفون" variant="danger" :icon="UserX" />
            </div>

            <!-- بطاقة الموظفين بنمط AdminLTE -->
            <div class="lte-card">
                <div class="lte-card-header">
                    <h3 class="lte-card-title">دليل الموظفين</h3>
                    <div class="flex flex-wrap items-center gap-2">
                        <div class="flex items-stretch overflow-hidden rounded-md border border-slate-300">
                            <span class="flex items-center bg-slate-50 px-2.5 text-slate-400"><Search class="h-4 w-4" /></span>
                            <input v-model="search" type="search" placeholder="بحث بالاسم أو البريد" class="w-48 border-0 px-3 py-2 text-sm focus:outline-none focus:ring-0" />
                        </div>
                        <button type="button" @click="openDemo" class="inline-flex items-center gap-1.5 rounded-md border border-amber-300 bg-amber-50 px-4 py-2 text-sm font-bold text-amber-700 transition hover:bg-amber-100">
                            <FlaskConical class="h-4 w-4" /> حسابات التجربة
                        </button>
                        <button type="button" @click="openCreate" class="inline-flex items-center gap-1.5 rounded-md bg-blue-600 px-4 py-2 text-sm font-bold text-white transition hover:bg-blue-700">
                            <Plus class="h-4 w-4" /> موظف جديد
                        </button>
                    </div>
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full min-w-[720px] text-sm">
                        <thead>
                            <tr class="border-b-2 border-[#dee2e6] text-[#1e3a8a]">
                                <th class="px-4 py-3 text-start font-semibold">الموظف</th>
                                <th class="px-4 py-3 text-start font-semibold">البريد</th>
                                <th class="px-4 py-3 text-center font-semibold">المجموعة والأقسام</th>
                                <th class="px-4 py-3 text-center font-semibold">نطاق الوحدات</th>
                                <th class="px-4 py-3 text-center font-semibold">الحالة</th>
                                <th class="px-4 py-3 text-end font-semibold">الإجراءات</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="u in users.data" :key="u.id" class="border-b border-slate-100 align-middle transition hover:bg-slate-50">
                                <td class="px-4 py-2.5">
                                    <span class="font-bold text-slate-800">{{ u.name }}</span>
                                    <span v-if="u.is_demo" class="ms-2 inline-flex items-center gap-1 rounded-full bg-amber-100 px-2 py-0.5 text-xs font-bold text-amber-700">
                                        <FlaskConical class="h-3 w-3" /> تجربة
                                    </span>
                                </td>
                                <td class="px-4 py-2.5">
                                    <div dir="ltr">{{ u.email }}</div>
                                    <div v-if="u.employee_name" class="text-[11px] font-bold text-slate-500">ملف: {{ u.employee_name }}</div>
                                </td>
                                <td class="px-4 py-2.5 text-center">
                                    <StatusBadge v-if="u.role" variant="primary" :label="u.role" />
                                    <span v-else class="text-slate-400">—</span>
                                    <div v-if="u.systems.length" class="mt-1 flex flex-wrap justify-center gap-0.5">
                                        <span v-for="s in u.systems" :key="s" class="rounded bg-slate-100 px-1.5 py-0.5 text-[10px] font-bold text-slate-600">{{ systemLabel(s) }}</span>
                                    </div>
                                </td>
                                <td class="px-4 py-2.5 text-center">
                                    <button
                                        type="button" @click="toggleScope(u)"
                                        :title="u.has_all_units ? 'مقيّد بوحدات محددة' : 'إتاحة كل الوحدات'"
                                        class="rounded-md px-2 py-0.5 text-[11px] font-bold transition"
                                        :class="u.has_all_units || u.is_super_admin ? 'bg-violet-100 text-violet-700 hover:bg-violet-200' : 'bg-amber-100 text-amber-700 hover:bg-amber-200'"
                                    >
                                        {{ u.has_all_units || u.is_super_admin ? 'كل الوحدات' : `${u.unit_ids.length} وحدة` }}
                                    </button>
                                    <div v-if="!u.has_all_units && !u.is_super_admin && u.unit_names.length" class="mt-1 text-[10px] font-medium text-slate-500">
                                        {{ u.unit_names.join('، ') }}
                                    </div>
                                    <div v-else-if="!u.has_all_units && !u.is_super_admin" class="mt-1 text-[10px] font-bold text-red-500">
                                        بلا وحدات — لن يرى شيئًا
                                    </div>
                                </td>
                                <td class="px-4 py-2.5 text-center">
                                    <StatusBadge :variant="u.is_active ? 'success' : 'danger'" :label="u.is_active ? 'مفعّل' : 'موقوف'" />
                                    <div class="mt-0.5 text-[10px] text-slate-400" dir="ltr">{{ u.created_at }}</div>
                                </td>
                                <td class="px-4 py-2.5">
                                    <div class="flex justify-end">
                                        <div class="inline-flex divide-x divide-slate-300 overflow-hidden rounded-md border border-slate-300 rtl:divide-x-reverse">
                                            <button type="button" @click="openEdit(u)" title="تعديل" class="px-2.5 py-2 text-slate-600 transition hover:bg-slate-100"><Pencil class="h-4 w-4" /></button>
                                            <button type="button" @click="toggle(u)" title="تفعيل/إيقاف" class="px-2.5 py-2 text-emerald-600 transition hover:bg-emerald-50"><Power class="h-4 w-4" /></button>
                                            <button v-if="u.is_demo" type="button" @click="openDemo" title="حساب تجربة محمي من الحذف — يُحذف من لوحة حسابات التجربة" class="px-2.5 py-2 text-slate-400 transition hover:bg-slate-100"><Lock class="h-4 w-4" /></button>
                                            <button v-else type="button" @click="destroy(u)" title="حذف" class="px-2.5 py-2 text-red-600 transition hover:bg-red-50"><Trash2 class="h-4 w-4" /></button>
                                        </div>
                                    </div>
                                </td>
                            </tr>
                            <tr v-if="users.data.length === 0">
                                <td colspan="6" class="px-4 py-12 text-center text-sm text-slate-400">لا يوجد موظفون مطابقون.</td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div class="lte-card-footer">
                    <div class="text-sm font-medium text-slate-500">
                        عرض {{ users.from ?? 0 }} إلى {{ users.to ?? 0 }} من {{ users.total }} موظف
                    </div>
                    <div v-if="users.links.length > 3" class="inline-flex divide-x divide-slate-300 overflow-hidden rounded-md border border-slate-300 rtl:divide-x-reverse">
                        <template v-for="(link, i) in users.links" :key="i">
                            <Link v-if="link.url" :href="link.url" preserve-scroll
                                :class="['px-3 py-1.5 text-sm transition', link.active ? 'bg-blue-600 font-bold text-white' : 'bg-white text-slate-600 hover:bg-slate-50']"
                                v-html="link.label" />
                            <span v-else class="bg-white px-3 py-1.5 text-sm text-slate-300" v-html="link.label" />
                        </template>
                    </div>
                </div>
            </div>
        </div>

        <!-- نافذة حسابات التجربة -->
        <div v-if="showDemo" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4" @click.self="showDemo = false">
            <div class="max-h-[90vh] w-full max-w-lg overflow-y-auto rounded-2xl bg-white p-6 shadow-2xl">
                <div class="mb-4 flex items-center justify-between">
                    <h2 class="flex items-center gap-2 text-lg font-extrabold text-slate-900">
                        <FlaskConical class="h-5 w-5 text-amber-600" /> حسابات التجربة
                    </h2>
                    <button type="button" @click="showDemo = false" class="rounded-lg p-1 text-slate-400 hover:bg-slate-100 hover:text-slate-600"><X class="h-5 w-5" /></button>
                </div>

                <p class="mb-4 rounded-xl bg-amber-50 px-3 py-2 text-xs font-medium leading-relaxed text-amber-800">
                    حسابات جاهزة لتجربة النظام. كلمة المرور للجميع:
                    <code class="rounded bg-white px-1.5 py-0.5 font-bold" dir="ltr">{{ demo.password }}</code>
                    <br />
                    محمية من زر الحذف في الجدول — تُحذف من هنا فقط، ويمكن إيقافها في أي وقت.
                </p>

                <!-- تفعيل حسابات جديدة -->
                <div class="mb-5">
                    <h3 class="mb-2 text-sm font-extrabold text-slate-800">تفعيل حسابات</h3>
                    <div v-if="creatablePresets.length" class="space-y-2">
                        <label v-for="p in creatablePresets" :key="p.key" class="flex cursor-pointer items-start gap-2.5 rounded-xl border border-slate-200 px-3 py-2.5 transition hover:bg-slate-50">
                            <input v-model="createSelection" :value="p.key" type="checkbox" class="mt-0.5 h-4 w-4 rounded border-slate-300 text-emerald-600 focus:ring-emerald-200" />
                            <span class="text-sm">
                                <span class="block font-bold text-slate-800">{{ p.label }}</span>
                                <span class="block text-xs text-slate-500" dir="ltr">{{ p.email }}</span>
                            </span>
                        </label>
                        <button type="button" @click="activateDemo" :disabled="demoBusy || createSelection.length === 0"
                            class="mt-1 w-full rounded-md bg-emerald-600 px-4 py-2 text-sm font-bold text-white transition hover:bg-emerald-700 disabled:opacity-50">
                            تفعيل المحدد ({{ createSelection.length }})
                        </button>
                    </div>
                    <p v-else class="rounded-xl border border-dashed border-slate-200 px-3 py-4 text-center text-xs text-slate-400">
                        كل حسابات التجربة مُفعّلة بالفعل.
                    </p>
                </div>

                <!-- الحسابات المفعّلة -->
                <div>
                    <h3 class="mb-2 text-sm font-extrabold text-slate-800">الحسابات المفعّلة</h3>
                    <div v-if="demo.accounts.length" class="space-y-2">
                        <label v-for="a in demo.accounts" :key="a.id" class="flex cursor-pointer items-start gap-2.5 rounded-xl border border-slate-200 px-3 py-2.5 transition hover:bg-slate-50">
                            <input v-model="deleteSelection" :value="a.id" type="checkbox" class="mt-0.5 h-4 w-4 rounded border-slate-300 text-red-600 focus:ring-red-200" />
                            <span class="flex-1 text-sm">
                                <span class="block font-bold text-slate-800">{{ a.name }}</span>
                                <span class="block text-xs text-slate-500" dir="ltr">{{ a.email }}</span>
                            </span>
                            <StatusBadge :variant="a.is_active ? 'success' : 'danger'" :label="a.is_active ? 'مفعّل' : 'موقوف'" />
                        </label>
                        <button type="button" @click="deleteDemo" :disabled="demoBusy || deleteSelection.length === 0"
                            class="mt-1 inline-flex w-full items-center justify-center gap-1.5 rounded-md bg-red-600 px-4 py-2 text-sm font-bold text-white transition hover:bg-red-700 disabled:opacity-50">
                            <Trash2 class="h-4 w-4" /> حذف المحدد ({{ deleteSelection.length }})
                        </button>
                    </div>
                    <p v-else class="rounded-xl border border-dashed border-slate-200 px-3 py-4 text-center text-xs text-slate-400">
                        لا توجد حسابات تجربة مفعّلة.
                    </p>
                </div>
            </div>
        </div>

        <!-- نافذة النموذج -->
        <div v-if="showModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4" @click.self="showModal = false">
            <div class="flex max-h-[92vh] w-full max-w-lg flex-col overflow-y-auto rounded-2xl bg-white p-6 shadow-2xl">
                <div class="mb-4 flex items-center justify-between">
                    <h2 class="text-lg font-extrabold text-slate-900">{{ editingId ? 'تعديل موظف' : 'موظف جديد' }}</h2>
                    <button type="button" @click="showModal = false" class="rounded-lg p-1 text-slate-400 hover:bg-slate-100 hover:text-slate-600"><X class="h-5 w-5" /></button>
                </div>
                <form @submit.prevent="submit" class="space-y-3">
                    <div>
                        <label class="mb-1 block text-sm font-bold text-slate-700">الاسم</label>
                        <input v-model="form.name" type="text" class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm focus:border-emerald-400 focus:outline-none focus:ring-2 focus:ring-emerald-100" />
                        <p v-if="form.errors.name" class="mt-1 text-xs text-red-500">{{ form.errors.name }}</p>
                    </div>
                    <div>
                        <label class="mb-1 block text-sm font-bold text-slate-700">البريد الإلكتروني</label>
                        <input v-model="form.email" type="email" dir="ltr" class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm focus:border-emerald-400 focus:outline-none focus:ring-2 focus:ring-emerald-100" />
                        <p v-if="form.errors.email" class="mt-1 text-xs text-red-500">{{ form.errors.email }}</p>
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="mb-1 block text-sm font-bold text-slate-700">المجموعة</label>
                            <select v-model="form.role_id" class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm focus:border-emerald-400 focus:outline-none focus:ring-2 focus:ring-emerald-100">
                                <option value="">— بدون مجموعة —</option>
                                <option v-for="r in roles" :key="r.id" :value="r.id">{{ r.name }}</option>
                            </select>
                        </div>
                        <div>
                            <label class="mb-1 block text-sm font-bold text-slate-700">ملف الموظف</label>
                            <select v-model="form.employee_id" class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm focus:border-emerald-400 focus:outline-none focus:ring-2 focus:ring-emerald-100">
                                <option :value="null">— بلا ربط —</option>
                                <option v-for="e in employees" :key="e.id" :value="e.id">{{ e.name }}</option>
                            </select>
                            <p v-if="form.errors.employee_id" class="mt-1 text-xs text-red-500">{{ form.errors.employee_id }}</p>
                        </div>
                    </div>

                    <!-- نطاق الوحدات -->
                    <div class="rounded-xl border-2 p-3 transition" :class="form.has_all_units || selectedRoleIsSuperAdmin ? 'border-violet-200 bg-violet-50' : 'border-amber-200 bg-amber-50'">
                        <label class="mb-2 block text-sm font-bold text-slate-700">نطاق الوحدات</label>

                        <label v-if="!selectedRoleIsSuperAdmin" class="mb-2 flex cursor-pointer items-center gap-2 text-sm font-bold text-slate-700">
                            <input type="checkbox" v-model="form.has_all_units" class="h-4 w-4 rounded border-slate-300 text-violet-600" />
                            يرى كل الوحدات دون تقييد
                        </label>
                        <p v-else class="mb-1 text-xs font-bold text-violet-700">المالك يرى كل الوحدات بحكم مجموعته.</p>

                        <div v-if="!form.has_all_units && !selectedRoleIsSuperAdmin">
                            <p class="mb-1.5 text-[11px] font-medium text-slate-600">اختر الوحدات التي يعمل عليها — لن يرى غيرها في أي شاشة.</p>
                            <div class="flex flex-wrap gap-1.5">
                                <button
                                    v-for="u in units" :key="u.id" type="button" @click="toggleUnit(u.id)"
                                    class="rounded-lg px-2.5 py-1.5 text-[11px] font-bold transition"
                                    :class="form.unit_ids.includes(u.id) ? 'bg-emerald-600 text-white shadow-sm' : 'bg-white text-slate-600 ring-1 ring-slate-200 hover:bg-slate-100'"
                                >{{ u.name }}</button>
                            </div>
                            <p v-if="!form.unit_ids.length" class="mt-1.5 text-[11px] font-bold text-red-600">
                                بلا وحدات لن يرى الموظف أي حجز أو وحدة.
                            </p>
                        </div>
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="mb-1 block text-sm font-bold text-slate-700">كلمة المرور</label>
                            <input v-model="form.password" type="password" :placeholder="editingId ? 'اتركها فارغة لعدم التغيير' : ''" class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm focus:border-emerald-400 focus:outline-none focus:ring-2 focus:ring-emerald-100" />
                            <p v-if="form.errors.password" class="mt-1 text-xs text-red-500">{{ form.errors.password }}</p>
                        </div>
                        <div>
                            <label class="mb-1 block text-sm font-bold text-slate-700">تأكيد كلمة المرور</label>
                            <input v-model="form.password_confirmation" type="password" class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm focus:border-emerald-400 focus:outline-none focus:ring-2 focus:ring-emerald-100" />
                        </div>
                    </div>
                    <label class="flex items-center gap-2 text-sm font-bold text-slate-700">
                        <input v-model="form.is_active" type="checkbox" class="h-4 w-4 rounded border-slate-300 text-emerald-600 focus:ring-emerald-200" />
                        حساب نشط
                    </label>
                    <div class="flex justify-end gap-2 pt-2">
                        <button type="button" @click="showModal = false" class="rounded-xl border border-slate-200 bg-white px-4 py-2 text-sm font-bold text-slate-600 hover:bg-slate-50">إلغاء</button>
                        <button type="submit" :disabled="form.processing" class="rounded-md bg-blue-600 px-5 py-2 text-sm font-bold text-white shadow-sm transition hover:bg-blue-700 disabled:opacity-60">حفظ</button>
                    </div>
                </form>
            </div>
        </div>
    </AppLayout>
</template>
