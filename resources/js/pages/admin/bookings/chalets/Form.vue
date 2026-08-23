<script setup lang="ts">
import ClientQuickAdd from '@/components/ClientQuickAdd.vue';
import SearchableSelect from '@/components/SearchableSelect.vue';
import { usePermissions } from '@/composables/usePermissions';
import AppLayout from '@/layouts/AppLayout.vue';
import { csrfToken } from '@/lib/csrf';
import { toHijri, weekdayName } from '@/lib/hijri';
import { type BreadcrumbItem, type PaymentMethodOption } from '@/types';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { AlertTriangle, ArrowRight, CheckCircle2, Loader2, LogIn, LogOut, Moon, Wallet } from 'lucide-vue-next';
import { computed, nextTick, onMounted, ref, watch } from 'vue';

interface SectionOption { id: number; name: string; gender: string }
interface UnitOption {
    id: number; name: string; code: string; type: string;
    bookable_mode: 'whole' | 'sections' | 'both';
    privacy_mode: 'open' | 'exclusive';
    /** Day periods this chalet is priced for — empty means stays only. */
    day_use_periods: string[];
    sections: SectionOption[];
}
interface AddonOption { id: number; name: string; price: number; pricing: string }
interface ClientOption { id: number; name: string; mobile: string | null }

interface ExistingBooking {
    id: number; reference: string;
    unit: { id: number; name: string; code: string };
    client: { id: number; name: string; mobile: string | null } | null;
    scope: 'whole' | 'sections';
    section_ids: number[];
    booking_date: string;
    check_out_date: string | null;
    /** 'overnight' is a stay; any other period is a day-use booking. */
    period: string;
    days_count: number | null;
    status: string;
    discount_amount: number;
    addons: Record<number, number>;
    guests_count: number | null;
    notes: string | null;
}

interface QuoteLine { label: string; amount: number }
interface Quote {
    availability: { ok: boolean; reason: string | null; conflicts: unknown[] };
    pricing: {
        base_amount: number; addons_amount: number; discount_amount: number;
        total_amount: number; deposit_amount: number; is_weekend: boolean;
        /** Stay quotes carry nights; day-use quotes carry days instead. */
        nights?: number; weekend_nights?: number; average_night?: number;
        days?: number;
        lines: QuoteLine[];
    } | null;
}

const props = defineProps<{
    booking: ExistingBooking | null;
    units: UnitOption[];
    addons: AddonOption[];
    clients: ClientOption[];
    meta: {
        statuses: { key: string; label: string; color: string }[];
        stay: { check_in_time: string; check_out_time: string; max_nights: number };
        payment_methods: PaymentMethodOption[];
    };
}>();

const { can } = usePermissions();

const isEdit = computed(() => props.booking !== null);

const breadcrumbs = computed<BreadcrumbItem[]>(() => [
    { title: 'لوحة التحكم', href: '/admin' },
    { title: 'حجوزات الشاليهات', href: '/admin/bookings/chalets' },
    {
        title: isEdit.value ? `تعديل ${props.booking?.reference}` : 'حجز جديد',
        href: isEdit.value ? `/admin/bookings/chalets/${props.booking?.id}/edit` : '/admin/bookings/chalets/create',
    },
]);

const money = (n: number) => new Intl.NumberFormat('ar-SA-u-nu-latn', { maximumFractionDigits: 2 }).format(n ?? 0);

const addDays = (date: string, days: number) => {
    const d = new Date(`${date}T00:00:00`);
    d.setDate(d.getDate() + days);

    return d.toISOString().slice(0, 10);
};

/** عدد الليالي بين تاريخين — يوم الخروج غير محسوب ليلةً، كقاعدة الخادم. */
const nightsBetween = (checkIn: string, checkOut: string) => {
    if (!checkIn || !checkOut) return 0;
    const ms = new Date(`${checkOut}T00:00:00`).getTime() - new Date(`${checkIn}T00:00:00`).getTime();

    return Math.round(ms / 86_400_000);
};

const today = new Date().toISOString().slice(0, 10);

/** The period value that means "an overnight stay" rather than day use. */
const STAY = 'overnight';

/**
 * أقدم تاريخ دخول يقبله الحقل — اليوم، فالشاليه لا يُحجز بأثر رجعي.
 * ويُستثنى حجز قائم مضى تاريخه ليبقى قابلًا للفتح والتصحيح.
 */
const minDate = computed(() => {
    const existing = props.booking?.booking_date;

    return existing && existing < today ? existing : today;
});

const quote = ref<Quote | null>(null);
const checking = ref(false);

