<script setup lang="ts">
import { StatPill } from '@/components/data-table';
import { usePermissions } from '@/composables/usePermissions';
import { useVat } from '@/composables/useVat';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem, type PaymentMethodOption } from '@/types';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { Eye, Printer, Receipt, RotateCcw, Search, Wallet, X } from 'lucide-vue-next';
import { computed, onMounted, ref } from 'vue';

interface SaleRow {
    id: number;
    number: string;
    type: string;
    type_label: string;
    date: string;
    time: string;
    client: string | null;
    client_mobile: string | null;
    department: string | null;
    cashier: string | null;
    payment_method_id: number;
    method_label: string;
    amount_before_tax: number;
    tax_amount: number;
    /** Was the invoice issued with tax? Its answer outranks its items' rates. */
    is_taxable: boolean;
    total: number;
    returned: number;
    net_total: number;
    paid: number;
    remaining: number;
    payment_status: string;
    payment_status_label: string;
    can_settle: boolean;
    can_refund: boolean;
}

interface SaleLine {
    id: number;
    item_id: number;
    name: string;
    code: string | null;
    quantity: number;
    returned_quantity: number;
    returnable_quantity: number;
    unit_price: number;
    discount_amount: number;
    tax_amount: number;
    total: number;
}

interface SaleDetails {
    sale: SaleRow & {
        notes: string | null;
        subtotal: number;
        discount_amount: number;
        original: { id: number; number: string } | null;
    };
    lines: SaleLine[];
    returns: { id: number; number: string; date: string; total: number; notes: string | null }[];
    vouchers: {
        id: number;
        number: string;
        date: string;
        amount: number;
        treasury: string | null;
        method_label: string;
        status: string;
        status_label: string;
        description: string | null;
    }[];
    /** هوية المنشأة على الفاتورة المطبوعة — `qr` فارغ بلا رقم ضريبي. */
    issuer: {
        business_name: string;
        logo_url: string | null;
        address: string | null;
        phone: string | null;
        email: string | null;
        tax_number: string | null;
        commercial_register: string | null;
        activity: string | null;
        qr: string | null;
    };
}

const props = defineProps<{
    sales: { data: SaleRow[]; links: { url: string | null; label: string; active: boolean }[] };
    stats: {
        count: number;
        sales_total: number;
        tax_total: number;
        returns_total: number;
        collected: number;
        remaining: number;
        partial_count: number;
        unpaid_count: number;
    };
    filters: Record<string, string | null>;
    departments: { id: number; name: string; code: string | null }[];
    methods: PaymentMethodOption[];
    paymentStatuses: { key: string; label: string }[];
    treasuries: { id: number; name: string; type_label: string; balance: number }[];
    voucherMethods: PaymentMethodOption[];
    /** فاتورة تُفتح نافذتها فور دخول الشاشة — يأتي بها التحويل من عرض السعر. */
    openSale: SaleRow | null;
}>();

const { can } = usePermissions();

// عمود الضريبة يبقى ما دام في المعروض ضريبةٌ حُصّلت، ويختفي حين تُطفأ ولا
// يبقى منها شيء: الفاتورة الصادرة تُقرأ بما حُرِّرت به.
const { shows } = useVat();

const showsTax = computed(() => shows(props.stats.tax_total));

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'لوحة التحكم', href: '/admin' },
    { title: 'سجل الفواتير', href: '/admin/sales' },
];

