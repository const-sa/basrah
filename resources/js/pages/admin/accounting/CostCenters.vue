<script setup lang="ts">
import { StatPill } from '@/components/data-table';
import { usePermissions } from '@/composables/usePermissions';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { Pencil, Plus, ScrollText, Trash2, X } from 'lucide-vue-next';
import { ref } from 'vue';

interface Center {
    id: number;
    code: string;
    name: string;
    type_label: string;
    belongs_to: string | null;
    is_active: boolean;
    is_system: boolean;
    revenue: number;
    expense: number;
    profit: number;
}

const props = defineProps<{
    centers: { data: Center[]; links: { url: string | null; label: string; active: boolean }[] };
    filters: { from: string; to: string; search: string | null; status: string | null };
    totals: { revenue: number; expense: number; profit: number };
}>();

const { can } = usePermissions();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'لوحة التحكم', href: '/admin' },
    { title: 'مراكز التكلفة', href: '/admin/accounting/cost-centers' },
];

const money = (n: number) => new Intl.NumberFormat('ar-SA-u-nu-latn', { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(n ?? 0);

const filters = ref({ ...props.filters });
const apply = () => router.get('/admin/accounting/cost-centers', filters.value, { preserveState: true, replace: true });

const statementUrl = (c: Center) => `/admin/accounting/cost-centers/${c.id}?from=${filters.value.from}&to=${filters.value.to}`;

const showModal = ref(false);
const editing = ref<Center | null>(null);
const form = useForm({ name: '', code: '', is_active: true as boolean });

const openCreate = () => {
    editing.value = null;
    form.reset();
    form.clearErrors();
    showModal.value = true;
};

const openEdit = (c: Center) => {
    editing.value = c;
    form.clearErrors();
    form.name = c.name;
    form.code = c.code;
    form.is_active = c.is_active;
    showModal.value = true;
};

const submit = () => {
    const done = { preserveScroll: true, onSuccess: () => (showModal.value = false) };

    editing.value
        ? form.put(`/admin/accounting/cost-centers/${editing.value.id}`, done)
        : form.post('/admin/accounting/cost-centers', done);
};

const destroy = (c: Center) => {
    if (confirm(`حذف مركز التكلفة "${c.name}"؟`)) {
        router.delete(`/admin/accounting/cost-centers/${c.id}`, { preserveScroll: true });
    }
};
</script>

<template>
    <Head title="مراكز التكلفة" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="min-h-full space-y-4 bg-slate-100 p-5">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h1 class="text-2xl font-extrabold text-slate-900">مراكز التكلفة</h1>
                    <p class="mt-1 text-sm font-medium text-slate-600">ربح كل قاعة ووحدة وقسم على حدة — مراكز الوحدات يُنشئها النظام تلقائيًا</p>
                </div>
                <button v-if="can('cost_centers.create')" type="button" @click="openCreate" class="inline-flex items-center gap-1.5 rounded-md bg-blue-600 px-4 py-2 text-sm font-bold text-white hover:bg-blue-700">
                    <Plus class="h-4 w-4" /> مركز جديد
                </button>
            </div>

            <div class="grid gap-3 sm:grid-cols-3">
                <StatPill label="إجمالي الإيراد" :value="money(totals.revenue)" variant="success" />
                <StatPill label="إجمالي المصروف" :value="money(totals.expense)" variant="danger" />
                <StatPill label="صافي الربح" :value="money(totals.profit)" :variant="totals.profit >= 0 ? 'info' : 'warning'" />
            </div>

            <div class="rounded-2xl border border-slate-200 bg-white p-3 shadow-sm">
                <div class="grid gap-2 sm:grid-cols-2 lg:grid-cols-5">
                    <input v-model="filters.search" type="search" placeholder="اسم أو كود المركز" @keyup.enter="apply" class="rounded-xl border border-slate-200 px-3 py-2.5 text-sm" />
                    <input v-model="filters.from" type="date" class="rounded-xl border border-slate-200 px-3 py-2.5 text-sm" />
                    <input v-model="filters.to" type="date" class="rounded-xl border border-slate-200 px-3 py-2.5 text-sm" />
                    <select v-model="filters.status" class="rounded-xl border border-slate-200 px-3 py-2.5 text-sm">
                        <option :value="null">كل المراكز</option>
                        <option value="active">نشط</option>
                        <option value="inactive">موقوف</option>
                    </select>
                    <button type="button" @click="apply" class="rounded-xl bg-slate-800 px-4 py-2.5 text-sm font-bold text-white hover:bg-slate-900">تطبيق</button>
                </div>
            </div>

            <div class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="bg-slate-100">
                            <tr>
                                <th class="px-4 py-3 text-right text-xs font-extrabold text-[#1e3a8a]">المركز</th>
                                <th class="px-4 py-3 text-right text-xs font-extrabold text-[#1e3a8a]">النوع</th>
                                <th class="px-4 py-3 text-left text-xs font-extrabold text-[#1e3a8a]">الإيراد</th>
                                <th class="px-4 py-3 text-left text-xs font-extrabold text-[#1e3a8a]">المصروف</th>
                                <th class="px-4 py-3 text-left text-xs font-extrabold text-[#1e3a8a]">الربح</th>
                                <th class="px-4 py-3 text-center text-xs font-extrabold text-[#1e3a8a]">الحالة</th>
                                <th class="px-4 py-3 text-center text-xs font-extrabold text-[#1e3a8a]">إجراءات</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="c in centers.data" :key="c.id" class="border-t border-slate-100 hover:bg-slate-50">
                                <td class="px-4 py-3 text-right">
                                    <Link :href="statementUrl(c)" class="font-extrabold text-slate-800 hover:text-blue-700">{{ c.name }}</Link>
                                    <!-- dir on the span, not the block, so the code stays under the name -->
                                    <div class="text-[11px] text-slate-500"><span dir="ltr">{{ c.code }}</span></div>
                                </td>
                                <td class="px-4 py-3 text-right">
                                    <span class="inline-block rounded-md bg-slate-100 px-2 py-0.5 text-[11px] font-bold text-slate-600">{{ c.type_label }}</span>
                                    <div v-if="c.belongs_to" class="mt-0.5 text-[11px] text-slate-500">{{ c.belongs_to }}</div>
                                </td>
                                <td class="px-4 py-3 text-left font-bold text-emerald-600" dir="ltr">{{ money(c.revenue) }}</td>
                                <td class="px-4 py-3 text-left font-bold text-red-600" dir="ltr">{{ money(c.expense) }}</td>
                                <td class="px-4 py-3 text-left font-extrabold" :class="c.profit >= 0 ? 'text-slate-900' : 'text-red-700'" dir="ltr">{{ money(c.profit) }}</td>
                                <td class="px-4 py-3 text-center">
                                    <span class="rounded-md px-2 py-0.5 text-[11px] font-bold" :class="c.is_active ? 'bg-emerald-100 text-emerald-700' : 'bg-slate-200 text-slate-600'">{{ c.is_active ? 'نشط' : 'موقوف' }}</span>
                                </td>
                                <td class="px-4 py-3">
                                    <div class="flex items-center justify-center gap-1">
                                        <Link :href="statementUrl(c)" title="كشف الحركة" class="rounded-lg bg-emerald-600 p-1.5 text-white hover:bg-emerald-700">
                                            <ScrollText class="h-3.5 w-3.5" />
                                        </Link>
                                        <button v-if="can('cost_centers.edit')" type="button" @click="openEdit(c)" title="تعديل" class="rounded-lg bg-blue-500 p-1.5 text-white hover:bg-blue-600">
                                            <Pencil class="h-3.5 w-3.5" />
                                        </button>
                                        <button v-if="can('cost_centers.delete') && !c.is_system" type="button" @click="destroy(c)" title="حذف" class="rounded-lg bg-red-500 p-1.5 text-white hover:bg-red-600">
                                            <Trash2 class="h-3.5 w-3.5" />
                                        </button>
                                    </div>
                                </td>
                            </tr>
                            <tr v-if="!centers.data.length"><td colspan="7" class="px-4 py-10 text-center text-sm text-slate-500">لا مراكز تكلفة</td></tr>
                        </tbody>
                    </table>
                </div>

                <div v-if="centers.links.length > 3" class="flex flex-wrap justify-center gap-1 border-t border-slate-100 p-3">
                    <Link v-for="l in centers.links" :key="l.label" :href="l.url ?? '#'"
                        :class="['rounded-lg px-3 py-1.5 text-xs font-bold', l.active ? 'bg-blue-600 text-white' : l.url ? 'bg-white text-slate-600 ring-1 ring-slate-200' : 'text-slate-300']"
                        v-html="l.label" />
                </div>
            </div>
        </div>

        <!-- مركز جديد / تعديل -->
        <div v-if="showModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4" @click.self="showModal = false">
            <div class="w-full max-w-md rounded-2xl bg-white shadow-2xl">
                <div class="flex items-center justify-between border-b border-slate-100 px-6 py-4">
                    <h2 class="text-lg font-extrabold text-slate-900">{{ editing ? 'تعديل مركز التكلفة' : 'مركز تكلفة جديد' }}</h2>
                    <button type="button" @click="showModal = false" class="text-slate-400 hover:text-slate-600"><X class="h-5 w-5" /></button>
                </div>

                <form @submit.prevent="submit" class="space-y-3 px-6 py-4">
                    <div>
                        <label class="mb-1 block text-sm font-bold text-slate-700">اسم المركز</label>
                        <input v-model="form.name" type="text" class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm" />
                        <p v-if="form.errors.name" class="mt-1 text-xs text-red-500">{{ form.errors.name }}</p>
                    </div>

                    <div>
                        <label class="mb-1 block text-sm font-bold text-slate-700">الكود</label>
                        <input v-model="form.code" type="text" dir="ltr" :disabled="!!editing && editing.is_system" placeholder="يُولَّد تلقائيًا إن تُرك فارغًا" class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm disabled:bg-slate-100 disabled:text-slate-500" />
                        <p v-if="form.errors.code" class="mt-1 text-xs text-red-500">{{ form.errors.code }}</p>
                        <p v-else-if="editing?.is_system" class="mt-1 text-xs text-slate-500">كود مركز يتبع وحدة أو قسمًا لا يُغيَّر.</p>
                    </div>

                    <label class="flex items-center gap-2 text-sm font-bold text-slate-700">
                        <input v-model="form.is_active" type="checkbox" class="h-4 w-4 rounded border-slate-300" />
                        نشط — يظهر في قوائم اختيار مركز التكلفة
                    </label>

                    <div class="flex justify-end gap-2 pt-2">
                        <button type="button" @click="showModal = false" class="rounded-xl border border-slate-200 px-4 py-2 text-sm font-bold text-slate-600">إلغاء</button>
                        <button type="submit" :disabled="form.processing" class="rounded-md bg-blue-600 px-5 py-2 text-sm font-bold text-white hover:bg-blue-700 disabled:opacity-60">حفظ</button>
                    </div>
                </form>
            </div>
        </div>
    </AppLayout>
</template>
