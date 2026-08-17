<script setup lang="ts">
import { usePermissions } from '@/composables/usePermissions';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { Check, Plus, Trash2, X } from 'lucide-vue-next';
import { ref } from 'vue';

interface Leave {
    id: number; employee_name: string | null; employee_id: number;
    type: string; type_label: string; starts_on: string; ends_on: string; days: number;
    status: string; status_label: string; reason: string | null;
}
interface Advance {
    id: number; employee_name: string | null; employee_id: number;
    amount: number; deducted_amount: number; remaining: number;
    installments: number; installment_amount: number;
    granted_on: string; status: string; status_label: string;
}
interface Bonus {
    id: number; employee_name: string | null; employee_id: number;
    amount: number; reason: string | null; granted_on: string;
    status: string; status_label: string; payroll_number: string | null;
}

defineProps<{
    leaves: { data: Leave[]; links: { url: string | null; label: string; active: boolean }[] };
    advances: Advance[];
    bonuses: Bonus[];
    filters: Record<string, string | null>;
    employees: { id: number; name: string }[];
    leaveTypes: { key: string; label: string }[];
}>();

const { can } = usePermissions();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'لوحة التحكم', href: '/admin' },
    { title: 'الإجازات والسلف والمكافآت', href: '/admin/hr/leaves' },
];

const money = (n: number) => new Intl.NumberFormat('ar-SA-u-nu-latn', { maximumFractionDigits: 2 }).format(n ?? 0);

const tab = ref<'leaves' | 'advances' | 'bonuses'>('leaves');

const leaveForm = useForm({ employee_id: null as number | null, type: 'annual', starts_on: '', ends_on: '', reason: '' });
const advanceForm = useForm({ employee_id: null as number | null, amount: 0, installments: 1, granted_on: new Date().toISOString().slice(0, 10), notes: '' });
const bonusForm = useForm({ employee_id: null as number | null, amount: 0, reason: '', granted_on: new Date().toISOString().slice(0, 10), notes: '' });

const showLeave = ref(false);
const showAdvance = ref(false);
const showBonus = ref(false);

const submitLeave = () => leaveForm.post('/admin/hr/leaves', { preserveScroll: true, onSuccess: () => (showLeave.value = false) });
const submitAdvance = () => advanceForm.post('/admin/hr/advances', { preserveScroll: true, onSuccess: () => (showAdvance.value = false) });
const submitBonus = () => bonusForm.post('/admin/hr/bonuses', { preserveScroll: true, onSuccess: () => (showBonus.value = false) });

const decide = (l: Leave, status: string) => router.patch(`/admin/hr/leaves/${l.id}/decide`, { status }, { preserveScroll: true });
const approveAdvance = (a: Advance) => router.patch(`/admin/hr/advances/${a.id}/approve`, {}, { preserveScroll: true });
const approveBonus = (b: Bonus) => router.patch(`/admin/hr/bonuses/${b.id}/approve`, {}, { preserveScroll: true });
const destroyBonus = (b: Bonus) => {
    if (confirm(`حذف مكافأة ${b.employee_name ?? ''} بمبلغ ${money(b.amount)}؟`)) {
        router.delete(`/admin/hr/bonuses/${b.id}`, { preserveScroll: true });
    }
};

// زرّ الإضافة يتبع اللسان المفتوح — وواحدٌ منها فقط ظاهر في كل لحظة.
const openCreate = () => {
    if (tab.value === 'leaves') showLeave.value = true;
    else if (tab.value === 'advances') showAdvance.value = true;
    else showBonus.value = true;
};

const createPerm = () => ({ leaves: 'leaves.create', advances: 'advances.create', bonuses: 'bonuses.create' })[tab.value];
const createLabel = () => ({ leaves: 'إجازة جديدة', advances: 'سلفة جديدة', bonuses: 'مكافأة جديدة' })[tab.value];

