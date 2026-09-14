<script setup lang="ts">
import { StatPill } from '@/components/data-table';
import UploadProgress from '@/components/UploadProgress.vue';
import { usePermissions } from '@/composables/usePermissions';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem, type PaymentMethodOption } from '@/types';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { Ban, CheckCircle2, FileText, ImageIcon, Paperclip, Plus, Trash2, X } from 'lucide-vue-next';
import { computed, ref } from 'vue';

interface Attachment {
    id: number; name: string; url: string; size: number; is_image: boolean;
}

interface Voucher {
    id: number; number: string; type: string; type_label: string;
    voucher_date: string; amount: number;
    treasury: string | null; account: string | null; party: string | null;
    description: string | null; status: string; status_label: string;
    attachments: Attachment[];
}

const ATTACHMENT_ACCEPT = 'image/jpeg,image/png,image/webp,application/pdf';

/** Mirrors ATTACHMENT_MAX in the controller. */
const MAX_ATTACHMENTS = 5;

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
    attachments: [] as File[],
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

const submit = () =>
    form.post('/admin/accounting/vouchers', {
        preserveScroll: true,
        forceFormData: true,
        onSuccess: () => (showModal.value = false),
    });

// ===== Attachments =====

const fileSize = (bytes: number) => (bytes >= 1048576 ? `${(bytes / 1048576).toFixed(1)} م.ب` : `${Math.max(1, Math.round(bytes / 1024))} ك.ب`);

/** Errors come back keyed per file (attachments.0), so the first one is shown. */
const firstAttachmentError = (errors: Partial<Record<string, string>>) =>
    Object.entries(errors).find(([key]) => key.startsWith('attachments'))?.[1] ?? '';

const pickFiles = (e: Event, target: { attachments: File[] }) => {
    const input = e.target as HTMLInputElement;
    target.attachments = [...target.attachments, ...Array.from(input.files ?? [])].slice(0, MAX_ATTACHMENTS);
    // Cleared so re-picking the same file fires change again.
    input.value = '';
};

const dropFile = (target: { attachments: File[] }, index: number) => {
    target.attachments = target.attachments.filter((_, i) => i !== index);
};

const attachId = ref<number | null>(null);
const attachForm = useForm({ attachments: [] as File[] });

/** Read from the prop, not a saved copy, so it refreshes after each upload or delete. */
const attachVoucher = computed(() => props.vouchers.data.find((v) => v.id === attachId.value) ?? null);

const openAttachments = (v: Voucher) => {
    attachForm.reset();
    attachForm.clearErrors();
    attachId.value = v.id;
};

const closeAttachments = () => (attachId.value = null);

const uploadAttachments = () => {
    if (!attachId.value || !attachForm.attachments.length) return;

    attachForm.post(`/admin/accounting/vouchers/${attachId.value}/attachments`, {
        preserveScroll: true,
        forceFormData: true,
        onSuccess: () => attachForm.reset(),
    });
};

const removeAttachment = (v: Voucher, a: Attachment) => {
    if (!confirm(`حذف المرفق «${a.name}»؟`)) return;

    router.delete(`/admin/accounting/vouchers/${v.id}/attachments/${a.id}`, { preserveScroll: true });
};

