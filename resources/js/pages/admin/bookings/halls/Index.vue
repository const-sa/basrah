<script setup lang="ts">
import { StatPill, TableActionButton } from '@/components/data-table';
import PageShortcuts from '@/components/PageShortcuts.vue';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuSeparator, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { usePermissions } from '@/composables/usePermissions';
import { useTableColumns, type ColumnPreset, type TableColumn } from '@/composables/useTableColumns';
import AppLayout from '@/layouts/AppLayout.vue';
import { isClosedStatus, statusChipClass } from '@/lib/bookingStatus';
import { toHijri } from '@/lib/hijri';
import { type BreadcrumbItem, type PaymentMethodOption } from '@/types';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { Bell, CalendarClock, Check, Columns3, Eye, FileSignature, FileText, Loader2, LogIn, LogOut, MoreVertical, Pencil, Plus, Receipt, Search, StickyNote, Trash2, Wallet, X } from 'lucide-vue-next';
import { computed, ref } from 'vue';

interface SectionOption { id: number; name: string; gender: string }
interface UnitOption {
    id: number; name: string; code: string; type: string;
    bookable_mode: 'whole' | 'sections' | 'both';
    privacy_mode: 'open' | 'exclusive';
    sections: SectionOption[];
}
interface EventTypeOption { id: number; unit_id: number; name: string; color: string; price: number }

interface Booking {
    id: number; reference: string;
    unit: { id: number; name: string; code: string };
    client: { id: number; name: string; mobile: string | null } | null;
    event_type: { id: number; name: string; color: string } | null;
    package: { id: number; name: string } | null;
    scope: 'whole' | 'sections';
    sections: string[]; section_ids: number[];
    period: string; period_label: string;
    booking_date: string;
    days_count: number; last_day_date: string;
    status: string; status_label: string; status_color: string;
    is_online: boolean;
    total_amount: number; package_amount: number; event_fee_amount: number;
    deposit_amount: number; paid_amount: number; remaining_amount: number;
    is_deposit_settled: boolean;
    guests_count: number | null; notes: string | null;
    contract: { id: number; number: string } | null;
    has_payments: boolean;
    // أعمدة الدفتر: من مبلغ الحجز إلى المسترجع
    subtotal_amount: number; discount_amount: number; tax_amount: number;
    addons_amount: number;
    paid_by_method: Record<number, number>;
    refunded_amount: number; payment_status: string;
}

interface MethodColumn { key: number; label: string }

interface LedgerTotals {
    subtotal: number; discount: number; deposit: number; tax: number;
    total: number; paid: number; paid_by_method: Record<number, number>;
    remaining: number; refunded: number; count: number;
}

interface Payment {
    id: number; type: string; type_label: string; method_label: string;
    amount: number; signed_amount: number; paid_on: string;
    reference: string | null; notes: string | null; received_by: string | null;
}
interface PaymentSummary {
    total_amount: number; deposit_amount: number; paid_amount: number;
    remaining_amount: number; is_deposit_settled: boolean; is_fully_paid: boolean;
}

const props = defineProps<{
    bookings: { data: Booking[]; links: { url: string | null; label: string; active: boolean }[] };
    filters: Record<string, string | null>;
    units: UnitOption[];
    eventTypes: EventTypeOption[];
    meta: {
        statuses: { key: string; label: string; color: string }[];
        periods: { key: string; label: string; start: string; end: string }[];
        payment_methods: PaymentMethodOption[];
    };
    stats: { total: number; tentative: number; pending_deposit: number; confirmed: number; unpaid: number };
    methods: MethodColumn[];
    totals: { page: LedgerTotals; all: LedgerTotals };
}>();

const { can } = usePermissions();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'لوحة التحكم', href: '/admin' },
    { title: 'حجوزات القاعات', href: '/admin/bookings/halls' },
];

const money = (n: number) => new Intl.NumberFormat('ar-SA-u-nu-latn', { maximumFractionDigits: 2 }).format(n ?? 0);

// ── الأعمدة المعروضة ────────────────────────────────────────
// السجل يحمل دفتر الحجز كاملًا، وعرضه كله يفرض تمريرًا أفقيًا طويلًا.
// فيُختار منه ما يعني الموظف، ويُحفظ اختياره لجلساته القادمة.
const IDENTITY_KEYS = ['reference', 'client', 'mobile', 'event', 'start', 'end', 'unit', 'sections'];
// «تفصيل طرق الدفع» ليس عمودًا واحدًا بل عمودًا لكل طريقة، فيُعدّ على حدة.
const MONEY_KEYS = ['subtotal', 'discount', 'deposit', 'tax', 'total', 'paid', 'remaining', 'refunded'];
const TAIL_KEYS = ['status', 'pay_status'];
const CONTROL_KEYS = ['notes', 'actions'];

const COLUMNS: TableColumn[] = [
    { key: 'reference', label: 'رقم الحجز', fixed: true },
    { key: 'client', label: 'العميل' },
    { key: 'mobile', label: 'جوال العميل' },
    { key: 'event', label: 'نوع المناسبة' },
    { key: 'start', label: 'بداية الحجز' },
    { key: 'end', label: 'نهاية الحجز' },
    { key: 'unit', label: 'القاعة' },
    { key: 'sections', label: 'الأقسام' },
    { key: 'subtotal', label: 'مبلغ الحجز' },
    { key: 'discount', label: 'الخصم' },
    { key: 'deposit', label: 'المقدم' },
    { key: 'tax', label: 'الضريبة' },
    { key: 'total', label: 'الإجمالي' },
    { key: 'paid', label: 'المدفوع' },
    { key: 'methods', label: 'تفصيل طرق الدفع' },
    { key: 'remaining', label: 'المتبقي' },
    { key: 'refunded', label: 'المسترجع' },
    { key: 'status', label: 'حالة الحجز' },
    { key: 'pay_status', label: 'حالة الدفع' },
    { key: 'notes', label: 'الملاحظات' },
    { key: 'actions', label: 'التحكم', fixed: true },
];

const PRESETS: ColumnPreset[] = [
    {
        key: 'compact',
        label: 'مختصر',
        columns: ['client', 'event', 'start', 'unit', 'total', 'paid', 'remaining', 'status', 'pay_status', 'notes'],
    },
    {
        key: 'finance',
        label: 'مالي',
        columns: ['client', 'start', 'subtotal', 'discount', 'deposit', 'tax', 'total', 'paid', 'methods', 'remaining', 'refunded', 'pay_status'],
    },
    {
        key: 'schedule',
        label: 'المواعيد',
        columns: ['client', 'mobile', 'event', 'start', 'end', 'unit', 'sections', 'status', 'notes'],
    },
    { key: 'full', label: 'كامل', columns: COLUMNS.map((c) => c.key) },
];

const { shows, toggle: toggleColumn, applyPreset, activePreset, countOf } = useTableColumns(
    'bookings.halls.columns',
    COLUMNS,
    PRESETS,
);

/** عمود «تفصيل طرق الدفع» يفتح عمودًا لكل طريقة، فيُحسب بعددها. */
const methodColumnCount = computed(() => (shows('methods') ? props.methods.length : 0));

const identityColSpan = computed(() => countOf(IDENTITY_KEYS));
const tailColSpan = computed(() => countOf(TAIL_KEYS));
const controlColSpan = computed(() => countOf(CONTROL_KEYS));

// صف «لا نتائج» يمتدّ على المعروض وحده.
const columnCount = computed(
    () => countOf([...IDENTITY_KEYS, ...MONEY_KEYS, ...TAIL_KEYS, ...CONTROL_KEYS]) + methodColumnCount.value,
);

const columnsOpen = ref(false);

const payStatusClass = (status: string) =>
    ({
        'مسدّدة': 'bg-emerald-100 text-emerald-800',
        'مسدّدة جزئيًا': 'bg-amber-100 text-amber-800',
        'غير مسدّدة': 'bg-red-100 text-red-800',
    })[status] ?? 'bg-slate-200 text-slate-800';