const form = useForm({
    unit_id: props.booking?.unit.id ?? null as number | null,
    client_id: props.booking?.client?.id ?? null as number | null,
    scope: (props.booking?.scope ?? 'whole') as 'whole' | 'sections',
    section_ids: [...(props.booking?.section_ids ?? [])] as number[],
    // booking_date هو تاريخ الدخول — سُمّي كذلك ليبقى عمودًا واحدًا في النوعين
    booking_date: props.booking?.booking_date ?? today,
    check_out_date: props.booking?.check_out_date ?? addDays(today, 1),
    // A chalet is a stay unless a day period is chosen — the same column the
    // server reads to decide which shape it is.
    period: props.booking?.period ?? STAY,
    days_count: props.booking?.days_count ?? 1,
    // الإقامة الجديدة مؤكدة افتراضيًا — و«مبدئي» يُختار من القائمة عند الحاجة.
    status: props.booking?.status ?? 'confirmed',
    addons: { ...(props.booking?.addons ?? {}) } as Record<number, number>,
    discount_amount: props.booking?.discount_amount ?? 0,
    notes: props.booking?.notes ?? '',

    payment_amount: 0,
    payment_type: 'deposit',
    // أول طريقة في الترتيب هي الافتراض — يرتّبها المستخدم من شاشة طرق الدفع.
    payment_method_id: props.meta.payment_methods[0]?.id ?? null as number | null,
    payment_paid_on: today,
    payment_notify: true,
});

const selectedUnit = computed(() => props.units.find((u) => u.id === form.unit_id) ?? null);

/** نسخة محلية من قائمة النزلاء ليُضاف إليها ما يُنشأ سريعًا بلا إعادة تحميل الصفحة. */
const clients = ref<ClientOption[]>([...props.clients]);

const clientOptions = computed(() =>
    clients.value.map((c) => ({ ...c, label: c.mobile ? `${c.name} — ${c.mobile}` : c.name })),
);

const onClientCreated = (client: ClientOption) => {
    clients.value = [client, ...clients.value];
    form.client_id = client.id;
};

// الهجري يظهر مع الميلادي في طرفَي الإقامة: العميل يتحدث بالهجري.
const checkInHijri = computed(() => toHijri(form.booking_date));
const checkOutHijri = computed(() => toHijri(form.check_out_date));
const checkInDay = computed(() => weekdayName(form.booking_date));
const checkOutDay = computed(() => weekdayName(form.check_out_date));

/** عدد ليالي الإقامة المختارة — يُحسب محليًا ليتحدث مع كل ضغطة. */
const nights = computed(() => nightsBetween(form.booking_date, form.check_out_date));
const overMaxNights = computed(() => nights.value > props.meta.stay.max_nights);

const canBookWhole = computed(() => ['whole', 'both'].includes(selectedUnit.value?.bookable_mode ?? 'both'));
const canBookSections = computed(() => ['sections', 'both'].includes(selectedUnit.value?.bookable_mode ?? 'both'));

// ── إقامة بليالٍ أم حجز نهاري ───────────────────────────────

const isStay = computed(() => form.period === STAY);

/**
 * Day periods the selected chalet may be sold for. Pricing is what opens a
 * period, so a chalet nobody priced for day use offers none and the mode
 * toggle stays hidden rather than leading to a refusal on save.
 */
const dayPeriods = computed(() => {
    const allowed = selectedUnit.value?.day_use_periods ?? [];

    return props.meta.periods.filter((p) => allowed.includes(p.key));
});

const canBookByDay = computed(() => dayPeriods.value.length > 0);

const periodLabel = (key: string) => props.meta.periods.find((p) => p.key === key)?.label ?? key;

/** عدد أيام الحجز النهاري — يوم واحد على الأقل. */
const days = computed(() => Math.max(1, Number(form.days_count) || 1));

const setMode = (stay: boolean) => {
    if (stay) {
        form.period = STAY;
        // Restore a valid range: a day-use booking left check_out_date empty.
        if (nightsBetween(form.booking_date, form.check_out_date ?? '') < 1) {
            form.check_out_date = addDays(form.booking_date, 1);
        }

        return;
    }

    form.period = dayPeriods.value[0]?.key ?? STAY;
    form.days_count = 1;
};

const hydrating = ref(true);

onMounted(() => nextTick(() => (hydrating.value = false)));

watch(() => form.unit_id, () => {
    if (hydrating.value) return;

    form.section_ids = [];
    form.scope = canBookWhole.value ? 'whole' : 'sections';
});

// A unit change can drop the period that was picked — fall back to a stay
// rather than posting a period this chalet has no price for.
watch(dayPeriods, (periods) => {
    if (hydrating.value || isStay.value) return;

    if (!periods.some((p) => p.key === form.period)) {
        setMode(true);
    }
});

