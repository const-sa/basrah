<script setup lang="ts">
import AvailabilityDatePicker from '@/components/AvailabilityDatePicker.vue';
import ClientQuickAdd from '@/components/ClientQuickAdd.vue';
import SearchableSelect from '@/components/SearchableSelect.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { csrfToken } from '@/lib/csrf';
import { addDays, daysBetween, formatTime12, startOfMonth, todayString } from '@/lib/dates';
import { toHijri, weekdayName } from '@/lib/hijri';
import { type BreadcrumbItem, type PaymentMethodOption } from '@/types';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { AlertTriangle, ArrowRight, CheckCircle2, Loader2, LogIn, LogOut, Moon, ShieldCheck, Wallet } from 'lucide-vue-next';
import { computed, nextTick, onMounted, ref, watch } from 'vue';

interface SectionOption { id: number; name: string; gender: string; is_active: boolean }
interface UnitOption {
    id: number; name: string; code: string; type: string;
    bookable_mode: 'whole' | 'sections' | 'both';
    /** Derived on the server from the chalet's rooms — never a free choice. */
    allows_whole: boolean;
    allows_sections: boolean;
    privacy_mode: 'open' | 'exclusive';
    /** Day periods this chalet is priced for — empty means stays only. */
    day_use_periods: string[];
    /** The refundable deposit normally taken on this chalet — 0 means none. */
    security_deposit: number;
    /** ساعات فترات هذا الشاليه سارية المفعول — ساعته إن كُتبت، وإلا ساعة الإعدادات. */
    hours: Record<string, { start: string; end: string }>;
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
    /** 'overnight' is a stay; 'hourly' is sold by the hour; anything else is day use. */
    period: string;
    days_count: number | null;
    /** مدى الحجز المحفوظ — منه تُقرأ ساعتا الحجز بالساعات عند تعديله. */
    starts_at: string;
    ends_at: string;
    /** المبلغ المتَّفق عليه — هو مبلغ الحجز بالساعات كما أُدخل. */
    base_amount: number;
    status: string;
    discount_amount: number;
    /** The deposit agreed on this booking, and what is still held of it. */
    security_deposit_amount: number;
    security_held: number;
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
        /** الضريبة مستخرجة من الإجمالي شاملةً لا مضافة فوقه. */
        is_taxable: boolean; tax_rate: number; net_amount: number; tax_amount: number;
        /** Stay quotes carry nights; day-use quotes carry days instead. */
        nights?: number; weekend_nights?: number; average_night?: number;
        days?: number;
        /** وحجز الساعات يحمل ساعاته — لا ليلة له ولا يوم. */
        hours?: number; hours_label?: string;
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
        /** Day periods with their configured hours — the day-use menu. */
        periods: { key: string; label: string; start: string; end: string }[];
        stay: { check_in_time: string; check_out_time: string; max_nights: number };
        payment_methods: PaymentMethodOption[];
    };
}>();

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

/** عدد الليالي بين تاريخين — يوم الخروج غير محسوب ليلةً، كقاعدة الخادم. */
const nightsBetween = daysBetween;

const today = todayString();

/** The period value that means "an overnight stay" rather than day use. */
const STAY = 'overnight';

/**
 * الحجز بالساعات: شكلٌ ثالث لا فترةَ له في الإعدادات.
 *
 * الفترات الثلاث ساعاتها مكتوبة سلفًا وأسعارها مسعَّرة؛ وهذا يكتب الموظف
 * ساعتيه ويُدخل مبلغه المتَّفق عليه في المكالمة.
 */
const HOURLY = 'hourly';

/** ساعة الطابع الزمني «Y-m-d H:i:s» كما تقبلها خانة الوقت. */
const timeOf = (at: string) => at.slice(11, 16);

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
    // ساعتا الحجز بالساعات ومبلغه. الحجز القائم يقرؤهما من مداه المحفوظ،
    // فتفتح الشاشة على ما اتُّفق عليه لا على قيمٍ افتراضية.
    start_time: props.booking?.period === HOURLY ? timeOf(props.booking.starts_at) : '16:00',
    end_time: props.booking?.period === HOURLY ? timeOf(props.booking.ends_at) : '21:00',
    hourly_amount: props.booking?.period === HOURLY ? props.booking.base_amount : 0,
    // الإقامة الجديدة مؤكدة افتراضيًا — و«مبدئي» يُختار من القائمة عند الحاجة.
    status: props.booking?.status ?? 'confirmed',
    addons: { ...(props.booking?.addons ?? {}) } as Record<number, number>,
    discount_amount: props.booking?.discount_amount ?? 0,
    // The contract prints it. Left empty it prints «—», which is the honest
    // answer when nobody was asked how many were coming.
    guests_count: props.booking?.guests_count ?? (null as number | null),
    notes: props.booking?.notes ?? '',

    // The security deposit: money held against damage, entirely outside the
    // price. A new booking starts from the chalet's usual amount once one is
    // chosen; an existing one keeps whatever was agreed on it.
    security_deposit_amount: props.booking?.security_deposit_amount ?? 0,
    security_collected: true,

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

/**
 * The rooms this chalet can be let by.
 *
 * A stopped room is left out — availability refuses it anyway — except one
 * this booking already holds, so an older booking opens with its room still
 * selected instead of with nothing.
 */
const bookableSections = computed<SectionOption[]>(() => {
    const held = props.booking?.section_ids ?? [];

    return (selectedUnit.value?.sections ?? []).filter((s) => s.is_active || held.includes(s.id));
});

/**
 * Whether this chalet is let by the room.
 *
 * Not a choice the clerk makes: a chalet with rooms is let by the room, and
 * one without is let whole. The server derives the same answer from the same
 * fact (Unit::allowsSectionBooking), so the form states the scope instead of
 * asking for it and cannot post one the save would refuse.
 */
const letByRoom = computed(() => selectedUnit.value?.allows_sections ?? false);

// ── إقامة بليالٍ أم حجز نهاري ───────────────────────────────

const isStay = computed(() => form.period === STAY);
const isHourly = computed(() => form.period === HOURLY);

/**
 * عدد ساعات المدى المكتوب، مقرَّبًا إلى ربع الساعة كما يقرّبه الخادم.
 *
 * والنهاية التي لا تتجاوز البداية تقع في الغد: من العاشرة مساءً إلى الواحدة
 * صباحًا ثلاث ساعات لا واحدٌ وعشرون بالسالب.
 */