const money = (n: number) => new Intl.NumberFormat('ar-SA-u-nu-latn', { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(n ?? 0);
const qty = (n: number) => new Intl.NumberFormat('ar-SA-u-nu-latn', { maximumFractionDigits: 3 }).format(n ?? 0);

// ── التصفية ─────────────────────────────────────────────────
const filters = ref({ ...props.filters });

const apply = () => router.get('/admin/sales', filters.value, { preserveState: true, preserveScroll: true, replace: true });

const reset = () => {
    filters.value = {
        department_id: filters.value.department_id,
        type: null,
        payment_status: null,
        payment_method_id: null,
        from: null,
        to: null,
        search: null,
    };
    apply();
};

const departmentTitle = computed(() => props.departments.find((d) => String(d.id) === String(filters.value.department_id))?.name ?? 'كل الأقسام');

const statusClass = (s: string) =>
    ({
        paid: 'bg-emerald-100 text-emerald-700',
        partial: 'bg-amber-100 text-amber-700',
        unpaid: 'bg-red-100 text-red-700',
    })[s] ?? 'bg-slate-100 text-slate-700';

// ── نافذة التفاصيل ──────────────────────────────────────────
const details = ref<SaleDetails | null>(null);
const detailsLoading = ref(false);

/**
 * Does the open invoice show tax?
 *
 * An invoice issued without tax has no tax row and no tax column, even with
 * the switch on today: the sheet is read by its own answer, not by the
 * business's setting now. And tax that was actually collected stays visible
 * after the switch is turned off — which is what `shows` says.
 */
const detailsShowsTax = computed(() => (details.value?.sale.is_taxable ?? true) && shows(details.value?.sale.tax_amount ?? 0));

const activeSale = ref<SaleRow | null>(null);

/** التفاصيل تُجلب عند الفتح فقط — تحميلها لكل صف يثقل القائمة بلا داعٍ. */
const loadDetails = async (sale: SaleRow) => {
    detailsLoading.value = true;
    try {
        const res = await fetch(`/admin/sales/${sale.id}`, { headers: { Accept: 'application/json' } });
        details.value = (await res.json()) as SaleDetails;
    } finally {
        detailsLoading.value = false;
    }
};

/**
 * إصدار الفاتورة من عرض السعر يُنزل المستخدم هنا مشيرًا إلى فاتورته، فتُفتح
 * نافذتها من فورها: هي حصيلة الضغطة، لا صفٌّ يُبحث عنه في القائمة.
 *
 * الصف يأتي من الخادم لا من `sales.data`، فالفاتورة قد تقع خارج الصفحة الأولى.
 */
onMounted(() => {
    if (props.openSale) openDetails(props.openSale);
});

const openDetails = (sale: SaleRow) => {
    activeSale.value = sale;
    details.value = null;
    loadDetails(sale);
};

const closeDetails = () => {
    activeSale.value = null;
    details.value = null;
};

/** الطباعة تخرج ورقة الفاتورة وحدها — قواعد @media print أسفل الملف تخفي ما عداها. */
const printInvoice = () => window.print();

// ── سند القبض ───────────────────────────────────────────────
const settleSale = ref<SaleRow | null>(null);

const settleForm = useForm({
    amount: 0,
    treasury_id: null as number | null,
    payment_method_id: props.voucherMethods[0]?.id ?? (null as number | null),
    voucher_date: new Date().toISOString().slice(0, 10),
    description: '',
});

const openSettle = (sale: SaleRow) => {
    settleForm.reset();
    settleForm.clearErrors();
    settleForm.amount = sale.remaining;
    settleForm.treasury_id = props.treasuries[0]?.id ?? null;
    settleSale.value = sale;
};

const submitSettle = () => {
    if (!settleSale.value) return;
    settleForm.post(`/admin/sales/${settleSale.value.id}/settle`, {
        preserveScroll: true,
        onSuccess: () => {
            settleSale.value = null;
            if (activeSale.value) loadDetails(activeSale.value);
        },
    });
};

// ── المرتجع ─────────────────────────────────────────────────
const refundSale = ref<SaleRow | null>(null);
const refundLines = ref<SaleLine[]>([]);
const refundLoading = ref(false);

const refundForm = useForm({
    quantities: {} as Record<number, number>,
    reason: '',
});

const openRefund = async (sale: SaleRow) => {
    refundForm.reset();
    refundForm.clearErrors();
    refundSale.value = sale;
    refundLines.value = [];
    refundLoading.value = true;

    try {
        const res = await fetch(`/admin/sales/${sale.id}`, { headers: { Accept: 'application/json' } });
        const data = (await res.json()) as SaleDetails;
        // ما استُنفد إرجاعه لا يُعرض — سطر بلا كمية متاحة لا يُرتجع.
        refundLines.value = data.lines.filter((l) => l.returnable_quantity > 0);
        refundForm.quantities = Object.fromEntries(refundLines.value.map((l) => [l.item_id, 0]));
    } finally {
        refundLoading.value = false;
    }
};

const refundTotal = computed(() => refundLines.value.reduce((sum, l) => sum + (refundForm.quantities[l.item_id] ?? 0) * l.unit_price, 0));

const fillRefund = () => {
    refundLines.value.forEach((l) => (refundForm.quantities[l.item_id] = l.returnable_quantity));
};

const submitRefund = () => {
    if (!refundSale.value) return;
    refundForm.post(`/admin/sales/${refundSale.value.id}/refund`, {
        preserveScroll: true,
        onSuccess: () => {
            refundSale.value = null;
            if (activeSale.value) loadDetails(activeSale.value);
        },
    });
};
</script>

<template>
    <Head title="سجل الفواتير" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="min-h-full space-y-4 bg-slate-100 p-5">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h1 class="text-2xl font-extrabold text-slate-900">سجل الفواتير — {{ departmentTitle }}</h1>
                    <p class="mt-1 text-sm font-medium text-slate-600">فواتير القسم ومرتجعاتها وسندات قبضها — والمتبقي على كل فاتورة مسدّدة جزئيًا</p>
                </div>
                <Link
                    v-if="can('pos.create')"
                    href="/admin/pos"
                    class="inline-flex items-center gap-1.5 rounded-md bg-blue-600 px-4 py-2 text-sm font-bold text-white hover:bg-blue-700"
                >
                    <Receipt class="h-4 w-4" /> فاتورة جديدة
                </Link>
            </div>

            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-6">
                <StatPill label="عدد الفواتير" :value="String(stats.count)" />
                <StatPill label="إجمالي المبيعات" :value="money(stats.sales_total)" variant="success" />
                <StatPill v-if="showsTax" label="الضريبة" :value="money(stats.tax_total)" variant="info" />
                <StatPill label="المرتجعات" :value="money(stats.returns_total)" :variant="stats.returns_total > 0 ? 'danger' : undefined" />
                <StatPill label="المحصّل" :value="money(stats.collected)" variant="success" />
                <StatPill label="المتبقي" :value="money(stats.remaining)" :variant="stats.remaining > 0 ? 'danger' : 'success'" />
            </div>

            <div class="rounded-2xl border border-slate-200 bg-white p-3 shadow-sm">
                <div class="grid gap-2 sm:grid-cols-2 lg:grid-cols-4">
                    <select v-model="filters.department_id" @change="apply" class="rounded-xl border border-slate-200 px-3 py-2.5 text-sm">
                        <option v-for="d in departments" :key="d.id" :value="String(d.id)">{{ d.name }}</option>
                        <option value="all">كل الأقسام</option>
                    </select>

                    <select v-model="filters.payment_status" @change="apply" class="rounded-xl border border-slate-200 px-3 py-2.5 text-sm">
                        <option :value="null">كل حالات السداد</option>
                        <option v-for="s in paymentStatuses" :key="s.key" :value="s.key">{{ s.label }}</option>
                    </select>

                    <select v-model="filters.type" @change="apply" class="rounded-xl border border-slate-200 px-3 py-2.5 text-sm">
                        <option :value="null">الفواتير والمرتجعات</option>
                        <option value="sale">الفواتير فقط</option>
                        <option value="return">المرتجعات فقط</option>
                    </select>

                    <select v-model="filters.payment_method_id" @change="apply" class="rounded-xl border border-slate-200 px-3 py-2.5 text-sm">
                        <option :value="null">كل طرق الدفع</option>
                        <option v-for="m in methods" :key="m.id" :value="m.id">{{ m.label }}</option>
                    </select>

                    <input v-model="filters.from" @change="apply" type="date" class="rounded-xl border border-slate-200 px-3 py-2.5 text-sm" />
                    <input v-model="filters.to" @change="apply" type="date" class="rounded-xl border border-slate-200 px-3 py-2.5 text-sm" />

                    <div class="relative sm:col-span-2">
                        <Search class="absolute right-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" />
                        <input
                            v-model="filters.search"
                            @keyup.enter="apply"
                            type="search"
                            placeholder="رقم الفاتورة أو اسم العميل أو جواله"
                            class="w-full rounded-xl border border-slate-200 py-2.5 pl-3 pr-9 text-sm"
                        />
                    </div>
                </div>

                <div class="mt-2 flex flex-wrap items-center gap-2">
                    <button type="button" @click="apply" class="rounded-lg bg-slate-800 px-4 py-1.5 text-xs font-bold text-white hover:bg-slate-900">
                        تطبيق
                    </button>
                    <button
                        type="button"
                        @click="reset"
                        class="rounded-lg border border-slate-200 px-4 py-1.5 text-xs font-bold text-slate-600 hover:bg-slate-50"
                    >
                        مسح التصفية
                    </button>
                    <span class="text-xs font-bold text-amber-700">مسدّدة جزئيًا: {{ stats.partial_count }}</span>
                    <span class="text-xs font-bold text-red-700">غير مسدّدة: {{ stats.unpaid_count }}</span>
                </div>
            </div>

            <div class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="bg-slate-100">
                            <tr>
                                <th class="px-4 py-3 text-right text-xs font-extrabold text-[#1e3a8a]">الفاتورة</th>
                                <th class="px-4 py-3 text-right text-xs font-extrabold text-[#1e3a8a]">العميل</th>
                                <th class="px-4 py-3 text-center text-xs font-extrabold text-[#1e3a8a]">الدفع</th>
                                <th class="px-4 py-3 text-left text-xs font-extrabold text-[#1e3a8a]">السعر</th>
                                <th v-if="showsTax" class="px-4 py-3 text-left text-xs font-extrabold text-[#1e3a8a]">الضريبة</th>
                                <th class="px-4 py-3 text-left text-xs font-extrabold text-[#1e3a8a]">الإجمالي</th>
                                <th class="px-4 py-3 text-left text-xs font-extrabold text-[#1e3a8a]">المرتجع</th>
                                <th class="px-4 py-3 text-left text-xs font-extrabold text-[#1e3a8a]">المسدّد</th>
                                <th class="px-4 py-3 text-left text-xs font-extrabold text-[#1e3a8a]">المتبقي</th>
                                <th class="px-4 py-3 text-center text-xs font-extrabold text-[#1e3a8a]">الحالة</th>
                                <th class="px-4 py-3 text-center text-xs font-extrabold text-[#1e3a8a]">إجراءات</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="s in sales.data" :key="s.id" class="border-t border-slate-100 hover:bg-slate-50">
                                <td class="px-4 py-3">
                                    <div class="flex items-center gap-1.5">
                                        <span class="font-extrabold text-slate-800" dir="ltr">{{ s.number }}</span>
                                        <span v-if="s.type === 'return'" class="rounded bg-red-100 px-1.5 py-0.5 text-[10px] font-bold text-red-700"
                                            >مرتجع</span
                                        >
                                    </div>
                                    <!-- dir="ltr" would left-align this block away from the number above it. -->
                                    <div class="text-right text-[11px] text-slate-500" dir="ltr">{{ s.date }} · {{ s.time }}</div>
                                </td>
                                <td class="px-4 py-3">
                                    <div class="text-xs font-bold text-slate-700">{{ s.client ?? 'عميل نقدي' }}</div>
                                    <div class="text-[11px] text-slate-500">{{ s.department ?? '—' }}</div>
                                </td>
                                <td class="px-4 py-3 text-center text-[11px] font-bold text-slate-600">{{ s.method_label }}</td>
                                <td class="px-4 py-3 text-left text-xs font-bold text-slate-700" dir="ltr">{{ money(s.amount_before_tax) }}</td>
                                <td
                                    v-if="showsTax"
                                    class="px-4 py-3 text-left text-xs font-bold"
                                    :class="s.tax_amount > 0 ? 'text-slate-600' : 'text-slate-400'"
                                    dir="ltr"
                                >
                                    {{ s.tax_amount > 0 ? money(s.tax_amount) : '—' }}
                                </td>
                                <td class="px-4 py-3 text-left font-extrabold text-slate-800" dir="ltr">{{ money(s.total) }}</td>
                                <td
                                    class="px-4 py-3 text-left text-xs font-bold"
                                    :class="s.returned > 0 ? 'text-red-600' : 'text-slate-400'"
                                    dir="ltr"
                                >
                                    {{ s.returned > 0 ? money(s.returned) : '—' }}
                                </td>
                                <td class="px-4 py-3 text-left text-xs font-bold text-emerald-700" dir="ltr">{{ money(s.paid) }}</td>
                                <td
                                    class="px-4 py-3 text-left text-xs font-extrabold"
                                    :class="s.remaining > 0 ? 'text-red-600' : 'text-slate-400'"
                                    dir="ltr"
                                >
                                    {{ s.remaining > 0 ? money(s.remaining) : '—' }}
                                </td>
                                <td class="px-4 py-3 text-center">
                                    <span
                                        v-if="s.type === 'sale'"
                                        class="rounded-md px-2 py-0.5 text-[11px] font-bold"
                                        :class="statusClass(s.payment_status)"
                                    >
                                        {{ s.payment_status_label }}
                                    </span>
                                    <span v-else class="text-[11px] text-slate-400">—</span>
                                </td>
                                <td class="px-4 py-3">
                                    <div class="flex items-center justify-center gap-1">
                                        <button
                                            type="button"
                                            @click="openDetails(s)"
                                            title="تفاصيل الفاتورة"
                                            class="rounded-lg bg-slate-500 p-1.5 text-white hover:bg-slate-600"
                                        >
                                            <Eye class="h-3.5 w-3.5" />
                                        </button>
                                        <button
                                            v-if="can('sales.create') && s.can_settle"
                                            type="button"
                                            @click="openSettle(s)"
                                            title="سند قبض"
                                            class="rounded-lg bg-emerald-500 p-1.5 text-white hover:bg-emerald-600"
                                        >
                                            <Wallet class="h-3.5 w-3.5" />
                                        </button>
                                        <button
                                            v-if="can('sales.create') && s.can_refund"
                                            type="button"
                                            @click="openRefund(s)"
                                            title="مرتجع"
                                            class="rounded-lg bg-amber-500 p-1.5 text-white hover:bg-amber-600"
                                        >
                                            <RotateCcw class="h-3.5 w-3.5" />
                                        </button>
                                    </div>
                                </td>
                            </tr>
                            <tr v-if="!sales.data.length">
                                <td :colspan="showsTax ? 11 : 10" class="px-4 py-10 text-center text-sm text-slate-500">لا فواتير مطابقة للتصفية</td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div v-if="sales.links.length > 3" class="flex flex-wrap justify-center gap-1 border-t border-slate-100 p-3">
                    <Link
                        v-for="(l, li) in sales.links"
                        :key="`${li}-${l.label}`"
                        :href="l.url ?? '#'"
                        :class="[
                            'rounded-lg px-3 py-1.5 text-xs font-bold',
                            l.active ? 'bg-blue-600 text-white' : l.url ? 'bg-white text-slate-600 ring-1 ring-slate-200' : 'text-slate-300',
                        ]"
                        v-html="l.label"
                    />
                </div>
            </div>
        </div>

        <!--
            معاينة الفاتورة — مستند قابل للطباعة كما هو.
            تُنقل إلى <body> مباشرةً (Teleport) حتى تصير شقيقة لجذر التطبيق،
            فتكفي قاعدة طباعة واحدة لإخفاء ما عداها بدل حيَل الرؤية.
        -->
        <Teleport to="body">
            <div
                v-if="activeSale"
                class="invoice-overlay fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-black/40 p-4"
                @click.self="closeDetails"
            >
                <div class="invoice-sheet my-auto w-full max-w-3xl rounded-2xl bg-white shadow-2xl">
                    <div class="flex items-center justify-between gap-2 border-b border-slate-100 px-6 py-3 print:hidden">
                        <div class="flex gap-2">
                            <button
                                type="button"
                                @click="printInvoice"
                                class="inline-flex items-center gap-1.5 rounded-md bg-blue-600 px-4 py-2 text-sm font-bold text-white hover:bg-blue-700"
                            >
                                <Printer class="h-4 w-4" /> طباعة
                            </button>
                            <button
                                v-if="can('sales.create') && activeSale.can_settle"
                                type="button"
                                @click="openSettle(activeSale)"
                                class="rounded-md bg-emerald-600 px-4 py-2 text-sm font-bold text-white hover:bg-emerald-700"
                            >
                                سند قبض
                            </button>
                            <button
                                v-if="can('sales.create') && activeSale.can_refund"
                                type="button"
                                @click="openRefund(activeSale)"
                                class="rounded-md bg-amber-500 px-4 py-2 text-sm font-bold text-white hover:bg-amber-600"
                            >
                                مرتجع
                            </button>
                        </div>
                        <button type="button" @click="closeDetails" class="text-slate-400 hover:text-slate-600"><X class="h-5 w-5" /></button>
                    </div>

                    <p v-if="detailsLoading" class="py-16 text-center text-sm text-slate-500">جارٍ التحميل…</p>

                    <!-- ورقة الفاتورة -->
                    <div v-else-if="details" class="invoice-body space-y-5 px-8 py-6">
                        <!-- الترويسة: الشعار وهوية النشاط يمينًا، والعميل وبيانات الفاتورة يسارًا -->
                        <header class="invoice-head grid gap-6 border-b-2 border-slate-800 pb-4 sm:grid-cols-2">
                            <div>
                                <img v-if="details.issuer.logo_url" :src="details.issuer.logo_url" alt="" class="mb-3 h-16 w-auto object-contain" />
                                <h2 class="text-xl font-extrabold text-slate-900">{{ details.issuer.business_name }}</h2>
                                <p v-if="details.issuer.activity" class="text-sm font-bold text-slate-600">{{ details.issuer.activity }}</p>
                                <div class="mt-1 space-y-0.5 text-[11px] font-medium text-slate-500">
                                    <p v-if="details.issuer.address">{{ details.issuer.address }}</p>
                                    <p v-if="details.issuer.phone">
                                        هاتف: <span dir="ltr">{{ details.issuer.phone }}</span>
                                    </p>
                                    <p v-if="details.issuer.tax_number">
                                        الرقم الضريبي: <span dir="ltr">{{ details.issuer.tax_number }}</span>
                                    </p>
                                    <p v-if="details.issuer.commercial_register">
                                        س.ت: <span dir="ltr">{{ details.issuer.commercial_register }}</span>
                                    </p>
                                </div>
                            </div>

                            <div class="text-left">
                                <div class="mb-2 inline-block rounded-lg bg-slate-800 px-3 py-1 text-sm font-extrabold text-white">
                                    {{
                                        details.sale.type === 'return'
                                            ? 'إشعار دائن (مرتجع)'
                                            : detailsShowsTax
                                              ? 'فاتورة ضريبية مبسّطة'
                                              : 'فاتورة مبيعات'
                                    }}
                                </div>
                                <dl class="space-y-1 text-xs">
                                    <div class="flex justify-between gap-3">
                                        <dt class="font-bold text-slate-500">رقم الفاتورة</dt>
                                        <dd class="font-extrabold text-slate-800" dir="ltr">{{ details.sale.number }}</dd>
                                    </div>
                                    <div class="flex justify-between gap-3">
                                        <dt class="font-bold text-slate-500">التاريخ</dt>
                                        <dd class="font-bold text-slate-700" dir="ltr">{{ details.sale.date }} {{ details.sale.time }}</dd>
                                    </div>
                                    <div class="flex justify-between gap-3">
                                        <dt class="font-bold text-slate-500">العميل</dt>
                                        <dd class="font-extrabold text-slate-800">{{ details.sale.client ?? 'عميل نقدي' }}</dd>
                                    </div>
                                    <div v-if="details.sale.client_mobile" class="flex justify-between gap-3">
                                        <dt class="font-bold text-slate-500">الجوال</dt>
                                        <dd class="font-bold text-slate-700" dir="ltr">{{ details.sale.client_mobile }}</dd>
                                    </div>
                                    <div class="flex justify-between gap-3">
                                        <dt class="font-bold text-slate-500">طريقة الدفع</dt>
                                        <dd class="font-bold text-slate-700">{{ details.sale.method_label }}</dd>
                                    </div>
                                    <div v-if="details.sale.original" class="flex justify-between gap-3">
                                        <dt class="font-bold text-slate-500">مرتجع من</dt>
                                        <dd class="font-bold text-slate-700" dir="ltr">{{ details.sale.original.number }}</dd>
                                    </div>
                                </dl>
                            </div>
                        </header>

                        <!-- التوزيع -->
                        <table class="w-full border border-slate-300 text-xs">
                            <thead class="bg-slate-100">
                                <tr>
                                    <th class="border border-slate-300 px-2 py-2 text-center font-extrabold text-[#1e3a8a]">#</th>
                                    <th class="border border-slate-300 px-2 py-2 text-right font-extrabold text-[#1e3a8a]">الصنف</th>
                                    <th class="border border-slate-300 px-2 py-2 text-center font-extrabold text-[#1e3a8a]">الكمية</th>
                                    <th class="border border-slate-300 px-2 py-2 text-center font-extrabold text-[#1e3a8a]">السعر</th>
                                    <th class="border border-slate-300 px-2 py-2 text-center font-extrabold text-[#1e3a8a]">الخصم</th>
                                    <th v-if="detailsShowsTax" class="border border-slate-300 px-2 py-2 text-center font-extrabold text-[#1e3a8a]">
                                        الضريبة
                                    </th>
                                    <th class="border border-slate-300 px-2 py-2 text-center font-extrabold text-[#1e3a8a]">الإجمالي</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="(l, i) in details.lines" :key="l.id">
                                    <td class="border border-slate-300 px-2 py-1.5 text-center text-slate-500" dir="ltr">{{ i + 1 }}</td>
                                    <td class="border border-slate-300 px-2 py-1.5 font-bold text-slate-800">
                                        {{ l.name }}
                                        <span v-if="l.returned_quantity > 0" class="text-[10px] font-bold text-red-600">
                                            (مرتجع منه {{ qty(l.returned_quantity) }})
                                        </span>
                                    </td>
                                    <td class="border border-slate-300 px-2 py-1.5 text-center" dir="ltr">{{ qty(l.quantity) }}</td>
                                    <td class="border border-slate-300 px-2 py-1.5 text-center" dir="ltr">{{ money(l.unit_price) }}</td>
                                    <td class="border border-slate-300 px-2 py-1.5 text-center" dir="ltr">{{ money(l.discount_amount) }}</td>
                                    <td v-if="detailsShowsTax" class="border border-slate-300 px-2 py-1.5 text-center" dir="ltr">
                                        {{ money(l.tax_amount) }}
                                    </td>
                                    <td class="border border-slate-300 px-2 py-1.5 text-center font-extrabold" dir="ltr">{{ money(l.total) }}</td>
                                </tr>
                            </tbody>
                        </table>

                        <!-- الإجماليات ورمز الاستجابة السريعة -->
                        <div class="invoice-totals grid items-start gap-6 sm:grid-cols-2">
                            <div class="order-2 sm:order-1">
                                <figure v-if="details.issuer.qr" class="text-center sm:text-right">
                                    <img :src="details.issuer.qr" alt="رمز الفاتورة" class="h-28 w-28" />
                                    <figcaption class="mt-1 w-28 text-center text-[10px] font-bold text-slate-500">رمز الفاتورة الضريبية</figcaption>
                                </figure>
                            </div>

                            <dl class="order-1 space-y-1 text-xs sm:order-2">
                                <div class="flex justify-between border-b border-slate-100 py-1">
                                    <dt class="font-bold text-slate-600">
                                        {{ detailsShowsTax ? 'الإجمالي قبل الضريبة' : 'الإجمالي' }}
                                    </dt>
                                    <dd class="font-bold text-slate-800" dir="ltr">{{ money(details.sale.subtotal) }}</dd>
                                </div>
                                <div v-if="details.sale.discount_amount > 0" class="flex justify-between border-b border-slate-100 py-1">
                                    <dt class="font-bold text-slate-600">الخصم</dt>
                                    <dd class="font-bold text-slate-800" dir="ltr">{{ money(details.sale.discount_amount) }}</dd>
                                </div>
                                <div v-if="detailsShowsTax" class="flex justify-between border-b border-slate-100 py-1">
                                    <dt class="font-bold text-slate-600">ضريبة القيمة المضافة</dt>
                                    <dd class="font-bold text-slate-800" dir="ltr">{{ money(details.sale.tax_amount) }}</dd>
                                </div>
                                <div class="flex justify-between bg-slate-800 px-2 py-1.5 text-sm text-white">
                                    <dt class="font-extrabold">{{ detailsShowsTax ? 'الإجمالي شامل الضريبة' : 'الإجمالي' }}</dt>
                                    <dd class="font-extrabold" dir="ltr">{{ money(details.sale.total) }}</dd>
                                </div>
                                <div v-if="details.sale.returned > 0" class="flex justify-between py-1">
                                    <dt class="font-bold text-red-600">المرتجع</dt>
                                    <dd class="font-bold text-red-600" dir="ltr">{{ money(details.sale.returned) }}</dd>
                                </div>
                                <div v-if="details.sale.returned > 0" class="flex justify-between border-b border-slate-100 py-1">
                                    <dt class="font-bold text-slate-600">الصافي المستحق</dt>
                                    <dd class="font-extrabold text-slate-800" dir="ltr">{{ money(details.sale.net_total) }}</dd>
                                </div>
                                <div class="flex justify-between py-1">
                                    <dt class="font-bold text-slate-600">المسدّد</dt>
                                    <dd class="font-bold text-emerald-700" dir="ltr">{{ money(details.sale.paid) }}</dd>
                                </div>
                                <div class="flex justify-between py-1">
                                    <dt class="font-bold text-slate-600">المتبقي</dt>
                                    <dd class="font-extrabold" :class="details.sale.remaining > 0 ? 'text-red-600' : 'text-slate-500'" dir="ltr">
                                        {{ money(details.sale.remaining) }}
                                    </dd>
                                </div>
                            </dl>
                        </div>

                        <p v-if="details.sale.notes" class="rounded-lg bg-slate-50 px-3 py-2 text-xs text-slate-600 print:bg-transparent print:px-0">
                            ملاحظات: {{ details.sale.notes }}
                        </p>

                        <!-- المرتجعات والسندات — سجل الفاتورة بعد إصدارها -->
                        <div
                            v-if="details.returns.length || details.vouchers.length"
                            class="grid gap-4 border-t border-slate-200 pt-4 sm:grid-cols-2"
                        >
                            <div v-if="details.returns.length">
                                <h3 class="mb-1.5 text-xs font-extrabold text-slate-800">المرتجعات</h3>
                                <ul class="space-y-1">
                                    <li
                                        v-for="r in details.returns"
                                        :key="r.id"
                                        class="flex items-center justify-between gap-2 border-b border-slate-100 py-1 text-[11px]"
                                    >
                                        <span class="font-bold text-red-700" dir="ltr">{{ r.number }}</span>
                                        <span class="text-slate-500" dir="ltr">{{ r.date }}</span>
                                        <span class="font-extrabold text-red-700" dir="ltr">{{ money(r.total) }}</span>
                                    </li>
                                </ul>
                            </div>

                            <div v-if="details.vouchers.length">
                                <h3 class="mb-1.5 text-xs font-extrabold text-slate-800">سندات القبض</h3>
                                <ul class="space-y-1">
                                    <li
                                        v-for="v in details.vouchers"
                                        :key="v.id"
                                        class="flex items-center justify-between gap-2 border-b border-slate-100 py-1 text-[11px]"
                                    >
                                        <span class="font-bold text-emerald-700" dir="ltr">{{ v.number }}</span>
                                        <span class="text-slate-500" dir="ltr">{{ v.date }}</span>
                                        <span class="text-slate-500">{{ v.status_label }}</span>
                                        <span
                                            class="font-extrabold"
                                            :class="v.status === 'cancelled' ? 'text-slate-400 line-through' : 'text-emerald-700'"
                                            dir="ltr"
                                        >
                                            {{ money(v.amount) }}
                                        </span>
                                    </li>
                                </ul>
                            </div>
                        </div>

                        <p class="pt-2 text-center text-[10px] font-bold text-slate-400">
                            {{ details.issuer.business_name }} — أصدرها {{ details.sale.cashier ?? '—' }}
                        </p>
                    </div>
                </div>
            </div>
        </Teleport>

        <!-- سند قبض على الفاتورة — منقول إلى <body> ليعلو معاينة الفاتورة المنقولة قبله -->
        <Teleport to="body">
            <div v-if="settleSale" class="fixed inset-0 z-[60] flex items-center justify-center bg-black/40 p-4" @click.self="settleSale = null">
                <div class="w-full max-w-md rounded-2xl bg-white shadow-2xl">
                    <div class="flex items-center justify-between border-b border-slate-100 px-6 py-4">
                        <div>
                            <h2 class="text-lg font-extrabold text-slate-900">سند قبض</h2>
                            <p class="text-xs font-medium text-slate-500">
                                الفاتورة <span dir="ltr">{{ settleSale.number }}</span> — المتبقي
                                <span class="font-extrabold text-red-600" dir="ltr">{{ money(settleSale.remaining) }}</span>
                            </p>
                        </div>
                        <button type="button" @click="settleSale = null" class="text-slate-400 hover:text-slate-600"><X class="h-5 w-5" /></button>
                    </div>

                    <form @submit.prevent="submitSettle" class="space-y-3 px-6 py-4">
                        <div>
                            <label class="mb-1 block text-sm font-bold text-slate-700">المبلغ المقبوض</label>
                            <input
                                v-model.number="settleForm.amount"
                                type="number"
                                min="0.01"
                                :max="settleSale.remaining"
                                step="0.01"
                                class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm"
                            />
                            <p v-if="settleForm.errors.amount" class="mt-1 text-xs text-red-500">{{ settleForm.errors.amount }}</p>
                        </div>

                        <div class="grid gap-3 sm:grid-cols-2">
                            <div>
                                <label class="mb-1 block text-sm font-bold text-slate-700">الخزينة</label>
                                <select v-model="settleForm.treasury_id" class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm">
                                    <option :value="null">—</option>
                                    <option v-for="t in treasuries" :key="t.id" :value="t.id">{{ t.name }}</option>
                                </select>
                                <p v-if="settleForm.errors.treasury_id" class="mt-1 text-xs text-red-500">{{ settleForm.errors.treasury_id }}</p>
                            </div>
                            <div>
                                <label class="mb-1 block text-sm font-bold text-slate-700">طريقة القبض</label>
                                <select v-model="settleForm.payment_method_id" class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm">
                                    <option v-for="m in voucherMethods" :key="m.id" :value="m.id">{{ m.label }}</option>
                                </select>
                            </div>
                        </div>

                        <div>
                            <label class="mb-1 block text-sm font-bold text-slate-700">تاريخ السند</label>
                            <input
                                v-model="settleForm.voucher_date"
                                type="date"
                                class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm"
                            />
                        </div>

                        <div>
                            <label class="mb-1 block text-sm font-bold text-slate-700">البيان</label>
                            <textarea
                                v-model="settleForm.description"
                                rows="2"
                                class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm"
                            ></textarea>
                        </div>

                        <p class="rounded-xl bg-emerald-50 px-3 py-2 text-[11px] font-medium text-emerald-800">
                            يُرحَّل السند فور الحفظ فيُقيَّد على ذمم العملاء ومركز تكلفة القسم، ويُخصم من المتبقي.
                        </p>

                        <div class="flex justify-end gap-2 border-t border-slate-100 pt-3">
                            <button
                                type="button"
                                @click="settleSale = null"
                                class="rounded-xl border border-slate-200 px-4 py-2 text-sm font-bold text-slate-600"
                            >
                                إلغاء
                            </button>
                            <button
                                type="submit"
                                :disabled="settleForm.processing"
                                class="rounded-md bg-emerald-600 px-5 py-2 text-sm font-bold text-white hover:bg-emerald-700 disabled:opacity-60"
                            >
                                حفظ وترحيل
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </Teleport>

        <!-- مرتجع من الفاتورة -->
        <Teleport to="body">
            <div v-if="refundSale" class="fixed inset-0 z-[60] flex items-center justify-center bg-black/40 p-4" @click.self="refundSale = null">
                <div class="flex max-h-[92vh] w-full max-w-xl flex-col rounded-2xl bg-white shadow-2xl">
                    <div class="flex items-center justify-between border-b border-slate-100 px-6 py-4">
                        <div>
                            <h2 class="text-lg font-extrabold text-slate-900">مرتجع</h2>
                            <p class="text-xs font-medium text-slate-500">
                                من الفاتورة <span dir="ltr">{{ refundSale.number }}</span>
                            </p>
                        </div>
                        <button type="button" @click="refundSale = null" class="text-slate-400 hover:text-slate-600"><X class="h-5 w-5" /></button>
                    </div>

                    <form @submit.prevent="submitRefund" class="flex min-h-0 flex-1 flex-col">
                        <div class="min-h-0 flex-1 space-y-3 overflow-y-auto px-6 py-4">
                            <p v-if="refundLoading" class="py-8 text-center text-sm text-slate-500">جارٍ التحميل…</p>
                            <p v-else-if="!refundLines.length" class="rounded-xl bg-slate-50 px-3 py-3 text-sm text-slate-500">
                                لا كميات متاحة للإرجاع في هذه الفاتورة.
                            </p>

                            <template v-else>
                                <div class="flex justify-end">
                                    <button
                                        type="button"
                                        @click="fillRefund"
                                        class="rounded-lg border border-slate-200 px-3 py-1.5 text-xs font-bold text-slate-600 hover:bg-slate-50"
                                    >
                                        إرجاع الكل
                                    </button>
                                </div>

                                <div class="overflow-hidden rounded-xl border border-slate-200">
                                    <table class="w-full text-xs">
                                        <thead class="bg-slate-50">
                                            <tr>
                                                <th class="px-3 py-2 text-right font-extrabold text-[#1e3a8a]">الصنف</th>
                                                <th class="px-3 py-2 text-center font-extrabold text-[#1e3a8a]">المتاح</th>
                                                <th class="px-3 py-2 text-center font-extrabold text-[#1e3a8a]">المرتجع</th>
                                                <th class="px-3 py-2 text-left font-extrabold text-[#1e3a8a]">القيمة</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <tr v-for="l in refundLines" :key="l.id" class="border-t border-slate-100">
                                                <td class="px-3 py-2 font-bold text-slate-700">{{ l.name }}</td>
                                                <td class="px-3 py-2 text-center" dir="ltr">{{ qty(l.returnable_quantity) }}</td>
                                                <td class="px-3 py-2">
                                                    <input
                                                        v-model.number="refundForm.quantities[l.item_id]"
                                                        type="number"
                                                        min="0"
                                                        :max="l.returnable_quantity"
                                                        step="0.001"
                                                        class="w-24 rounded-lg border border-slate-200 px-2 py-1 text-center text-xs"
                                                    />
                                                </td>
                                                <td class="px-3 py-2 text-left font-bold" dir="ltr">
                                                    {{ money((refundForm.quantities[l.item_id] ?? 0) * l.unit_price) }}
                                                </td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>

                                <div>
                                    <label class="mb-1 block text-sm font-bold text-slate-700">سبب الإرجاع</label>
                                    <textarea
                                        v-model="refundForm.reason"
                                        rows="2"
                                        class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm"
                                    ></textarea>
                                </div>

                                <p v-if="refundForm.errors.quantities" class="text-xs text-red-500">{{ refundForm.errors.quantities }}</p>

                                <div class="flex items-center justify-between rounded-xl bg-amber-50 px-3 py-2 text-sm">
                                    <span class="font-bold text-amber-800">{{ showsTax ? 'قيمة المرتجع قبل الضريبة' : 'قيمة المرتجع' }}</span>
                                    <span class="font-extrabold text-amber-900" dir="ltr">{{ money(refundTotal) }}</span>
                                </div>
                            </template>
                        </div>

                        <div class="flex justify-end gap-2 border-t border-slate-100 px-6 py-4">
                            <button
                                type="button"
                                @click="refundSale = null"
                                class="rounded-xl border border-slate-200 px-4 py-2 text-sm font-bold text-slate-600"
                            >
                                إلغاء
                            </button>
                            <button
                                type="submit"
                                :disabled="refundForm.processing || !refundLines.length || refundTotal <= 0"
                                class="rounded-md bg-amber-500 px-5 py-2 text-sm font-bold text-white hover:bg-amber-600 disabled:opacity-60"
                            >
                                تأكيد المرتجع
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </Teleport>
    </AppLayout>
</template>

<style>
/*
 * الطباعة تُخرج ورقة الفاتورة وحدها.
 *
 * المعاينة منقولة إلى <body> فصارت شقيقة لجذر التطبيق: يكفي إخفاء بقية
 * أبناء <body> ثم إعادة الورقة إلى تدفق الصفحة العادي، فتنسكب على عدة
 * صفحات إن طالت أصنافها بدل أن تُقصّ عند حدّ الشاشة.
 */
@media print {
    @page {
        size: A4;
        margin: 10mm;
    }

    html,
    body {
        height: auto !important;
        overflow: visible !important;
        background: #fff !important;
    }

    body:has(.invoice-overlay) > *:not(.invoice-overlay) {
        display: none !important;
    }

    .invoice-overlay {
        position: static !important;
        display: block !important;
        overflow: visible !important;
        background: transparent !important;
        padding: 0 !important;
    }

    .invoice-sheet {
        width: 100% !important;
        max-width: none !important;
        margin: 0 !important;
        border-radius: 0 !important;
        box-shadow: none !important;

        /*
         * المتصفح يُسقط ألوان الخلفيات عند الطباعة توفيرًا للحبر، فيخرج
         * شريط الإجمالي وشارة نوع الفاتورة أبيضَ على أبيض. هذه القاعدة
         * تُلزمه بطباعتها كما هي.
         */
        -webkit-print-color-adjust: exact !important;
        print-color-adjust: exact !important;
    }

    /*
     * حاشية داخل الورقة نفسها لا على @page وحده: بعض المتصفحات تطبع
     * بهوامش «بلا» حسب إعداد المستخدم، فتلتصق الترويسة بحافة الورق.
     */
    .invoice-body {
        box-sizing: border-box;
        padding: 4mm 6mm !important;
    }

    /* لا فيض أفقي يقصّ عمود العميل عند حافة الورقة. */
    .invoice-sheet,
    .invoice-body,
    .invoice-body * {
        max-width: 100%;
    }

    .invoice-body td,
    .invoice-body th {
        overflow-wrap: anywhere;
    }

    /*
     * الترويسة والإجماليات عمودان دائمًا على الورق.
     * لا يُترك ذلك لنقطة الانكسار sm: لأن عرض الطباعة المُبلَّغ يختلف بين
     * المتصفحات، فقد ينهار العمودان فوق بعضهما بلا داعٍ على ورقة A4.
     */
    .invoice-head,
    .invoice-totals {
        display: grid !important;
        grid-template-columns: 1fr 1fr !important;
        align-items: start !important;
    }

    .invoice-head > :last-child,
    .invoice-totals > :first-child {
        order: 0 !important;
    }

    /* سطر الجدول والصور لا تُقطع بين صفحتين، والترويسة تتكرّر أعلى كل صفحة. */
    .invoice-body tr,
    .invoice-body img,
    .invoice-body header {
        break-inside: avoid;
    }

    .invoice-body thead {
        display: table-header-group;
    }
}
</style>
