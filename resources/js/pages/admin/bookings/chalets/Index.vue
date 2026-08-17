<script setup lang="ts">
import { StatPill, TableActionButton } from '@/components/data-table';
import PageShortcuts from '@/components/PageShortcuts.vue';
import { usePermissions } from '@/composables/usePermissions';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { Bell, Check, Loader2, LogIn, LogOut, Moon, Pencil, Plus, Search, Trash2, Wallet, X } from 'lucide-vue-next';
import { ref } from 'vue';

interface SectionOption { id: number; name: string; gender: string }
interface UnitOption {
    id: number; name: string; code: string; type: string;
    bookable_mode: 'whole' | 'sections' | 'both';
    privacy_mode: 'open' | 'exclusive';
    sections: SectionOption[];
}

interface Booking {
    id: number; reference: string;
    unit: { id: number; name: string; code: string };
    client: { id: number; name: string; mobile: string | null } | null;
    scope: 'whole' | 'sections';
    sections: string[]; section_ids: number[];
    period: string; period_label: string; schedule_label: string;
    booking_date: string;
    check_out_date: string | null;
    nights: number | null;
    status: string; status_label: string; status_color: string;
    total_amount: number; deposit_amount: number; paid_amount: number; remaining_amount: number;
    is_deposit_settled: boolean;
    guests_count: number | null; notes: string | null;
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
    meta: {
        statuses: { key: string; label: string; color: string }[];
        periods: { key: string; label: string; start: string; end: string }[];
        stay: { check_in_time: string; check_out_time: string; max_nights: number };
    };
    stats: { total: number; tentative: number; confirmed: number; unpaid: number };
}>();

const { can } = usePermissions();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'لوحة التحكم', href: '/admin' },
    { title: 'حجوزات الشاليهات', href: '/admin/bookings/chalets' },
];

const money = (n: number) => new Intl.NumberFormat('ar-SA-u-nu-latn', { maximumFractionDigits: 2 }).format(n ?? 0);

const today = new Date().toISOString().slice(0, 10);

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
    method: 'cash',
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