// ── الفلاتر ─────────────────────────────────────────────────
const filters = ref({ ...props.filters });
const applyFilters = () => router.get('/admin/bookings/halls', filters.value, { preserveState: true, replace: true });
const resetFilters = () => {
    filters.value = { status: null, unit_id: null, event_type_id: null, from: null, to: null, search: null };
    applyFilters();
};

// ── الدفعات والعربون ────────────────────────────────────────
const payBooking = ref<Booking | null>(null);
const payments = ref<Payment[]>([]);
const paySummary = ref<PaymentSummary | null>(null);
const payLoading = ref(false);

const payForm = useForm({
    type: 'deposit',
    payment_method_id: props.meta.payment_methods[0]?.id ?? null as number | null,
    amount: 0,
    paid_on: new Date().toISOString().slice(0, 10),
    reference: '',
    notes: '',
    notify: true,
});

const loadPayments = async (b: Booking) => {
    payLoading.value = true;
    try {
        const res = await fetch(`/admin/bookings/${b.id}/payments`, {
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        });
        if (res.ok) {
            const data = await res.json();
            payments.value = data.payments;
            paySummary.value = data.summary;
        }
    } finally {
        payLoading.value = false;
    }
};

const openPayments = (b: Booking) => {
    payBooking.value = b;
    payments.value = [];
    paySummary.value = null;
    payForm.reset();
    payForm.clearErrors();
    // الاقتراح الافتراضي: ما تبقّى من العربون، وإلا فالمتبقي كاملًا
    payForm.type = b.is_deposit_settled ? 'payment' : 'deposit';
    payForm.amount = b.is_deposit_settled
        ? b.remaining_amount
        : Math.min(b.deposit_amount - b.paid_amount, b.remaining_amount);
    loadPayments(b);
};

const submitPayment = () => {
    if (!payBooking.value) return;
    payForm.post(`/admin/bookings/${payBooking.value.id}/payments`, {
        preserveScroll: true,
        onSuccess: () => {
            const updated = props.bookings.data.find((x) => x.id === payBooking.value?.id);
            if (updated) payBooking.value = updated;
            payForm.reset('amount', 'reference', 'notes');
            if (payBooking.value) loadPayments(payBooking.value);
        },
    });
};

// ── المعاينة ────────────────────────────────────────────────
// الأعمدة تُخفى ليُقرأ الجدول، فتبقى المعاينة موضع التفاصيل كلها: تُفتح
// على الصف فتعرض ما أُخفي عنه دون مغادرة السجل. وبياناتها من الصف نفسه
// لا من طلب جديد — كلها وصلت مع الصفحة.
const previewBooking = ref<Booking | null>(null);

const openPreview = (b: Booking) => (previewBooking.value = b);

// ── الملاحظات ───────────────────────────────────────────────
// الملاحظة تُكتب بعد الحجز غالبًا (طلب طارئ، تنبيه للمناوبة)، فتُحرَّر من
// السجل مباشرة بدل فتح شاشة التعديل كاملة لسطر واحد.
const notesBooking = ref<Booking | null>(null);
const notesForm = useForm({ notes: '' });

const openNotes = (b: Booking) => {
    notesBooking.value = b;
    notesForm.clearErrors();
    notesForm.notes = b.notes ?? '';
};

const saveNotes = () => {
    if (!notesBooking.value) return;

    notesForm.patch(`/admin/bookings/${notesBooking.value.id}/notes`, {
        preserveScroll: true,
        onSuccess: () => (notesBooking.value = null),
    });
};

// ── العقد ───────────────────────────────────────────────────
/** لا عقد بعد: يُولَّد من القالب الافتراضي ثم يظهر زرّ فتحه في الصف. */
const generateContract = (b: Booking) => {
    if (confirm(`توليد عقد للحجز ${b.reference}؟`)) {
        router.post('/admin/contracts', { booking_id: b.id }, { preserveScroll: true });
    }
};

const sendReminder = (b: Booking) => {
    if (confirm(`إرسال تذكير بالموعد على واتساب ${b.client?.mobile ?? ''}؟`)) {
        router.post(`/admin/bookings/${b.id}/remind`, {}, { preserveScroll: true });
    }
};

// الإلغاء والتأجيل يخرجان الحجز من مساره، فيُسأل عن السبب ليبقى في سجل التدقيق.
const REASON_PROMPTS: Record<string, string> = {
    cancelled: 'سبب الإلغاء (اختياري):',
    postponed: 'سبب التأجيل (اختياري):',
};

const changeStatus = (b: Booking, status: string) => {
    const ask = REASON_PROMPTS[status];
    const reason = ask ? prompt(ask) ?? '' : '';
    router.patch(`/admin/bookings/${b.id}/status`, { status, reason }, { preserveScroll: true });
};

const destroy = (b: Booking) => {
    if (confirm(`حذف الحجز ${b.reference}؟`)) {
        router.delete(`/admin/bookings/${b.id}`, { preserveScroll: true });
    }
};

/** شارة نوع المناسبة — نفس مفاتيح EventType::COLORS في الخادم. */
const eventBadge = (color: string) =>
    ({
        emerald: 'bg-emerald-100 text-emerald-700',
        sky: 'bg-sky-100 text-sky-700',
        violet: 'bg-violet-100 text-violet-700',
        amber: 'bg-amber-100 text-amber-700',
        rose: 'bg-rose-100 text-rose-700',
        slate: 'bg-slate-200 text-slate-700',
    })[color] ?? 'bg-slate-100 text-slate-700';

const colorClass = statusChipClass;
</script>

