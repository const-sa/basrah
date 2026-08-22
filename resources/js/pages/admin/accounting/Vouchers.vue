<script setup lang="ts">
import { StatPill } from '@/components/data-table';
import { usePermissions } from '@/composables/usePermissions';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem, type PaymentMethodOption } from '@/types';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { Ban, CheckCircle2, Plus, X } from 'lucide-vue-next';
import { computed, ref } from 'vue';

interface Voucher {
    id: number; number: string; type: string; type_label: string;
    voucher_date: string; amount: number;
    treasury: string | null; account: string | null; party: string | null;
    description: string | null; status: string; status_label: string;
}

const props = defineProps<{
    vouchers: { data: Voucher[]; links: { url: string | null; label: string; active: boolean }[] };
    filters: Record<string, string | null>;
    types: { key: string; label: string }[];
    methods: PaymentMethodOption[];
    treasuries: { id: number; name: string; type_label: string; balance: number }[];
    accounts: { id: number; code: string; name: string; type: string }[];
    costCenters: { id: number; code: string; name: string }[];
    clients: { id: number; name: string }[];
    suppliers: { id: number; name: string }[];
}>();

const { can } = usePermissions();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'لوحة التحكم', href: '/admin' },
    { title: 'السندات والخزائن', href: '/admin/accounting/vouchers' },
];