// تاريخ الخروج يتبع الدخول: تقديم الدخول إلى ما بعد الخروج يترك مدى فارغًا
// يرفضه الخادم، فيُدفع الخروج ليلةً واحدة بدل أن يُترك الحقل متناقضًا.
watch(() => form.booking_date, (checkIn) => {
    if (hydrating.value || !checkIn) return;

    if (nightsBetween(checkIn, form.check_out_date) < 1) {
        form.check_out_date = addDays(checkIn, 1);
    }
});

const setNights = (count: number) => {
    form.check_out_date = addDays(form.booking_date, count);
};

const toggleSection = (id: number) => {
    const i = form.section_ids.indexOf(id);
    i === -1 ? form.section_ids.push(id) : form.section_ids.splice(i, 1);
};

const toggleAddon = (id: number) => {
    if (form.addons[id]) {
        delete form.addons[id];
    } else {
        form.addons[id] = 1;
    }
};

/**
 * فحص الإتاحة واحتساب سعر الإقامة على الخادم عند كل تغيير مؤثر.
 * التسعير هنا مجموع ليالٍ لا سعر يوم، فلا يمكن اشتقاقه في الواجهة.
 */
let timer: ReturnType<typeof setTimeout> | undefined;
const refreshQuote = () => {
    // A stay needs a night between its dates; a day-use booking only needs
    // its period, which the date and days count already imply.
    const rangeReady = isStay.value ? nights.value >= 1 : form.period !== STAY;

    if (!form.unit_id || !rangeReady || (form.scope === 'sections' && !form.section_ids.length)) {
        quote.value = null;

        return;
    }

    clearTimeout(timer);
    timer = setTimeout(async () => {
        checking.value = true;
        try {
            const res = await fetch('/admin/bookings/chalets/quote', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-XSRF-TOKEN': csrfToken(),
                },
                body: JSON.stringify({
                    unit_id: form.unit_id,
                    scope: form.scope,
                    section_ids: form.section_ids,
                    booking_date: form.booking_date,
                    period: form.period,
                    check_out_date: isStay.value ? form.check_out_date : null,
                    days_count: isStay.value ? null : days.value,
                    client_id: form.client_id,
                    addons: form.addons,
                    discount_amount: form.discount_amount,
                    ignore_booking_id: props.booking?.id ?? null,
                }),
            });
            quote.value = res.ok ? await res.json() : null;
        } finally {
            checking.value = false;
        }
    }, 350);
};

watch(
    () => [form.unit_id, form.scope, [...form.section_ids], form.booking_date, form.check_out_date, form.period, form.days_count, form.client_id, JSON.stringify(form.addons), form.discount_amount],
    refreshQuote,
    { deep: true },
);

onMounted(refreshQuote);

const blocked = computed(() => quote.value !== null && !quote.value.availability.ok);
const pricing = computed(() => quote.value?.pricing ?? null);

// ── السداد عند إنشاء الحجز ──────────────────────────────────
const suggestedDeposit = computed(() => pricing.value?.deposit_amount ?? 0);
const suggestedTotal = computed(() => pricing.value?.total_amount ?? 0);

const payChoice = computed(() => {
    if (form.payment_amount <= 0) return 'none';
    if (form.payment_amount >= suggestedTotal.value && suggestedTotal.value > 0) return 'full';
    if (form.payment_amount === suggestedDeposit.value) return 'deposit';

    return 'custom';
});

const setPayChoice = (choice: 'none' | 'deposit' | 'full') => {
    if (choice === 'none') {
        form.payment_amount = 0;

        return;
    }

    form.payment_amount = choice === 'deposit' ? suggestedDeposit.value : suggestedTotal.value;
    form.payment_type = choice === 'deposit' ? 'deposit' : 'payment';
};

/**
 * Errors whose field is on screen right now. Anything else is listed in the
 * summary below the form — a hidden field failing validation used to leave
 * the page looking as if the save button did nothing.
 */
const inlineErrorKeys = computed(() => [
    'unit_id', 'client_id', 'booking_date', 'period', 'days_count',
    'availability', 'payment_amount', 'discount_amount', 'notes',
    ...(isStay.value ? ['check_out_date'] : []),
]);

const otherErrors = computed(() =>
    Object.entries(form.errors)
        .filter(([key]) => !inlineErrorKeys.value.includes(key))
        .map(([, message]) => message as string),
);

const submit = () => {
    // The two shapes carry different fields, and the one that does not apply
    // is sent as null rather than left at whatever the hidden input still
    // holds — a stale check-out date would otherwise fail a rule for a field
    // the day-use form never renders.
    form.transform((data) => ({
        ...data,
        check_out_date: isStay.value ? data.check_out_date : null,
        days_count: isStay.value ? null : days.value,
    }));

    isEdit.value
        ? form.put(`/admin/bookings/chalets/${props.booking?.id}`)
        : form.post('/admin/bookings/chalets');
};
</script>