const hourlyMinutes = computed(() => {
    const [fromH, fromM] = (form.start_time || '').split(':').map(Number);
    const [toH, toM] = (form.end_time || '').split(':').map(Number);

    if ([fromH, fromM, toH, toM].some((n) => Number.isNaN(n))) return 0;

    const span = toH * 60 + toM - (fromH * 60 + fromM);

    return span > 0 ? span : span + 24 * 60;
});

const hours = computed(() => Math.round(hourlyMinutes.value / 60 * 4) / 4);

/** عدد الساعات كما يُقرأ — «ساعتان ونصف» لا «2.5». */
const hoursLabel = computed(() => {
    const whole = Math.floor(hours.value);
    const fraction = Math.round((hours.value - whole) * 100) / 100;
    const fractionLabel = { 0.25: 'ربع', 0.5: 'نصف', 0.75: 'ثلاثة أرباع' }[fraction] ?? null;

    const wholeLabel = whole === 0 ? null
        : whole === 1 ? 'ساعة'
        : whole === 2 ? 'ساعتان'
        : whole <= 10 ? `${whole} ساعات`
        : `${whole} ساعة`;

    if (wholeLabel === null) return fractionLabel === null ? 'أقل من ساعة' : `${fractionLabel} ساعة`;

    return fractionLabel === null ? wholeLabel : `${wholeLabel} و${fractionLabel}`;
});

/** ما يمنع الحفظ من مدى الساعات — نصفُ ساعةٍ حدٌّ أدنى، وما بلغ اليوم يُحجز ليلةً. */
const hourlyBlocker = computed<string | null>(() => {
    if (!isHourly.value) return null;
    if (hourlyMinutes.value < 30) return 'أقصر حجز بالساعات 30 دقيقة.';
    if (hourlyMinutes.value > 23 * 60) return 'ما تجاوز 23 ساعة يُحجز بالليلة لا بالساعات.';

    return null;
});

/**
 * Day periods the selected chalet may be sold for. Pricing is what opens a
 * period, so a chalet nobody priced for day use offers none and the mode
 * toggle stays hidden rather than leading to a refusal on save.
 */
const dayPeriods = computed(() => {
    const allowed = selectedUnit.value?.day_use_periods ?? [];

    // بساعات هذا الشاليه لا بساعات الإعدادات: الفترة قد تكون مكتوبةً له
    // بساعة أخرى، وعليها سيُبنى حجزه فعلًا.
    return props.meta.periods
        .filter((p) => allowed.includes(p.key))
        .map((p) => ({ ...p, ...unitHours(p.key) }));
});

/**
 * ساعتا فترةٍ على الشاليه المختار، وإلا ساعتا الإعدادات.
 *
 * الشاشة تقول ساعة الوحدة لا ساعة النظام، لأنها الساعة التي يُسلَّم بها
 * النزيل ويُطبع بها عقده.
 */
function unitHours(key: string): { start: string; end: string } {
    const written = selectedUnit.value?.hours?.[key];

    if (written) return written;

    if (key === STAY) {
        return { start: props.meta.stay.check_in_time, end: props.meta.stay.check_out_time };
    }

    const fallback = props.meta.periods.find((p) => p.key === key);

    return { start: fallback?.start ?? '', end: fallback?.end ?? '' };
}

/** ساعتا الإقامة على الشاليه المختار — ما يُعرض على خانتَي الدخول والخروج. */
const stayHours = computed(() => unitHours(STAY));

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

/**
 * الفترة كحقلٍ واحد: «إقامة بليالٍ» أو إحدى فترات اليوم المسعَّرة.
 *
 * العبور بين الوجهين يجرّ معه ضبط المدى — ليلةً للإقامة ويومًا للفترة — فيمرّ
 * الاختيار على setMode بدل الكتابة في form.period مباشرةً وترك مدى متناقض.
 */
const periodChoice = computed<string>({
    get: () => form.period,
    set: (key) => {
        if (key === STAY) {
            setMode(true);

            return;
        }

        // الحجز بالساعات لا يتكرر أيامًا ولا يقرأ فترةً مسعَّرة، فيُكتب
        // مباشرةً بلا ضبط المدى الذي يلزم الفترات النهارية.
        if (key === HOURLY) {
            form.period = HOURLY;
            form.days_count = 1;

            return;
        }

        if (isStay.value) setMode(false);

        form.period = key;
    },
});

const hydrating = ref(true);

onMounted(() => nextTick(() => (hydrating.value = false)));

watch(() => form.unit_id, () => {
    if (hydrating.value) return;

    form.section_ids = [];
    // Each chalet has its own deposit, so switching chalets brings its amount
    // rather than carrying the previous one across.
    form.security_deposit_amount = selectedUnit.value?.security_deposit ?? 0;
});

// The scope follows the chalet rather than the clerk. Immediate, so an edit
// opened on a chalet whose rooms have changed since is corrected on arrival
// instead of posting a scope that unit no longer accepts.
watch(letByRoom, (byRoom) => {
    form.scope = byRoom ? 'sections' : 'whole';

    if (!byRoom) form.section_ids = [];
}, { immediate: true });

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

/**
 * القسم المختار مفردًا — واجهةُ الحقل المنسدل على مصفوفة section_ids.
 *
 * الخادم يستقبل مصفوفة (يتّسع لحجزٍ متعدّد الأقسام لاحقًا)، والحجز اليوم
 * يأخذ قسمًا واحدًا، فيُترجم هنا بدل أن يعرف الحقل بالمصفوفة.
 */
const sectionId = computed<number | null>({
    get: () => form.section_ids[0] ?? null,
    set: (id) => {
        form.section_ids = id ? [id] : [];
    },
});

/**
 * فحص الإتاحة واحتساب سعر الإقامة على الخادم عند كل تغيير مؤثر.
 * التسعير هنا مجموع ليالٍ لا سعر يوم، فلا يمكن اشتقاقه في الواجهة.
 */