<template>
    <Head title="حجوزات القاعات" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="min-h-full space-y-5 bg-slate-100 p-5">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h1 class="text-2xl font-extrabold text-slate-900">حجوزات القاعات</h1>
                    <p class="mt-1 text-sm font-medium text-slate-600">مناسبة داخل يوم واحد بفترة محددة — الوحدة كاملة أو قسم منفرد</p>
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    <!-- اختصارات شاشات القاعات — تُشتق من القائمة فلا تحتاج صيانة -->
                    <PageShortcuts />
                    <Link v-if="can('bookings.create')" href="/admin/bookings/halls/create" class="inline-flex items-center gap-1.5 rounded-md bg-blue-600 px-4 py-2 text-sm font-bold text-white shadow-sm hover:bg-blue-700">
                        <Plus class="h-4 w-4" /> حجز قاعة جديد
                    </Link>
                </div>
            </div>

            <div class="grid grid-cols-2 gap-3 sm:grid-cols-5">
                <StatPill label="إجمالي الحجوزات" :value="stats.total" variant="primary" />
                <StatPill label="حجز مبدئي" :value="stats.tentative" variant="warning" />
                <StatPill label="بانتظار العربون" :value="stats.pending_deposit" variant="dark" />
                <StatPill label="حجز مؤكد" :value="stats.confirmed" variant="success" />
                <StatPill label="عليه متبقٍ" :value="stats.unpaid" variant="danger" />
            </div>

            <!-- الفلاتر -->
            <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
                <div class="grid gap-2 sm:grid-cols-2 lg:grid-cols-7">
                    <div class="lg:col-span-2">
                        <div class="relative">
                            <Search class="absolute top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400 ltr:left-3 rtl:right-3" />
                            <input v-model="filters.search" @keyup.enter="applyFilters" placeholder="رقم الحجز أو العميل" class="w-full rounded-xl border border-slate-200 py-2.5 text-sm ltr:pl-9 ltr:pr-3 rtl:pl-3 rtl:pr-9" />
                        </div>
                    </div>
                    <select v-model="filters.status" @change="applyFilters" class="rounded-xl border border-slate-200 px-3 py-2.5 text-sm">
                        <option :value="null">كل الحالات</option>
                        <option v-for="s in meta.statuses" :key="s.key" :value="s.key">{{ s.label }}</option>
                    </select>
                    <select v-model="filters.unit_id" @change="applyFilters" class="rounded-xl border border-slate-200 px-3 py-2.5 text-sm">
                        <option :value="null">كل القاعات</option>
                        <option v-for="u in units" :key="u.id" :value="u.id">{{ u.name }}</option>
                    </select>
                    <select v-model="filters.event_type_id" @change="applyFilters" class="rounded-xl border border-slate-200 px-3 py-2.5 text-sm">
                        <option :value="null">كل المناسبات</option>
                        <option v-for="t in eventTypes" :key="t.id" :value="t.id">{{ t.name }}</option>
                    </select>
                    <input v-model="filters.from" @change="applyFilters" type="date" class="rounded-xl border border-slate-200 px-3 py-2.5 text-sm" />
                    <input v-model="filters.to" @change="applyFilters" type="date" class="rounded-xl border border-slate-200 px-3 py-2.5 text-sm" />
                </div>
                <button type="button" @click="resetFilters" class="mt-2 text-[11px] font-bold text-slate-500 hover:text-slate-700">إعادة ضبط الفلاتر</button>
            </div>

            <!-- الجدول -->
            <div class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                <!-- اختيار الأعمدة: السجل يحمل دفتر الحجز كاملًا، ويُعرض منه
                     ما يعني الموظف. والاختيار يُحفظ في متصفحه لجلساته القادمة. -->
                <div class="flex flex-wrap items-center justify-between gap-2 border-b border-slate-200 bg-slate-50 px-4 py-2.5 print:hidden">
                    <div class="flex flex-wrap items-center gap-1.5">
                        <span class="text-xs font-extrabold text-slate-800">طريقة العرض</span>
                        <button
                            v-for="p in PRESETS" :key="p.key" type="button" @click="applyPreset(p)"
                            class="rounded-lg px-2.5 py-1 text-xs font-bold transition"
                            :class="activePreset === p.key ? 'bg-slate-800 text-white shadow-sm' : 'bg-white text-slate-800 ring-1 ring-slate-300 hover:bg-slate-100'"
                        >{{ p.label }}</button>
                    </div>

                    <DropdownMenu v-model:open="columnsOpen">
                        <DropdownMenuTrigger :as-child="true">
                            <button
                                type="button"
                                class="inline-flex items-center gap-1.5 rounded-lg bg-white px-3 py-1.5 text-xs font-bold text-slate-800 ring-1 ring-slate-300 transition hover:bg-slate-100 data-[state=open]:bg-slate-100"
                            >
                                <Columns3 class="h-4 w-4" />
                                الأعمدة ({{ columnCount }})
                            </button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="end" class="max-h-96 w-56 overflow-y-auto">
                            <label
                                v-for="c in COLUMNS" :key="c.key"
                                class="flex items-center gap-2 px-2 py-1.5 text-sm font-bold"
                                :class="c.fixed ? 'cursor-not-allowed text-slate-500' : 'cursor-pointer text-slate-800 hover:bg-slate-100'"
                            >
                                <input
                                    type="checkbox" :checked="shows(c.key)" :disabled="c.fixed"
                                    @change="toggleColumn(c.key)"
                                    class="h-4 w-4 rounded border-slate-300 text-emerald-600"
                                />
                                {{ c.label }}
                                <span v-if="c.fixed" class="ms-auto text-[11px] font-medium">ثابت</span>
                            </label>
                        </DropdownMenuContent>
                    </DropdownMenu>
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="bg-slate-100">
                            <tr>
                                <!-- رقم الحجز مثبَّت عند حافة الجدول: هو مرساة الصف،
                                     وبضياعه أثناء التمرير الأفقي تصير الأرقام بلا صاحب. -->
                                <th class="sticky z-20 whitespace-nowrap bg-slate-100 px-3 py-3 text-right text-xs font-extrabold text-slate-800 ltr:left-0 rtl:right-0">رقم الحجز</th>
                                <th v-if="shows('client')" class="whitespace-nowrap px-3 py-3 text-right text-xs font-extrabold text-slate-800">العميل</th>
                                <th v-if="shows('mobile')" class="whitespace-nowrap px-3 py-3 text-right text-xs font-extrabold text-slate-800">جوال العميل</th>
                                <th v-if="shows('event')" class="whitespace-nowrap px-3 py-3 text-right text-xs font-extrabold text-slate-800">نوع المناسبة</th>
                                <th v-if="shows('start')" class="whitespace-nowrap px-3 py-3 text-right text-xs font-extrabold text-slate-800">بداية الحجز</th>
                                <th v-if="shows('end')" class="whitespace-nowrap px-3 py-3 text-right text-xs font-extrabold text-slate-800">نهاية الحجز</th>
                                <th v-if="shows('unit')" class="whitespace-nowrap px-3 py-3 text-right text-xs font-extrabold text-slate-800">القاعة</th>
                                <th v-if="shows('sections')" class="whitespace-nowrap px-3 py-3 text-right text-xs font-extrabold text-slate-800">الأقسام</th>
                                <th v-if="shows('subtotal')" class="whitespace-nowrap px-3 py-3 text-center text-xs font-extrabold text-slate-800">مبلغ الحجز</th>
                                <th v-if="shows('discount')" class="whitespace-nowrap px-3 py-3 text-center text-xs font-extrabold text-slate-800">الخصم</th>
                                <th v-if="shows('deposit')" class="whitespace-nowrap px-3 py-3 text-center text-xs font-extrabold text-slate-800">المقدم</th>
                                <th v-if="shows('tax')" class="whitespace-nowrap px-3 py-3 text-center text-xs font-extrabold text-slate-800">الضريبة</th>
                                <th v-if="shows('total')" class="whitespace-nowrap px-3 py-3 text-center text-xs font-extrabold text-slate-800">الإجمالي</th>
                                <th v-if="shows('paid')" class="whitespace-nowrap px-3 py-3 text-center text-xs font-extrabold text-slate-800">المدفوع</th>
                                <th v-for="m in (shows('methods') ? methods : [])" :key="m.key" class="whitespace-nowrap px-3 py-3 text-center text-xs font-extrabold text-slate-800">
                                    {{ m.label }}
                                </th>
                                <th v-if="shows('remaining')" class="whitespace-nowrap px-3 py-3 text-center text-xs font-extrabold text-slate-800">المتبقي</th>
                                <th v-if="shows('refunded')" class="whitespace-nowrap px-3 py-3 text-center text-xs font-extrabold text-slate-800">المسترجع</th>
                                <th v-if="shows('status')" class="whitespace-nowrap px-3 py-3 text-center text-xs font-extrabold text-slate-800">حالة الحجز</th>
                                <th v-if="shows('pay_status')" class="whitespace-nowrap px-3 py-3 text-center text-xs font-extrabold text-slate-800">حالة الدفع</th>
                                <th v-if="shows('notes')" class="whitespace-nowrap px-3 py-3 text-center text-xs font-extrabold text-slate-800 print:hidden">الملاحظات</th>
                                <th class="whitespace-nowrap px-3 py-3 text-center text-xs font-extrabold text-slate-800 print:hidden">التحكم</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="b in bookings.data" :key="b.id" class="group border-t border-slate-100 transition hover:bg-slate-50">
                                <td class="sticky z-10 whitespace-nowrap bg-white px-3 py-3 font-extrabold text-slate-900 group-hover:bg-slate-50 ltr:left-0 rtl:right-0" dir="ltr">
                                    {{ b.reference }}
                                    <span v-if="b.is_online" title="حجز وصل من الموقع" class="ms-1 rounded bg-indigo-100 px-1.5 py-0.5 text-[10px] font-bold text-indigo-700">أونلاين</span>
                                </td>
                                <td v-if="shows('client')" class="px-3 py-3 font-bold text-slate-900">{{ b.client?.name ?? '—' }}</td>
                                <td v-if="shows('mobile')" class="whitespace-nowrap px-3 py-3 font-medium text-slate-800" dir="ltr">{{ b.client?.mobile ?? '—' }}</td>
                                <td v-if="shows('event')" class="px-3 py-3">
                                    <span v-if="b.event_type" class="rounded px-1.5 py-0.5 text-xs font-bold" :class="eventBadge(b.event_type.color)">
                                        {{ b.event_type.name }}
                                    </span>
                                    <span v-else class="text-slate-600">—</span>
                                    <div v-if="b.package" class="mt-1 text-xs font-bold text-slate-700">{{ b.package.name }}</div>
                                </td>
                                <td v-if="shows('start')" class="whitespace-nowrap px-3 py-3">
                                    <div class="font-bold text-slate-900" dir="ltr">{{ b.booking_date }}</div>
                                    <div class="text-xs font-bold text-emerald-800">{{ toHijri(b.booking_date) }}</div>
                                    <div class="text-xs font-medium text-slate-700">{{ b.period_label }}</div>
                                </td>
                                <td v-if="shows('end')" class="whitespace-nowrap px-3 py-3">
                                    <div class="font-bold text-slate-900" dir="ltr">{{ b.last_day_date }}</div>
                                    <div class="text-xs font-bold text-emerald-800">{{ toHijri(b.last_day_date) }}</div>
                                    <!-- المناسبة الممتدة تُقرأ بمداها لا ببدايتها وحدها -->
                                    <div v-if="b.days_count > 1" class="text-xs font-bold text-slate-800">{{ b.days_count }} أيام</div>
                                </td>
                                <td v-if="shows('unit')" class="px-3 py-3 font-bold text-slate-900">{{ b.unit.name }}</td>
                                <td v-if="shows('sections')" class="px-3 py-3">
                                    <span v-if="b.scope === 'whole'" class="rounded bg-violet-100 px-1.5 py-0.5 text-xs font-bold text-violet-800">الوحدة كاملة</span>
                                    <span v-else class="rounded bg-sky-100 px-1.5 py-0.5 text-xs font-bold text-sky-800">{{ b.sections.join('، ') }}</span>
                                </td>
                                <td v-if="shows('subtotal')" class="whitespace-nowrap px-3 py-3 text-center font-bold text-slate-900" dir="ltr">{{ money(b.subtotal_amount) }}</td>
                                <td v-if="shows('discount')" class="whitespace-nowrap px-3 py-3 text-center font-bold" :class="b.discount_amount > 0 ? 'text-amber-800' : 'text-slate-600'" dir="ltr">
                                    {{ money(b.discount_amount) }}
                                </td>
                                <td v-if="shows('deposit')" class="whitespace-nowrap px-3 py-3 text-center font-bold" dir="ltr">
                                    <span :class="b.is_deposit_settled ? 'text-slate-900' : 'text-amber-800'">{{ money(b.deposit_amount) }}</span>
                                </td>
                                <td v-if="shows('tax')" class="whitespace-nowrap px-3 py-3 text-center font-bold text-slate-800" dir="ltr">{{ money(b.tax_amount) }}</td>
                                <td v-if="shows('total')" class="whitespace-nowrap px-3 py-3 text-center font-extrabold text-slate-900" dir="ltr">{{ money(b.total_amount) }}</td>
                                <td v-if="shows('paid')" class="whitespace-nowrap px-3 py-3 text-center font-bold text-emerald-800" dir="ltr">{{ money(b.paid_amount) }}</td>
                                <td v-for="m in (shows('methods') ? methods : [])" :key="m.key" class="whitespace-nowrap px-3 py-3 text-center font-medium" dir="ltr"
                                    :class="b.paid_by_method[m.key] > 0 ? 'font-bold text-slate-900' : 'text-slate-500'">
                                    {{ money(b.paid_by_method[m.key] ?? 0) }}
                                </td>
                                <td v-if="shows('remaining')" class="whitespace-nowrap px-3 py-3 text-center font-extrabold" :class="b.remaining_amount > 0 ? 'text-red-700' : 'text-emerald-800'" dir="ltr">
                                    {{ money(b.remaining_amount) }}
                                </td>
                                <td v-if="shows('refunded')" class="whitespace-nowrap px-3 py-3 text-center font-bold" :class="b.refunded_amount > 0 ? 'text-red-700' : 'text-slate-500'" dir="ltr">
                                    {{ money(b.refunded_amount) }}
                                </td>
                                <td v-if="shows('status')" class="px-3 py-3 text-center">
                                    <span class="whitespace-nowrap rounded-md px-2 py-0.5 text-xs font-bold" :class="colorClass(b.status_color)">{{ b.status_label }}</span>
                                </td>
                                <td v-if="shows('pay_status')" class="px-3 py-3 text-center">
                                    <span class="whitespace-nowrap rounded-md px-2 py-0.5 text-xs font-bold" :class="payStatusClass(b.payment_status)">
                                        {{ b.payment_status }}
                                    </span>
                                </td>
                                <!-- الملاحظات عمود مستقل: يُقرأ بنظرة ويُحرَّر بنقرة -->
                                <td v-if="shows('notes')" class="px-3 py-3 text-center print:hidden">
                                    <button
                                        type="button" @click="openNotes(b)"
                                        :title="b.notes || 'عرض/تعديل الملاحظات'"
                                        class="inline-flex h-9 w-9 items-center justify-center rounded-lg border transition"
                                        :class="b.notes
                                            ? 'border-amber-300 bg-amber-50 text-amber-600 hover:bg-amber-100'
                                            : 'border-slate-200 bg-white text-slate-400 hover:bg-slate-50 hover:text-slate-600'"
                                    >
                                        <StickyNote class="h-4 w-4" />
                                    </button>
                                </td>
                                <td class="px-3 py-3 print:hidden">
                                    <div class="flex items-center justify-center gap-1">
                                        <!-- المعاينة أولى الأزرار: هي بديل الأعمدة المخفيّة -->
                                        <TableActionButton variant="view" :icon="Eye" title="معاينة كل التفاصيل" @click="openPreview(b)" />

                                        <TableActionButton v-if="can('bookings.edit')" variant="primary" :icon="Wallet" title="الدفعات والعربون" @click="openPayments(b)" />

                                        <!-- السند لا يُحرَّر على حجز لم يُقبض منه شيء -->
                                        <TableActionButton v-if="b.has_payments" variant="dark" :icon="Receipt" title="السند" @click="router.visit(`/admin/bookings/${b.id}/bond`)" />

                                        <!-- الفاتورة تُحرَّر على الحجز نفسه، فتصلح قبل القبض وبعده -->
                                        <TableActionButton variant="success" :icon="FileText" title="الفاتورة" @click="router.visit(`/admin/bookings/${b.id}/invoice`)" />

                                        <TableActionButton
                                            v-if="b.contract" variant="danger" :icon="FileSignature"
                                            :title="`العقد ${b.contract.number}`"
                                            @click="router.visit(`/admin/contracts/${b.contract.id}`)"
                                        />
                                        <TableActionButton
                                            v-else-if="can('contracts.create')" variant="muted" :icon="FileSignature"
                                            title="توليد العقد" @click="generateContract(b)"
                                        />
                                        <TableActionButton v-if="can('whatsapp.send') && b.client?.mobile && !isClosedStatus(b.status)" variant="view" :icon="Bell" title="تذكير واتساب" @click="sendReminder(b)" />

                                        <!-- خطوة واحدة تظهر في كل مرة: الحالة الحالية تحدّد التالية في
                                             المسار، فلا يحتار الموظف بين أزرار لا تنطبق. -->
                                        <TableActionButton v-if="can('bookings.edit') && ['tentative', 'pending_deposit'].includes(b.status)" variant="primary" :icon="Check" title="تأكيد الحجز" @click="changeStatus(b, 'confirmed')" />
                                        <TableActionButton v-if="can('bookings.edit') && b.status === 'confirmed'" variant="primary" :icon="LogIn" title="تسجيل الدخول" @click="changeStatus(b, 'checked_in')" />
                                        <TableActionButton v-if="can('bookings.edit') && b.status === 'checked_in'" variant="success" :icon="LogOut" title="تسجيل الخروج" @click="changeStatus(b, 'checked_out')" />
                                        <TableActionButton v-if="can('bookings.edit') && !isClosedStatus(b.status)" variant="warning" :icon="X" title="إلغاء" @click="changeStatus(b, 'cancelled')" />

                                        <!-- التعديل والحذف داخل قائمة النقاط الثلاث: إجراءان يغيّران الحجز نفسه،
                                             فإخفاؤهما خلف نقرة يقلّل الضغط الخاطئ ويُهدّئ صفّ الإجراءات. -->
                                        <DropdownMenu v-if="can('bookings.edit') || can('bookings.delete')">
                                            <DropdownMenuTrigger :as-child="true">
                                                <button type="button" title="خيارات" class="inline-flex h-9 w-9 items-center justify-center rounded-lg bg-slate-200 text-slate-700 shadow-sm transition hover:bg-slate-300 data-[state=open]:bg-slate-300">
                                                    <MoreVertical class="h-4 w-4" />
                                                </button>
                                            </DropdownMenuTrigger>
                                            <DropdownMenuContent align="end" class="w-40">
                                                <DropdownMenuItem v-if="can('bookings.edit')" class="cursor-pointer font-bold text-slate-700" @select="router.visit(`/admin/bookings/halls/${b.id}/edit`)">
                                                    <Pencil class="h-4 w-4 text-cyan-600" /> تعديل
                                                </DropdownMenuItem>

                                                <!-- التأجيل هنا لا في صفّ الإجراءات: أقلّ استعمالًا من الإلغاء
                                                     ويُخلَط به، فإخفاؤه يمنع النقرة الخاطئة. -->
                                                <DropdownMenuItem v-if="can('bookings.edit') && !isClosedStatus(b.status)" class="cursor-pointer font-bold text-violet-700 focus:bg-violet-50" @select="changeStatus(b, 'postponed')">
                                                    <CalendarClock class="h-4 w-4" /> تأجيل
                                                </DropdownMenuItem>
                                                <DropdownMenuSeparator v-if="can('bookings.edit') && can('bookings.delete')" />
                                                <DropdownMenuItem v-if="can('bookings.delete')" class="cursor-pointer font-bold text-red-600 focus:bg-red-50 focus:text-red-700" @select="destroy(b)">
                                                    <Trash2 class="h-4 w-4" /> حذف
                                                </DropdownMenuItem>
                                            </DropdownMenuContent>
                                        </DropdownMenu>
                                    </div>
                                </td>
                            </tr>
                            <tr v-if="!bookings.data.length">
                                <td :colspan="columnCount" class="px-4 py-10 text-center text-sm font-medium text-slate-700">لا توجد حجوزات مطابقة</td>
                            </tr>
                        </tbody>

                        <!-- مجاميع الصفحة المعروضة — تُقرأ تحت أعمدتها مباشرة -->
                        <tfoot v-if="bookings.data.length" class="border-t-2 border-slate-300 bg-slate-100">
                            <tr>
                                <td :colspan="identityColSpan" class="sticky z-10 bg-slate-100 px-3 py-3 text-right text-xs font-extrabold text-slate-800 ltr:left-0 rtl:right-0">
                                    مجموع هذه الصفحة ({{ totals.page.count }} حجز)
                                </td>
                                <td v-if="shows('subtotal')" class="px-3 py-3 text-center text-xs font-extrabold text-slate-900" dir="ltr">{{ money(totals.page.subtotal) }}</td>
                                <td v-if="shows('discount')" class="px-3 py-3 text-center text-xs font-extrabold text-amber-800" dir="ltr">{{ money(totals.page.discount) }}</td>
                                <td v-if="shows('deposit')" class="px-3 py-3 text-center text-xs font-extrabold text-slate-900" dir="ltr">{{ money(totals.page.deposit) }}</td>
                                <td v-if="shows('tax')" class="px-3 py-3 text-center text-xs font-extrabold text-slate-900" dir="ltr">{{ money(totals.page.tax) }}</td>
                                <td v-if="shows('total')" class="px-3 py-3 text-center text-xs font-extrabold text-slate-900" dir="ltr">{{ money(totals.page.total) }}</td>
                                <td v-if="shows('paid')" class="px-3 py-3 text-center text-xs font-extrabold text-emerald-800" dir="ltr">{{ money(totals.page.paid) }}</td>
                                <td v-for="m in (shows('methods') ? methods : [])" :key="m.key" class="px-3 py-3 text-center text-xs font-extrabold text-slate-900" dir="ltr">
                                    {{ money(totals.page.paid_by_method[m.key] ?? 0) }}
                                </td>
                                <td v-if="shows('remaining')" class="px-3 py-3 text-center text-xs font-extrabold text-red-700" dir="ltr">{{ money(totals.page.remaining) }}</td>
                                <td v-if="shows('refunded')" class="px-3 py-3 text-center text-xs font-extrabold text-red-700" dir="ltr">{{ money(totals.page.refunded) }}</td>
                                <!-- عمودا الحالة يبقيان في الطباعة، والملاحظات والتحكم لا يُطبعان،
                                     ففُصلا في خليتين حتى لا ينزاح الصف عن ترويسته على الورق. -->
                                <td v-if="tailColSpan" :colspan="tailColSpan"></td>
                                <td :colspan="controlColSpan" class="print:hidden"></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>

                <div v-if="bookings.links.length > 3" class="flex flex-wrap justify-center gap-1 border-t border-slate-100 p-3">
                    <Link
                        v-for="l in bookings.links"
                        :key="l.label"
                        :href="l.url ?? '#'"
                        :class="['rounded-lg px-3 py-1.5 text-xs font-bold', l.active ? 'bg-blue-600 text-white' : l.url ? 'bg-white text-slate-600 ring-1 ring-slate-200 hover:bg-slate-50' : 'cursor-default text-slate-300']"
                        v-html="l.label"
                    />
                </div>
            </div>

            <!-- إجمالي كل الصفحات المفلترة: الصفحة وحدها لا تكفي المراجعة،
                 والمشغّل يريد حصيلة الفلتر كاملًا لا حصيلة عشرين صفًّا. -->
            <div class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                <div class="border-b border-slate-200 bg-slate-50 px-4 py-3">
                    <h2 class="text-sm font-extrabold text-slate-900">
                        إجمالي كل الصفحات المفلترة
                        <span class="font-bold text-slate-700">({{ totals.all.count }} حجز)</span>
                    </h2>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="bg-slate-100">
                            <tr>
                                <th v-if="shows('subtotal')" class="whitespace-nowrap px-3 py-2.5 text-center text-xs font-extrabold text-slate-800">مبلغ الحجز</th>
                                <th v-if="shows('discount')" class="whitespace-nowrap px-3 py-2.5 text-center text-xs font-extrabold text-slate-800">الخصم</th>
                                <th v-if="shows('deposit')" class="whitespace-nowrap px-3 py-2.5 text-center text-xs font-extrabold text-slate-800">المقدم</th>
                                <th v-if="shows('tax')" class="whitespace-nowrap px-3 py-2.5 text-center text-xs font-extrabold text-slate-800">الضريبة</th>
                                <th v-if="shows('total')" class="whitespace-nowrap px-3 py-2.5 text-center text-xs font-extrabold text-slate-800">الإجمالي</th>
                                <th v-if="shows('paid')" class="whitespace-nowrap px-3 py-2.5 text-center text-xs font-extrabold text-slate-800">المدفوع</th>
                                <th v-for="m in (shows('methods') ? methods : [])" :key="m.key" class="whitespace-nowrap px-3 py-2.5 text-center text-xs font-extrabold text-slate-800">
                                    {{ m.label }}
                                </th>
                                <th v-if="shows('remaining')" class="whitespace-nowrap px-3 py-2.5 text-center text-xs font-extrabold text-slate-800">المتبقي</th>
                                <th v-if="shows('refunded')" class="whitespace-nowrap px-3 py-2.5 text-center text-xs font-extrabold text-slate-800">المسترجع</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr class="border-t border-slate-200">
                                <td v-if="shows('subtotal')" class="whitespace-nowrap px-3 py-3 text-center font-extrabold text-slate-900" dir="ltr">{{ money(totals.all.subtotal) }}</td>
                                <td v-if="shows('discount')" class="whitespace-nowrap px-3 py-3 text-center font-extrabold text-amber-800" dir="ltr">{{ money(totals.all.discount) }}</td>
                                <td v-if="shows('deposit')" class="whitespace-nowrap px-3 py-3 text-center font-extrabold text-slate-900" dir="ltr">{{ money(totals.all.deposit) }}</td>
                                <td v-if="shows('tax')" class="whitespace-nowrap px-3 py-3 text-center font-extrabold text-slate-900" dir="ltr">{{ money(totals.all.tax) }}</td>
                                <td v-if="shows('total')" class="whitespace-nowrap px-3 py-3 text-center font-extrabold text-slate-900" dir="ltr">{{ money(totals.all.total) }}</td>
                                <td v-if="shows('paid')" class="whitespace-nowrap px-3 py-3 text-center font-extrabold text-emerald-800" dir="ltr">{{ money(totals.all.paid) }}</td>
                                <td v-for="m in (shows('methods') ? methods : [])" :key="m.key" class="whitespace-nowrap px-3 py-3 text-center font-bold text-slate-900" dir="ltr">
                                    {{ money(totals.all.paid_by_method[m.key] ?? 0) }}
                                </td>
                                <td v-if="shows('remaining')" class="whitespace-nowrap px-3 py-3 text-center font-extrabold text-red-700" dir="ltr">{{ money(totals.all.remaining) }}</td>
                                <td v-if="shows('refunded')" class="whitespace-nowrap px-3 py-3 text-center font-extrabold text-red-700" dir="ltr">{{ money(totals.all.refunded) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- معاينة الحجز — كل تفاصيله مهما أُخفي من الأعمدة -->
        <div v-if="previewBooking" class="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-black/40 p-4" @click.self="previewBooking = null">
            <div class="my-4 w-full max-w-3xl rounded-2xl bg-white shadow-2xl">
                <div class="flex items-start justify-between gap-3 border-b border-slate-200 px-6 py-4">
                    <div class="min-w-0">
                        <div class="flex flex-wrap items-center gap-2">
                            <h2 class="text-lg font-extrabold text-slate-900" dir="ltr">{{ previewBooking.reference }}</h2>
                            <span class="rounded-md px-2 py-0.5 text-xs font-bold" :class="colorClass(previewBooking.status_color)">
                                {{ previewBooking.status_label }}
                            </span>
                            <span class="rounded-md px-2 py-0.5 text-xs font-bold" :class="payStatusClass(previewBooking.payment_status)">
                                {{ previewBooking.payment_status }}
                            </span>
                        </div>
                        <p class="mt-1 text-sm font-medium text-slate-700">
                            {{ previewBooking.unit.name }} · {{ previewBooking.client?.name ?? 'بلا عميل' }}
                        </p>
                    </div>
                    <button type="button" @click="previewBooking = null" class="rounded-lg p-1 text-slate-600 hover:bg-slate-100">
                        <X class="h-5 w-5" />
                    </button>
                </div>

                <div class="max-h-[70vh] space-y-4 overflow-y-auto px-6 py-4">
                    <!-- العميل والمناسبة -->
                    <section>
                        <h3 class="mb-2 text-xs font-extrabold uppercase text-slate-600">العميل والمناسبة</h3>
                        <dl class="grid gap-x-6 gap-y-2 sm:grid-cols-2">
                            <div class="flex justify-between gap-3 border-b border-slate-100 py-1">
                                <dt class="font-bold text-slate-700">العميل</dt>
                                <dd class="font-extrabold text-slate-900">{{ previewBooking.client?.name ?? '—' }}</dd>
                            </div>
                            <div class="flex justify-between gap-3 border-b border-slate-100 py-1">
                                <dt class="font-bold text-slate-700">جوال العميل</dt>
                                <dd class="font-bold text-slate-900" dir="ltr">{{ previewBooking.client?.mobile ?? '—' }}</dd>
                            </div>
                            <div class="flex justify-between gap-3 border-b border-slate-100 py-1">
                                <dt class="font-bold text-slate-700">نوع المناسبة</dt>
                                <dd class="font-bold text-slate-900">{{ previewBooking.event_type?.name ?? '—' }}</dd>
                            </div>
                            <div class="flex justify-between gap-3 border-b border-slate-100 py-1">
                                <dt class="font-bold text-slate-700">الباقة</dt>
                                <dd class="font-bold text-slate-900">{{ previewBooking.package?.name ?? 'بلا باقة' }}</dd>
                            </div>
                            <div class="flex justify-between gap-3 border-b border-slate-100 py-1">
                                <dt class="font-bold text-slate-700">عدد الضيوف</dt>
                                <dd class="font-bold text-slate-900">{{ previewBooking.guests_count ?? '—' }}</dd>
                            </div>
                            <div class="flex justify-between gap-3 border-b border-slate-100 py-1">
                                <dt class="font-bold text-slate-700">العقد</dt>
                                <dd class="font-bold text-slate-900" dir="ltr">{{ previewBooking.contract?.number ?? '—' }}</dd>
                            </div>
                        </dl>
                    </section>

                    <!-- الموعد والمكان -->
                    <section>
                        <h3 class="mb-2 text-xs font-extrabold uppercase text-slate-600">الموعد والمكان</h3>
                        <dl class="grid gap-x-6 gap-y-2 sm:grid-cols-2">
                            <div class="flex justify-between gap-3 border-b border-slate-100 py-1">
                                <dt class="font-bold text-slate-700">القاعة</dt>
                                <dd class="font-bold text-slate-900">{{ previewBooking.unit.name }} <span class="text-slate-600" dir="ltr">({{ previewBooking.unit.code }})</span></dd>
                            </div>
                            <div class="flex justify-between gap-3 border-b border-slate-100 py-1">
                                <dt class="font-bold text-slate-700">النطاق</dt>
                                <dd class="font-bold text-slate-900">
                                    {{ previewBooking.scope === 'whole' ? 'الوحدة كاملة' : previewBooking.sections.join('، ') }}
                                </dd>
                            </div>
                            <div class="flex justify-between gap-3 border-b border-slate-100 py-1">
                                <dt class="font-bold text-slate-700">بداية الحجز</dt>
                                <dd class="text-left">
                                    <div class="font-bold text-slate-900" dir="ltr">{{ previewBooking.booking_date }}</div>
                                    <div class="text-xs font-bold text-emerald-800">{{ toHijri(previewBooking.booking_date) }}</div>
                                </dd>
                            </div>
                            <div class="flex justify-between gap-3 border-b border-slate-100 py-1">
                                <dt class="font-bold text-slate-700">نهاية الحجز</dt>
                                <dd class="text-left">
                                    <div class="font-bold text-slate-900" dir="ltr">{{ previewBooking.last_day_date }}</div>
                                    <div class="text-xs font-bold text-emerald-800">{{ toHijri(previewBooking.last_day_date) }}</div>
                                </dd>
                            </div>
                            <div class="flex justify-between gap-3 border-b border-slate-100 py-1">
                                <dt class="font-bold text-slate-700">الفترة</dt>
                                <dd class="font-bold text-slate-900">{{ previewBooking.schedule_label }}</dd>
                            </div>
                            <div class="flex justify-between gap-3 border-b border-slate-100 py-1">
                                <dt class="font-bold text-slate-700">عدد الأيام</dt>
                                <dd class="font-bold text-slate-900">{{ previewBooking.days_count }}</dd>
                            </div>
                        </dl>
                    </section>

                    <!-- الدفتر المالي — نفس أعمدة السجل مجموعةً في مكان واحد -->
                    <section>
                        <h3 class="mb-2 text-xs font-extrabold uppercase text-slate-600">المالية</h3>
                        <dl class="grid gap-x-6 gap-y-2 sm:grid-cols-2">
                            <div class="flex justify-between gap-3 border-b border-slate-100 py-1">
                                <dt class="font-bold text-slate-700">مبلغ الحجز</dt>
                                <dd class="font-bold text-slate-900" dir="ltr">{{ money(previewBooking.subtotal_amount) }}</dd>
                            </div>
                            <div class="flex justify-between gap-3 border-b border-slate-100 py-1">
                                <dt class="font-bold text-slate-700">الخصم</dt>
                                <dd class="font-bold text-amber-800" dir="ltr">{{ money(previewBooking.discount_amount) }}</dd>
                            </div>
                            <div class="flex justify-between gap-3 border-b border-slate-100 py-1">
                                <dt class="font-bold text-slate-700">المقدم (العربون)</dt>
                                <dd class="font-bold" :class="previewBooking.is_deposit_settled ? 'text-slate-900' : 'text-amber-800'" dir="ltr">
                                    {{ money(previewBooking.deposit_amount) }}
                                </dd>
                            </div>
                            <div class="flex justify-between gap-3 border-b border-slate-100 py-1">
                                <dt class="font-bold text-slate-700">الضريبة</dt>
                                <dd class="font-bold text-slate-900" dir="ltr">{{ money(previewBooking.tax_amount) }}</dd>
                            </div>
                            <div class="flex justify-between gap-3 border-b-2 border-slate-300 py-1">
                                <dt class="font-extrabold text-slate-900">الإجمالي</dt>
                                <dd class="font-extrabold text-slate-900" dir="ltr">{{ money(previewBooking.total_amount) }}</dd>
                            </div>
                            <div class="flex justify-between gap-3 border-b-2 border-slate-300 py-1">
                                <dt class="font-extrabold text-slate-900">المدفوع</dt>
                                <dd class="font-extrabold text-emerald-800" dir="ltr">{{ money(previewBooking.paid_amount) }}</dd>
                            </div>
                            <div class="flex justify-between gap-3 border-b border-slate-100 py-1">
                                <dt class="font-bold text-slate-700">المتبقي</dt>
                                <dd class="font-extrabold" :class="previewBooking.remaining_amount > 0 ? 'text-red-700' : 'text-emerald-800'" dir="ltr">
                                    {{ money(previewBooking.remaining_amount) }}
                                </dd>
                            </div>
                            <div class="flex justify-between gap-3 border-b border-slate-100 py-1">
                                <dt class="font-bold text-slate-700">المسترجع</dt>
                                <dd class="font-bold" :class="previewBooking.refunded_amount > 0 ? 'text-red-700' : 'text-slate-600'" dir="ltr">
                                    {{ money(previewBooking.refunded_amount) }}
                                </dd>
                            </div>
                        </dl>

                        <!-- المقبوض موزّعًا على طرقه -->
                        <div class="mt-2 flex flex-wrap gap-1.5">
                            <span
                                v-for="m in methods" :key="m.key"
                                class="rounded-lg px-2 py-1 text-xs font-bold"
                                :class="previewBooking.paid_by_method[m.key] > 0 ? 'bg-emerald-100 text-emerald-800' : 'bg-slate-100 text-slate-600'"
                            >
                                {{ m.label }}: <span dir="ltr">{{ money(previewBooking.paid_by_method[m.key] ?? 0) }}</span>
                            </span>
                        </div>
                    </section>

                    <section v-if="previewBooking.notes">
                        <h3 class="mb-2 text-xs font-extrabold uppercase text-slate-600">الملاحظات</h3>
                        <p class="whitespace-pre-wrap rounded-xl bg-amber-50 px-3 py-2 text-sm font-medium leading-relaxed text-slate-900">
                            {{ previewBooking.notes }}
                        </p>
                    </section>
                </div>

                <!-- مستندات الحجز — تُفتح من المعاينة مباشرة -->
                <div class="flex flex-wrap items-center justify-between gap-2 border-t border-slate-200 bg-slate-50 px-6 py-3">
                    <div class="flex flex-wrap gap-2">
                        <Link
                            :href="`/admin/bookings/${previewBooking.id}/invoice`"
                            class="inline-flex items-center gap-1.5 rounded-md bg-emerald-600 px-3 py-2 text-sm font-bold text-white hover:bg-emerald-700"
                        >
                            <FileText class="h-4 w-4" /> الفاتورة
                        </Link>
                        <Link
                            v-if="previewBooking.has_payments"
                            :href="`/admin/bookings/${previewBooking.id}/bond`"
                            class="inline-flex items-center gap-1.5 rounded-md bg-slate-800 px-3 py-2 text-sm font-bold text-white hover:bg-slate-900"
                        >
                            <Receipt class="h-4 w-4" /> السند
                        </Link>
                        <Link
                            v-if="previewBooking.contract"
                            :href="`/admin/contracts/${previewBooking.contract.id}`"
                            class="inline-flex items-center gap-1.5 rounded-md bg-red-600 px-3 py-2 text-sm font-bold text-white hover:bg-red-700"
                        >
                            <FileSignature class="h-4 w-4" /> العقد
                        </Link>
                    </div>
                    <Link
                        v-if="can('bookings.edit')"
                        :href="`/admin/bookings/halls/${previewBooking.id}/edit`"
                        class="inline-flex items-center gap-1.5 rounded-md border border-slate-300 bg-white px-3 py-2 text-sm font-bold text-slate-800 hover:bg-slate-100"
                    >
                        <Pencil class="h-4 w-4" /> تعديل الحجز
                    </Link>
                </div>
            </div>
        </div>

        <!-- عرض وتعديل الملاحظات -->
        <div v-if="notesBooking" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4" @click.self="notesBooking = null">
            <div class="w-full max-w-lg rounded-2xl bg-white shadow-2xl">
                <div class="flex items-center justify-between border-b border-slate-100 px-6 py-4">
                    <div>
                        <h2 class="text-lg font-extrabold text-slate-900">عرض وتعديل الملاحظات</h2>
                        <p class="text-xs font-medium text-slate-500">
                            <span dir="ltr">{{ notesBooking.reference }}</span> · {{ notesBooking.unit.name }} · {{ notesBooking.client?.name ?? 'بلا عميل' }}
                        </p>
                    </div>
                    <button type="button" @click="notesBooking = null" class="rounded-lg p-1 text-slate-400 hover:bg-slate-100"><X class="h-5 w-5" /></button>
                </div>

                <form @submit.prevent="saveNotes" class="px-6 py-4">
                    <label class="mb-1 block text-sm font-bold text-slate-700">الملاحظات</label>
                    <textarea
                        v-model="notesForm.notes" rows="6"
                        placeholder="طلبات العميل، تنبيهات المناوبة، أي شيء يخصّ هذا الحجز…"
                        class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm focus:border-blue-400 focus:outline-none focus:ring-2 focus:ring-blue-100"
                    ></textarea>
                    <p v-if="notesForm.errors.notes" class="mt-1 text-xs text-red-500">{{ notesForm.errors.notes }}</p>

                    <div class="mt-4 flex justify-end gap-2">
                        <button type="button" @click="notesBooking = null" class="rounded-xl border border-slate-200 bg-white px-4 py-2 text-sm font-bold text-slate-600 hover:bg-slate-50">إلغاء</button>
                        <button type="submit" :disabled="notesForm.processing" class="rounded-md bg-blue-600 px-5 py-2 text-sm font-bold text-white shadow-sm transition hover:bg-blue-700 disabled:opacity-60">حفظ الملاحظات</button>
                    </div>
                </form>
            </div>
        </div>

        <!-- الدفعات والعربون -->
        <div v-if="payBooking" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4" @click.self="payBooking = null">
            <div class="flex max-h-[92vh] w-full max-w-3xl flex-col rounded-2xl bg-white shadow-2xl">
                <div class="flex items-center justify-between border-b border-slate-100 px-6 py-4">
                    <div>
                        <h2 class="text-lg font-extrabold text-slate-900">الدفعات — <span dir="ltr">{{ payBooking.reference }}</span></h2>
                        <p class="text-xs font-medium text-slate-500">{{ payBooking.unit.name }} · {{ payBooking.client?.name ?? 'بلا عميل' }}</p>
                    </div>
                    <button type="button" @click="payBooking = null" class="text-slate-400 hover:text-slate-600"><X class="h-5 w-5" /></button>
                </div>

                <div class="min-h-0 flex-1 overflow-y-auto px-6 py-4">
                    <!-- الملخّص المالي -->
                    <div v-if="paySummary" class="mb-4 grid grid-cols-2 gap-2 sm:grid-cols-4">
                        <div class="rounded-xl bg-slate-50 p-3 text-center">
                            <div class="text-[11px] font-bold text-slate-500">الإجمالي</div>
                            <div class="text-base font-extrabold text-slate-800">{{ money(paySummary.total_amount) }}</div>
                        </div>
                        <div class="rounded-xl p-3 text-center" :class="paySummary.is_deposit_settled ? 'bg-emerald-50' : 'bg-amber-50'">
                            <div class="text-[11px] font-bold" :class="paySummary.is_deposit_settled ? 'text-emerald-600' : 'text-amber-600'">العربون المطلوب</div>
                            <div class="text-base font-extrabold" :class="paySummary.is_deposit_settled ? 'text-emerald-700' : 'text-amber-700'">{{ money(paySummary.deposit_amount) }}</div>
                        </div>
                        <div class="rounded-xl bg-sky-50 p-3 text-center">
                            <div class="text-[11px] font-bold text-sky-600">المسدَّد</div>
                            <div class="text-base font-extrabold text-sky-700">{{ money(paySummary.paid_amount) }}</div>
                        </div>
                        <div class="rounded-xl p-3 text-center" :class="paySummary.is_fully_paid ? 'bg-emerald-50' : 'bg-red-50'">
                            <div class="text-[11px] font-bold" :class="paySummary.is_fully_paid ? 'text-emerald-600' : 'text-red-600'">المتبقي</div>
                            <div class="text-base font-extrabold" :class="paySummary.is_fully_paid ? 'text-emerald-700' : 'text-red-700'">{{ money(paySummary.remaining_amount) }}</div>
                        </div>
                    </div>

                    <div class="grid gap-4 lg:grid-cols-[300px_1fr]">
                        <!-- تسجيل دفعة -->
                        <form v-if="can('bookings.edit')" @submit.prevent="submitPayment" class="space-y-2.5 rounded-xl border border-slate-200 bg-slate-50 p-3">
                            <h3 class="text-sm font-extrabold text-slate-800">تسجيل دفعة</h3>

                            <div class="grid grid-cols-3 gap-1">
                                <button v-for="t in [['deposit','عربون'],['payment','دفعة'],['refund','استرداد']]" :key="t[0]"
                                    type="button" @click="payForm.type = t[0]"
                                    class="rounded-lg py-1.5 text-[11px] font-bold transition"
                                    :class="payForm.type === t[0] ? (t[0] === 'refund' ? 'bg-red-500 text-white' : 'bg-emerald-600 text-white') : 'bg-white text-slate-600 ring-1 ring-slate-200'"
                                >{{ t[1] }}</button>
                            </div>

                            <div>
                                <label class="mb-1 block text-[11px] font-bold text-slate-600">المبلغ</label>
                                <input v-model.number="payForm.amount" type="number" min="0.01" step="0.01" class="w-full rounded-lg border border-slate-200 px-2.5 py-2 text-sm" />
                                <p v-if="payForm.errors.amount" class="mt-1 text-[11px] text-red-500">{{ payForm.errors.amount }}</p>
                            </div>

                            <div class="grid grid-cols-2 gap-2">
                                <div>
                                    <label class="mb-1 block text-[11px] font-bold text-slate-600">الطريقة</label>
                                    <select v-model="payForm.payment_method_id" class="w-full rounded-lg border border-slate-200 px-2 py-2 text-xs">
                                        <option v-for="m in meta.payment_methods" :key="m.id" :value="m.id">{{ m.label }}</option>
                                    </select>
                                </div>
                                <div>
                                    <label class="mb-1 block text-[11px] font-bold text-slate-600">التاريخ</label>
                                    <input v-model="payForm.paid_on" type="date" class="w-full rounded-lg border border-slate-200 px-2 py-2 text-xs" />
                                </div>
                            </div>

                            <div>
                                <label class="mb-1 block text-[11px] font-bold text-slate-600">مرجع العملية</label>
                                <input v-model="payForm.reference" dir="ltr" class="w-full rounded-lg border border-slate-200 px-2.5 py-2 text-xs" />
                            </div>

                            <label v-if="payBooking.client?.mobile && payForm.type !== 'refund'" class="flex cursor-pointer items-center gap-2 text-[11px] font-bold text-slate-700">
                                <input type="checkbox" v-model="payForm.notify" class="h-3.5 w-3.5 rounded border-slate-300 text-emerald-600" />
                                إشعار العميل على واتساب
                            </label>

                            <button type="submit" :disabled="payForm.processing || payForm.amount <= 0" class="w-full rounded-md bg-blue-600 py-2 text-sm font-bold text-white hover:bg-blue-700 disabled:opacity-50">
                                تسجيل الدفعة
                            </button>

                            <p class="text-[10px] font-medium leading-5 text-slate-500">
                                العربون يُقيَّد التزامًا (إيراد غير مكتسب) لا إيرادًا، ويتحوّل إلى إيراد عند إنهاء الحجز.
                            </p>
                        </form>

                        <!-- سجل الدفعات -->
                        <div>
                            <h3 class="mb-2 text-sm font-extrabold text-slate-800">سجل الدفعات</h3>

                            <div v-if="payLoading" class="flex items-center gap-2 py-8 text-sm font-bold text-slate-400">
                                <Loader2 class="h-4 w-4 animate-spin" /> جارٍ التحميل…
                            </div>

                            <table v-else-if="payments.length" class="w-full text-xs">
                                <thead class="bg-slate-50">
                                    <tr>
                                        <th class="px-2 py-2 text-right font-extrabold text-slate-600">النوع</th>
                                        <th class="px-2 py-2 text-right font-extrabold text-slate-600">التاريخ</th>
                                        <th class="px-2 py-2 text-right font-extrabold text-slate-600">الطريقة</th>
                                        <th class="px-2 py-2 text-left font-extrabold text-slate-600">المبلغ</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="p in payments" :key="p.id" class="border-t border-slate-100">
                                        <td class="px-2 py-2">
                                            <span class="rounded px-1.5 py-0.5 text-[10px] font-bold" :class="p.type === 'refund' ? 'bg-red-100 text-red-700' : p.type === 'deposit' ? 'bg-amber-100 text-amber-700' : 'bg-emerald-100 text-emerald-700'">
                                                {{ p.type_label }}
                                            </span>
                                        </td>
                                        <td class="px-2 py-2 text-slate-600" dir="ltr">{{ p.paid_on }}</td>
                                        <td class="px-2 py-2 text-slate-600">{{ p.method_label }}</td>
                                        <td class="px-2 py-2 text-left font-extrabold" :class="p.signed_amount < 0 ? 'text-red-600' : 'text-slate-800'" dir="ltr">
                                            {{ p.signed_amount < 0 ? '−' : '' }}{{ money(p.amount) }}
                                        </td>
                                    </tr>
                                </tbody>
                            </table>

                            <p v-else class="rounded-xl bg-slate-50 py-8 text-center text-xs font-medium text-slate-400">لا دفعات بعد</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>

    </AppLayout>
</template>
