<script setup lang="ts">
import { StatPill, TableActionButton } from '@/components/data-table';
import { usePermissions } from '@/composables/usePermissions';
import AppLayout from '@/layouts/AppLayout.vue';
import { isClosedStatus, statusChipClass } from '@/lib/bookingStatus';
import { formatTime12, todayString } from '@/lib/dates';
import { toHijri } from '@/lib/hijri';
import { type BreadcrumbItem, type PaymentMethodOption } from '@/types';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import {
    Bell,
    CalendarClock,
    Check,
    Eye,
    FileSignature,
    FileText,
    Loader2,
    LogIn,
    LogOut,
    Moon,
    Pencil,
    Plus,
    Receipt,
    Search,
    Trash2,
    Wallet,
    X,
} from 'lucide-vue-next';
import { computed, ref } from 'vue';

interface SectionOption {
    id: number;
    name: string;
    gender: string;
}
interface UnitOption {
    id: number;
    name: string;
    code: string;
    type: string;
    bookable_mode: 'whole' | 'sections' | 'both';
    privacy_mode: 'open' | 'exclusive';
    sections: SectionOption[];
}

interface Booking {
    id: number;
    reference: string;
    unit: { id: number; name: string; code: string };
    client: { id: number; name: string; mobile: string | null } | null;
    scope: 'whole' | 'sections';
    sections: string[];
    section_ids: number[];
    period: string;
    period_label: string;
    schedule_label: string;
    booking_date: string;
    check_out_date: string | null;
    nights: number | null;
    status: string;
    status_label: string;
    status_color: string;
    is_online: boolean;
    total_amount: number;
    deposit_amount: number;
    paid_amount: number;
    remaining_amount: number;
    is_deposit_settled: boolean;
    /** Agreed against actually in hand — the second is what must go back. */
    security_deposit_amount: number;
    security_held: number;
    guests_count: number | null;
    notes: string | null;
    /** The contract issued from this stay, if one was generated. */
    contract: { id: number; number: string } | null;
    has_payments: boolean;
    subtotal_amount: number;
    discount_amount: number;
    addons_amount: number;
    tax_amount: number;
    refunded_amount: number;
    payment_status: string;
    /** {payment method id: amount taken by it} */
    paid_by_method: Record<number, number>;
}

interface Payment {
    id: number;
    type: string;
    type_label: string;
    method_label: string;
    amount: number;
    signed_amount: number;
    paid_on: string;
    reference: string | null;
    notes: string | null;
    received_by: string | null;
}
interface PaymentSummary {
    total_amount: number;
    deposit_amount: number;
    paid_amount: number;
    remaining_amount: number;
    is_deposit_settled: boolean;
    is_fully_paid: boolean;
    security_deposit_amount: number;
    security_held: number;
}

const props = defineProps<{
    bookings: { data: Booking[]; links: { url: string | null; label: string; active: boolean }[] };
    filters: { status: string | null; unit_id: number | null; from: string | null; to: string | null; search: string | null };
    units: UnitOption[];
    meta: {
        statuses: { key: string; label: string; color: string }[];
        periods: { key: string; label: string; start: string; end: string }[];
        stay: { check_in_time: string; check_out_time: string; max_nights: number };
        payment_methods: PaymentMethodOption[];
    };
    stats: { total: number; tentative: number; pending_deposit: number; confirmed: number; unpaid: number };
}>();

const { can } = usePermissions();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'لوحة التحكم', href: '/admin' },
    { title: 'حجوزات الشاليهات', href: '/admin/bookings/chalets' },
];

const money = (n: number) => new Intl.NumberFormat('ar-SA-u-nu-latn', { maximumFractionDigits: 2 }).format(n ?? 0);

/**
 * الطرق التي قُبض بها هذا الحجز فعلًا، وما قُبض بكل واحدة.
 *
 * الطريقة التي لم يُقبض بها شيء لا تُعرض: الصف يقول ما جرى لا ما كان
 * ممكنًا، وسردُ الطرق كلها بأصفارها يُغرق الرقم الذي يُقرأ.
 */
const paidMethods = (b: Booking) =>
    props.meta.payment_methods
        .map((m) => ({ label: m.label, amount: Number(b.paid_by_method?.[m.id] ?? 0) }))
        .filter((m) => m.amount > 0);

const today = todayString();

// ── الفلاتر ─────────────────────────────────────────────────
const filters = ref({ ...props.filters });
const applyFilters = () => router.get('/admin/bookings/chalets', filters.value, { preserveState: true, replace: true });
const resetFilters = () => {
    filters.value = { status: null, unit_id: null, from: null, to: null, search: null };
    applyFilters();
};

// ── الدفعات والعربون ────────────────────────────────────────
const payBooking = ref<Booking | null>(null);
const payments = ref<Payment[]>([]);
const paySummary = ref<PaymentSummary | null>(null);
const payLoading = ref(false);