let timer: ReturnType<typeof setTimeout> | undefined;
const refreshQuote = () => {
    // A stay needs a night between its dates; a day-use booking only needs
    // its period, which the date and days count already imply.
    // الحجز بالساعات جاهزٌ للسؤال متى صحّ مداه؛ ومبلغه لا يمنع السؤال لأن
    // الإتاحة تُفحص قبل أن يُتَّفق على المبلغ.
    const rangeReady = isStay.value
        ? nights.value >= 1
        : isHourly.value
            ? hourlyBlocker.value === null
            : form.period !== STAY;

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
                    start_time: isHourly.value ? form.start_time : null,
                    end_time: isHourly.value ? form.end_time : null,
                    hourly_amount: isHourly.value ? form.hourly_amount : null,
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
    () => [form.unit_id, form.scope, [...form.section_ids], form.booking_date, form.check_out_date, form.period, form.days_count, form.start_time, form.end_time, form.hourly_amount, form.client_id, JSON.stringify(form.addons), form.discount_amount],
    refreshQuote,
    { deep: true },
);

onMounted(refreshQuote);

const blocked = computed(() => quote.value !== null && !quote.value.availability.ok);
const pricing = computed(() => quote.value?.pricing ?? null);

// ── The chalet's diary: what is taken, known before a date is picked ──

interface DayAvailability {
    /** Is the night that starts on this day still free? */
    stay: boolean;
    /** Each day period this chalet is priced for, and whether it is free. */
    periods: Record<string, boolean>;
}

/**
 * The chosen chalet's diary for the days the calendar is showing.
 *
 * The quote is still what decides a save; this exists so a taken day can be
 * refused where it is picked rather than after the fact. An empty map — no
 * chalet chosen yet, or the request failed — marks nothing, so the form
 * behaves exactly as it did before.
 */
const availability = ref<Record<string, DayAvailability>>({});
const loadingDiary = ref(false);

/**
 * The stretch of days already loaded. It is deliberately wider than one month
 * grid, so paging a month either way costs no round trip.
 */
const diaryWindow = ref({ from: '', to: '' });

const setDiaryWindow = (anchor: string) => {
    const from = addDays(startOfMonth(anchor), -10);

    diaryWindow.value = { from, to: addDays(from, 91) };
};

setDiaryWindow(form.booking_date);

/** A grid a picker is about to draw — reloaded only when it falls outside. */
const onCalendarView = ({ from, to }: { from: string; to: string }) => {
    if (from >= diaryWindow.value.from && to <= diaryWindow.value.to) return;

    setDiaryWindow(from);
};

let diaryTimer: ReturnType<typeof setTimeout> | undefined;
const refreshAvailability = () => {
    const ready = form.unit_id && diaryWindow.value.from
        && (form.scope !== 'sections' || form.section_ids.length > 0);

    if (!ready) {
        availability.value = {};

        return;
    }

    clearTimeout(diaryTimer);
    diaryTimer = setTimeout(async () => {
        loadingDiary.value = true;
        try {
            const res = await fetch('/admin/bookings/chalets/availability', {
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
                    from: diaryWindow.value.from,
                    to: diaryWindow.value.to,
                    client_id: form.client_id,
                    ignore_booking_id: props.booking?.id ?? null,
                }),
            });
            availability.value = res.ok ? ((await res.json()).days ?? {}) : {};
        } finally {
            loadingDiary.value = false;
        }
    }, 250);
};

watch(
    () => [form.unit_id, form.scope, [...form.section_ids], form.client_id, diaryWindow.value.from, diaryWindow.value.to],
    refreshAvailability,
    { deep: true },
);

onMounted(refreshAvailability);

/** Nights already taken — a stay may not begin on one of them. */
const takenNights = computed(() =>
    Object.entries(availability.value)
        .filter(([, day]) => !day.stay)
        .map(([date]) => date),
);

/**
 * Day-use days, split by how much of them is gone. A day is closed only when
 * every period this chalet sells is taken; one taken period out of three still
 * leaves a day worth opening, marked so nobody picks it expecting all three.
 */
const dayUseDays = computed(() => {
    const keys = dayPeriods.value.map((p) => p.key);
    const closed: string[] = [];
    const partial: string[] = [];

    if (keys.length) {
        for (const [date, day] of Object.entries(availability.value)) {
            const taken = keys.filter((key) => day.periods[key] === false).length;

            if (taken === keys.length) {
                closed.push(date);
            } else if (taken > 0) {
                partial.push(date);
            }
        }
    }

    return { closed, partial };
});

/** The nights the chosen stay occupies — drawn as a band in both calendars. */
const stayNights = computed(() =>
    Array.from({ length: Math.max(0, Math.min(nights.value, 120)) }, (_, i) => addDays(form.booking_date, i)),
);

/** The days the chosen day-use booking repeats over. */
const dayUseSpan = computed(() =>
    Array.from({ length: days.value }, (_, i) => addDays(form.booking_date, i)),
);

const minCheckOut = computed(() => addDays(form.booking_date, 1));

/**
 * The latest departure the chosen arrival allows: a stay may not run through a
 * night that is already taken, so it ends on the morning that night begins.
 */
const maxCheckOut = computed(() => {
    for (let i = 0; i < props.meta.stay.max_nights; i++) {
        const night = addDays(form.booking_date, i);

        if (availability.value[night]?.stay === false) return night;
    }

    return addDays(form.booking_date, props.meta.stay.max_nights);
});

// Moving the arrival can push the departure past a night someone else has.
// Pulling it back is kinder than leaving a range the server will refuse — and
// a stay of no nights is never the answer, so an arrival with nothing free
// after it is left alone for the check-in calendar to explain.
watch(maxCheckOut, (max) => {
    if (hydrating.value || !isStay.value) return;

    if (max > form.booking_date && form.check_out_date > max) {
        form.check_out_date = max;
    }
});

/** Whether a period is still free on every day this booking would span. */
const periodFree = (key: string): boolean =>
    dayUseSpan.value.every((date) => availability.value[date]?.periods[key] !== false);

/**
 * لماذا لا يُحفظ الحجز النهاري بعد.
 *
 * الإقامة يحرسها مدًى ظاهر — ليلةٌ على الأقل — أما الفترة فلا مدى لها يُقاس،
 * فالتحقّق نفسه هو حارسها: فترةٌ محجوزة، أو تحقّقٌ لم يتمّ بعد، يمنعان الحفظ
 * بدل أن يمرّ الحجز ويرتدّ من الخادم.
 */