const openPayments = (b: Booking) => {
    payBooking.value = b;
    payments.value = [];
    paySummary.value = null;
    payForm.reset();
    payForm.clearErrors();
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

const sendReminder = (b: Booking) => {
    if (confirm(`إرسال تذكير بموعد الدخول على واتساب ${b.client?.mobile ?? ''}؟`)) {
        router.post(`/admin/bookings/${b.id}/remind`, {}, { preserveScroll: true });
    }
};

const changeStatus = (b: Booking, status: string) => {
    const reason = status === 'cancelled' ? prompt('سبب الإلغاء (اختياري):') ?? '' : '';
    router.patch(`/admin/bookings/${b.id}/status`, { status, reason }, { preserveScroll: true });
};

const destroy = (b: Booking) => {
    if (confirm(`حذف الحجز ${b.reference}؟`)) {
        router.delete(`/admin/bookings/${b.id}`, { preserveScroll: true });
    }
};

const colorClass = (color: string) =>
    ({
        amber: 'bg-amber-100 text-amber-700',
        emerald: 'bg-emerald-100 text-emerald-700',
        slate: 'bg-slate-200 text-slate-700',
        red: 'bg-red-100 text-red-700',
        rose: 'bg-rose-100 text-rose-700',
    })[color] ?? 'bg-slate-100 text-slate-700';
</script>

<template>
    <Head title="حجوزات الشاليهات" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="min-h-full space-y-5 bg-slate-100 p-5">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h1 class="text-2xl font-extrabold text-slate-900">حجوزات الشاليهات</h1>
                    <p class="mt-1 text-sm font-medium text-slate-600">
                        إقامة بالليالي — دخول {{ meta.stay.check_in_time }} وخروج {{ meta.stay.check_out_time }}
                    </p>
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    <!-- اختصارات شاشات الشاليهات — تُشتق من القائمة فلا تحتاج صيانة -->
                    <PageShortcuts />
                    <Link v-if="can('bookings.create')" href="/admin/bookings/chalets/create" class="inline-flex items-center gap-1.5 rounded-md bg-teal-600 px-4 py-2 text-sm font-bold text-white shadow-sm hover:bg-teal-700">
                        <Plus class="h-4 w-4" /> إقامة جديدة
                    </Link>
                </div>
            </div>

            <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
                <StatPill label="إجمالي الإقامات" :value="stats.total" variant="primary" />
                <StatPill label="مبدئي" :value="stats.tentative" variant="warning" />
                <StatPill label="مؤكد" :value="stats.confirmed" variant="success" />
                <StatPill label="عليه متبقٍ" :value="stats.unpaid" variant="danger" />
            </div>

            <!-- الفلاتر -->
            <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
                <div class="grid gap-2 sm:grid-cols-2 lg:grid-cols-6">
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
                        <option :value="null">كل الشاليهات</option>
                        <option v-for="u in units" :key="u.id" :value="u.id">{{ u.name }}</option>
                    </select>
                    <input v-model="filters.from" @change="applyFilters" type="date" title="دخول من" class="rounded-xl border border-slate-200 px-3 py-2.5 text-sm" />
                    <input v-model="filters.to" @change="applyFilters" type="date" title="دخول حتى" class="rounded-xl border border-slate-200 px-3 py-2.5 text-sm" />
                </div>
                <button type="button" @click="resetFilters" class="mt-2 text-[11px] font-bold text-slate-500 hover:text-slate-700">إعادة ضبط الفلاتر</button>
            </div>

            <!-- الجدول -->
            <div class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="bg-slate-100">
                            <tr>
                                <th class="px-4 py-3 text-right text-xs font-extrabold text-slate-700">رقم الحجز</th>
                                <th class="px-4 py-3 text-right text-xs font-extrabold text-slate-700">الشاليه والنطاق</th>
                                <th class="px-4 py-3 text-right text-xs font-extrabold text-slate-700">النزيل</th>
                                <th class="px-4 py-3 text-right text-xs font-extrabold text-slate-700">الدخول والخروج</th>
                                <th class="px-4 py-3 text-center text-xs font-extrabold text-slate-700">الحالة</th>
                                <th class="px-4 py-3 text-right text-xs font-extrabold text-slate-700">المالية</th>
                                <th class="px-4 py-3 text-center text-xs font-extrabold text-slate-700">إجراءات</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="b in bookings.data" :key="b.id" class="border-t border-slate-100 transition hover:bg-slate-50">
                                <td class="px-4 py-3 font-extrabold text-slate-800" dir="ltr">{{ b.reference }}</td>
                                <td class="px-4 py-3">
                                    <div class="font-bold text-slate-800">{{ b.unit.name }}</div>
                                    <div class="mt-0.5 text-[11px] font-medium text-slate-500">
                                        <span v-if="b.scope === 'whole'" class="rounded bg-violet-100 px-1.5 py-0.5 font-bold text-violet-700">الشاليه كاملًا</span>
                                        <span v-else class="rounded bg-sky-100 px-1.5 py-0.5 font-bold text-sky-700">{{ b.sections.join('، ') }}</span>
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
                                    <div class="mt-1 inline-flex items-center gap-1 rounded bg-teal-50 px-1.5 py-0.5 text-[10px] font-extrabold text-teal-700">
                                        <Moon class="h-3 w-3" /> {{ b.schedule_label }}
                                    </div>
                                </td>
                                <td class="px-4 py-3 text-center">
                                    <span class="rounded-md px-2 py-0.5 text-[11px] font-bold" :class="colorClass(b.status_color)">{{ b.status_label }}</span>
                                </td>
                                <td class="px-4 py-3">
                                    <div class="font-extrabold text-slate-800">{{ money(b.total_amount) }}</div>
                                    <div class="text-[11px] font-bold" :class="b.remaining_amount > 0 ? 'text-red-600' : 'text-emerald-600'">
                                        {{ b.remaining_amount > 0 ? `متبقٍ ${money(b.remaining_amount)}` : 'مسدَّد بالكامل' }}
                                    </div>
                                    <div v-if="!b.is_deposit_settled && b.deposit_amount > 0" class="mt-0.5 text-[10px] font-bold text-amber-600">
                                        العربون {{ money(b.deposit_amount) }} غير مستوفى
                                    </div>
                                </td>
                                <td class="px-4 py-3">
                                    <div class="flex items-center justify-center gap-1">
                                        <TableActionButton v-if="can('bookings.edit')" variant="primary" :icon="Wallet" title="الدفعات والعربون" @click="openPayments(b)" />
                                        <TableActionButton v-if="can('whatsapp.send') && b.client?.mobile && !['cancelled','completed'].includes(b.status)" variant="view" :icon="Bell" title="تذكير واتساب" @click="sendReminder(b)" />
                                        <TableActionButton v-if="can('bookings.edit') && b.status === 'confirmed'" variant="primary" :icon="Check" title="إنهاء الإقامة" @click="changeStatus(b, 'completed')" />
                                        <TableActionButton v-if="can('bookings.edit')" variant="edit" :icon="Pencil" title="تعديل" @click="router.visit(`/admin/bookings/chalets/${b.id}/edit`)" />
                                        <TableActionButton v-if="can('bookings.edit') && !['cancelled', 'completed'].includes(b.status)" variant="warning" :icon="X" title="إلغاء" @click="changeStatus(b, 'cancelled')" />
                                        <TableActionButton v-if="can('bookings.delete')" variant="danger" :icon="Trash2" title="حذف" @click="destroy(b)" />
                                    </div>
                                </td>
                            </tr>
                            <tr v-if="!bookings.data.length">
                                <td colspan="7" class="px-4 py-10 text-center text-sm font-medium text-slate-500">لا توجد إقامات مطابقة</td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div v-if="bookings.links.length > 3" class="flex flex-wrap justify-center gap-1 border-t border-slate-100 p-3">
                    <Link
                        v-for="l in bookings.links"
                        :key="l.label"
                        :href="l.url ?? '#'"
                        :class="['rounded-lg px-3 py-1.5 text-xs font-bold', l.active ? 'bg-teal-600 text-white' : l.url ? 'bg-white text-slate-600 ring-1 ring-slate-200 hover:bg-slate-50' : 'cursor-default text-slate-300']"
                        v-html="l.label"
                    />
                </div>
            </div>
        </div>

        <!-- الدفعات والعربون -->
        <div v-if="payBooking" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4" @click.self="payBooking = null">
            <div class="flex max-h-[92vh] w-full max-w-3xl flex-col rounded-2xl bg-white shadow-2xl">
                <div class="flex items-center justify-between border-b border-slate-100 px-6 py-4">
                    <div>
                        <h2 class="text-lg font-extrabold text-slate-900">الدفعات — <span dir="ltr">{{ payBooking.reference }}</span></h2>
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
                                    <select v-model="payForm.method" class="w-full rounded-lg border border-slate-200 px-2 py-2 text-xs">
                                        <option value="cash">نقدًا</option>
                                        <option value="transfer">تحويل</option>
                                        <option value="card">شبكة</option>
                                        <option value="online">إلكتروني</option>
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
                                إشعار النزيل على واتساب
                            </label>

                            <button type="submit" :disabled="payForm.processing || payForm.amount <= 0" class="w-full rounded-md bg-teal-600 py-2 text-sm font-bold text-white hover:bg-teal-700 disabled:opacity-50">
                                تسجيل الدفعة
                            </button>

                            <p class="text-[10px] font-medium leading-5 text-slate-500">
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