const canAttach = (v: Voucher) => can('vouchers.edit') && v.status !== 'cancelled';

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
                                <th class="px-4 py-3 text-center text-xs font-extrabold text-[#1e3a8a]">المرفقات</th>
                                <th class="px-4 py-3 text-center text-xs font-extrabold text-[#1e3a8a]">إجراءات</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="v in vouchers.data" :key="v.id" class="border-t border-slate-100 hover:bg-slate-50">
                                <td class="px-4 py-3 text-right">
                                    <div class="font-extrabold text-slate-800"><span dir="ltr">{{ v.number }}</span></div>
                                    <div class="text-[11px] text-slate-500"><span dir="ltr">{{ v.voucher_date }}</span></div>
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
                                <td class="px-4 py-3 text-center">
                                    <button
                                        type="button" @click="openAttachments(v)"
                                        :title="v.attachments.length ? `${v.attachments.length} مرفق` : 'لا مرفقات — اضغط للإضافة'"
                                        class="inline-flex items-center gap-1 rounded-lg px-2 py-1 text-xs font-bold transition"
                                        :class="v.attachments.length ? 'bg-blue-50 text-blue-600 hover:bg-blue-100' : 'text-slate-400 hover:bg-slate-100 hover:text-slate-600'"
                                    >
                                        <Paperclip class="h-3.5 w-3.5" />
                                        <span class="tabular-nums">{{ v.attachments.length || '—' }}</span>
                                    </button>
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
                            <tr v-if="!vouchers.data.length"><td colspan="8" class="px-4 py-10 text-center text-sm text-slate-500">لا سندات</td></tr>
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

                        <div>
                            <label class="mb-1 block text-sm font-bold text-slate-700">المرفقات</label>
                            <label class="flex cursor-pointer items-center justify-center gap-2 rounded-xl border-2 border-dashed border-slate-200 px-3 py-3 text-sm font-bold text-slate-500 hover:border-blue-300 hover:text-blue-600">
                                <Paperclip class="h-4 w-4" />
                                إرفاق الحوالة أو الفاتورة
                                <input type="file" multiple :accept="ATTACHMENT_ACCEPT" class="hidden" @change="pickFiles($event, form)" />
                            </label>

                            <ul v-if="form.attachments.length" class="mt-2 space-y-1">
                                <li v-for="(f, i) in form.attachments" :key="`${f.name}-${i}`" class="flex items-center justify-between gap-2 rounded-lg bg-slate-50 px-2.5 py-1.5 text-xs">
                                    <span class="truncate font-bold text-slate-700">{{ f.name }}</span>
                                    <button type="button" @click="dropFile(form, i)" class="shrink-0 font-bold text-red-500 hover:text-red-600">إزالة</button>
                                </li>
                            </ul>

                            <p class="mt-1 text-[11px] text-slate-500">PDF أو صورة، حتى 5 ميجابايت للملف و{{ MAX_ATTACHMENTS }} ملفات كحد أقصى.</p>
                            <p v-if="firstAttachmentError(form.errors)" class="mt-1 text-xs text-red-500">{{ firstAttachmentError(form.errors) }}</p>
                            <UploadProgress :progress="form.progress" />
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

        <div v-if="attachVoucher" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4" @click.self="closeAttachments">
            <div class="flex max-h-[92vh] w-full max-w-lg flex-col rounded-2xl bg-white shadow-2xl">
                <div class="flex items-center justify-between border-b border-slate-100 px-6 py-4">
                    <div>
                        <h2 class="text-lg font-extrabold text-slate-900">مرفقات السند</h2>
                        <p class="text-xs font-bold text-slate-500" dir="ltr">{{ attachVoucher.number }}</p>
                    </div>
                    <button type="button" @click="closeAttachments" class="text-slate-400 hover:text-slate-600"><X class="h-5 w-5" /></button>
                </div>

                <div class="min-h-0 flex-1 space-y-2 overflow-y-auto px-6 py-4">
                    <div
                        v-for="a in attachVoucher.attachments" :key="a.id"
                        class="flex items-center gap-3 rounded-xl border border-slate-200 p-2.5 hover:border-blue-300"
                    >
                        <a :href="a.url" target="_blank" rel="noopener" class="flex min-w-0 flex-1 items-center gap-3">
                            <span class="grid h-9 w-9 shrink-0 place-items-center rounded-lg bg-slate-100 text-slate-500">
                                <ImageIcon v-if="a.is_image" class="h-4 w-4" />
                                <FileText v-else class="h-4 w-4" />
                            </span>
                            <span class="min-w-0 flex-1">
                                <span class="block truncate text-sm font-bold text-slate-700">{{ a.name }}</span>
                                <span class="block text-[11px] text-slate-500">{{ fileSize(a.size) }}</span>
                            </span>
                        </a>
                        <button
                            v-if="can('vouchers.edit')" type="button" title="حذف المرفق"
                            @click="removeAttachment(attachVoucher, a)"
                            class="shrink-0 rounded-lg bg-red-50 p-1.5 text-red-500 hover:bg-red-100"
                        >
                            <Trash2 class="h-3.5 w-3.5" />
                        </button>
                    </div>

                    <p v-if="!attachVoucher.attachments.length" class="py-8 text-center text-sm text-slate-500">لا مرفقات على هذا السند</p>
                </div>

                <div v-if="canAttach(attachVoucher)" class="border-t border-slate-100 px-6 py-4">
                    <label class="flex cursor-pointer items-center justify-center gap-2 rounded-xl border-2 border-dashed border-slate-200 px-3 py-3 text-sm font-bold text-slate-500 hover:border-blue-300 hover:text-blue-600">
                        <Paperclip class="h-4 w-4" />
                        اختيار ملفات
                        <input type="file" multiple :accept="ATTACHMENT_ACCEPT" class="hidden" @change="pickFiles($event, attachForm)" />
                    </label>

                    <ul v-if="attachForm.attachments.length" class="mt-2 space-y-1">
                        <li v-for="(f, i) in attachForm.attachments" :key="`${f.name}-${i}`" class="flex items-center justify-between gap-2 rounded-lg bg-slate-50 px-2.5 py-1.5 text-xs">
                            <span class="truncate font-bold text-slate-700">{{ f.name }}</span>
                            <button type="button" @click="dropFile(attachForm, i)" class="shrink-0 font-bold text-red-500 hover:text-red-600">إزالة</button>
                        </li>
                    </ul>

                    <p v-if="firstAttachmentError(attachForm.errors)" class="mt-1 text-xs text-red-500">{{ firstAttachmentError(attachForm.errors) }}</p>
                    <UploadProgress :progress="attachForm.progress" />

                    <button
                        type="button" @click="uploadAttachments" :disabled="attachForm.processing || !attachForm.attachments.length"
                        class="mt-3 w-full rounded-md bg-blue-600 px-5 py-2 text-sm font-bold text-white hover:bg-blue-700 disabled:opacity-60"
                    >رفع المرفقات</button>
                </div>

                <p v-else-if="attachVoucher.status === 'cancelled'" class="border-t border-slate-100 px-6 py-4 text-center text-xs font-bold text-slate-500">
                    السند ملغي — لا يقبل مرفقات جديدة
                </p>
            </div>
        </div>
    </AppLayout>
</template>