const dayUseBlocker = computed<string | null>(() => {
    // الحجز بالساعات ليس فترةً في التقويم: التقويم يرسم الفترات الثلاث
    // وحدها، فقراءةُ إتاحته منها تقول «محجوزة» عن شكلٍ لا وجود له فيها.
    // حارسه مداه هو، وعرضُ السعر يفحص التعارض قبل الحفظ.
    if (isStay.value || isHourly.value) return null;

    if (!periodFree(form.period)) return 'الفترة المختارة محجوزة في أحد أيام الحجز — اختر فترة أخرى أو تاريخًا آخر.';

    if (checking.value) return 'جارٍ التحقق من إتاحة الفترة…';

    if (quote.value === null) return 'أكمل الشاليه والقسم ليتم التحقق من إتاحة الفترة.';

    return null;
});

// ── The security deposit: held, not charged ─────────────────

/** What this chalet usually asks for — the figure the form starts from. */
const unitSecurityDeposit = computed(() => selectedUnit.value?.security_deposit ?? 0);

const securityChanged = computed(() =>
    unitSecurityDeposit.value > 0 && Number(form.security_deposit_amount) !== unitSecurityDeposit.value,
);

/**
 * The statuses this form may set.
 *
 * Neither end of the stay is picked from here: «تم الدخول» and «تم الخروج»
 * follow from the stay itself, not from a choice made while editing. Each one
 * stays on the list for a booking already sitting in it, so an older one can
 * still be opened and corrected instead of quietly changing status the moment
 * it is saved.
 */
const HIDDEN_STATUSES = ['checked_in', 'checked_out'];

const statusOptions = computed(() =>
    props.meta.statuses.filter(
        (s) => !HIDDEN_STATUSES.includes(s.key) || props.booking?.status === s.key,
    ),
);

// ── السداد عند إنشاء الحجز ──────────────────────────────────
const suggestedTotal = computed(() => pricing.value?.total_amount ?? 0);

const payChoice = computed(() => {
    if (form.payment_amount <= 0) return 'none';
    if (form.payment_amount >= suggestedTotal.value && suggestedTotal.value > 0) return 'full';

    return 'custom';
});

const setPayChoice = (choice: 'none' | 'full') => {
    if (choice === 'none') {
        form.payment_amount = 0;

        return;
    }

    form.payment_amount = suggestedTotal.value;
    form.payment_type = 'payment';
};

/**
 * Errors whose field is on screen right now. Anything else is listed in the
 * summary below the form — a hidden field failing validation used to leave
 * the page looking as if the save button did nothing.
 */
const inlineErrorKeys = computed(() => [
    'unit_id', 'client_id', 'booking_date', 'period', 'days_count',
    'availability', 'payment_amount', 'discount_amount', 'notes',
    'security_deposit_amount', 'start_time', 'end_time', 'hourly_amount',
    ...(isStay.value ? ['check_out_date'] : []),
]);

const otherErrors = computed(() =>
    Object.entries(form.errors)
        .filter(([key]) => !inlineErrorKeys.value.includes(key))
        .map(([, message]) => message as string),
);