const money = (n: number) => new Intl.NumberFormat('ar-SA-u-nu-latn', { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(n ?? 0);

const filters = ref({ ...props.filters });
const apply = () => router.get('/admin/accounting/vouchers', filters.value, { preserveState: true, replace: true });

const showModal = ref(false);
const form = useForm({
    type: 'receipt', voucher_date: new Date().toISOString().slice(0, 10),
    amount: 0, treasury_id: null as number | null, account_id: null as number | null,
    cost_center_id: null as number | null, client_id: null as number | null, supplier_id: null as number | null,
    payment_method_id: props.methods[0]?.id ?? null as number | null,
    reference: '', description: '', post_now: true,
});

/**
 * الحسابات المقترحة حسب نوع السند: القبض إيراد، والصرف والمصروف مصروف.
 * تصفيتها تمنع اختيار حساب لا يصح مقابلًا للسند.
 */
const suggestedAccounts = computed(() =>
    props.accounts.filter((a) =>
        form.type === 'receipt' ? ['revenue', 'liability', 'asset'].includes(a.type) : ['expense', 'asset', 'liability'].includes(a.type),
    ),
);

const openCreate = () => {
    form.reset();
    form.clearErrors();
    form.treasury_id = props.treasuries[0]?.id ?? null;
    showModal.value = true;
};

const submit = () => form.post('/admin/accounting/vouchers', { preserveScroll: true, onSuccess: () => (showModal.value = false) });

const post = (v: Voucher) => router.post(`/admin/accounting/vouchers/${v.id}/post`, {}, { preserveScroll: true });

const cancel = (v: Voucher) => {
    const reason = prompt(`سبب إلغاء السند ${v.number}:`);
    if (reason !== null) router.post(`/admin/accounting/vouchers/${v.id}/cancel`, { reason }, { preserveScroll: true });
};

const typeClass = (t: string) =>
    ({ receipt: 'bg-emerald-100 text-emerald-700', payment: 'bg-red-100 text-red-700', expense: 'bg-amber-100 text-amber-700' })[t] ??
    'bg-slate-100 text-slate-700';
</script>

<template>
    <Head title="السندات والخزائن" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="min-h-full space-y-4 bg-slate-100 p-5">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h1 class="text-2xl font-extrabold text-slate-900">السندات والخزائن</h1>
                    <p class="mt-1 text-sm font-medium text-slate-600">القبض والصرف والمصروفات — كل سند مرحَّل يولّد قيده</p>
                </div>
                <button v-if="can('vouchers.create')" type="button" @click="openCreate" class="inline-flex items-center gap-1.5 rounded-md bg-blue-600 px-4 py-2 text-sm font-bold text-white hover:bg-blue-700">
                    <Plus class="h-4 w-4" /> سند جديد
                </button>
            </div>

            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <StatPill v-for="t in treasuries" :key="t.id" :label="`${t.name}`" :value="money(t.balance)" :variant="t.balance < 0 ? 'danger' : 'success'" />
            </div>

            <div class="rounded-2xl border border-slate-200 bg-white p-3 shadow-sm">
                <div class="grid gap-2 sm:grid-cols-2">
                    <select v-model="filters.type" @change="apply" class="rounded-xl border border-slate-200 px-3 py-2.5 text-sm">
                        <option :value="null">كل الأنواع</option>
                        <option v-for="t in types" :key="t.key" :value="t.key">{{ t.label }}</option>
                    </select>
                    <select v-model="filters.status" @change="apply" class="rounded-xl border border-slate-200 px-3 py-2.5 text-sm">
                        <option :value="null">كل الحالات</option>
                        <option value="draft">مسوّدة</option>
                        <option value="posted">مرحَّل</option>
                        <option value="cancelled">ملغي</option>
                    </select>
                </div>
            </div>

            <div class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="bg-slate-100">
                            <tr>
                                <th class="px-4 py-3 text-right text-xs font-extrabold text-[#1e3a8a]">السند</th>
                                <th class="px-4 py-3 text-center text-xs font-extrabold text-[#1e3a8a]">النوع</th>
                                <th class="px-4 py-3 text-right text-xs font-extrabold text-[#1e3a8a]">الخزينة والحساب</th>
                                <th class="px-4 py-3 text-right text-xs font-extrabold text-[#1e3a8a]">الطرف</th>
                                <th class="px-4 py-3 text-left text-xs font-extrabold text-[#1e3a8a]">المبلغ</th>
                                <th class="px-4 py-3 text-center text-xs font-extrabold text-[#1e3a8a]">الحالة</th>
                                <th class="px-4 py-3 text-center text-xs font-extrabold text-[#1e3a8a]">إجراءات</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="v in vouchers.data" :key="v.id" class="border-t border-slate-100 hover:bg-slate-50">
                                <td class="px-4 py-3">
                                    <div class="font-extrabold text-slate-800" dir="ltr">{{ v.number }}</div>
                                    <div class="text-[11px] text-slate-500" dir="ltr">{{ v.voucher_date }}</div>
                                </td>
                                <td class="px-4 py-3 text-center">
                                    <span class="rounded-md px-2 py-0.5 text-[11px] font-bold" :class="typeClass(v.type)">{{ v.type_label }}</span>
                                </td>
                                <td class="px-4 py-3 text-xs">
                                    <div class="font-bold text-slate-700">{{ v.treasury }}</div>
                                    <div class="text-slate-500">{{ v.account }}</div>
                                </td>
                                <td class="px-4 py-3 text-xs font-bold text-slate-700">{{ v.party ?? '—' }}</td>
                                <td class="px-4 py-3 text-left font-extrabold text-slate-800" dir="ltr">{{ money(v.amount) }}</td>
                                <td class="px-4 py-3 text-center">
                                    <span
                                        class="rounded-md px-2 py-0.5 text-[11px] font-bold"
                                        :class="{ 'bg-emerald-100 text-emerald-700': v.status === 'posted', 'bg-amber-100 text-amber-700': v.status === 'draft', 'bg-slate-200 text-slate-600': v.status === 'cancelled' }"
                                    >{{ v.status_label }}</span>
                                </td>
                                <td class="px-4 py-3">
                                    <div class="flex items-center justify-center gap-1">
                                        <button v-if="can('vouchers.approve') && v.status === 'draft'" type="button" @click="post(v)" title="ترحيل" class="rounded-lg bg-emerald-500 p-1.5 text-white hover:bg-emerald-600">
                                            <CheckCircle2 class="h-3.5 w-3.5" />
                                        </button>
                                        <button v-if="can('vouchers.approve') && v.status !== 'cancelled'" type="button" @click="cancel(v)" title="إلغاء" class="rounded-lg bg-red-500 p-1.5 text-white hover:bg-red-600">
                                            <Ban class="h-3.5 w-3.5" />
                                        </button>
                                    </div>
                                </td>
                            </tr>
                            <tr v-if="!vouchers.data.length"><td colspan="7" class="px-4 py-10 text-center text-sm text-slate-500">لا سندات</td></tr>
                        </tbody>
                    </table>
                </div>

                <div v-if="vouchers.links.length > 3" class="flex flex-wrap justify-center gap-1 border-t border-slate-100 p-3">
                    <Link v-for="l in vouchers.links" :key="l.label" :href="l.url ?? '#'"
                        :class="['rounded-lg px-3 py-1.5 text-xs font-bold', l.active ? 'bg-blue-600 text-white' : l.url ? 'bg-white text-slate-600 ring-1 ring-slate-200' : 'text-slate-300']"
                        v-html="l.label" />
                </div>
            </div>
        </div>

        <div v-if="showModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4" @click.self="showModal = false">
            <div class="flex max-h-[92vh] w-full max-w-xl flex-col rounded-2xl bg-white shadow-2xl">
                <div class="flex items-center justify-between border-b border-slate-100 px-6 py-4">
                    <h2 class="text-lg font-extrabold text-slate-900">سند جديد</h2>
                    <button type="button" @click="showModal = false" class="text-slate-400 hover:text-slate-600"><X class="h-5 w-5" /></button>
                </div>

                <form @submit.prevent="submit" class="flex min-h-0 flex-1 flex-col">
                    <div class="min-h-0 flex-1 space-y-3 overflow-y-auto px-6 py-4">
                        <div class="grid grid-cols-3 gap-2">
                            <button
                                v-for="t in types" :key="t.key" type="button" @click="form.type = t.key"
                                class="rounded-xl border-2 py-2.5 text-sm font-extrabold transition"
                                :class="form.type === t.key ? 'border-emerald-400 bg-emerald-50 text-emerald-700' : 'border-slate-200 text-slate-600 hover:border-slate-300'"
                            >{{ t.label }}</button>
                        </div>

                        <div class="grid gap-3 sm:grid-cols-2">
                            <div>
                                <label class="mb-1 block text-sm font-bold text-slate-700">التاريخ</label>
                                <input v-model="form.voucher_date" type="date" class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm" />
                            </div>
                            <div>
                                <label class="mb-1 block text-sm font-bold text-slate-700">المبلغ</label>
                                <input v-model.number="form.amount" type="number" min="0.01" step="0.01" class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm" />
                                <p v-if="form.errors.amount" class="mt-1 text-xs text-red-500">{{ form.errors.amount }}</p>
                            </div>
                        </div>

                        <div class="grid gap-3 sm:grid-cols-2">
                            <div>
                                <label class="mb-1 block text-sm font-bold text-slate-700">الخزينة</label>
                                <select v-model="form.treasury_id" class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm">
                                    <option :value="null">—</option>
                                    <option v-for="t in treasuries" :key="t.id" :value="t.id">{{ t.name }} ({{ money(t.balance) }})</option>
                                </select>
                                <p v-if="form.errors.treasury_id" class="mt-1 text-xs text-red-500">{{ form.errors.treasury_id }}</p>
                            </div>
                            <div>
                                <label class="mb-1 block text-sm font-bold text-slate-700">الحساب المقابل</label>
                                <select v-model="form.account_id" class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm">
                                    <option :value="null">—</option>
                                    <option v-for="a in suggestedAccounts" :key="a.id" :value="a.id">{{ a.code }} — {{ a.name }}</option>
                                </select>
                                <p v-if="form.errors.account_id" class="mt-1 text-xs text-red-500">{{ form.errors.account_id }}</p>
                            </div>
                        </div>

                        <div class="grid gap-3 sm:grid-cols-2">
                            <div>
                                <label class="mb-1 block text-sm font-bold text-slate-700">مركز التكلفة</label>
                                <select v-model="form.cost_center_id" class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm">
                                    <option :value="null">—</option>
                                    <option v-for="c in costCenters" :key="c.id" :value="c.id">{{ c.name }}</option>
                                </select>
                            </div>
                            <div>
                                <label class="mb-1 block text-sm font-bold text-slate-700">طريقة الدفع</label>
                                <select v-model="form.payment_method_id" class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm">
                                    <option v-for="m in methods" :key="m.id" :value="m.id">{{ m.label }}</option>
                                </select>
                            </div>
                        </div>

                        <div class="grid gap-3 sm:grid-cols-2">
                            <div>
                                <label class="mb-1 block text-sm font-bold text-slate-700">العميل</label>
                                <select v-model="form.client_id" class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm">
                                    <option :value="null">—</option>
                                    <option v-for="c in clients" :key="c.id" :value="c.id">{{ c.name }}</option>
                                </select>
                            </div>
                            <div>
                                <label class="mb-1 block text-sm font-bold text-slate-700">المورد</label>
                                <select v-model="form.supplier_id" class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm">
                                    <option :value="null">—</option>
                                    <option v-for="s in suppliers" :key="s.id" :value="s.id">{{ s.name }}</option>
                                </select>
                            </div>
                        </div>

                        <div>
                            <label class="mb-1 block text-sm font-bold text-slate-700">البيان</label>
                            <textarea v-model="form.description" rows="2" class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm"></textarea>
                        </div>

                        <label class="flex cursor-pointer items-center gap-2 text-sm font-bold text-slate-700">
                            <input type="checkbox" v-model="form.post_now" class="h-4 w-4 rounded border-slate-300 text-emerald-600" />
                            ترحيل السند فورًا وتوليد قيده
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
