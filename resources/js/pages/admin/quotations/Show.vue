<script setup lang="ts">
import { usePermissions } from '@/composables/usePermissions';
import { useVat } from '@/composables/useVat';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem, type PaymentMethodOption } from '@/types';
import { Head, Link, router } from '@inertiajs/vue3';
import { CheckCircle2, ChevronRight, Clock, FileText, Printer, ReceiptText, X, XCircle } from 'lucide-vue-next';
import { computed, ref } from 'vue';

interface QuotationItem {
    id: number;
    name: string;
    code: string | null;
    quantity: number;
    unit_price: number;
    tax_amount: number;
    total_price: number;
}

interface QuotationData {
    id: number;
    number: string;
    date: string;
    time: string;
    valid_until: string | null;
    client: string | null;
    client_mobile: string | null;
    user: string | null;
    subtotal: number;
    discount_amount: number;
    tax_amount: number;
    total: number;
    status: string;
    status_label: string;
    notes: string | null;
    /** الفاتورة الصادرة عن العرض، إن صدرت. */
    invoice: { id: number; number: string } | null;
}

const props = defineProps<{
    quotation: QuotationData;
    items: QuotationItem[];
    methods: PaymentMethodOption[];
}>();

const { can } = usePermissions();

// العرض يُقرأ بما حُرِّر به: عرضٌ حُسبت ضريبته تبقى سطورها بعد إطفاء المفتاح.
const { shows } = useVat();

const showsTax = computed(() => shows(props.quotation.tax_amount));

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'لوحة التحكم', href: '/admin' },
    { title: 'عروض الأسعار', href: '/admin/quotations' },
    { title: `عرض سعر رقم ${props.quotation.number}`, href: '#' },
];