const statusClass = (s: string) =>
    ({ pending: 'bg-amber-100 text-amber-700', approved: 'bg-emerald-100 text-emerald-700', rejected: 'bg-red-100 text-red-700', paid: 'bg-sky-100 text-sky-700', settled: 'bg-slate-200 text-slate-600', cancelled: 'bg-slate-200 text-slate-600' })[s] ??
    'bg-slate-100 text-slate-700';
</script>

<template>
    <Head title="الإجازات والسلف والمكافآت" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="min-h-full space-y-4 bg-slate-100 p-5">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h1 class="text-2xl font-extrabold text-slate-900">الإجازات والسلف والمكافآت</h1>
                    <p class="mt-1 text-sm font-medium text-slate-600">
                        السلفة المعتمدة تُستقطع من مسيّر الراتب، والمكافأة المعتمدة تُضاف إلى مسيّر شهرها
                    </p>
                </div>
                <Link href="/admin/hr/staff" class="rounded-md border border-slate-200 bg-white px-4 py-2 text-sm font-bold text-slate-700 hover:bg-slate-50">ملفات الموظفين</Link>
            </div>

            <div class="flex gap-1.5">
                <button type="button" @click="tab = 'leaves'" class="rounded-xl px-4 py-2 text-sm font-bold transition" :class="tab === 'leaves' ? 'bg-blue-600 text-white' : 'bg-white text-slate-600 ring-1 ring-slate-200'">الإجازات</button>
                <button type="button" @click="tab = 'advances'" class="rounded-xl px-4 py-2 text-sm font-bold transition" :class="tab === 'advances' ? 'bg-blue-600 text-white' : 'bg-white text-slate-600 ring-1 ring-slate-200'">السلف</button>
                <button type="button" @click="tab = 'bonuses'" class="rounded-xl px-4 py-2 text-sm font-bold transition" :class="tab === 'bonuses' ? 'bg-blue-600 text-white' : 'bg-white text-slate-600 ring-1 ring-slate-200'">المكافآت</button>
                <button
                    v-if="can(createPerm())" type="button"
                    @click="openCreate"
                    class="ms-auto inline-flex items-center gap-1.5 rounded-md bg-blue-600 px-4 py-2 text-sm font-bold text-white hover:bg-blue-700"
                >
                    <Plus class="h-4 w-4" /> {{ createLabel() }}
                </button>
            </div>

            <!-- الإجازات -->
            <div v-if="tab === 'leaves'" class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                <table class="w-full text-sm">
                    <thead class="bg-slate-100">
                        <tr>
                            <th class="px-4 py-3 text-right text-xs font-extrabold text-slate-700">الموظف</th>
                            <th class="px-4 py-3 text-center text-xs font-extrabold text-slate-700">النوع</th>
                            <th class="px-4 py-3 text-right text-xs font-extrabold text-slate-700">الفترة</th>
                            <th class="px-4 py-3 text-center text-xs font-extrabold text-slate-700">الأيام</th>
                            <th class="px-4 py-3 text-center text-xs font-extrabold text-slate-700">الحالة</th>
                            <th class="px-4 py-3 text-center text-xs font-extrabold text-slate-700">إجراء</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="l in leaves.data" :key="l.id" class="border-t border-slate-100 hover:bg-slate-50">
                            <td class="px-4 py-3 font-bold text-slate-800">{{ l.employee_name }}</td>
                            <td class="px-4 py-3 text-center"><span class="rounded-md bg-sky-100 px-2 py-0.5 text-[11px] font-bold text-sky-700">{{ l.type_label }}</span></td>
                            <td class="px-4 py-3 text-xs text-slate-600" dir="ltr">{{ l.starts_on }} → {{ l.ends_on }}</td>
                            <td class="px-4 py-3 text-center font-bold text-slate-700">{{ l.days }}</td>
                            <td class="px-4 py-3 text-center"><span class="rounded-md px-2 py-0.5 text-[11px] font-bold" :class="statusClass(l.status)">{{ l.status_label }}</span></td>
                            <td class="px-4 py-3">
                                <div v-if="can('leaves.approve') && l.status === 'pending'" class="flex justify-center gap-1">
                                    <button type="button" @click="decide(l, 'approved')" title="اعتماد" class="rounded-lg bg-emerald-500 p-1.5 text-white hover:bg-emerald-600"><Check class="h-3.5 w-3.5" /></button>
                                    <button type="button" @click="decide(l, 'rejected')" title="رفض" class="rounded-lg bg-red-500 p-1.5 text-white hover:bg-red-600"><X class="h-3.5 w-3.5" /></button>
                                </div>
                                <span v-else class="block text-center text-[11px] text-slate-400">—</span>
                            </td>
                        </tr>
                        <tr v-if="!leaves.data.length"><td colspan="6" class="px-4 py-10 text-center text-sm text-slate-500">لا إجازات</td></tr>
                    </tbody>
                </table>
            </div>

            <!-- السلف -->
            <div v-if="tab === 'advances'" class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                <table class="w-full text-sm">
                    <thead class="bg-slate-100">
                        <tr>
                            <th class="px-4 py-3 text-right text-xs font-extrabold text-slate-700">الموظف</th>
                            <th class="px-4 py-3 text-left text-xs font-extrabold text-slate-700">المبلغ</th>
                            <th class="px-4 py-3 text-center text-xs font-extrabold text-slate-700">الأقساط</th>
                            <th class="px-4 py-3 text-left text-xs font-extrabold text-slate-700">المستقطع / المتبقي</th>
                            <th class="px-4 py-3 text-center text-xs font-extrabold text-slate-700">الحالة</th>
                            <th class="px-4 py-3 text-center text-xs font-extrabold text-slate-700">إجراء</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="a in advances" :key="a.id" class="border-t border-slate-100 hover:bg-slate-50">
                            <td class="px-4 py-3 font-bold text-slate-800">{{ a.employee_name }}</td>
                            <td class="px-4 py-3 text-left font-extrabold text-slate-800" dir="ltr">{{ money(a.amount) }}</td>
                            <td class="px-4 py-3 text-center text-xs text-slate-600">{{ a.installments }} × {{ money(a.installment_amount) }}</td>
                            <td class="px-4 py-3 text-left text-xs" dir="ltr">
                                <span class="font-bold text-emerald-600">{{ money(a.deducted_amount) }}</span>
                                <span class="text-slate-400"> / </span>
                                <span class="font-bold text-red-600">{{ money(a.remaining) }}</span>
                            </td>
                            <td class="px-4 py-3 text-center"><span class="rounded-md px-2 py-0.5 text-[11px] font-bold" :class="statusClass(a.status)">{{ a.status_label }}</span></td>
                            <td class="px-4 py-3 text-center">
                                <button v-if="can('advances.approve') && a.status === 'pending'" type="button" @click="approveAdvance(a)" class="rounded-lg bg-emerald-500 px-3 py-1.5 text-[11px] font-bold text-white hover:bg-emerald-600">اعتماد</button>
                                <span v-else class="text-[11px] text-slate-400">—</span>
                            </td>
                        </tr>
                        <tr v-if="!advances.length"><td colspan="6" class="px-4 py-10 text-center text-sm text-slate-500">لا سلف</td></tr>
                    </tbody>
                </table>
            </div>

            <!-- المكافآت -->
            <div v-if="tab === 'bonuses'" class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                <table class="w-full text-sm">
                    <thead class="bg-slate-100">
                        <tr>
                            <th class="px-4 py-3 text-right text-xs font-extrabold text-slate-700">الموظف</th>
                            <th class="px-4 py-3 text-left text-xs font-extrabold text-slate-700">المبلغ</th>
                            <th class="px-4 py-3 text-right text-xs font-extrabold text-slate-700">السبب</th>
                            <th class="px-4 py-3 text-center text-xs font-extrabold text-slate-700">تاريخ المنح</th>
                            <th class="px-4 py-3 text-center text-xs font-extrabold text-slate-700">المسيّر</th>
                            <th class="px-4 py-3 text-center text-xs font-extrabold text-slate-700">الحالة</th>
                            <th class="px-4 py-3 text-center text-xs font-extrabold text-slate-700">إجراء</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="b in bonuses" :key="b.id" class="border-t border-slate-100 hover:bg-slate-50">
                            <td class="px-4 py-3 font-bold text-slate-800">{{ b.employee_name }}</td>
                            <td class="px-4 py-3 text-left font-extrabold text-emerald-700" dir="ltr">{{ money(b.amount) }}</td>
                            <td class="px-4 py-3 text-xs text-slate-600">{{ b.reason ?? '—' }}</td>
                            <td class="px-4 py-3 text-center text-xs text-slate-600" dir="ltr">{{ b.granted_on }}</td>
                            <td class="px-4 py-3 text-center text-xs text-slate-500" dir="ltr">{{ b.payroll_number ?? '—' }}</td>
                            <td class="px-4 py-3 text-center"><span class="rounded-md px-2 py-0.5 text-[11px] font-bold" :class="statusClass(b.status)">{{ b.status_label }}</span></td>
                            <td class="px-4 py-3">
                                <div class="flex justify-center gap-1">
                                    <button v-if="can('bonuses.approve') && b.status === 'pending'" type="button" @click="approveBonus(b)" class="rounded-lg bg-emerald-500 px-3 py-1.5 text-[11px] font-bold text-white hover:bg-emerald-600">اعتماد</button>
                                    <button v-if="can('bonuses.delete') && b.status !== 'paid'" type="button" @click="destroyBonus(b)" title="حذف" class="rounded-lg bg-red-500 p-1.5 text-white hover:bg-red-600"><Trash2 class="h-3.5 w-3.5" /></button>
                                    <span v-if="b.status === 'paid'" class="text-[11px] text-slate-400">صُرفت</span>
                                </div>
                            </td>
                        </tr>
                        <tr v-if="!bonuses.length"><td colspan="7" class="px-4 py-10 text-center text-sm text-slate-500">لا مكافآت</td></tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- إجازة -->
        <div v-if="showLeave" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4" @click.self="showLeave = false">
            <div class="w-full max-w-md rounded-2xl bg-white p-5 shadow-2xl">
                <div class="mb-3 flex items-center justify-between">
                    <h2 class="text-lg font-extrabold text-slate-900">إجازة جديدة</h2>
                    <button type="button" @click="showLeave = false" class="text-slate-400 hover:text-slate-600"><X class="h-5 w-5" /></button>
                </div>
                <form @submit.prevent="submitLeave" class="space-y-3">
                    <select v-model="leaveForm.employee_id" class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm">
                        <option :value="null">— اختر الموظف —</option>
                        <option v-for="e in employees" :key="e.id" :value="e.id">{{ e.name }}</option>
                    </select>
                    <select v-model="leaveForm.type" class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm">
                        <option v-for="t in leaveTypes" :key="t.key" :value="t.key">{{ t.label }}</option>
                    </select>
                    <div class="grid grid-cols-2 gap-2">
                        <input v-model="leaveForm.starts_on" type="date" class="rounded-xl border border-slate-200 px-3 py-2.5 text-sm" />
                        <input v-model="leaveForm.ends_on" type="date" class="rounded-xl border border-slate-200 px-3 py-2.5 text-sm" />
                    </div>
                    <textarea v-model="leaveForm.reason" rows="2" placeholder="السبب" class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm"></textarea>
                    <button type="submit" :disabled="leaveForm.processing" class="w-full rounded-md bg-blue-600 py-2.5 text-sm font-bold text-white hover:bg-blue-700 disabled:opacity-60">حفظ</button>
                </form>
            </div>
        </div>

        <!-- سلفة -->
        <div v-if="showAdvance" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4" @click.self="showAdvance = false">
            <div class="w-full max-w-md rounded-2xl bg-white p-5 shadow-2xl">
                <div class="mb-3 flex items-center justify-between">
                    <h2 class="text-lg font-extrabold text-slate-900">سلفة جديدة</h2>
                    <button type="button" @click="showAdvance = false" class="text-slate-400 hover:text-slate-600"><X class="h-5 w-5" /></button>
                </div>
                <form @submit.prevent="submitAdvance" class="space-y-3">
                    <select v-model="advanceForm.employee_id" class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm">
                        <option :value="null">— اختر الموظف —</option>
                        <option v-for="e in employees" :key="e.id" :value="e.id">{{ e.name }}</option>
                    </select>
                    <div class="grid grid-cols-2 gap-2">
                        <div>
                            <label class="mb-1 block text-[11px] font-bold text-slate-600">المبلغ</label>
                            <input v-model.number="advanceForm.amount" type="number" min="1" step="0.01" class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm" />
                        </div>
                        <div>
                            <label class="mb-1 block text-[11px] font-bold text-slate-600">عدد الأقساط</label>
                            <input v-model.number="advanceForm.installments" type="number" min="1" max="36" class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm" />
                        </div>
                    </div>
                    <p v-if="advanceForm.amount > 0 && advanceForm.installments > 0" class="rounded-lg bg-slate-50 px-3 py-2 text-xs font-bold text-slate-600">
                        القسط الشهري: {{ money(advanceForm.amount / advanceForm.installments) }}
                    </p>
                    <input v-model="advanceForm.granted_on" type="date" class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm" />
                    <button type="submit" :disabled="advanceForm.processing" class="w-full rounded-md bg-blue-600 py-2.5 text-sm font-bold text-white hover:bg-blue-700 disabled:opacity-60">حفظ</button>
                </form>
            </div>
        </div>

        <!-- مكافأة -->
        <div v-if="showBonus" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4" @click.self="showBonus = false">
            <div class="w-full max-w-md rounded-2xl bg-white p-5 shadow-2xl">
                <div class="mb-3 flex items-center justify-between">
                    <h2 class="text-lg font-extrabold text-slate-900">مكافأة جديدة</h2>
                    <button type="button" @click="showBonus = false" class="text-slate-400 hover:text-slate-600"><X class="h-5 w-5" /></button>
                </div>
                <form @submit.prevent="submitBonus" class="space-y-3">
                    <select v-model="bonusForm.employee_id" class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm">
                        <option :value="null">— اختر الموظف —</option>
                        <option v-for="e in employees" :key="e.id" :value="e.id">{{ e.name }}</option>
                    </select>
                    <div>
                        <label class="mb-1 block text-[11px] font-bold text-slate-600">المبلغ</label>
                        <input v-model.number="bonusForm.amount" type="number" min="1" step="0.01" class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm" />
                    </div>
                    <div>
                        <label class="mb-1 block text-[11px] font-bold text-slate-600">السبب — يظهر في سجل المكافآت</label>
                        <input v-model="bonusForm.reason" type="text" maxlength="255" placeholder="مثال: مكافأة أداء الربع الثالث" class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm" />
                    </div>
                    <div>
                        <label class="mb-1 block text-[11px] font-bold text-slate-600">تاريخ المنح — يحدد الشهر الذي تُصرف فيه</label>
                        <input v-model="bonusForm.granted_on" type="date" class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm" />
                    </div>
                    <p class="rounded-lg bg-slate-50 px-3 py-2 text-xs font-medium text-slate-600">
                        المكافأة لا تدخل المسيّر حتى تُعتمد، وتُقفل تلقائيًا عند اعتماد مسيّر شهرها فلا تُصرف مرتين.
                    </p>
                    <button type="submit" :disabled="bonusForm.processing" class="w-full rounded-md bg-blue-600 py-2.5 text-sm font-bold text-white hover:bg-blue-700 disabled:opacity-60">حفظ</button>
                </form>
            </div>
        </div>
    </AppLayout>
</template>