const payForm = useForm({
    type: 'deposit',
    payment_method_id: props.meta.payment_methods[0]?.id ?? (null as number | null),
    amount: 0,
    paid_on: today,
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

/**
 * Security movements — a second row of buttons, because they are not part of
 * the price.
 *
 * They share this panel because one till and one receipt book serve both, but
 * they answer a different question: how much of the guest's own money is still
 * being held, not how much of the price is still owed.
 */
const SECURITY_TYPES = ['security_deposit', 'security_refund', 'security_forfeit'];

const isSecurity = computed(() => SECURITY_TYPES.includes(payForm.type));

/** What is held right now — from the summary once it lands, else from the row. */
const securityHeld = computed(() => paySummary.value?.security_held ?? payBooking.value?.security_held ?? 0);

const securityAgreed = computed(() => paySummary.value?.security_deposit_amount ?? payBooking.value?.security_deposit_amount ?? 0);

/**
 * The amount each type opens with: the rest of what is due, or the rest of
 * what is held. A refund typed from memory is how a guest gets back more than
 * they left.
 */
const suggestedAmount = (type: string): number => {
    const b = payBooking.value;

    if (!b) return 0;

    const paid = paySummary.value?.paid_amount ?? b.paid_amount;
    const remaining = paySummary.value?.remaining_amount ?? b.remaining_amount;

    switch (type) {
        case 'security_deposit':
            return Math.max(0, securityAgreed.value - securityHeld.value);
        case 'security_refund':
        case 'security_forfeit':
            return securityHeld.value;
        case 'refund':
            return 0;
        case 'deposit':
            return Math.max(0, Math.min((paySummary.value?.deposit_amount ?? b.deposit_amount) - paid, remaining));
        default:
            return remaining;
    }
};

const setPayType = (type: string) => {
    payForm.type = type;
    payForm.amount = suggestedAmount(type);
};

const openPayments = (b: Booking) => {
    payBooking.value = b;
    payments.value = [];
    paySummary.value = null;
    payForm.reset();
    payForm.clearErrors();
    payForm.type = b.is_deposit_settled ? 'payment' : 'deposit';
    payForm.amount = suggestedAmount(payForm.type);
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

const sendReminder = (b: Booking) => {
    if (confirm(`إرسال تذكير بموعد الدخول على واتساب ${b.client?.mobile ?? ''}؟`)) {
        router.post(`/admin/bookings/${b.id}/remind`, {}, { preserveScroll: true });
    }
};

/** تذكير بالمبلغ المتبقي — غير تذكير الموعد، ولا يُعرض لحجزٍ مسدَّد. */
const sendBalanceReminder = (b: Booking) => {
    if (confirm(`إرسال تذكير بالمبلغ المتبقي على واتساب ${b.client?.mobile ?? ''}؟`)) {
        router.post(`/admin/bookings/${b.id}/remind-balance`, {}, { preserveScroll: true });
    }
};

// الإلغاء والتأجيل يخرجان الحجز من مساره، فيُسأل عن السبب ليبقى في سجل التدقيق.
const REASON_PROMPTS: Record<string, string> = {
    cancelled: 'سبب الإلغاء (اختياري):',
    postponed: 'سبب التأجيل (اختياري):',
};

const changeStatus = (b: Booking, status: string) => {
    const ask = REASON_PROMPTS[status];
    const reason = ask ? (prompt(ask) ?? '') : '';

    // إشعار الإلغاء يُسأل عنه ولا يُرسل تلقائيًا: الإلغاء قد يكون تصحيحًا
    // لخطأ إدخال، ورسالةٌ تخرج حينها تُقلق عميلًا لم يُلغَ حجزه.
    const notify = status === 'cancelled' && b.client?.mobile ? confirm(`إبلاغ العميل بالإلغاء على واتساب ${b.client.mobile}؟`) : false;

    router.patch(`/admin/bookings/${b.id}/status`, { status, reason, notify }, { preserveScroll: true });
};

const destroy = (b: Booking) => {
    if (confirm(`حذف الحجز ${b.reference}؟`)) {
        router.delete(`/admin/bookings/${b.id}`, { preserveScroll: true });
    }
};

const colorClass = statusChipClass;

const payStatusClass = (status: string) =>
    ({
        مسدّدة: 'bg-emerald-100 text-emerald-800',
        'مسدّدة جزئيًا': 'bg-amber-100 text-amber-800',
        'غير مسدّدة': 'bg-red-100 text-red-800',
    })[status] ?? 'bg-slate-200 text-slate-800';

// Preview stands in for the columns the table has no room for.
const previewBooking = ref<Booking | null>(null);

const openPreview = (b: Booking) => (previewBooking.value = b);

/** Contracts are generated from the booking itself, whatever its unit type. */
const generateContract = (b: Booking) => {
    if (confirm(`توليد عقد للحجز ${b.reference}؟`)) {
        router.post('/admin/contracts', { booking_id: b.id }, { preserveScroll: true });
    }
};
</script>

<template>
    <Head title="حجوزات الشاليهات" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="min-h-full space-y-5 bg-slate-100 p-5">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h1 class="text-2xl font-extrabold text-slate-900">حجوزات الشاليهات</h1>
                    <p class="mt-1 text-sm font-medium text-slate-600">
                        إقامة بالليالي — دخول {{ formatTime12(meta.stay.check_in_time) }} وخروج {{ formatTime12(meta.stay.check_out_time) }}
                    </p>
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    <Link
                        v-if="can('chalet_bookings.create')"
                        href="/admin/bookings/chalets/create"
                        class="inline-flex items-center gap-1.5 rounded-md bg-teal-600 px-4 py-2 text-sm font-bold text-white shadow-sm hover:bg-teal-700"
                    >
                        <Plus class="h-4 w-4" /> حجز جديد
                    </Link>
                </div>
            </div>

            <div class="grid grid-cols-2 gap-3 sm:grid-cols-5">
                <StatPill label="إجمالي الإقامات" :value="stats.total" variant="primary" />
                <StatPill label="حجز مبدئي" :value="stats.tentative" variant="warning" />
                <StatPill label="بانتظار العربون" :value="stats.pending_deposit" variant="dark" />
                <StatPill label="حجز مؤكد" :value="stats.confirmed" variant="success" />
                <StatPill label="عليه متبقٍ" :value="stats.unpaid" variant="danger" />
            </div>

            <!-- الفلاتر -->
            <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
                <div class="grid gap-2 sm:grid-cols-2 lg:grid-cols-6">
                    <div class="lg:col-span-2">
                        <div class="relative">
                            <Search class="absolute top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400 ltr:left-3 rtl:right-3" />
                            <input
                                v-model="filters.search"
                                @keyup.enter="applyFilters"
                                placeholder="رقم الحجز أو العميل"
                                class="w-full rounded-xl border border-slate-200 py-2.5 text-sm ltr:pl-9 ltr:pr-3 rtl:pl-3 rtl:pr-9"
                            />
                        </div>
                    </div>
                    <select v-model="filters.status" @change="applyFilters" class="rounded-xl border border-slate-200 px-3 py-2.5 text-sm">
                        <option :value="null">كل الحالات</option>
                        <option v-for="s in meta.statuses" :key="s.key" :value="s.key">{{ s.label }}</option>
                    </select>
                    <select v-model="filters.unit_id" @change="applyFilters" class="rounded-xl border border-slate-200 px-3 py-2.5 text-sm">
                        <option :value="null">كل الشاليهات</option>
                        <option v-for="u in units" :key="u.id" :value="u.id">{{ u.name }}</option>
                    </select>
                    <input
                        v-model="filters.from"
                        @change="applyFilters"
                        type="date"
                        title="دخول من"
                        class="rounded-xl border border-slate-200 px-3 py-2.5 text-sm"
                    />
                    <input
                        v-model="filters.to"
                        @change="applyFilters"
                        type="date"
                        title="دخول حتى"
                        class="rounded-xl border border-slate-200 px-3 py-2.5 text-sm"
                    />
                </div>
                <button type="button" @click="resetFilters" class="mt-2 text-[11px] font-bold text-slate-500 hover:text-slate-700">
                    إعادة ضبط الفلاتر
                </button>
            </div>

            <!-- الجدول -->
            <div class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="bg-slate-100">
                            <tr>
                                <th class="px-4 py-3 text-right text-xs font-extrabold text-[#1e3a8a]">رقم الحجز</th>
                                <th class="px-4 py-3 text-right text-xs font-extrabold text-[#1e3a8a]">الشاليه والنطاق</th>
                                <th class="px-4 py-3 text-right text-xs font-extrabold text-[#1e3a8a]">النزيل</th>
                                <th class="px-4 py-3 text-right text-xs font-extrabold text-[#1e3a8a]">الدخول والخروج</th>
                                <th class="px-4 py-3 text-center text-xs font-extrabold text-[#1e3a8a]">الحالة</th>
                                <th class="whitespace-nowrap px-4 py-3 text-center text-xs font-extrabold text-[#1e3a8a]">المبلغ</th>
                                <th class="whitespace-nowrap px-4 py-3 text-center text-xs font-extrabold text-[#1e3a8a]">المدفوع</th>
                                <th class="whitespace-nowrap px-4 py-3 text-center text-xs font-extrabold text-[#1e3a8a]">المتبقي</th>
                                <th class="px-4 py-3 text-center text-xs font-extrabold text-[#1e3a8a]">إجراءات</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="b in bookings.data" :key="b.id" class="border-t border-slate-100 transition hover:bg-slate-50">
                                <td class="px-4 py-3 font-extrabold text-slate-800" dir="ltr">
                                    {{ b.reference }}
                                    <span
                                        v-if="b.is_online"
                                        title="حجز وصل من الموقع"
                                        class="ms-1 rounded bg-indigo-100 px-1.5 py-0.5 text-[10px] font-bold text-indigo-700"
                                        >أونلاين</span
                                    >
                                </td>
                                <td class="px-4 py-3">
                                    <div class="font-bold text-slate-800">{{ b.unit.name }}</div>
                                    <div class="mt-0.5 text-[11px] font-medium text-slate-500">
                                        <span v-if="b.scope === 'whole'" class="rounded bg-violet-100 px-1.5 py-0.5 font-bold text-violet-700"
                                            >الشاليه كاملًا</span
                                        >
                                        <span v-else class="rounded bg-sky-100 px-1.5 py-0.5 font-bold text-sky-700">{{
                                            b.sections.join('، ')
                                        }}</span>
                                    </div>
                                </td>
                                <td class="px-4 py-3">
                                    <div class="font-bold text-slate-800">{{ b.client?.name ?? '—' }}</div>
                                    <div class="text-[11px] text-slate-500" dir="ltr">{{ b.client?.mobile ?? '' }}</div>
                                    <div v-if="b.guests_count" class="mt-0.5 text-[10px] font-bold text-slate-500">{{ b.guests_count }} نزيل</div>
                                </td>
                                <!-- الإقامة صفٌّ واحد لا تاريخان منفصلان: المدة هي ما يهمّ الموظف -->
                                <td class="px-4 py-3">
                                    <div class="flex items-center gap-1.5 text-xs font-bold text-slate-800">
                                        <LogIn class="h-3.5 w-3.5 shrink-0 text-emerald-500" />
                                        <span dir="ltr">{{ b.booking_date }}</span>
                                    </div>
                                    <div class="mt-0.5 flex items-center gap-1.5 text-xs font-bold text-slate-800">
                                        <LogOut class="h-3.5 w-3.5 shrink-0 text-rose-500" />
                                        <span dir="ltr">{{ b.check_out_date ?? '—' }}</span>
                                    </div>
                                    <div
                                        class="mt-1 inline-flex items-center gap-1 rounded bg-teal-50 px-1.5 py-0.5 text-[10px] font-extrabold text-teal-700"
                                    >
                                        <Moon class="h-3 w-3" /> {{ b.schedule_label }}
                                    </div>
                                </td>
                                <td class="px-4 py-3 text-center">
                                    <span class="rounded-md px-2 py-0.5 text-[11px] font-bold" :class="colorClass(b.status_color)">{{
                                        b.status_label
                                    }}</span>
                                </td>
<!-- المال ثلاثة أعمدة كسجل القاعات: ما عليه، وما قبض منه، وما بقي -->
                                <td class="whitespace-nowrap px-4 py-3 text-center font-extrabold text-slate-800" dir="ltr">
                                    {{ money(b.total_amount) }}
                                </td>
                                <td
                                    class="whitespace-nowrap px-4 py-3 text-center font-bold"
                                    :class="b.paid_amount > 0 ? 'text-emerald-600' : 'text-slate-400'"
                                >
                                    <span dir="ltr">{{ money(b.paid_amount) }}</span>
                                    <div v-if="!b.is_deposit_settled && b.deposit_amount > 0" class="mt-0.5 text-[10px] font-bold text-amber-600">
                                        العربون {{ money(b.deposit_amount) }} غير مستوفى
                                    </div>
                                    <!--
                                        وبمَ قُبض: سؤالٌ يُسأل عند التسليم وعند جرد الصندوق
                                        آخر اليوم، وكان جوابه لا يُقرأ إلا بفتح لوحة الدفعات
                                        حجزًا حجزًا.
                                    -->
                                    <div v-if="paidMethods(b).length" class="mt-1 flex flex-wrap justify-center gap-1">
                                        <span
                                            v-for="m in paidMethods(b)" :key="m.label"
                                            class="inline-flex items-center gap-1 rounded bg-slate-100 px-1.5 py-0.5 text-[10px] font-bold text-slate-600"
                                        >
                                            {{ m.label }} <span dir="ltr">{{ money(m.amount) }}</span>
                                        </span>
                                    </div>
                                </td>
                                <td
                                    class="whitespace-nowrap px-4 py-3 text-center font-extrabold"
                                    :class="b.remaining_amount > 0 ? 'text-red-600' : 'text-emerald-600'"
                                    dir="ltr"
                                >
                                    {{ money(b.remaining_amount) }}
                                </td>
                                <td class="px-4 py-3">
                                    <div class="flex items-center justify-center gap-1">
                                        <!-- المعاينة أولى الأزرار: هي بديل الأعمدة المخفيّة -->
                                        <TableActionButton variant="view" :icon="Eye" title="معاينة كل التفاصيل" @click="openPreview(b)" />

                                        <TableActionButton
                                            v-if="can('chalet_bookings.edit')"
                                            variant="primary"
                                            :icon="Wallet"
                                            title="الدفعات والعربون"
                                            @click="openPayments(b)"
                                        />

                                        <!-- السند لا يُحرَّر على حجز لم يُقبض منه شيء -->
                                        <TableActionButton
                                            v-if="b.has_payments"
                                            variant="dark"
                                            :icon="Receipt"
                                            title="السند"
                                            @click="router.visit(`/admin/bookings/${b.id}/bond`)"
                                        />

                                        <!-- الفاتورة تُحرَّر على الحجز نفسه، فتصلح قبل القبض وبعده -->
                                        <TableActionButton
                                            variant="success"
                                            :icon="FileText"
                                            title="الفاتورة"
                                            @click="router.visit(`/admin/bookings/${b.id}/invoice`)"
                                        />

                                        <TableActionButton
                                            v-if="b.contract"
                                            variant="danger"
                                            :icon="FileSignature"
                                            :title="`العقد ${b.contract.number}`"
                                            @click="router.visit(`/admin/contracts/${b.contract.id}`)"
                                        />
                                        <TableActionButton
                                            v-else-if="can('contracts.create')"
                                            variant="muted"
                                            :icon="FileSignature"
                                            title="توليد العقد"
                                            @click="generateContract(b)"
                                        />
                                        <TableActionButton
                                            v-if="can('whatsapp.send') && b.client?.mobile && !isClosedStatus(b.status)"
                                            variant="view"
                                            :icon="Bell"
                                            title="تذكير واتساب"
                                            @click="sendReminder(b)"
                                        />
                                        <TableActionButton
                                            v-if="can('whatsapp.send') && b.client?.mobile && b.remaining_amount > 0 && !isClosedStatus(b.status)"
                                            variant="view"
                                            :icon="Wallet"
                                            title="تذكير بالمتبقي"
                                            @click="sendBalanceReminder(b)"
                                        />

                                        <!-- خطوة واحدة تظهر في كل مرة: الحالة الحالية تحدّد التالية في المسار -->
                                        <TableActionButton
                                            v-if="can('chalet_bookings.edit') && ['tentative', 'pending_deposit'].includes(b.status)"
                                            variant="primary"
                                            :icon="Check"
                                            title="تأكيد الحجز"
                                            @click="changeStatus(b, 'confirmed')"
                                        />
                                        <TableActionButton
                                            v-if="can('chalet_bookings.edit') && !isClosedStatus(b.status)"
                                            variant="warning"
                                            :icon="X"
                                            title="إلغاء"
                                            @click="changeStatus(b, 'cancelled')"
                                        />

                                        <!-- التعديل والحذف داخل قائمة النقاط الثلاث: إجراءان يغيّران الحجز نفسه،
                                             فإخفاؤهما خلف نقرة يقلّل الضغط الخاطئ ويُهدّئ صفّ الإجراءات. -->
                                        <DropdownMenu v-if="can('chalet_bookings.edit') || can('chalet_bookings.delete')">
                                            <DropdownMenuTrigger :as-child="true">
                                                <button
                                                    type="button"
                                                    title="خيارات"
                                                    class="inline-flex h-9 w-9 items-center justify-center rounded-lg bg-slate-200 text-slate-700 shadow-sm transition hover:bg-slate-300 data-[state=open]:bg-slate-300"
                                                >
                                                    <MoreVertical class="h-4 w-4" />
                                                </button>
                                            </DropdownMenuTrigger>
                                            <DropdownMenuContent align="end" class="w-40">
                                                <DropdownMenuItem
                                                    v-if="can('chalet_bookings.edit')"
                                                    class="cursor-pointer font-bold text-slate-700"
                                                    @select="router.visit(`/admin/bookings/chalets/${b.id}/edit`)"
                                                >
                                                    <Pencil class="h-4 w-4 text-cyan-600" /> تعديل
                                                </DropdownMenuItem>
                                                <DropdownMenuSeparator v-if="can('chalet_bookings.edit') && can('chalet_bookings.delete')" />
                                                <DropdownMenuItem
                                                    v-if="can('chalet_bookings.delete')"
                                                    class="cursor-pointer font-bold text-red-600 focus:bg-red-50 focus:text-red-700"
                                                    @select="destroy(b)"
                                                >
                                                    <Trash2 class="h-4 w-4" /> حذف
                                                </DropdownMenuItem>
                                            </DropdownMenuContent>
                                        </DropdownMenu>
                                    </div>
                                </td>
                            </tr>
                            <tr v-if="!bookings.data.length">
                                <td colspan="9" class="px-4 py-10 text-center text-sm font-medium text-slate-500">لا توجد إقامات مطابقة</td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div v-if="bookings.links.length > 3" class="flex flex-wrap justify-center gap-1 border-t border-slate-100 p-3">
                    <Link
                        v-for="l in bookings.links"
                        :key="l.label"
                        :href="l.url ?? '#'"
                        :class="[
                            'rounded-lg px-3 py-1.5 text-xs font-bold',
                            l.active
                                ? 'bg-teal-600 text-white'
                                : l.url
                                  ? 'bg-white text-slate-600 ring-1 ring-slate-200 hover:bg-slate-50'
                                  : 'cursor-default text-slate-300',
                        ]"
                        v-html="l.label"
                    />
                </div>
            </div>
        </div>

        <!-- معاينة الإقامة — بديل الأعمدة التي لا يتّسع لها الجدول -->
        <div v-if="previewBooking" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4" @click.self="previewBooking = null">
            <div class="flex max-h-[92vh] w-full max-w-3xl flex-col rounded-2xl bg-white shadow-2xl">
                <div class="flex items-start justify-between gap-3 border-b border-slate-200 px-6 py-4">
                    <div>
                        <div class="flex flex-wrap items-center gap-2">
                            <h2 class="text-lg font-extrabold text-slate-900" dir="ltr">{{ previewBooking.reference }}</h2>
                            <span class="rounded-md px-2 py-0.5 text-xs font-bold" :class="colorClass(previewBooking.status_color)">
                                {{ previewBooking.status_label }}
                            </span>
                            <span class="rounded-md px-2 py-0.5 text-xs font-bold" :class="payStatusClass(previewBooking.payment_status)">
                                {{ previewBooking.payment_status }}
                            </span>
                        </div>
                        <p class="mt-1 text-xs font-bold text-slate-600">
                            {{ previewBooking.unit.name }} · {{ previewBooking.client?.name ?? 'بلا نزيل' }}
                        </p>
                    </div>
                    <button type="button" @click="previewBooking = null" class="rounded-lg p-1 text-slate-600 hover:bg-slate-100">
                        <X class="h-5 w-5" />
                    </button>
                </div>

                <div class="min-h-0 flex-1 space-y-5 overflow-y-auto px-6 py-4">
                    <section>
                        <h3 class="mb-2 text-xs font-extrabold text-slate-500">النزيل</h3>
                        <dl class="grid gap-x-6 gap-y-2 text-sm sm:grid-cols-2">
                            <div class="flex justify-between gap-2 border-b border-slate-100 pb-1">
                                <dt class="font-bold text-slate-600">الاسم</dt>
                                <dd class="font-extrabold text-slate-900">{{ previewBooking.client?.name ?? '—' }}</dd>
                            </div>
                            <div class="flex justify-between gap-2 border-b border-slate-100 pb-1">
                                <dt class="font-bold text-slate-600">الجوال</dt>
                                <dd class="font-bold text-slate-900" dir="ltr">{{ previewBooking.client?.mobile ?? '—' }}</dd>
                            </div>
                            <div class="flex justify-between gap-2 border-b border-slate-100 pb-1">
                                <dt class="font-bold text-slate-600">عدد النزلاء</dt>
                                <dd class="font-bold text-slate-900">{{ previewBooking.guests_count ?? '—' }}</dd>
                            </div>
                            <div class="flex justify-between gap-2 border-b border-slate-100 pb-1">
                                <dt class="font-bold text-slate-600">العقد</dt>
                                <dd class="font-bold text-slate-900" dir="ltr">{{ previewBooking.contract?.number ?? '—' }}</dd>
                            </div>
                        </dl>
                    </section>

                    <section>
                        <h3 class="mb-2 text-xs font-extrabold text-slate-500">الإقامة</h3>
                        <dl class="grid gap-x-6 gap-y-2 text-sm sm:grid-cols-2">
                            <div class="flex justify-between gap-2 border-b border-slate-100 pb-1">
                                <dt class="font-bold text-slate-600">الشاليه</dt>
                                <dd class="font-extrabold text-slate-900">
                                    {{ previewBooking.unit.name }} <span class="text-slate-600" dir="ltr">({{ previewBooking.unit.code }})</span>
                                </dd>
                            </div>
                            <div class="flex justify-between gap-2 border-b border-slate-100 pb-1">
                                <dt class="font-bold text-slate-600">النطاق</dt>
                                <dd class="font-bold text-slate-900">
                                    {{ previewBooking.scope === 'whole' ? 'الوحدة كاملة' : previewBooking.sections.join('، ') }}
                                </dd>
                            </div>
                            <div class="flex justify-between gap-2 border-b border-slate-100 pb-1">
                                <dt class="font-bold text-slate-600">الدخول</dt>
                                <dd class="text-left">
                                    <div class="font-bold text-slate-900" dir="ltr">{{ previewBooking.booking_date }}</div>
                                    <div class="text-xs font-bold text-emerald-800">{{ toHijri(previewBooking.booking_date) }}</div>
                                </dd>
                            </div>
                            <div v-if="previewBooking.check_out_date" class="flex justify-between gap-2 border-b border-slate-100 pb-1">
                                <dt class="font-bold text-slate-600">الخروج</dt>
                                <dd class="text-left">
                                    <div class="font-bold text-slate-900" dir="ltr">{{ previewBooking.check_out_date }}</div>
                                    <div class="text-xs font-bold text-emerald-800">{{ toHijri(previewBooking.check_out_date) }}</div>
                                </dd>
                            </div>
                            <div class="flex justify-between gap-2 border-b border-slate-100 pb-1">
                                <dt class="font-bold text-slate-600">المدة</dt>
                                <dd class="font-bold text-slate-900">{{ previewBooking.nights ?? '—' }} ليلة</dd>
                            </div>
                            <div class="flex justify-between gap-2 border-b border-slate-100 pb-1">
                                <dt class="font-bold text-slate-600">التوقيت</dt>
                                <dd class="font-bold text-slate-900">{{ previewBooking.schedule_label }}</dd>
                            </div>
                        </dl>
                    </section>

                    <section>
                        <h3 class="mb-2 text-xs font-extrabold text-slate-500">المال</h3>
                        <dl class="grid gap-x-6 gap-y-2 text-sm sm:grid-cols-2">
                            <div class="flex justify-between gap-2 border-b border-slate-100 pb-1">
                                <dt class="font-bold text-slate-600">مبلغ الإقامة</dt>
                                <dd class="font-bold text-slate-900" dir="ltr">{{ money(previewBooking.subtotal_amount) }}</dd>
                            </div>
                            <div class="flex justify-between gap-2 border-b border-slate-100 pb-1">
                                <dt class="font-bold text-slate-600">الخدمات الإضافية</dt>
                                <dd class="font-bold text-slate-900" dir="ltr">{{ money(previewBooking.addons_amount) }}</dd>
                            </div>
                            <div class="flex justify-between gap-2 border-b border-slate-100 pb-1">
                                <dt class="font-bold text-slate-600">الخصم</dt>
                                <dd class="font-bold text-amber-800" dir="ltr">{{ money(previewBooking.discount_amount) }}</dd>
                            </div>
                            <div class="flex justify-between gap-2 border-b border-slate-100 pb-1">
                                <dt class="font-bold text-slate-600">العربون</dt>
                                <dd class="font-bold" :class="previewBooking.is_deposit_settled ? 'text-slate-900' : 'text-amber-800'" dir="ltr">
                                    {{ money(previewBooking.deposit_amount) }}
                                </dd>
                            </div>
                            <div class="flex justify-between gap-2 border-b border-slate-100 pb-1">
                                <dt class="font-bold text-slate-600">الضريبة</dt>
                                <dd class="font-bold text-slate-900" dir="ltr">{{ money(previewBooking.tax_amount) }}</dd>
                            </div>
                            <div class="flex justify-between gap-2 border-b border-slate-100 pb-1">
                                <dt class="font-bold text-slate-600">الإجمالي</dt>
                                <dd class="font-extrabold text-slate-900" dir="ltr">{{ money(previewBooking.total_amount) }}</dd>
                            </div>
                            <div class="flex justify-between gap-2 border-b border-slate-100 pb-1">
                                <dt class="font-bold text-slate-600">المدفوع</dt>
                                <dd class="font-extrabold text-emerald-800" dir="ltr">{{ money(previewBooking.paid_amount) }}</dd>
                            </div>
                            <div class="flex justify-between gap-2 border-b border-slate-100 pb-1">
                                <dt class="font-bold text-slate-600">المتبقي</dt>
                                <dd
                                    class="font-extrabold"
                                    :class="previewBooking.remaining_amount > 0 ? 'text-red-700' : 'text-emerald-800'"
                                    dir="ltr"
                                >
                                    {{ money(previewBooking.remaining_amount) }}
                                </dd>
                            </div>
                            <div class="flex justify-between gap-2 border-b border-slate-100 pb-1">
                                <dt class="font-bold text-slate-600">المسترجع</dt>
                                <dd class="font-bold" :class="previewBooking.refunded_amount > 0 ? 'text-red-700' : 'text-slate-600'" dir="ltr">
                                    {{ money(previewBooking.refunded_amount) }}
                                </dd>
                            </div>
                        </dl>

                        <!-- المقبوض موزّعًا على طرقه — يقرأ الموظف منه ما دخل الصندوق وما دخل البنك -->
                        <div class="mt-2 flex flex-wrap gap-1.5">
                            <span
                                v-for="m in meta.payment_methods"
                                :key="m.id"
                                class="rounded-md px-2 py-0.5 text-[11px] font-bold"
                                :class="previewBooking.paid_by_method[m.id] > 0 ? 'bg-emerald-100 text-emerald-800' : 'bg-slate-100 text-slate-600'"
                            >
                                {{ m.label }}: <span dir="ltr">{{ money(previewBooking.paid_by_method[m.id] ?? 0) }}</span>
                            </span>
                        </div>
                    </section>

                    <section v-if="previewBooking.notes">
                        <h3 class="mb-2 text-xs font-extrabold text-slate-500">الملاحظات</h3>
                        <p class="whitespace-pre-wrap rounded-xl bg-amber-50 px-3 py-2 text-sm font-medium text-amber-900">
                            {{ previewBooking.notes }}
                        </p>
                    </section>
                </div>

                <!-- مستندات الإقامة — تُفتح من المعاينة مباشرة -->
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
                        v-if="can('chalet_bookings.edit')"
                        :href="`/admin/bookings/chalets/${previewBooking.id}/edit`"
                        class="inline-flex items-center gap-1.5 rounded-md border border-slate-300 bg-white px-3 py-2 text-sm font-bold text-slate-800 hover:bg-slate-100"
                    >
                        <Pencil class="h-4 w-4" /> تعديل الإقامة
                    </Link>
                </div>
            </div>
        </div>

        <!-- الدفعات والعربون -->
        <div v-if="payBooking" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4" @click.self="payBooking = null">
            <div class="flex max-h-[92vh] w-full max-w-3xl flex-col rounded-2xl bg-white shadow-2xl">
                <div class="flex items-center justify-between border-b border-slate-100 px-6 py-4">
                    <div>
                        <h2 class="text-lg font-extrabold text-slate-900">
                            الدفعات — <span dir="ltr">{{ payBooking.reference }}</span>
                        </h2>
                        <p class="text-xs font-medium text-slate-500">
                            {{ payBooking.unit.name }} · {{ payBooking.client?.name ?? 'بلا نزيل' }} · {{ payBooking.schedule_label }}
                        </p>
                    </div>
                    <button type="button" @click="payBooking = null" class="text-slate-400 hover:text-slate-600"><X class="h-5 w-5" /></button>
                </div>

                <div class="min-h-0 flex-1 overflow-y-auto px-6 py-4">
                    <div v-if="paySummary" class="mb-4 grid grid-cols-2 gap-2 sm:grid-cols-4">
                        <div class="rounded-xl bg-slate-50 p-3 text-center">
                            <div class="text-[11px] font-bold text-slate-500">الإجمالي</div>
                            <div class="text-base font-extrabold text-slate-800">{{ money(paySummary.total_amount) }}</div>
                        </div>
                        <div class="rounded-xl p-3 text-center" :class="paySummary.is_deposit_settled ? 'bg-emerald-50' : 'bg-amber-50'">
                            <div class="text-[11px] font-bold" :class="paySummary.is_deposit_settled ? 'text-emerald-600' : 'text-amber-600'">
                                العربون المطلوب
                            </div>
                            <div class="text-base font-extrabold" :class="paySummary.is_deposit_settled ? 'text-emerald-700' : 'text-amber-700'">
                                {{ money(paySummary.deposit_amount) }}
                            </div>
                        </div>
                        <div class="rounded-xl bg-sky-50 p-3 text-center">
                            <div class="text-[11px] font-bold text-sky-600">المسدَّد</div>
                            <div class="text-base font-extrabold text-sky-700">{{ money(paySummary.paid_amount) }}</div>
                        </div>
                        <div class="rounded-xl p-3 text-center" :class="paySummary.is_fully_paid ? 'bg-emerald-50' : 'bg-red-50'">
                            <div class="text-[11px] font-bold" :class="paySummary.is_fully_paid ? 'text-emerald-600' : 'text-red-600'">المتبقي</div>
                            <div class="text-base font-extrabold" :class="paySummary.is_fully_paid ? 'text-emerald-700' : 'text-red-700'">
                                {{ money(paySummary.remaining_amount) }}
                            </div>
                        </div>
                    </div>

                    <!-- Outside the four tiles on purpose: this figure is not added to them -->
                    <div
                        v-if="paySummary && (paySummary.security_deposit_amount > 0 || paySummary.security_held > 0)"
                        class="mb-4 flex flex-wrap items-center gap-x-4 gap-y-1 rounded-xl bg-indigo-50 px-3 py-2.5"
                    >
                        <span class="text-[11px] font-extrabold text-indigo-700">التأمين</span>
                        <span class="text-[11px] font-bold text-indigo-600">
                            المتفق عليه <span class="font-extrabold text-indigo-800">{{ money(paySummary.security_deposit_amount) }}</span>
                        </span>
                        <span class="text-[11px] font-bold text-indigo-600">
                            المحتجز الآن <span class="font-extrabold text-indigo-800">{{ money(paySummary.security_held) }}</span>
                        </span>
                        <span class="min-w-0 flex-1 text-[10px] font-medium text-slate-500">
                            محتجز للنزيل — لا يدخل في الإجمالي ولا في المتبقي أعلاه.
                        </span>
                    </div>

                    <div class="grid gap-4 lg:grid-cols-[300px_1fr]">
                        <form
                            v-if="can('chalet_bookings.edit')"
                            @submit.prevent="submitPayment"
                            class="space-y-2.5 rounded-xl border border-slate-200 bg-slate-50 p-3"
                        >
                            <h3 class="text-sm font-extrabold text-slate-800">تسجيل دفعة</h3>

                            <div class="grid grid-cols-3 gap-1">
                                <button
                                    v-for="t in [
                                        ['deposit', 'عربون'],
                                        ['payment', 'دفعة'],
                                        ['refund', 'استرداد'],
                                    ]"
                                    :key="t[0]"
                                    type="button"
                                    @click="setPayType(t[0])"
                                    class="rounded-lg py-1.5 text-[11px] font-bold transition"
                                    :class="
                                        payForm.type === t[0]
                                            ? t[0] === 'refund'
                                                ? 'bg-red-500 text-white'
                                                : 'bg-emerald-600 text-white'
                                            : 'bg-white text-slate-600 ring-1 ring-slate-200'
                                    "
                                >
                                    {{ t[1] }}
                                </button>
                            </div>

                            <!--
                                Kept on its own row and in its own colour: this
                                money is held for the guest, and a clerk who
                                reaches for «رد تأمين» must never be one slip
                                away from «استرداد» off the price.
                            -->
                            <div class="grid grid-cols-3 gap-1 border-t border-slate-200 pt-2.5">
                                <button
                                    v-for="t in [
                                        ['security_deposit', 'قبض تأمين'],
                                        ['security_refund', 'رد تأمين'],
                                        ['security_forfeit', 'خصم تلفيات'],
                                    ]"
                                    :key="t[0]"
                                    type="button"
                                    :disabled="t[0] !== 'security_deposit' && securityHeld <= 0"
                                    @click="setPayType(t[0])"
                                    class="rounded-lg py-1.5 text-[11px] font-bold transition disabled:cursor-not-allowed disabled:bg-white disabled:text-slate-300 disabled:ring-slate-100"
                                    :class="payForm.type === t[0] ? 'bg-indigo-600 text-white' : 'bg-white text-indigo-700 ring-1 ring-indigo-200'"
                                >
                                    {{ t[1] }}
                                </button>
                            </div>

                            <p v-if="isSecurity" class="rounded-lg bg-indigo-50 px-2.5 py-2 text-[11px] font-bold text-indigo-700">
                                <template v-if="payForm.type === 'security_deposit'">
                                    التأمين المتفق عليه {{ money(securityAgreed) }} — المحتجز الآن {{ money(securityHeld) }}.
                                </template>
                                <template v-else-if="payForm.type === 'security_forfeit'">
                                    يُخصم من المحتجز ({{ money(securityHeld) }}) مقابل تلفيات، ولا يخرج نقدًا من الصندوق.
                                </template>
                                <template v-else> يُعاد للنزيل من المحتجز ({{ money(securityHeld) }}). </template>
                                لا يتغيّر به إجمالي الحجز ولا المتبقي.
                            </p>

                            <div>
                                <label class="mb-1 block text-[11px] font-bold text-slate-600">المبلغ</label>
                                <input
                                    v-model.number="payForm.amount"
                                    type="number"
                                    min="0.01"
                                    step="0.01"
                                    class="w-full rounded-lg border border-slate-200 px-2.5 py-2 text-sm"
                                />
                                <p v-if="payForm.errors.amount" class="mt-1 text-[11px] text-red-500">{{ payForm.errors.amount }}</p>
                            </div>

                            <div class="grid gap-2" :class="payForm.type === 'security_forfeit' ? 'grid-cols-1' : 'grid-cols-2'">
                                <!-- A forfeit moves no cash, so naming a till would be a fiction -->
                                <div v-if="payForm.type !== 'security_forfeit'">
                                    <label class="mb-1 block text-[11px] font-bold text-slate-600">الطريقة</label>
                                    <select v-model="payForm.payment_method_id" class="w-full rounded-lg border border-slate-200 px-2 py-2 text-xs">
                                        <option v-for="m in meta.payment_methods" :key="m.id" :value="m.id">{{ m.label }}</option>
                                    </select>
                                </div>
                                <div>
                                    <label class="mb-1 block text-[11px] font-bold text-slate-600">التاريخ</label>
                                    <input
                                        v-model="payForm.paid_on"
                                        type="date"
                                        class="w-full rounded-lg border border-slate-200 px-2 py-2 text-xs"
                                    />
                                </div>
                            </div>

                            <div>
                                <label class="mb-1 block text-[11px] font-bold text-slate-600">مرجع العملية</label>
                                <input v-model="payForm.reference" dir="ltr" class="w-full rounded-lg border border-slate-200 px-2.5 py-2 text-xs" />
                            </div>

                            <label
                                v-if="payBooking.client?.mobile && payForm.type !== 'refund' && !isSecurity"
                                class="flex cursor-pointer items-center gap-2 text-[11px] font-bold text-slate-700"
                            >
                                <input type="checkbox" v-model="payForm.notify" class="h-3.5 w-3.5 rounded border-slate-300 text-emerald-600" />
                                إشعار النزيل على واتساب
                            </label>

                            <button
                                type="submit"
                                :disabled="payForm.processing || payForm.amount <= 0"
                                class="w-full rounded-md bg-teal-600 py-2 text-sm font-bold text-white hover:bg-teal-700 disabled:opacity-50"
                            >
                                {{ isSecurity ? 'تسجيل حركة التأمين' : 'تسجيل الدفعة' }}
                            </button>

                            <p v-if="isSecurity" class="text-[10px] font-medium leading-5 text-slate-500">
                                التأمين يُقيَّد في حساب «تأمينات حجوزات مستردة» لا في العرابين، فلا يظهر إيرادًا. وما يُخصم منه للتلفيات وحده هو الذي
                                يتحوّل إلى إيراد.
                            </p>
                            <p v-else class="text-[10px] font-medium leading-5 text-slate-500">
                                العربون يُقيَّد التزامًا (إيراد غير مكتسب) لا إيرادًا، ويتحوّل إلى إيراد عند إنهاء الإقامة.
                            </p>
                        </form>

                        <div>
                            <h3 class="mb-2 text-sm font-extrabold text-slate-800">سجل الدفعات</h3>

                            <div v-if="payLoading" class="flex items-center gap-2 py-8 text-sm font-bold text-slate-400">
                                <Loader2 class="h-4 w-4 animate-spin" /> جارٍ التحميل…
                            </div>

                            <table v-else-if="payments.length" class="w-full text-xs">
                                <thead class="bg-slate-50">
                                    <tr>
                                        <th class="px-2 py-2 text-right font-extrabold text-[#1e3a8a]">النوع</th>
                                        <th class="px-2 py-2 text-right font-extrabold text-[#1e3a8a]">التاريخ</th>
                                        <th class="px-2 py-2 text-right font-extrabold text-[#1e3a8a]">الطريقة</th>
                                        <th class="px-2 py-2 text-left font-extrabold text-[#1e3a8a]">المبلغ</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="p in payments" :key="p.id" class="border-t border-slate-100">
                                        <td class="px-2 py-2">
                                            <span
                                                class="rounded px-1.5 py-0.5 text-[10px] font-bold"
                                                :class="
                                                    p.type === 'refund'
                                                        ? 'bg-red-100 text-red-700'
                                                        : p.type === 'deposit'
                                                          ? 'bg-amber-100 text-amber-700'
                                                          : 'bg-emerald-100 text-emerald-700'
                                                "
                                            >
                                                {{ p.type_label }}
                                            </span>
                                        </td>
                                        <td class="px-2 py-2 text-slate-600" dir="ltr">{{ p.paid_on }}</td>
                                        <td class="px-2 py-2 text-slate-600">{{ p.method_label }}</td>
                                        <td
                                            class="px-2 py-2 text-left font-extrabold"
                                            :class="p.signed_amount < 0 ? 'text-red-600' : 'text-slate-800'"
                                            dir="ltr"
                                        >
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