const money = (n: number) => new Intl.NumberFormat('ar-SA-u-nu-latn', { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(n ?? 0);
const qty = (n: number) => new Intl.NumberFormat('ar-SA-u-nu-latn', { maximumFractionDigits: 3 }).format(n ?? 0);

const print = () => window.print();

// ── إصدار الفاتورة من العرض ───────────────────
/**
 * العرض يُرسَل للعميل، فإذا وافق فُتحت صفحته وصدرت الفاتورة منها — بنفس
 * الأصناف والأسعار والعميل والقسم. وهذا هو الزرّ الذي يُنتظر عند الموافقة،
 * فوجوده في سجلّ العروض وحده كان يُلزم العودة إليه بلا سبب.
 */
const invoicing = ref(false);
const paymentMethodId = ref<number | null>(props.methods[0]?.id ?? null);
const paidAmount = ref<number | null>(null);
const submitting = ref(false);

const selectedMethod = computed(() => props.methods.find((m) => m.id === paymentMethodId.value) ?? null);

const canInvoice = computed(() => can('sales.create') && !props.quotation.invoice && props.quotation.status !== 'rejected');

const openInvoice = () => {
    paymentMethodId.value = props.methods[0]?.id ?? null;
    paidAmount.value = null;
    invoicing.value = true;
};

const submitInvoice = () => {
    if (!paymentMethodId.value || submitting.value) return;

    submitting.value = true;
    router.post(
        `/admin/quotations/${props.quotation.id}/invoice`,
        {
            payment_method_id: paymentMethodId.value,
            paid_amount: paidAmount.value,
        },
        {
            preserveScroll: true,
            onFinish: () => {
                submitting.value = false;
                invoicing.value = false;
            },
        },
    );
};
</script>

<template>
    <Head :title="`عرض سعر ${quotation.number}`" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="mx-auto max-w-5xl space-y-6 p-4 sm:p-6 lg:p-8">
            <!-- Header Actions -->
            <div class="flex flex-wrap items-center justify-between gap-4 print:hidden">
                <div class="flex items-center gap-3">
                    <Link
                        href="/admin/quotations"
                        class="inline-flex h-10 w-10 items-center justify-center rounded-xl bg-white text-slate-500 shadow-sm ring-1 ring-inset ring-slate-200 transition-all hover:bg-slate-50 hover:text-slate-700"
                    >
                        <ChevronRight class="h-5 w-5" />
                    </Link>
                    <div>
                        <h1 class="text-2xl font-extrabold tracking-tight text-slate-900">
                            عرض سعر <span class="text-emerald-700" dir="ltr">#{{ quotation.number }}</span>
                        </h1>
                        <p class="mt-0.5 text-sm font-medium text-slate-500">
                            صالح حتى <span dir="ltr">{{ quotation.valid_until ?? 'غير محدد' }}</span>
                        </p>
                    </div>
                </div>
                <div class="flex items-center gap-2">
                    <button
                        @click="print"
                        class="inline-flex items-center gap-2 rounded-xl bg-white px-4 py-2.5 text-sm font-bold text-slate-700 shadow-sm ring-1 ring-inset ring-slate-300 transition-all hover:bg-slate-50"
                    >
                        <Printer class="h-4 w-4 text-slate-500" />
                        <span>طباعة</span>
                    </button>
                    <Link
                        :href="`/admin/quotations/${quotation.id}/edit`"
                        class="inline-flex items-center gap-2 rounded-xl bg-emerald-700 px-4 py-2.5 text-sm font-bold text-white shadow-sm transition-all hover:bg-emerald-800"
                    >
                        <FileText class="h-4 w-4" />
                        <span>تعديل العرض</span>
                    </Link>

                    <!-- الفاتورة إن صدرت، وإلا زرّ إصدارها -->
                    <Link
                        v-if="quotation.invoice"
                        :href="`/admin/sales?invoice=${quotation.invoice.id}`"
                        class="inline-flex items-center gap-2 rounded-xl bg-indigo-600 px-4 py-2.5 text-sm font-bold text-white shadow-sm transition-all hover:bg-indigo-700"
                    >
                        <ReceiptText class="h-4 w-4" />
                        <span
                            >الفاتورة <span dir="ltr">{{ quotation.invoice.number }}</span></span
                        >
                    </Link>
                    <button
                        v-else-if="canInvoice"
                        type="button"
                        @click="openInvoice"
                        class="inline-flex items-center gap-2 rounded-xl bg-indigo-600 px-4 py-2.5 text-sm font-bold text-white shadow-sm transition-all hover:bg-indigo-700"
                    >
                        <ReceiptText class="h-4 w-4" />
                        <span>إنشاء فاتورة</span>
                    </button>
                </div>
            </div>

            <!-- Invoice Paper -->
            <div class="relative overflow-hidden rounded-2xl bg-white shadow-xl ring-1 ring-slate-200 print:shadow-none print:ring-0">
                <div class="absolute left-0 right-0 top-0 h-2 bg-emerald-700 print:hidden"></div>

                <div class="p-8 sm:p-12">
                    <!-- Invoice Header Row -->
                    <div class="mb-10 flex flex-col justify-between gap-8 md:flex-row md:items-start">
                        <div class="space-y-2">
                            <h2 class="text-3xl font-black tracking-tight text-slate-900">عرض سعر</h2>
                            <p class="text-lg font-bold text-emerald-700" dir="ltr">{{ quotation.number }}</p>
                            <div
                                class="mt-4 inline-flex items-center gap-1.5 rounded-full px-3 py-1 text-sm font-bold"
                                :class="{
                                    'bg-emerald-100 text-emerald-800': quotation.status === 'accepted',
                                    'bg-amber-100 text-amber-800': quotation.status === 'pending',
                                    'bg-red-100 text-red-800': quotation.status === 'rejected',
                                }"
                            >
                                <CheckCircle2 v-if="quotation.status === 'accepted'" class="h-4 w-4" />
                                <Clock v-else-if="quotation.status === 'pending'" class="h-4 w-4" />
                                <XCircle v-else class="h-4 w-4" />
                                <span>{{ quotation.status_label }}</span>
                            </div>
                        </div>

                        <div class="grid grid-cols-2 gap-x-8 gap-y-4 text-right text-sm">
                            <div>
                                <dt class="mb-1 font-bold text-slate-500">العميل</dt>
                                <dd class="font-extrabold text-slate-900">{{ quotation.client ?? '—' }}</dd>
                            </div>
                            <div>
                                <dt class="mb-1 font-bold text-slate-500">رقم التواصل</dt>
                                <dd class="font-extrabold text-slate-900" dir="ltr">{{ quotation.client_mobile ?? '—' }}</dd>
                            </div>
                            <div>
                                <dt class="mb-1 font-bold text-slate-500">تاريخ الإصدار</dt>
                                <dd class="font-extrabold text-slate-900" dir="ltr">{{ quotation.date }}</dd>
                            </div>
                            <div>
                                <dt class="mb-1 font-bold text-slate-500">صالح حتى</dt>
                                <dd class="font-extrabold text-slate-900" dir="ltr">{{ quotation.valid_until ?? '—' }}</dd>
                            </div>
                        </div>
                    </div>

                    <!-- Items Table -->
                    <div class="mt-8 overflow-hidden rounded-xl border border-slate-200">
                        <table class="w-full text-sm">
                            <thead class="border-b border-slate-200 bg-slate-50">
                                <tr>
                                    <th class="px-4 py-3 text-right font-extrabold text-slate-800">#</th>
                                    <th class="px-4 py-3 text-right font-extrabold text-slate-800">الصنف</th>
                                    <th class="px-4 py-3 text-center font-extrabold text-slate-800">الكمية</th>
                                    <th class="px-4 py-3 text-center font-extrabold text-slate-800">سعر الوحدة</th>
                                    <th v-if="showsTax" class="px-4 py-3 text-center font-extrabold text-slate-800">الضريبة</th>
                                    <th class="px-4 py-3 text-left font-extrabold text-slate-800">الإجمالي</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-200 bg-white">
                                <tr v-for="(item, idx) in items" :key="item.id" class="hover:bg-slate-50/50">
                                    <td class="px-4 py-3 font-bold text-slate-500" dir="ltr">{{ idx + 1 }}</td>
                                    <td class="px-4 py-3 font-extrabold text-slate-900">{{ item.name }}</td>
                                    <td class="px-4 py-3 text-center font-bold text-slate-700" dir="ltr">{{ qty(item.quantity) }}</td>
                                    <td class="px-4 py-3 text-center font-bold text-slate-700" dir="ltr">{{ money(item.unit_price) }}</td>
                                    <td v-if="showsTax" class="px-4 py-3 text-center font-bold text-slate-700" dir="ltr">
                                        {{ money(item.tax_amount) }}
                                    </td>
                                    <td class="px-4 py-3 text-left font-black text-slate-900" dir="ltr">{{ money(item.total_price) }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <!-- Summary and Notes -->
                    <div class="mt-8 flex flex-col justify-between gap-8 md:flex-row md:items-start">
                        <div class="w-full space-y-6 md:w-1/2">
                            <div v-if="quotation.notes" class="rounded-xl border border-amber-200/50 bg-amber-50 p-4">
                                <h4 class="mb-2 text-xs font-black uppercase tracking-wider text-amber-800">ملاحظات / الشروط</h4>
                                <p class="text-sm font-bold leading-relaxed text-amber-900">{{ quotation.notes }}</p>
                            </div>

                            <div class="text-sm font-medium text-slate-500">
                                <p>
                                    بواسطة: <span class="font-bold text-slate-700">{{ quotation.user ?? '—' }}</span>
                                </p>
                            </div>
                        </div>

                        <div class="w-full md:w-1/3">
                            <div class="rounded-2xl border border-slate-200 bg-slate-50 p-6">
                                <dl class="space-y-3 text-sm">
                                    <div class="flex justify-between font-bold text-slate-600">
                                        <dt>{{ showsTax ? 'المجموع (قبل الخصم والضريبة)' : 'المجموع (قبل الخصم)' }}</dt>
                                        <dd dir="ltr">{{ money(quotation.subtotal) }}</dd>
                                    </div>
                                    <div v-if="quotation.discount_amount > 0" class="flex justify-between font-bold text-red-600">
                                        <dt>الخصم</dt>
                                        <dd dir="ltr">-{{ money(quotation.discount_amount) }}</dd>
                                    </div>
                                    <div v-if="showsTax" class="flex justify-between font-bold text-slate-600">
                                        <dt>الضريبة</dt>
                                        <dd dir="ltr">{{ money(quotation.tax_amount) }}</dd>
                                    </div>

                                    <div class="my-4 border-t border-slate-200"></div>

                                    <div class="flex items-center justify-between text-lg">
                                        <dt class="font-black text-slate-900">الإجمالي النهائي</dt>
                                        <dd class="font-black text-emerald-700" dir="ltr">{{ money(quotation.total) }}</dd>
                                    </div>
                                </dl>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Footer pattern -->
                <div class="h-4 w-full bg-emerald-900/5 print:hidden"></div>
            </div>
        </div>

        <Teleport to="body">
            <div
                v-if="invoicing"
                class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4 print:hidden"
                @click.self="invoicing = false"
            >
                <div class="w-full max-w-md rounded-2xl bg-white shadow-xl">
                    <div class="flex items-center justify-between border-b border-slate-200 px-5 py-3">
                        <h2 class="text-base font-bold text-slate-800">
                            إنشاء فاتورة من عرض السعر <span dir="ltr">{{ quotation.number }}</span>
                        </h2>
                        <button type="button" @click="invoicing = false" class="rounded-lg p-1.5 text-slate-500 hover:bg-slate-100">
                            <X class="h-4 w-4" />
                        </button>
                    </div>

                    <form @submit.prevent="submitInvoice" class="space-y-4 p-5">
                        <dl class="space-y-1.5 rounded-xl bg-slate-50 p-3 text-sm">
                            <div class="flex justify-between">
                                <dt class="text-slate-500">العميل</dt>
                                <dd class="font-bold text-slate-800">{{ quotation.client ?? '—' }}</dd>
                            </div>
                            <div class="flex justify-between">
                                <dt class="text-slate-500">عدد الأصناف</dt>
                                <dd class="font-bold text-slate-800">{{ items.length }}</dd>
                            </div>
                            <div class="flex justify-between">
                                <dt class="text-slate-500">إجمالي العرض</dt>
                                <dd class="font-bold text-slate-800" dir="ltr">{{ money(quotation.total) }}</dd>
                            </div>
                        </dl>

                        <div>
                            <label class="mb-1.5 block text-sm font-bold text-slate-700">طريقة الدفع</label>
                            <div class="flex flex-wrap gap-1.5">
                                <button
                                    v-for="m in methods"
                                    :key="m.id"
                                    type="button"
                                    @click="paymentMethodId = m.id"
                                    class="flex-1 rounded-lg py-2 text-xs font-extrabold transition"
                                    :class="
                                        paymentMethodId === m.id
                                            ? 'bg-emerald-700 text-white shadow'
                                            : 'border-2 border-slate-300 bg-white text-slate-800 hover:bg-slate-100'
                                    "
                                >
                                    {{ m.label }}
                                </button>
                            </div>
                        </div>

                        <div>
                            <label class="mb-1.5 block text-sm font-bold text-slate-700">المقبوض عند الإصدار</label>
                            <input
                                v-model.number="paidAmount"
                                type="number"
                                min="0"
                                :max="quotation.total"
                                step="0.01"
                                :placeholder="selectedMethod?.is_credit ? '0.00 — آجل بلا سداد' : money(quotation.total)"
                                class="w-full rounded-xl border-2 border-slate-300 px-3 py-2.5 text-sm font-bold focus:border-emerald-700 focus:outline-none"
                            />
                            <p class="mt-1 text-[11px] text-slate-500">اتركه فارغًا ليأخذ تلقائيّ طريقة الدفع.</p>
                        </div>

                        <div class="flex items-center justify-end gap-2 border-t border-slate-200 pt-3">
                            <button
                                type="button"
                                @click="invoicing = false"
                                class="rounded-lg border-2 border-slate-300 px-4 py-2 text-sm font-bold text-slate-700 hover:bg-slate-100"
                            >
                                إلغاء
                            </button>
                            <button
                                type="submit"
                                :disabled="submitting || !paymentMethodId"
                                class="inline-flex items-center gap-2 rounded-lg bg-indigo-600 px-5 py-2 text-sm font-extrabold text-white hover:bg-indigo-700 disabled:cursor-not-allowed disabled:bg-slate-400"
                            >
                                <ReceiptText class="h-4 w-4" /> {{ submitting ? 'جارٍ الإصدار…' : 'إصدار الفاتورة' }}
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </Teleport>
    </AppLayout>
</template>

<style>
@media print {
    body {
        background-color: white !important;
    }
    #app-sidebar,
    #app-header {
        display: none !important;
    }
}
</style>