const submit = () => {
    // الزرّ معطَّل في هذه الحال، لكن Enter داخل حقلٍ يُرسل النموذج من دونه —
    // فالحارس هنا لا في الزرّ وحده.
    if (dayUseBlocker.value !== null || hourlyBlocker.value !== null) return;

    // The two shapes carry different fields, and the one that does not apply
    // is sent as null rather than left at whatever the hidden input still
    // holds — a stale check-out date would otherwise fail a rule for a field
    // the day-use form never renders.
    form.transform((data) => ({
        ...data,
        check_out_date: isStay.value ? data.check_out_date : null,
        days_count: isStay.value || isHourly.value ? null : days.value,
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
                    <p class="mt-1 text-sm font-semibold text-slate-700">
                        إقامة بالليالي — دخول {{ stayHours.start }} وخروج {{ stayHours.end }}
                    </p>
                </div>
                <Link href="/admin/bookings/chalets" class="inline-flex items-center gap-1.5 rounded-md border border-slate-300 bg-white px-4 py-2 text-sm font-extrabold text-slate-800 shadow-sm hover:bg-slate-50">
                    <ArrowRight class="h-4 w-4" /> رجوع للسجل
                </Link>
            </div>

            <div class="grid gap-5 lg:grid-cols-[1fr_340px]">
                <div class="space-y-4">
                    <div class="rounded-2xl border border-slate-300 bg-white p-5 shadow-sm">
                        <h2 class="mb-4 border-b-2 border-slate-300 pb-2.5 text-base font-extrabold text-slate-900">الشاليه والنزيل</h2>

                        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                            <div>
                                <label class="mb-1 block text-sm font-extrabold text-slate-900">الشاليه</label>
                                <select v-model="form.unit_id" class="w-full rounded-xl border border-slate-400 px-3 py-2.5 text-sm">
                                    <option :value="null">— اختر الشاليه —</option>
                                    <option v-for="u in units" :key="u.id" :value="u.id">{{ u.name }} ({{ u.code }})</option>
                                </select>
                                <p v-if="form.errors.unit_id" class="mt-1 text-xs text-red-500">{{ form.errors.unit_id }}</p>
                                <p v-else-if="selectedUnit && !letByRoom" class="mt-1 text-[11px] font-semibold text-slate-600">
                                    لا أقسام في هذا الشاليه — يُحجز بكامله.
                                </p>
                            </div>
                            <!-- القسم — يظهر للشاليه المقسَّم وحده، فغير المقسَّم يُحجز كاملًا ولا شيء يُختار -->
                            <div v-if="letByRoom">
                                <label class="mb-1 block text-sm font-extrabold text-slate-900">القسم</label>
                                <select v-model="sectionId" class="w-full rounded-xl border border-slate-400 px-3 py-2.5 text-sm">
                                    <option :value="null">— اختر القسم —</option>
                                    <option v-for="s in bookableSections" :key="s.id" :value="s.id">{{ s.name }}</option>
                                </select>
                                <p v-if="form.errors.section_ids" class="mt-1 text-xs text-red-500">{{ form.errors.section_ids }}</p>
                            </div>
                            <!--
                                التاريخ قبل الفترة، لا بعدها: الفترة تُقرأ متاحةً أو محجوزة على
                                يومٍ بعينه، فلا معنى لعرضها قبل أن يُختار اليوم.
                            -->
                            <div>
                                <label class="mb-1 flex items-center gap-1 text-sm font-extrabold text-slate-900">
                                    <LogIn class="h-3.5 w-3.5 text-emerald-500" /> التاريخ
                                </label>
                                <AvailabilityDatePicker
                                    v-model="form.booking_date"
                                    :min="minDate"
                                    :blocked="isStay ? takenNights : isHourly ? [] : dayUseDays.closed"
                                    :partial="isStay || isHourly ? [] : dayUseDays.partial"
                                    :in-range="isStay ? stayNights : isHourly ? [form.booking_date] : dayUseSpan"
                                    :loading="loadingDiary"
                                    @view="onCalendarView"
                                />
                                <p v-if="checkInHijri" class="mt-1 flex flex-wrap items-center gap-1 text-[11px] font-bold text-emerald-700">
                                    <span class="rounded bg-emerald-50 px-1.5 py-0.5">{{ checkInHijri }}</span>
                                    <span class="font-bold text-slate-700">{{ checkInDay }}</span>
                                </p>
                                <p v-if="form.errors.booking_date" class="mt-1 text-xs text-red-500">{{ form.errors.booking_date }}</p>
                            </div>
                            <!-- الفترة — إقامةً بليالٍ أو فترةً نهارية من فترات هذا الشاليه المسعَّرة -->
                            <div>
                                <label class="mb-1 block text-sm font-extrabold text-slate-900">الفترة</label>
                                <select v-model="periodChoice" class="w-full rounded-xl border border-slate-400 px-3 py-2.5 text-sm">
                                    <option :value="STAY">إقامة بليالٍ</option>
                                    <!-- المحجوزة تبقى معروضة ومعطّلة: أن تُرى محجوزةً أوضح من أن تختفي -->
                                    <option v-for="p in dayPeriods" :key="p.key" :value="p.key" :disabled="!periodFree(p.key)">
                                        {{ p.label }} — {{ formatTime12(p.start) }} إلى {{ formatTime12(p.end) }}{{ periodFree(p.key) ? '' : ' (محجوزة)' }}
                                    </option>
                                    <!-- بالساعات: لا يقرأ جدول الأسعار، فلا يُشترط تسعيرُ فترةٍ لعرضه -->
                                    <option :value="HOURLY">بالساعات — تُحدَّد ساعتاه ومبلغه</option>
                                </select>
                                <p v-if="form.errors.period" class="mt-1 text-xs text-red-500">{{ form.errors.period }}</p>
                                <p v-else-if="!isStay && !isHourly && !periodFree(form.period)" class="mt-1 text-[11px] font-bold text-red-600">
                                    الفترة المختارة محجوزة في هذا التاريخ — اختر فترة أخرى أو تاريخًا آخر.
                                </p>
                                <p v-else-if="selectedUnit && !canBookByDay" class="mt-1 text-[11px] font-semibold text-slate-600">
                                    لا فترات نهارية مسعَّرة لهذا الشاليه — يُحجز بالليالي.
                                </p>
                            </div>
                            <div>
                                <div class="mb-1 flex items-center justify-between gap-2">
                                    <label class="block text-sm font-extrabold text-slate-900">النزيل</label>
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
                        <div class="mb-4 flex items-center justify-between gap-2 border-b-2 border-slate-300 pb-2.5">
                            <h2 class="flex items-center gap-1.5 text-base font-extrabold text-slate-900">
                                <Moon class="h-4 w-4 text-teal-500" />
                                {{ isStay ? 'مدة الإقامة' : isHourly ? 'ساعات الحجز' : 'فترة الحجز' }}
                            </h2>
                            <span class="rounded-lg bg-teal-600 px-3 py-1 text-sm font-extrabold text-white">
                                <template v-if="isStay">{{ nights }} {{ nights === 1 ? 'ليلة' : 'ليالٍ' }}</template>
                                <template v-else-if="isHourly">{{ hoursLabel }}</template>
                                <template v-else>{{ periodLabel(form.period) }}{{ days > 1 ? ` × ${days}` : '' }}</template>
                            </span>
                        </div>

                        <!--
                            الحجز بالساعات: ساعتاه ومبلغه. المبلغ يُكتب هنا لا
                            يُقرأ من جدول — لا تسعيرة لهذا الشكل، وما يُتَّفق
                            عليه في المكالمة هو ما يدخل الحجز.
                        -->
                        <div v-if="isHourly" class="space-y-3">
                            <div class="grid gap-3 sm:grid-cols-3">
                                <div>
                                    <div class="mb-1 flex items-center gap-1 text-sm font-extrabold text-slate-900">
                                        <LogIn class="h-3.5 w-3.5 text-emerald-500" /> من الساعة
                                    </div>
                                    <input v-model="form.start_time" type="time" dir="ltr" class="w-full rounded-xl border border-slate-400 px-3 py-2.5 text-sm" />
                                    <p v-if="form.errors.start_time" class="mt-1 text-xs text-red-500">{{ form.errors.start_time }}</p>
                                </div>
                                <div>
                                    <div class="mb-1 flex items-center gap-1 text-sm font-extrabold text-slate-900">
                                        <LogOut class="h-3.5 w-3.5 text-rose-500" /> إلى الساعة
                                    </div>
                                    <input v-model="form.end_time" type="time" dir="ltr" class="w-full rounded-xl border border-slate-400 px-3 py-2.5 text-sm" />
                                    <p v-if="form.errors.end_time" class="mt-1 text-xs text-red-500">{{ form.errors.end_time }}</p>
                                </div>
                                <div>
                                    <label class="mb-1 block text-sm font-extrabold text-slate-900">المبلغ المتَّفق عليه</label>
                                    <input v-model.number="form.hourly_amount" type="number" min="0" step="any" dir="ltr" class="w-full rounded-xl border border-slate-400 px-3 py-2.5 text-sm" />
                                    <p v-if="form.errors.hourly_amount" class="mt-1 text-xs text-red-500">{{ form.errors.hourly_amount }}</p>
                                </div>
                            </div>

                            <p v-if="hourlyBlocker" class="rounded-xl bg-red-50 px-3 py-2 text-xs font-bold text-red-600">
                                {{ hourlyBlocker }}
                            </p>
                            <p v-else class="text-[11px] font-semibold text-slate-600">
                                {{ hoursLabel }} — تُقفل الوحدة فيها ولا تُباع لغير هذا النزيل.
                                <span v-if="hourlyMinutes > 0 && form.end_time <= form.start_time" class="font-bold text-amber-700">
                                    النهاية في اليوم التالي.
                                </span>
                            </p>
                        </div>

                        <!-- الحجز النهاري: التاريخ والفترة فوق، فلا يبقى هنا إلا تكرار الفترة أيامًا -->
                        <div v-else-if="!isStay" class="grid gap-3 sm:grid-cols-2">
                            <div>
                                <label class="mb-1 block text-sm font-extrabold text-slate-900">عدد الأيام</label>
                                <input v-model.number="form.days_count" type="number" min="1" class="w-full rounded-xl border border-slate-400 px-3 py-2.5 text-sm" />
                                <p class="mt-1 text-[11px] font-semibold text-slate-600">الفترة نفسها تتكرر في كل يوم، ويُسعَّر كل يوم بيومه.</p>
                                <p v-if="form.errors.days_count" class="mt-1 text-xs text-red-500">{{ form.errors.days_count }}</p>
                            </div>
                        </div>

                        <div v-else class="grid gap-3 sm:grid-cols-2">
                            <div>
                                <div class="mb-1 flex items-center gap-1 text-sm font-extrabold text-slate-900">
                                    <LogIn class="h-3.5 w-3.5 text-emerald-500" /> الدخول (<bdi>{{ formatTime12(stayHours.start) }}</bdi>)
                                </div>
                                <div class="rounded-xl border border-slate-300 bg-slate-100 px-3 py-2.5 text-sm font-extrabold text-slate-900">
                                    {{ form.booking_date }}
                                    <span v-if="checkInDay" class="text-xs font-bold text-slate-600">— {{ checkInDay }}</span>
                                </div>
                                <p v-if="checkInHijri" class="mt-1 text-[11px] font-bold text-emerald-700">{{ checkInHijri }}</p>
                            </div>
                            <div>
                                <label class="mb-1 flex items-center gap-1 text-sm font-extrabold text-slate-900">
                                    <LogOut class="h-3.5 w-3.5 text-rose-500" /> الخروج (<bdi>{{ formatTime12(stayHours.end) }}</bdi>)
                                </label>
                                <!-- Capped at the first taken night after arrival: a stay cannot run through someone else's night -->
                                <AvailabilityDatePicker
                                    v-model="form.check_out_date"
                                    :min="minCheckOut"
                                    :max="maxCheckOut"
                                    :in-range="stayNights"
                                    :loading="loadingDiary"
                                    @view="onCalendarView"
                                />
                                <p v-if="checkOutHijri" class="mt-1 flex flex-wrap items-center gap-1 text-[11px] font-bold text-rose-700">
                                    <span class="rounded bg-rose-50 px-1.5 py-0.5">{{ checkOutHijri }}</span>
                                    <span class="font-bold text-slate-700">{{ checkOutDay }}</span>
                                </p>
                                <p v-if="form.errors.check_out_date" class="mt-1 text-xs text-red-500">{{ form.errors.check_out_date }}</p>
                            </div>
                        </div>

                        <!-- مدد سريعة: أغلب الإقامات ليلة أو ليلتان أو نهاية أسبوع -->
                        <div v-if="isStay" class="mt-3 flex flex-wrap items-center gap-1.5">
                            <span class="text-[11px] font-bold text-slate-500">مدد سريعة:</span>
                            <!-- A shortcut that would land past a taken night is offered struck out, not silently refused -->
                            <button v-for="n in [1, 2, 3, 7]" :key="n" type="button" @click="setNights(n)"
                                :disabled="addDays(form.booking_date, n) > maxCheckOut"
                                class="rounded-lg px-2.5 py-1 text-[11px] font-bold transition disabled:cursor-not-allowed disabled:bg-red-50 disabled:text-red-300 disabled:line-through"
                                :class="nights === n ? 'bg-teal-600 text-white' : 'bg-slate-100 text-slate-600 hover:bg-slate-200'"
                            >{{ n }} {{ n === 1 ? 'ليلة' : 'ليالٍ' }}</button>
                        </div>

                        <p v-if="isStay && overMaxNights" class="mt-2 rounded-lg bg-red-50 px-2.5 py-1.5 text-[11px] font-bold text-red-600">
                            أقصى مدة إقامة {{ meta.stay.max_nights }} ليلة، والمطلوب {{ nights }}.
                        </p>
                    </div>


                    <div class="rounded-2xl border border-slate-300 bg-white p-5 shadow-sm">
                        <div class="grid gap-3 sm:grid-cols-3">
                            <div>
                                <label class="mb-1 block text-sm font-extrabold text-slate-900">الخصم</label>
                                <input v-model.number="form.discount_amount" type="number" min="0" step="0.01" class="w-full rounded-xl border border-slate-400 px-3 py-2.5 text-sm" />
                            </div>
                            <div>
                                <label class="mb-1 block text-sm font-extrabold text-slate-900">الحالة</label>
                                <select v-model="form.status" class="w-full rounded-xl border border-slate-400 px-3 py-2.5 text-sm">
                                    <option v-for="s in statusOptions" :key="s.key" :value="s.key">{{ s.label }}</option>
                                </select>
                            </div>
                            <div>
                                <label class="mb-1 block text-sm font-extrabold text-slate-900">
                                    عدد الضيوف <span class="text-[11px] font-bold text-slate-500">(اختياري)</span>
                                </label>
                                <input v-model.number="form.guests_count" type="number" min="1" placeholder="—" class="w-full rounded-xl border border-slate-400 px-3 py-2.5 text-sm" />
                                <p class="mt-1 text-[11px] font-semibold text-slate-600">يُطبع في العقد.</p>
                                <p v-if="form.errors.guests_count" class="mt-1 text-xs text-red-500">{{ form.errors.guests_count }}</p>
                            </div>
                        </div>

                        <label class="mb-1 mt-3 block text-sm font-extrabold text-slate-900">ملاحظات</label>
                        <textarea v-model="form.notes" rows="2" class="w-full rounded-xl border border-slate-400 px-3 py-2.5 text-sm"></textarea>
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

                    <div v-if="pricing" class="rounded-2xl border border-slate-300 bg-white p-4 shadow-sm">
                        <!-- ملخّص الليالي: السعر مجموع ليالٍ، ومتوسط الليلة هو ما يفاوض عليه العميل -->
                        <div v-if="isStay" class="mb-2 grid grid-cols-2 gap-2">
                            <div class="rounded-xl bg-teal-50 p-2 text-center">
                                <div class="text-[10px] font-bold text-teal-600">الليالي</div>
                                <div class="text-base font-extrabold text-teal-700">{{ pricing.nights }}</div>
                            </div>
                            <div class="rounded-xl bg-slate-50 p-2 text-center">
                                <div class="text-[10px] font-extrabold text-slate-600">متوسط الليلة</div>
                                <div class="text-base font-extrabold text-slate-700">{{ money(pricing.average_night) }}</div>
                            </div>
                        </div>

                        <!-- الحجز بالساعات يُقاس بساعاته وبمداها من متى إلى متى -->
                        <div v-else-if="isHourly" class="mb-2 grid grid-cols-2 gap-2">
                            <div class="rounded-xl bg-teal-50 p-2 text-center">
                                <div class="text-[10px] font-bold text-teal-600">المدة</div>
                                <div class="text-base font-extrabold text-teal-700">{{ hoursLabel }}</div>
                            </div>
                            <div class="rounded-xl bg-slate-50 p-2 text-center">
                                <div class="text-[10px] font-extrabold text-slate-600">من — إلى</div>
                                <div class="text-base font-extrabold text-slate-700" dir="ltr">
                                    {{ formatTime12(form.start_time) }} – {{ formatTime12(form.end_time) }}
                                </div>
                            </div>
                        </div>

                        <!-- الحجز النهاري يُقاس بالفترة والأيام لا بالليالي -->
                        <div v-else class="mb-2 grid grid-cols-2 gap-2">
                            <div class="rounded-xl bg-teal-50 p-2 text-center">
                                <div class="text-[10px] font-bold text-teal-600">الفترة</div>
                                <div class="text-base font-extrabold text-teal-700">{{ periodLabel(form.period) }}</div>
                            </div>
                            <div class="rounded-xl bg-slate-50 p-2 text-center">
                                <div class="text-[10px] font-extrabold text-slate-600">الأيام</div>
                                <div class="text-base font-extrabold text-slate-700">{{ pricing.days ?? days }}</div>
                            </div>
                        </div>

                        <div class="mb-2 flex flex-wrap gap-1">
                            <span v-if="isStay && pricing.weekend_nights > 0" class="rounded bg-amber-100 px-2 py-0.5 text-[11px] font-bold text-amber-700">
                                {{ pricing.weekend_nights }} ليلة نهاية أسبوع
                            </span>
                            <span v-else-if="!isStay && !isHourly && pricing.is_weekend" class="rounded bg-amber-100 px-2 py-0.5 text-[11px] font-bold text-amber-700">
                                يشمل نهاية الأسبوع
                            </span>
                        </div>

                        <div class="space-y-1 border-b border-slate-100 pb-2 text-xs">
                            <div v-for="(l, i) in pricing.lines" :key="i" class="flex justify-between gap-2">
                                <span class="font-bold text-slate-700">{{ l.label }}</span>
                                <span class="shrink-0 font-bold text-slate-800">{{ money(l.amount) }}</span>
                            </div>
                        </div>

                        <div class="space-y-1 pt-2 text-xs">
                            <div v-if="pricing.discount_amount > 0" class="flex justify-between text-red-600">
                                <span class="font-medium">الخصم</span>
                                <span class="font-bold">− {{ money(pricing.discount_amount) }}</span>
                            </div>
                            <!-- الضريبة تُضاف فوق المُسعَّر — تُعرَض هنا كما ستخرج في
                                 الفاتورة، فلا يوقّع الموظف عقدًا برقمٍ لم يره. -->
                            <template v-if="pricing.is_taxable">
                                <div class="flex justify-between border-t border-slate-100 pt-1.5">
                                    <span class="font-medium text-slate-600">الإجمالي قبل الضريبة</span>
                                    <span class="font-bold text-slate-800">{{ money(pricing.net_amount) }}</span>
                                </div>
                                <div class="flex justify-between text-teal-700">
                                    <span class="font-medium">ضريبة القيمة المضافة ({{ pricing.tax_rate }}%)</span>
                                    <span class="font-bold">+ {{ money(pricing.tax_amount) }}</span>
                                </div>
                            </template>
                            <div class="flex justify-between border-t border-slate-100 pt-1.5 text-base">
                                <span class="font-extrabold text-slate-700">{{ pricing.is_taxable ? 'الإجمالي شامل الضريبة' : 'الإجمالي' }}</span>
                                <span class="font-extrabold text-teal-600">{{ money(pricing.total_amount) }}</span>
                            </div>
                            <div class="flex justify-between text-amber-700">
                                <span class="font-bold">العربون المطلوب</span>
                                <span class="font-extrabold">{{ money(pricing.deposit_amount) }}</span>
                            </div>
                        </div>
                    </div>

                    <!-- السداد عند الحجز — عند الإنشاء فقط، والتعديل له لوحة الدفعات -->
                    <div v-if="!isEdit && pricing" class="rounded-2xl border border-slate-300 bg-white p-4 shadow-sm">
                        <h3 class="mb-3 flex items-center gap-1.5 border-b-2 border-slate-300 pb-2 text-base font-extrabold text-slate-900">
                            <Wallet class="h-4 w-4 text-slate-400" /> السداد
                        </h3>

                        <div class="grid grid-cols-2 gap-1">
                            <button type="button" @click="setPayChoice('none')"
                                class="rounded-lg py-1.5 text-[11px] font-bold transition"
                                :class="payChoice === 'none' ? 'bg-red-500 text-white' : 'bg-slate-100 text-slate-600 hover:bg-slate-200'"
                            >غير مسدَّد</button>
                            <button type="button" @click="setPayChoice('full')"
                                class="rounded-lg py-1.5 text-[11px] font-bold transition"
                                :class="payChoice === 'full' ? 'bg-emerald-600 text-white' : 'bg-slate-100 text-slate-600 hover:bg-slate-200'"
                            >مسدَّد كامل</button>
                        </div>

                        <div v-if="form.payment_amount > 0" class="mt-2.5 space-y-2.5">
                            <div>
                                <label class="mb-1 block text-xs font-extrabold text-slate-800">المبلغ المقبوض</label>
                                <input v-model.number="form.payment_amount" type="number" min="0" step="0.01" class="w-full rounded-lg border border-slate-400 px-2.5 py-2 text-sm font-bold" />
                                <p v-if="form.errors.payment_amount" class="mt-1 text-[11px] text-red-500">{{ form.errors.payment_amount }}</p>
                            </div>

                            <div class="grid grid-cols-2 gap-2">
                                <div>
                                    <label class="mb-1 block text-xs font-extrabold text-slate-800">الطريقة</label>
                                    <select v-model="form.payment_method_id" class="w-full rounded-lg border border-slate-400 px-2 py-2 text-xs">
                                        <option v-for="m in meta.payment_methods" :key="m.id" :value="m.id">{{ m.label }}</option>
                                    </select>
                                </div>
                                <div>
                                    <label class="mb-1 block text-xs font-extrabold text-slate-800">تاريخ القبض</label>
                                    <input v-model="form.payment_paid_on" type="date" class="w-full rounded-lg border border-slate-400 px-2 py-2 text-xs" />
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

                        <p v-else class="mt-2 text-[11px] font-semibold text-slate-600">
                            تُحفظ الإقامة بلا دفعة، ويبقى المبلغ كاملًا على النزيل.
                        </p>
                    </div>

                    <!--
                        Its own card, deliberately away from the price: this
                        money is held, not taken. Putting it beside the total
                        would invite adding the two, which is exactly the
                        mistake the separate account exists to prevent.
                    -->
                    <div v-if="selectedUnit" class="rounded-2xl border border-indigo-100 bg-white p-4 shadow-sm">
                        <h3 class="mb-1.5 flex items-center gap-1.5 text-base font-extrabold text-slate-900">
                            <ShieldCheck class="h-4 w-4 text-indigo-400" /> التأمين
                        </h3>
                        <p class="mb-2.5 text-[11px] font-semibold leading-relaxed text-slate-600">
                            ضمانٌ للتلفيات يُعاد عند الخروج — خارج الإجمالي وخارج المتبقي على النزيل.
                        </p>

                        <label class="mb-1 block text-xs font-extrabold text-slate-800">المبلغ</label>
                        <input
                            v-model.number="form.security_deposit_amount"
                            type="number" min="0" step="0.01" dir="ltr"
                            class="w-full rounded-lg border border-slate-400 px-2.5 py-2 text-sm font-bold focus:border-indigo-400 focus:outline-none focus:ring-2 focus:ring-indigo-100"
                        />
                        <p v-if="form.errors.security_deposit_amount" class="mt-1 text-[11px] text-red-500">{{ form.errors.security_deposit_amount }}</p>

                        <p v-if="securityChanged" class="mt-1.5 text-[11px] font-bold text-amber-600">
                            المعتاد لهذا الشاليه {{ money(unitSecurityDeposit) }} —
                            <button type="button" @click="form.security_deposit_amount = unitSecurityDeposit" class="underline hover:text-amber-700">استعادته</button>
                        </p>

                        <template v-if="!isEdit">
                            <label v-if="form.security_deposit_amount > 0" class="mt-2.5 flex cursor-pointer items-center gap-2 text-[11px] font-bold text-slate-700">
                                <input type="checkbox" v-model="form.security_collected" class="h-3.5 w-3.5 rounded border-slate-300 text-indigo-600" />
                                قُبض التأمين الآن
                            </label>
                            <p v-if="form.security_deposit_amount > 0 && !form.security_collected" class="mt-1.5 text-[11px] font-bold text-amber-600">
                                يُثبَت المبلغ في الحجز ويبقى غير مقبوض حتى يُستلم من لوحة الدفعات.
                            </p>
                        </template>

                        <div v-else class="mt-2.5 space-y-1">
                            <div class="flex justify-between rounded-lg bg-indigo-50 px-2.5 py-2 text-xs">
                                <span class="font-bold text-indigo-700">المحتجز الآن</span>
                                <span class="font-extrabold text-indigo-800">{{ money(booking?.security_held ?? 0) }}</span>
                            </div>
                            <p class="text-[11px] font-semibold text-slate-600">قبض التأمين ورده يتمّان من لوحة الدفعات في السجل.</p>
                        </div>
                    </div>

                    <p v-if="form.errors.availability" class="rounded-xl bg-red-50 px-3 py-2 text-xs font-bold text-red-600">{{ form.errors.availability }}</p>

                    <div class="rounded-2xl border border-slate-300 bg-white p-4 shadow-sm">
                        <p v-if="blocked" class="mb-2 text-xs font-bold text-red-600">لا يمكن الحفظ ما دام الشاليه غير متاح</p>
                        <p v-else-if="isStay && nights < 1" class="mb-2 text-xs font-bold text-amber-600">مدة الإقامة ليلة واحدة على الأقل</p>
                        <p v-else-if="dayUseBlocker" class="mb-2 text-xs font-bold text-amber-600">{{ dayUseBlocker }}</p>
                        <p v-else-if="hourlyBlocker" class="mb-2 text-xs font-bold text-amber-600">{{ hourlyBlocker }}</p>

                        <!-- خطأ لا يقابله حقل ظاهر — حتى لا يبدو الحفظ بلا أثر -->
                        <ul v-if="otherErrors.length" class="mb-2 space-y-1 rounded-lg bg-red-50 px-3 py-2">
                            <li v-for="(msg, i) in otherErrors" :key="i" class="text-[11px] font-bold text-red-600">{{ msg }}</li>
                        </ul>
                        <div class="flex gap-2">
                            <button type="submit" :disabled="form.processing || blocked || (isStay && (nights < 1 || overMaxNights)) || dayUseBlocker !== null || hourlyBlocker !== null" class="flex-1 rounded-md bg-teal-600 px-5 py-2.5 text-sm font-bold text-white shadow-sm transition hover:bg-teal-700 disabled:opacity-50">
                                {{ isEdit ? 'حفظ التعديل' : isStay ? 'حفظ الإقامة' : 'حفظ الحجز' }}
                            </button>
                            <Link href="/admin/bookings/chalets" class="rounded-xl border border-slate-300 bg-white px-4 py-2.5 text-sm font-extrabold text-slate-700 hover:bg-slate-50">
                                إلغاء
                            </Link>
                        </div>
                    </div>
                </aside>
            </div>
        </form>
    </AppLayout>
</template>