<template>
    <Head :title="isEdit ? 'تعديل الحجز' : 'حجز جديد'" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <form @submit.prevent="submit" class="min-h-full space-y-5 bg-slate-100 p-5">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h1 class="text-2xl font-extrabold text-slate-900">
                        {{ isEdit ? `تعديل الحجز ${booking?.reference}` : 'حجز جديد' }}
                    </h1>
                    <p class="mt-1 text-sm font-medium text-slate-600">
                        إقامة بالليالي — دخول {{ meta.stay.check_in_time }} وخروج {{ meta.stay.check_out_time }}
                    </p>
                </div>
                <Link href="/admin/bookings/chalets" class="inline-flex items-center gap-1.5 rounded-md border border-slate-200 bg-white px-4 py-2 text-sm font-bold text-slate-700 shadow-sm hover:bg-slate-50">
                    <ArrowRight class="h-4 w-4" /> رجوع للسجل
                </Link>
            </div>

            <div class="grid gap-5 lg:grid-cols-[1fr_340px]">
                <div class="space-y-4">
                    <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                        <h2 class="mb-3 text-sm font-extrabold text-slate-800">الشاليه والنزيل</h2>

                        <div class="grid gap-3 sm:grid-cols-2">
                            <div>
                                <label class="mb-1 block text-sm font-bold text-slate-700">الشاليه</label>
                                <select v-model="form.unit_id" class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm">
                                    <option :value="null">— اختر الشاليه —</option>
                                    <option v-for="u in units" :key="u.id" :value="u.id">{{ u.name }} ({{ u.code }})</option>
                                </select>
                                <p v-if="form.errors.unit_id" class="mt-1 text-xs text-red-500">{{ form.errors.unit_id }}</p>
                            </div>
                            <div>
                                <div class="mb-1 flex items-center justify-between gap-2">
                                    <label class="block text-sm font-bold text-slate-700">النزيل</label>
                                    <ClientQuickAdd category="chalet" @created="onClientCreated" />
                                </div>
                                <SearchableSelect
                                    v-model="form.client_id"
                                    :options="clientOptions"
                                    label-key="label"
                                    :search-keys="['mobile']"
                                    placeholder="— اختر النزيل —"
                                />
                                <p v-if="form.errors.client_id" class="mt-1 text-xs text-red-500">{{ form.errors.client_id }}</p>
                            </div>
                        </div>
                    </div>

                    <!-- مدة الإقامة: تاريخا الدخول والخروج، والليالي تُحسب بينهما -->
                    <div class="rounded-2xl border-2 border-teal-100 bg-white p-5 shadow-sm">
                        <div class="mb-3 flex items-center justify-between gap-2">
                            <h2 class="flex items-center gap-1.5 text-sm font-extrabold text-slate-800">
                                <Moon class="h-4 w-4 text-teal-500" /> {{ isStay ? 'مدة الإقامة' : 'فترة الحجز' }}
                            </h2>
                            <span class="rounded-lg bg-teal-600 px-3 py-1 text-sm font-extrabold text-white">
                                <template v-if="isStay">{{ nights }} {{ nights === 1 ? 'ليلة' : 'ليالٍ' }}</template>
                                <template v-else>{{ periodLabel(form.period) }}{{ days > 1 ? ` × ${days}` : '' }}</template>
                            </span>
                        </div>

                        <!-- نوع الحجز: لا يظهر إلا لشاليه مسعَّر بالفترات -->
                        <div v-if="canBookByDay" class="mb-3 flex flex-wrap gap-1.5 rounded-xl bg-slate-100 p-1">
                            <button type="button" @click="setMode(true)"
                                class="flex-1 rounded-lg px-3 py-1.5 text-xs font-extrabold transition"
                                :class="isStay ? 'bg-white text-teal-700 shadow-sm' : 'text-slate-500 hover:text-slate-700'"
                            >إقامة بليالٍ</button>
                            <button type="button" @click="setMode(false)"
                                class="flex-1 rounded-lg px-3 py-1.5 text-xs font-extrabold transition"
                                :class="!isStay ? 'bg-white text-teal-700 shadow-sm' : 'text-slate-500 hover:text-slate-700'"
                            >حجز بالفترة</button>
                        </div>

                        <!-- الحجز النهاري: تاريخ واحد وفترة، بلا تاريخ خروج -->
                        <div v-if="!isStay" class="space-y-3">
                            <div class="grid gap-3 sm:grid-cols-2">
                                <div>
                                    <label class="mb-1 flex items-center gap-1 text-xs font-bold text-slate-700">
                                        <LogIn class="h-3.5 w-3.5 text-emerald-500" /> التاريخ
                                    </label>
                                    <input v-model="form.booking_date" type="date" :min="minDate" class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm" />
                                    <p v-if="checkInHijri" class="mt-1 flex flex-wrap items-center gap-1 text-[11px] font-bold text-emerald-700">
                                        <span class="rounded bg-emerald-50 px-1.5 py-0.5">{{ checkInHijri }}</span>
                                        <span class="text-slate-500">{{ checkInDay }}</span>
                                    </p>
                                    <p v-if="form.errors.booking_date" class="mt-1 text-xs text-red-500">{{ form.errors.booking_date }}</p>
                                </div>
                                <div>
                                    <label class="mb-1 block text-xs font-bold text-slate-700">عدد الأيام</label>
                                    <input v-model.number="form.days_count" type="number" min="1" class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm" />
                                    <p class="mt-1 text-[11px] font-medium text-slate-500">الفترة نفسها تتكرر في كل يوم، ويُسعَّر كل يوم بيومه.</p>
                                    <p v-if="form.errors.days_count" class="mt-1 text-xs text-red-500">{{ form.errors.days_count }}</p>
                                </div>
                            </div>

                            <div>
                                <label class="mb-1.5 block text-xs font-bold text-slate-700">اختر الفترة</label>
                                <div class="flex flex-wrap gap-1.5">
                                    <button v-for="p in dayPeriods" :key="p.key" type="button" @click="form.period = p.key"
                                        class="rounded-lg px-3 py-1.5 text-xs font-bold transition"
                                        :class="form.period === p.key ? 'bg-teal-600 text-white' : 'bg-slate-100 text-slate-600 hover:bg-slate-200'"
                                    >{{ p.label }} <span class="text-[10px] opacity-70" dir="ltr">{{ p.start }}–{{ p.end }}</span></button>
                                </div>
                                <p v-if="form.errors.period" class="mt-1 text-xs text-red-500">{{ form.errors.period }}</p>
                            </div>
                        </div>

                        <div v-else class="grid gap-3 sm:grid-cols-2">
                            <div>
                                <label class="mb-1 flex items-center gap-1 text-xs font-bold text-slate-700">
                                    <LogIn class="h-3.5 w-3.5 text-emerald-500" /> الدخول ({{ meta.stay.check_in_time }})
                                </label>
                                <input v-model="form.booking_date" type="date" :min="minDate" class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm" />
                                <p v-if="checkInHijri" class="mt-1 flex flex-wrap items-center gap-1 text-[11px] font-bold text-emerald-700">
                                    <span class="rounded bg-emerald-50 px-1.5 py-0.5">{{ checkInHijri }}</span>
                                    <span class="text-slate-500">{{ checkInDay }}</span>
                                </p>
                                <p v-if="form.errors.booking_date" class="mt-1 text-xs text-red-500">{{ form.errors.booking_date }}</p>
                            </div>
                            <div>
                                <label class="mb-1 flex items-center gap-1 text-xs font-bold text-slate-700">
                                    <LogOut class="h-3.5 w-3.5 text-rose-500" /> الخروج ({{ meta.stay.check_out_time }})
                                </label>
                                <input v-model="form.check_out_date" type="date" :min="form.booking_date" class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm" />
                                <p v-if="checkOutHijri" class="mt-1 flex flex-wrap items-center gap-1 text-[11px] font-bold text-rose-700">
                                    <span class="rounded bg-rose-50 px-1.5 py-0.5">{{ checkOutHijri }}</span>
                                    <span class="text-slate-500">{{ checkOutDay }}</span>
                                </p>
                                <p v-if="form.errors.check_out_date" class="mt-1 text-xs text-red-500">{{ form.errors.check_out_date }}</p>
                            </div>
                        </div>

                        <!-- مدد سريعة: أغلب الإقامات ليلة أو ليلتان أو نهاية أسبوع -->
                        <div v-if="isStay" class="mt-3 flex flex-wrap items-center gap-1.5">
                            <span class="text-[11px] font-bold text-slate-500">مدد سريعة:</span>
                            <button v-for="n in [1, 2, 3, 7]" :key="n" type="button" @click="setNights(n)"
                                class="rounded-lg px-2.5 py-1 text-[11px] font-bold transition"
                                :class="nights === n ? 'bg-teal-600 text-white' : 'bg-slate-100 text-slate-600 hover:bg-slate-200'"
                            >{{ n }} {{ n === 1 ? 'ليلة' : 'ليالٍ' }}</button>
                        </div>

                        <p v-if="isStay && overMaxNights" class="mt-2 rounded-lg bg-red-50 px-2.5 py-1.5 text-[11px] font-bold text-red-600">
                            أقصى مدة إقامة {{ meta.stay.max_nights }} ليلة، والمطلوب {{ nights }}.
                        </p>
                    </div>

                    <!-- النطاق -->
                    <div v-if="selectedUnit" class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                        <h2 class="mb-3 text-sm font-extrabold text-slate-800">نطاق الحجز</h2>

                        <div class="grid gap-2 sm:grid-cols-2">
                            <label v-if="canBookWhole" class="cursor-pointer rounded-xl border-2 p-3 text-center transition" :class="form.scope === 'whole' ? 'border-teal-400 bg-teal-50' : 'border-slate-200 hover:border-slate-300'">
                                <input v-model="form.scope" type="radio" value="whole" class="sr-only" />
                                <div class="text-sm font-extrabold text-slate-800">الشاليه كاملًا</div>
                                <div class="mt-0.5 text-[11px] font-medium text-slate-500">تُقفل جميع الأقسام</div>
                            </label>
                            <label v-if="canBookSections" class="cursor-pointer rounded-xl border-2 p-3 text-center transition" :class="form.scope === 'sections' ? 'border-teal-400 bg-teal-50' : 'border-slate-200 hover:border-slate-300'">
                                <input v-model="form.scope" type="radio" value="sections" class="sr-only" />
                                <div class="text-sm font-extrabold text-slate-800">أقسام محددة</div>
                                <div class="mt-0.5 text-[11px] font-medium text-slate-500">حجز قسم دون بقية الشاليه</div>
                            </label>
                        </div>

                        <div v-if="form.scope === 'sections'" class="mt-2">
                            <div class="mb-1 text-[11px] font-bold text-slate-600">اختر الأقسام</div>
                            <div class="flex flex-wrap gap-1.5">
                                <button
                                    v-for="s in selectedUnit.sections"
                                    :key="s.id"
                                    type="button"
                                    @click="toggleSection(s.id)"
                                    class="rounded-lg px-3 py-1.5 text-xs font-bold transition"
                                    :class="form.section_ids.includes(s.id) ? 'bg-teal-600 text-white shadow-sm' : 'bg-slate-100 text-slate-700 hover:bg-slate-200'"
                                >{{ s.name }}</button>
                            </div>
                            <p v-if="form.errors.section_ids" class="mt-1 text-xs text-red-500">{{ form.errors.section_ids }}</p>
                        </div>
                    </div>

                    <!-- الخدمات الإضافية -->
                    <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                        <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
                            <h2 class="text-sm font-extrabold text-slate-800">الخدمات الإضافية</h2>
                            <Link v-if="can('addons.view')" href="/admin/addons" class="text-[11px] font-bold text-blue-600 hover:underline">
                                إدارة الخدمات وأسعارها
                            </Link>
                        </div>
                        <div class="space-y-1.5">
                            <div v-for="a in addons" :key="a.id" class="flex items-center gap-2 rounded-xl border border-slate-200 px-3 py-2">
                                <input type="checkbox" :checked="!!form.addons[a.id]" @change="toggleAddon(a.id)" class="h-4 w-4 rounded border-slate-300 text-teal-600" />
                                <span class="flex-1 text-sm font-bold text-slate-700">{{ a.name }}</span>
                                <span class="text-xs font-bold text-slate-500">{{ money(a.price) }}</span>
                                <input v-if="form.addons[a.id]" v-model.number="form.addons[a.id]" type="number" min="1" class="w-20 rounded-lg border border-slate-200 px-2 py-1 text-xs" />
                            </div>
                        </div>
                    </div>

                    <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                        <div class="grid gap-3 sm:grid-cols-2">
                            <div>
                                <label class="mb-1 block text-sm font-bold text-slate-700">الخصم</label>
                                <input v-model.number="form.discount_amount" type="number" min="0" step="0.01" class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm" />
                            </div>
                            <div>
                                <label class="mb-1 block text-sm font-bold text-slate-700">الحالة</label>
                                <select v-model="form.status" class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm">
                                    <option v-for="s in meta.statuses" :key="s.key" :value="s.key">{{ s.label }}</option>
                                </select>
                            </div>
                        </div>

                        <label class="mb-1 mt-3 block text-sm font-bold text-slate-700">ملاحظات</label>
                        <textarea v-model="form.notes" rows="2" class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm"></textarea>
                    </div>
                </div>

                <!-- لوحة الإتاحة والتسعير -->
                <aside class="space-y-3 lg:sticky lg:top-4 lg:self-start">
                    <div v-if="checking" class="flex items-center gap-2 rounded-xl bg-white px-4 py-3 text-sm font-bold text-slate-500 shadow-sm">
                        <Loader2 class="h-4 w-4 animate-spin" /> جارٍ فحص الإتاحة…
                    </div>

                    <div v-else-if="blocked" class="rounded-xl border-2 border-red-200 bg-red-50 p-4">
                        <div class="mb-1 flex items-center gap-2 text-sm font-extrabold text-red-700">
                            <AlertTriangle class="h-4 w-4 shrink-0" /> الشاليه غير متاح
                        </div>
                        <p class="text-xs font-medium leading-relaxed text-red-700">{{ quote?.availability.reason }}</p>
                    </div>

                    <div v-else-if="quote" class="rounded-xl border-2 border-emerald-200 bg-emerald-50 p-3">
                        <div class="flex items-center gap-2 text-sm font-extrabold text-emerald-700">
                            <CheckCircle2 class="h-4 w-4" /> {{ isStay ? 'الليالي متاحة' : 'الفترة متاحة' }}
                        </div>
                    </div>

                    <div v-if="pricing" class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
                        <!-- ملخّص الليالي: السعر مجموع ليالٍ، ومتوسط الليلة هو ما يفاوض عليه العميل -->
                        <div v-if="isStay" class="mb-2 grid grid-cols-2 gap-2">
                            <div class="rounded-xl bg-teal-50 p-2 text-center">
                                <div class="text-[10px] font-bold text-teal-600">الليالي</div>
                                <div class="text-base font-extrabold text-teal-700">{{ pricing.nights }}</div>
                            </div>
                            <div class="rounded-xl bg-slate-50 p-2 text-center">
                                <div class="text-[10px] font-bold text-slate-500">متوسط الليلة</div>
                                <div class="text-base font-extrabold text-slate-700">{{ money(pricing.average_night) }}</div>
                            </div>
                        </div>

                        <!-- الحجز النهاري يُقاس بالفترة والأيام لا بالليالي -->
                        <div v-else class="mb-2 grid grid-cols-2 gap-2">
                            <div class="rounded-xl bg-teal-50 p-2 text-center">
                                <div class="text-[10px] font-bold text-teal-600">الفترة</div>
                                <div class="text-base font-extrabold text-teal-700">{{ periodLabel(form.period) }}</div>
                            </div>
                            <div class="rounded-xl bg-slate-50 p-2 text-center">
                                <div class="text-[10px] font-bold text-slate-500">الأيام</div>
                                <div class="text-base font-extrabold text-slate-700">{{ pricing.days ?? days }}</div>
                            </div>
                        </div>

                        <div class="mb-2 flex flex-wrap gap-1">
                            <span v-if="isStay && pricing.weekend_nights > 0" class="rounded bg-amber-100 px-2 py-0.5 text-[11px] font-bold text-amber-700">
                                {{ pricing.weekend_nights }} ليلة نهاية أسبوع
                            </span>
                            <span v-else-if="!isStay && pricing.is_weekend" class="rounded bg-amber-100 px-2 py-0.5 text-[11px] font-bold text-amber-700">
                                يشمل نهاية الأسبوع
                            </span>
                        </div>

                        <div class="space-y-1 border-b border-slate-100 pb-2 text-xs">
                            <div v-for="(l, i) in pricing.lines" :key="i" class="flex justify-between gap-2">
                                <span class="font-medium text-slate-600">{{ l.label }}</span>
                                <span class="shrink-0 font-bold text-slate-800">{{ money(l.amount) }}</span>
                            </div>
                        </div>

                        <div class="space-y-1 pt-2 text-xs">
                            <div v-if="pricing.discount_amount > 0" class="flex justify-between text-red-600">
                                <span class="font-medium">الخصم</span>
                                <span class="font-bold">− {{ money(pricing.discount_amount) }}</span>
                            </div>
                            <div class="flex justify-between border-t border-slate-100 pt-1.5 text-base">
                                <span class="font-extrabold text-slate-700">الإجمالي</span>
                                <span class="font-extrabold text-teal-600">{{ money(pricing.total_amount) }}</span>
                            </div>
                            <div class="flex justify-between text-amber-700">
                                <span class="font-bold">العربون المطلوب</span>
                                <span class="font-extrabold">{{ money(pricing.deposit_amount) }}</span>
                            </div>
                        </div>
                    </div>

                    <!-- السداد عند الحجز — عند الإنشاء فقط، والتعديل له لوحة الدفعات -->
                    <div v-if="!isEdit && pricing" class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
                        <h3 class="mb-2 flex items-center gap-1.5 text-sm font-extrabold text-slate-800">
                            <Wallet class="h-4 w-4 text-slate-400" /> السداد
                        </h3>

                        <div class="grid grid-cols-3 gap-1">
                            <button type="button" @click="setPayChoice('none')"
                                class="rounded-lg py-1.5 text-[11px] font-bold transition"
                                :class="payChoice === 'none' ? 'bg-red-500 text-white' : 'bg-slate-100 text-slate-600 hover:bg-slate-200'"
                            >غير مسدَّد</button>
                            <button type="button" @click="setPayChoice('deposit')"
                                class="rounded-lg py-1.5 text-[11px] font-bold transition"
                                :class="payChoice === 'deposit' ? 'bg-amber-500 text-white' : 'bg-slate-100 text-slate-600 hover:bg-slate-200'"
                            >عربون</button>
                            <button type="button" @click="setPayChoice('full')"
                                class="rounded-lg py-1.5 text-[11px] font-bold transition"
                                :class="payChoice === 'full' ? 'bg-emerald-600 text-white' : 'bg-slate-100 text-slate-600 hover:bg-slate-200'"
                            >مسدَّد كامل</button>
                        </div>

                        <div v-if="form.payment_amount > 0" class="mt-2.5 space-y-2.5">
                            <div>
                                <label class="mb-1 block text-[11px] font-bold text-slate-600">المبلغ المقبوض</label>
                                <input v-model.number="form.payment_amount" type="number" min="0" step="0.01" class="w-full rounded-lg border border-slate-200 px-2.5 py-2 text-sm font-bold" />
                                <p v-if="form.errors.payment_amount" class="mt-1 text-[11px] text-red-500">{{ form.errors.payment_amount }}</p>
                            </div>

                            <div class="grid grid-cols-2 gap-2">
                                <div>
                                    <label class="mb-1 block text-[11px] font-bold text-slate-600">الطريقة</label>
                                    <select v-model="form.payment_method_id" class="w-full rounded-lg border border-slate-200 px-2 py-2 text-xs">
                                        <option v-for="m in meta.payment_methods" :key="m.id" :value="m.id">{{ m.label }}</option>
                                    </select>
                                </div>
                                <div>
                                    <label class="mb-1 block text-[11px] font-bold text-slate-600">تاريخ القبض</label>
                                    <input v-model="form.payment_paid_on" type="date" class="w-full rounded-lg border border-slate-200 px-2 py-2 text-xs" />
                                </div>
                            </div>

                            <div class="flex justify-between rounded-lg bg-slate-50 px-2.5 py-2 text-xs">
                                <span class="font-bold text-slate-600">المتبقي بعد السداد</span>
                                <span class="font-extrabold text-slate-800">{{ money(Math.max(0, pricing.total_amount - form.payment_amount)) }}</span>
                            </div>

                            <label v-if="form.client_id" class="flex cursor-pointer items-center gap-2 text-[11px] font-bold text-slate-700">
                                <input type="checkbox" v-model="form.payment_notify" class="h-3.5 w-3.5 rounded border-slate-300 text-emerald-600" />
                                إشعار النزيل على واتساب
                            </label>
                        </div>

                        <p v-else class="mt-2 text-[11px] font-medium text-slate-500">
                            تُحفظ الإقامة بلا دفعة، ويبقى المبلغ كاملًا على النزيل.
                        </p>
                    </div>

                    <p v-if="form.errors.availability" class="rounded-xl bg-red-50 px-3 py-2 text-xs font-bold text-red-600">{{ form.errors.availability }}</p>

                    <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
                        <p v-if="blocked" class="mb-2 text-xs font-bold text-red-600">لا يمكن الحفظ ما دام الشاليه غير متاح</p>
                        <p v-else-if="isStay && nights < 1" class="mb-2 text-xs font-bold text-amber-600">مدة الإقامة ليلة واحدة على الأقل</p>

                        <!-- خطأ لا يقابله حقل ظاهر — حتى لا يبدو الحفظ بلا أثر -->
                        <ul v-if="otherErrors.length" class="mb-2 space-y-1 rounded-lg bg-red-50 px-3 py-2">
                            <li v-for="(msg, i) in otherErrors" :key="i" class="text-[11px] font-bold text-red-600">{{ msg }}</li>
                        </ul>
                        <div class="flex gap-2">
                            <button type="submit" :disabled="form.processing || blocked || (isStay && (nights < 1 || overMaxNights))" class="flex-1 rounded-md bg-teal-600 px-5 py-2.5 text-sm font-bold text-white shadow-sm transition hover:bg-teal-700 disabled:opacity-50">
                                {{ isEdit ? 'حفظ التعديل' : isStay ? 'حفظ الإقامة' : 'حفظ الحجز' }}
                            </button>
                            <Link href="/admin/bookings/chalets" class="rounded-xl border border-slate-200 bg-white px-4 py-2.5 text-sm font-bold text-slate-600 hover:bg-slate-50">
                                إلغاء
                            </Link>
                        </div>
                    </div>
                </aside>
            </div>
        </form>
    </AppLayout>
</template>
