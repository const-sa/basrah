<script setup lang="ts">
import ClientQuickAdd from '@/components/ClientQuickAdd.vue';
import SearchableSelect from '@/components/SearchableSelect.vue';
import { useVat } from '@/composables/useVat';
import AppLayout from '@/layouts/AppLayout.vue';
import { jsonHeaders } from '@/lib/csrf';
import { toHijri, weekdayName } from '@/lib/hijri';
import { todayString } from '@/lib/dates';
import { type BreadcrumbItem, type PaymentMethodOption } from '@/types';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { AlertTriangle, ArrowRight, Building2, CalendarDays, CheckCircle2, Info, Loader2, PartyPopper, Wallet } from 'lucide-vue-next';
import { computed, nextTick, onMounted, ref, watch } from 'vue';

interface SectionOption { id: number; name: string; gender: string }
interface UnitOption {
    id: number; name: string; code: string; type: string;
    bookable_mode: 'whole' | 'sections' | 'both';
    privacy_mode: 'open' | 'exclusive';
    /** ساعات فترات هذه القاعة سارية المفعول — ساعتها إن كُتبت، وإلا ساعة الإعدادات. */
    hours: Record<string, { start: string; end: string }>;
    sections: SectionOption[];
}
interface ClientOption { id: number; name: string; mobile: string | null }
interface EventTypeOption { id: number; unit_id: number; name: string; color: string; price: number }
interface PackageItem { name: string; quantity: string }
interface PackageOption { id: number; name: string; unit_id: number | null; price: number; items: PackageItem[] }

interface ExistingBooking {
    id: number; reference: string;
    unit: { id: number; name: string; code: string };
    client: { id: number; name: string; mobile: string | null } | null;
    event_type_id: number | null;
    package_id: number | null;
    scope: 'whole' | 'sections';
    section_ids: number[];
    period: string;
    booking_date: string;
    days_count: number;
    last_day_date: string;
    status: string;
    discount_amount: number;
    /** Is this booking invoiced with tax? Stored with it, and asked again on every edit. */
    is_taxable: boolean;
    guests_count: number | null;
    notes: string | null;
}

interface QuoteLine { label: string; amount: number }
interface Quote {
    availability: { ok: boolean; reason: string | null; conflicts: unknown[] };
    pricing: {
        base_amount: number; package_amount: number; event_fee_amount: number;
        priced_by_event: boolean;
        addons_amount: number; discount_amount: number;
        total_amount: number; deposit_amount: number; is_weekend: boolean;
        /** الضريبة مستخرجة من الإجمالي شاملةً لا مضافة فوقه. */
        is_taxable: boolean; tax_rate: number; net_amount: number; tax_amount: number;
        days: number;
        package: { id: number; name: string; price: number } | null;
        event_type: { id: number; name: string; price: number } | null;
        lines: QuoteLine[];
    };
    schedule: { days: number; last_day_date: string };
}

const props = defineProps<{
    booking: ExistingBooking | null;
    units: UnitOption[];
    clients: ClientOption[];
    eventTypes: EventTypeOption[];
    packages: PackageOption[];
    meta: {
        statuses: { key: string; label: string; color: string }[];
        periods: { key: string; label: string; start: string; end: string }[];
        payment_methods: PaymentMethodOption[];
    };
    // ما يصل من التقويم الشهري عند فتح الحجز من خلية يوم — غائب في التعديل.
    prefill?: {
        unit_id?: number;
        booking_date?: string;
        period?: string;
        section_ids?: number[];
    };
}>();

// An entry screen, so it follows the switch alone: the booking is not asked
// about tax where there is no tax in the system at all. The pricing panel
// answers with `pricing.is_taxable`, not with this.
const { applies: vatApplies } = useVat();

const isEdit = computed(() => props.booking !== null);

const breadcrumbs = computed<BreadcrumbItem[]>(() => [
    { title: 'لوحة التحكم', href: '/admin' },
    { title: 'حجوزات القاعات', href: '/admin/bookings/halls' },
    {
        title: isEdit.value ? `تعديل ${props.booking?.reference}` : 'حجز جديد',
        href: isEdit.value ? `/admin/bookings/halls/${props.booking?.id}/edit` : '/admin/bookings/halls/create',
    },
]);

const money = (n: number) => new Intl.NumberFormat('ar-SA-u-nu-latn', { maximumFractionDigits: 2 }).format(n ?? 0);
const today = todayString();

/**
 * أقدم تاريخ يقبله حقل التاريخ — اليوم، فالقاعة لا تُحجز بأثر رجعي.
 * ويُستثنى حجز قائم مضى تاريخه: يبقى تاريخه مقبولًا ليُفتح للتصحيح،
 * وإلا رفض المتصفح قيمته المحفوظة وأفرغ الحقل أمام الموظف.
 */
const minDate = computed(() => {
    const existing = props.booking?.booking_date;

    return existing && existing < today ? existing : today;
});

const quote = ref<Quote | null>(null);
const checking = ref(false);

// سبب تعذّر التسعير — يُعرض بدل اختفاء اللوحة بلا بيان: الموظف كان يرى المساحة
// فارغة فيظن الحجز مجانيًا أو الشاشة معطوبة، والسبب طلبٌ مرفوض لا سعرٌ مفقود.
const quoteError = ref<string | null>(null);

// اقتراح التقويم يسبق الافتراض العام ويتأخّر عن الحجز القائم: التعديل يعرض
// ما حُفظ، والإنشاء من خلية يوم يعرض ما اختاره الموظف في التقويم.
const pre = props.prefill ?? {};
const preSections = pre.section_ids ?? [];

const form = useForm({
    unit_id: props.booking?.unit.id ?? pre.unit_id ?? null as number | null,
    client_id: props.booking?.client?.id ?? null as number | null,
    event_type_id: props.booking?.event_type_id ?? null as number | null,
    package_id: props.booking?.package_id ?? null as number | null,
    // القسم الواصل من التقويم يعني حجزًا بالأقسام لا للوحدة كاملة.
    scope: (props.booking?.scope ?? (preSections.length ? 'sections' : 'whole')) as 'whole' | 'sections',
    section_ids: [...(props.booking?.section_ids ?? preSections)] as number[],
    booking_date: props.booking?.booking_date ?? pre.booking_date ?? today,
    // المناسبة يوم واحد في الغالب، والامتداد استثناء يطلبه الموظف صراحةً.
    days_count: props.booking?.days_count ?? 1,
    period: props.booking?.period ?? pre.period ?? 'full_day',
    // الحجز الجديد مؤكد افتراضيًا — و«مبدئي» يُختار من القائمة عند الحاجة.
    status: props.booking?.status ?? 'confirmed',
    discount_amount: props.booking?.discount_amount ?? 0,
    // With tax unless told otherwise, which is the common case; an edit opens
    // on whatever the booking was written with.
    is_taxable: props.booking?.is_taxable ?? true,
    guests_count: props.booking?.guests_count ?? null as number | null,
    notes: props.booking?.notes ?? '',

    // السداد عند الحجز — صفر يعني حجزًا غير مسدَّد
    payment_amount: 0,
    payment_type: 'deposit',
    // أول طريقة في الترتيب هي الافتراض — يرتّبها المستخدم من شاشة طرق الدفع.
    payment_method_id: props.meta.payment_methods[0]?.id ?? null as number | null,
    payment_paid_on: today,
    payment_notify: true,
});

const selectedUnit = computed(() => props.units.find((u) => u.id === form.unit_id) ?? null);

/**
 * الفترات بساعات القاعة المختارة.
 *
 * القاعة قد تُكتب لها ساعاتها في شاشة أسعارها، وعليها يُبنى مدى حجزها وكشف
 * تعارضه، فتُعرض هي لا ساعة الإعدادات.
 */
const periods = computed(() =>
    props.meta.periods.map((p) => ({ ...p, ...(selectedUnit.value?.hours?.[p.key] ?? {}) })),
);

/** الباقات المتاحة للقاعة المختارة: الخاصة بها والعامة معًا — كقاعدة الخادم. */
const availablePackages = computed(() =>
    props.packages.filter((p) => p.unit_id === null || p.unit_id === form.unit_id),
);

/** الباقة المختارة — تُعرض بنودها تحتها ليقرأها الموظف للعميل. */
const selectedPackage = computed(
    () => availablePackages.value.find((p) => p.id === form.package_id) ?? null,
);

/** أنواع المناسبات تتبع قاعتها: لكل قاعة أنواعها وأسعارها وحدها. */
const availableEventTypes = computed(() =>
    props.eventTypes.filter((t) => t.unit_id === form.unit_id),
);

const selectedEventType = computed(() =>
    availableEventTypes.value.find((t) => t.id === form.event_type_id) ?? null,
);

/** نسخة محلية من قائمة العملاء ليُضاف إليها ما يُنشأ سريعًا بلا إعادة تحميل الصفحة. */
const clients = ref<ClientOption[]>([...props.clients]);

const clientOptions = computed(() =>
    clients.value.map((c) => ({ ...c, label: c.mobile ? `${c.name} — ${c.mobile}` : c.name })),
);

const onClientCreated = (client: ClientOption) => {
    clients.value = [client, ...clients.value];
    form.client_id = client.id;
};

const MAX_DAYS = 30;

/** إزاحة يوم بالتقويم المحلي — لا عبر UTC حتى لا ينزلق التاريخ يومًا. */
const addDays = (date: string, count: number): string => {
    const d = new Date(`${date}T12:00:00`);

    if (Number.isNaN(d.getTime())) return date;

    d.setDate(d.getDate() + count);

    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
};

/** عدد الأيام بعد تشذيبه — الحقل قد يُفرَّغ أو يُكتب فيه صفر. */
const daysCount = computed(() => Math.min(MAX_DAYS, Math.max(1, Math.floor(form.days_count || 1))));

/**
 * تاريخ آخر يوم: البداية + (عدد الأيام − 1). يومان يعنيان اليوم وتاليه.
 * يُحسب هنا ليتحدث مع كل ضغطة، ويؤكده الخادم في التسعيرة.
 */
const lastDate = computed(() => addDays(form.booking_date, daysCount.value - 1));

// الهجري يظهر مع الميلادي: العميل يتحدث بالهجري والنظام يخزّن الميلادي.
const hijriDate = computed(() => toHijri(form.booking_date));
const dayName = computed(() => weekdayName(form.booking_date));
const hijriLastDate = computed(() => toHijri(lastDate.value));
const lastDayName = computed(() => weekdayName(lastDate.value));

const daysLabel = computed(() =>
    ({ 1: 'يوم واحد', 2: 'يومان' })[daysCount.value] ?? `${daysCount.value} ${daysCount.value <= 10 ? 'أيام' : 'يومًا'}`,
);

const canBookWhole = computed(() => ['whole', 'both'].includes(selectedUnit.value?.bookable_mode ?? 'both'));
const canBookSections = computed(() => ['sections', 'both'].includes(selectedUnit.value?.bookable_mode ?? 'both'));

/**
 * سعر النوع ثمن القاعة كاملة، فلا يسري على حجز قسم منفرد.
 * التنبيه صريح لأن الموظف يرى سعرًا على الزر ثم يجده غير مطبَّق.
 */
const eventPriceIgnored = computed(
    () => form.scope === 'sections' && (selectedEventType.value?.price ?? 0) > 0,
);

// تعبئة النموذج من حجز قائم ليست تبديلًا للقاعة: بدون هذا العلم يمسح المراقب
// أدناه أقسام الحجز ونوعه وباقته لحظة فتحه للتعديل.
const hydrating = ref(true);

onMounted(() => nextTick(() => (hydrating.value = false)));

// عند تبديل القاعة يُعاد ضبط النطاق والأقسام لأن أقسام القاعة السابقة لا تعنيها،
// ويُسقط النوع والباقة إن كانا يخصّان القاعة السابقة.
watch(() => form.unit_id, () => {
    if (hydrating.value) return;

    form.section_ids = [];
    form.scope = canBookWhole.value ? 'whole' : 'sections';

    if (form.event_type_id && !availableEventTypes.value.some((t) => t.id === form.event_type_id)) {
        form.event_type_id = null;
    }

    if (form.package_id && !availablePackages.value.some((p) => p.id === form.package_id)) {
        form.package_id = null;
    }
});

const toggleSection = (id: number) => {
    const i = form.section_ids.indexOf(id);
    i === -1 ? form.section_ids.push(id) : form.section_ids.splice(i, 1);
};

/**
 * فحص الإتاحة واحتساب السعر على الخادم عند كل تغيير مؤثر.
 * مؤجَّل قليلًا حتى لا يُرسل طلبًا مع كل ضغطة مفتاح.
 */
let timer: ReturnType<typeof setTimeout> | undefined;
const refreshQuote = () => {
    if (!form.unit_id || (form.scope === 'sections' && !form.section_ids.length)) {
        quote.value = null;
        quoteError.value = null;

        return;
    }

    clearTimeout(timer);
    timer = setTimeout(async () => {
        checking.value = true;
        quoteError.value = null;
        try {
            const res = await fetch('/admin/bookings/halls/quote', {
                method: 'POST',
                headers: jsonHeaders(),
                body: JSON.stringify({
                    unit_id: form.unit_id,
                    scope: form.scope,
                    section_ids: form.section_ids,
                    booking_date: form.booking_date,
                    days_count: daysCount.value,
                    period: form.period,
                    client_id: form.client_id,
                    event_type_id: form.event_type_id,
                    package_id: form.package_id,
                    discount_amount: form.discount_amount,
                    is_taxable: form.is_taxable,
                    ignore_booking_id: props.booking?.id ?? null,
                }),
            });
            if (res.ok) {
                quote.value = await res.json();
            } else {
                quote.value = null;
                quoteError.value = quoteFailure(res.status);
            }
        } catch {
            quote.value = null;
            quoteError.value = 'تعذّر الاتصال بالخادم لاحتساب السعر.';
        } finally {
            checking.value = false;
        }
    }, 350);
};

const quoteFailure = (status: number) =>
    ({
        419: 'انتهت صلاحية الجلسة — حدّث الصفحة ثم أعد المحاولة.',
        403: 'لا صلاحية لديك لعرض تسعيرة الحجز.',
        422: 'بيانات الحجز غير مكتملة أو غير صالحة لاحتساب السعر.',
    })[status] ?? `تعذّر احتساب السعر (${status}).`;

watch(
    () => [form.unit_id, form.scope, [...form.section_ids], form.booking_date, daysCount.value, form.period, form.client_id, form.event_type_id, form.package_id, form.discount_amount, form.is_taxable],
    refreshQuote,
    { deep: true },
);

onMounted(refreshQuote);

const blocked = computed(() => quote.value !== null && !quote.value.availability.ok);

// ── السداد عند إنشاء الحجز ──────────────────────────────────
const suggestedDeposit = computed(() => quote.value?.pricing.deposit_amount ?? 0);
const suggestedTotal = computed(() => quote.value?.pricing.total_amount ?? 0);

/** حالة السداد المختارة — تُشتق من المبلغ حتى لا يتناقض الزر مع الحقل. */
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

const submit = () => {
    // الحقل قد يُترك فارغًا أو بقيمة خارج الحد — يُرسل مشذَّبًا كما حُسب وعُرض.
    form.days_count = daysCount.value;

    isEdit.value
        ? form.put(`/admin/bookings/halls/${props.booking?.id}`)
        : form.post('/admin/bookings/halls');
};

/** شارة نوع المناسبة — نفس مفاتيح EventType::COLORS في الخادم. */
const eventBadge = (color: string) =>
    ({
        emerald: 'bg-emerald-100 text-emerald-800',
        sky: 'bg-sky-100 text-sky-800',
        violet: 'bg-violet-100 text-violet-800',
        amber: 'bg-amber-100 text-amber-800',
        rose: 'bg-rose-100 text-rose-800',
        slate: 'bg-slate-200 text-slate-900',
    })[color] ?? 'bg-slate-100 text-slate-900';
</script>

<template>
    <Head :title="isEdit ? 'تعديل حجز القاعة' : 'حجز قاعة جديد'" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <form @submit.prevent="submit" class="min-h-full space-y-5 bg-slate-100 p-5">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h1 class="text-2xl font-extrabold text-slate-900">
                        {{ isEdit ? `تعديل الحجز ${booking?.reference}` : 'حجز قاعة جديد' }}
                    </h1>
                    <p class="mt-1 text-[15px] font-medium text-slate-800">القاعة ثم العميل والأقسام ونوع المناسبة، ثم التاريخ وعدد الأيام</p>
                </div>
                <Link href="/admin/bookings/halls" class="inline-flex items-center gap-1.5 rounded-md border border-slate-300 bg-white px-4 py-2 text-[15px] font-bold text-slate-900 shadow-sm hover:bg-slate-50">
                    <ArrowRight class="h-4 w-4" /> رجوع للسجل
                </Link>
            </div>

            <div class="grid gap-5 lg:grid-cols-[1fr_420px]">
                <!-- تفاصيل الحجز -->
                <div class="space-y-4">
                    <!--
                        ترتيب الحقول هو ترتيب الحوار مع العميل نفسه:
                        القاعة ← العميل ← الأقسام ← نوع المناسبة ← التاريخ ← عدد الأيام.
                        كل خطوة تفتح ما بعدها: الأقسام لا تُعرف قبل القاعة، وأنواع
                        المناسبات وأسعارها تتبع القاعة أيضًا.
                    -->
                    <div class="rounded-2xl border border-slate-300 bg-white p-5 shadow-sm">
                        <h2 class="mb-3 flex items-center gap-1.5 text-[15px] font-extrabold text-slate-900">
                            <Building2 class="h-4 w-4 text-slate-600" /> القاعة والعميل
                        </h2>

                        <div class="grid gap-3 sm:grid-cols-2">
                            <div>
                                <label class="mb-1 flex items-center gap-1.5 text-[15px] font-bold text-slate-900">
                                    <span class="inline-flex h-5 w-5 items-center justify-center rounded-full bg-slate-900 text-xs font-extrabold text-white">1</span>
                                    القاعة
                                </label>
                                <select v-model="form.unit_id" class="w-full rounded-xl border border-slate-300 px-3 py-2.5 text-[15px]">
                                    <option :value="null">— اختر القاعة —</option>
                                    <option v-for="u in units" :key="u.id" :value="u.id">{{ u.name }} ({{ u.code }})</option>
                                </select>
                                <p v-if="form.errors.unit_id" class="mt-1 text-sm text-red-700">{{ form.errors.unit_id }}</p>
                            </div>
                            <div>
                                <div class="mb-1 flex items-center justify-between gap-2">
                                    <label class="flex items-center gap-1.5 text-[15px] font-bold text-slate-900">
                                        <span class="inline-flex h-5 w-5 items-center justify-center rounded-full bg-slate-900 text-xs font-extrabold text-white">2</span>
                                        العميل
                                    </label>
                                    <ClientQuickAdd category="hall" @created="onClientCreated" />
                                </div>
                                <SearchableSelect
                                    v-model="form.client_id"
                                    :options="clientOptions"
                                    label-key="label"
                                    :search-keys="['mobile']"
                                    placeholder="— اختر العميل —"
                                />
                                <p v-if="form.errors.client_id" class="mt-1 text-sm text-red-700">{{ form.errors.client_id }}</p>
                            </div>
                        </div>

                        <!-- 3 — الأقسام: نطاق الحجز ثم أقسامه إن كان جزئيًا -->
                        <div class="mt-4 border-t border-slate-200 pt-3">
                            <label class="mb-1.5 flex items-center gap-1.5 text-[15px] font-bold text-slate-900">
                                <span class="inline-flex h-5 w-5 items-center justify-center rounded-full bg-slate-900 text-xs font-extrabold text-white">3</span>
                                الأقسام
                            </label>

                            <p v-if="!selectedUnit" class="rounded-xl bg-slate-50 px-3 py-2 text-[13px] font-medium text-slate-700">
                                اختر القاعة أولًا — تظهر أقسامها هنا.
                            </p>

                            <template v-else>
                                <div class="grid gap-2 sm:grid-cols-2">
                                    <label v-if="canBookWhole" class="cursor-pointer rounded-xl border-2 p-3 text-center transition" :class="form.scope === 'whole' ? 'border-emerald-500 bg-emerald-50' : 'border-slate-300 hover:border-slate-400'">
                                        <input v-model="form.scope" type="radio" value="whole" class="sr-only" />
                                        <div class="text-[15px] font-extrabold text-slate-900">القاعة كاملة</div>
                                        <div class="mt-0.5 text-[13px] font-medium text-slate-700">تُقفل جميع الأقسام</div>
                                    </label>
                                    <label v-if="canBookSections" class="cursor-pointer rounded-xl border-2 p-3 text-center transition" :class="form.scope === 'sections' ? 'border-emerald-500 bg-emerald-50' : 'border-slate-300 hover:border-slate-400'">
                                        <input v-model="form.scope" type="radio" value="sections" class="sr-only" />
                                        <div class="text-[15px] font-extrabold text-slate-900">أقسام محددة</div>
                                        <div class="mt-0.5 text-[13px] font-medium text-slate-700">حجز قسم دون بقية القاعة</div>
                                    </label>
                                </div>

                                <div v-if="form.scope === 'sections'" class="mt-2">
                                    <div class="mb-1 flex items-center gap-2 text-[13px] font-bold text-slate-800">
                                        <span>اختر الأقسام</span>
                                        <span v-if="selectedUnit.privacy_mode === 'exclusive'" class="rounded bg-amber-100 px-1.5 py-0.5 text-amber-800">خصوصية مغلقة</span>
                                    </div>
                                    <div class="flex flex-wrap gap-1.5">
                                        <button
                                            v-for="s in selectedUnit.sections"
                                            :key="s.id"
                                            type="button"
                                            @click="toggleSection(s.id)"
                                            class="rounded-lg px-3 py-1.5 text-sm font-bold transition"
                                            :class="form.section_ids.includes(s.id) ? 'bg-emerald-600 text-white shadow-sm' : 'bg-slate-100 text-slate-900 hover:bg-slate-200'"
                                        >{{ s.name }}</button>
                                    </div>
                                    <p v-if="form.errors.section_ids" class="mt-1 text-sm text-red-700">{{ form.errors.section_ids }}</p>
                                </div>
                            </template>
                        </div>

                        <!-- 4 — نوع المناسبة: هو ما يحدد السعر الأساسي في هذه القاعة -->
                        <div class="mt-4 border-t border-slate-200 pt-3">
                            <div class="mb-2 flex items-center justify-between gap-2">
                                <label class="flex items-center gap-1.5 text-[15px] font-bold text-slate-900">
                                    <span class="inline-flex h-5 w-5 items-center justify-center rounded-full bg-slate-900 text-xs font-extrabold text-white">4</span>
                                    <PartyPopper class="h-4 w-4 text-slate-600" /> نوع المناسبة
                                </label>
                                <Link href="/admin/event-types" class="text-[13px] font-bold text-blue-700 hover:underline">
                                    إدارة الأنواع وأسعارها
                                </Link>
                            </div>

                            <p v-if="!form.unit_id" class="rounded-xl bg-slate-50 px-3 py-2 text-[13px] font-medium text-slate-700">
                                اختر القاعة أولًا — لكل قاعة أنواع مناسباتها وأسعارها.
                            </p>

                            <div v-else-if="availableEventTypes.length" class="flex flex-wrap gap-1.5">
                                <button
                                    v-for="t in availableEventTypes" :key="t.id" type="button"
                                    @click="form.event_type_id = form.event_type_id === t.id ? null : t.id"
                                    class="flex items-center gap-1.5 rounded-lg px-3 py-2 text-sm font-bold transition"
                                    :class="form.event_type_id === t.id ? 'bg-slate-800 text-white shadow-sm' : eventBadge(t.color) + ' hover:opacity-80'"
                                >
                                    {{ t.name }}
                                    <span v-if="t.price > 0" class="rounded bg-black/10 px-1.5 py-0.5 text-xs font-extrabold">
                                        {{ money(t.price) }}
                                    </span>
                                    <span v-else class="text-xs opacity-75">تسعيرة القاعة</span>
                                </button>
                            </div>
                            <p v-else class="rounded-xl bg-amber-50 px-3 py-2 text-[13px] font-bold text-amber-800">
                                لا أنواع مناسبات لهذه القاعة بعد — أضفها من «إدارة الأنواع» لتظهر هنا.
                            </p>

                            <p v-if="selectedEventType && selectedEventType.price > 0 && !eventPriceIgnored"
                                class="mt-2 flex items-start gap-1.5 rounded-xl bg-emerald-50 px-3 py-2 text-[13px] font-bold text-emerald-800">
                                <Info class="mt-px h-3.5 w-3.5 shrink-0" />
                                السعر الأساسي {{ money(selectedEventType.price) }} — سعر «{{ selectedEventType.name }}» في هذه القاعة، ويحل محل تسعيرة اليوم.
                                <span v-if="daysCount > 1">ويُضرب في عدد الأيام.</span>
                            </p>

                            <p v-if="eventPriceIgnored"
                                class="mt-2 flex items-start gap-1.5 rounded-xl bg-amber-50 px-3 py-2 text-[13px] font-bold text-amber-800">
                                <AlertTriangle class="mt-px h-3.5 w-3.5 shrink-0" />
                                سعر النوع ثمن القاعة كاملة — حجز أقسام منها يُسعَّر بتسعيرة الأقسام لا بسعر النوع.
                            </p>

                            <p v-if="form.errors.event_type_id" class="mt-1 text-sm text-red-700">{{ form.errors.event_type_id }}</p>
                        </div>
                    </div>

                    <!-- تفاصيل الموعد: التاريخ ثم عدد الأيام، والنهاية مشتقة منهما -->
                    <div class="rounded-2xl border border-slate-300 bg-white p-5 shadow-sm">
                        <h2 class="mb-3 flex items-center gap-1.5 text-[15px] font-extrabold text-slate-900">
                            <CalendarDays class="h-4 w-4 text-slate-600" /> تاريخ المناسبة
                        </h2>

                        <div class="grid gap-3 sm:grid-cols-3">
                            <div>
                                <label class="mb-1 flex items-center gap-1.5 text-[15px] font-bold text-slate-900">
                                    <span class="inline-flex h-5 w-5 items-center justify-center rounded-full bg-slate-900 text-xs font-extrabold text-white">5</span>
                                    تاريخ البداية
                                </label>
                                <input v-model="form.booking_date" type="date" :min="minDate" class="w-full rounded-xl border border-slate-300 px-3 py-2.5 text-[15px] font-bold" />
                                <p v-if="form.errors.booking_date" class="mt-1 text-sm text-red-700">{{ form.errors.booking_date }}</p>
                            </div>

                            <div>
                                <label class="mb-1 flex items-center gap-1.5 text-[15px] font-bold text-slate-900">
                                    <span class="inline-flex h-5 w-5 items-center justify-center rounded-full bg-slate-900 text-xs font-extrabold text-white">6</span>
                                    عدد الأيام
                                </label>
                                <input
                                    v-model.number="form.days_count"
                                    type="number" min="1" :max="MAX_DAYS" step="1"
                                    class="w-full rounded-xl border border-slate-300 px-3 py-2.5 text-[15px] font-bold"
                                />
                                <p class="mt-1 text-[13px] font-medium text-slate-700">
                                    يوم واحد ما لم تمتد المناسبة — الحد الأعلى {{ MAX_DAYS }} يومًا.
                                </p>
                                <p v-if="form.errors.days_count" class="mt-1 text-sm text-red-700">{{ form.errors.days_count }}</p>
                            </div>

                            <div>
                                <label class="mb-1 block text-[15px] font-bold text-slate-900">تاريخ النهاية</label>
                                <input :value="lastDate" type="date" readonly class="w-full cursor-not-allowed rounded-xl border border-slate-300 bg-slate-50 px-3 py-2.5 text-[15px] font-bold text-slate-800" />
                                <p class="mt-1 text-[13px] font-medium text-slate-700">يُحسب من البداية وعدد الأيام</p>
                            </div>
                        </div>

                        <!-- التاريخ كما يُقرأ للعميل: ميلادي وهجري واسم اليوم -->
                        <div class="mt-3 rounded-xl border border-emerald-300 bg-emerald-50 px-4 py-3.5">
                            <div class="grid gap-3 sm:grid-cols-2">
                                <div>
                                    <div class="text-[15px] font-extrabold text-emerald-900">{{ daysCount > 1 ? 'أول يوم' : 'يوم المناسبة' }}</div>
                                    <div class="mt-1 text-xl font-extrabold text-slate-900" dir="ltr">{{ form.booking_date || '—' }}</div>
                                    <div class="mt-0.5 text-lg font-extrabold text-emerald-800">{{ hijriDate }}</div>
                                    <div class="text-lg font-bold text-slate-800">{{ dayName }}</div>
                                </div>
                                <div v-if="daysCount > 1" class="sm:border-e sm:border-emerald-300 sm:pe-3">
                                    <div class="text-[15px] font-extrabold text-emerald-900">آخر يوم</div>
                                    <div class="mt-1 text-xl font-extrabold text-slate-900" dir="ltr">{{ lastDate }}</div>
                                    <div class="mt-0.5 text-lg font-extrabold text-emerald-800">{{ hijriLastDate }}</div>
                                    <div class="text-lg font-bold text-slate-800">{{ lastDayName }}</div>
                                </div>
                            </div>

                            <p v-if="daysCount > 1" class="mt-3 flex items-start gap-2 border-t border-emerald-300 pt-2.5 text-[15px] font-bold text-emerald-900">
                                <Info class="mt-0.5 h-4 w-4 shrink-0" />
                                المناسبة {{ daysLabel }} — تُقفل القاعة في أيامها كلها بنفس الفترة، وتُسعَّر كل يوم بيومه.
                            </p>
                        </div>

                        <div class="mt-3 grid gap-3 sm:grid-cols-2">
                            <div>
                                <label class="mb-1 block text-[15px] font-bold text-slate-900">الفترة</label>
                                <!-- القاعة تُباع بفترة واحدة، فتُعرض خبرًا لا قائمةً بخيار واحد -->
                                <div
                                    v-if="periods.length === 1"
                                    class="w-full rounded-xl border border-slate-200 bg-slate-50 px-3 py-2.5 text-[15px] font-bold text-slate-700"
                                >
                                    {{ periods[0].label }}
                                    <span class="text-sm font-medium text-slate-500" dir="ltr">({{ periods[0].start }}–{{ periods[0].end }})</span>
                                </div>
                                <select v-else v-model="form.period" class="w-full rounded-xl border border-slate-300 px-3 py-2.5 text-[15px]">
                                    <option v-for="p in periods" :key="p.key" :value="p.key">{{ p.label }} ({{ p.start }}–{{ p.end }})</option>
                                </select>
                            </div>
                            <div>
                                <label class="mb-1 block text-[15px] font-bold text-slate-900">عدد الضيوف</label>
                                <input v-model.number="form.guests_count" type="number" min="1" class="w-full rounded-xl border border-slate-300 px-3 py-2.5 text-[15px]" />
                            </div>
                        </div>
                    </div>

                    <!-- الباقة والخدمات -->
                    <div v-if="selectedUnit" class="rounded-2xl border border-slate-300 bg-white p-5 shadow-sm">
                        <div class="mb-2 flex items-center justify-between gap-2">
                            <h2 class="text-[15px] font-extrabold text-slate-900">الباقة</h2>
                            <Link href="/admin/packages" class="text-[13px] font-bold text-blue-700 hover:underline">إدارة الباقات</Link>
                        </div>

                        <div v-if="availablePackages.length" class="grid gap-2 sm:grid-cols-2">
                            <button
                                v-for="p in availablePackages" :key="p.id" type="button"
                                @click="form.package_id = form.package_id === p.id ? null : p.id"
                                class="flex items-center justify-between gap-2 rounded-xl border-2 px-3 py-2 text-right transition"
                                :class="form.package_id === p.id ? 'border-emerald-500 bg-emerald-50' : 'border-slate-300 hover:border-slate-400'"
                            >
                                <span class="min-w-0 truncate text-[15px] font-extrabold text-slate-900">{{ p.name }}</span>
                                <span class="shrink-0 text-sm font-extrabold" :class="form.package_id === p.id ? 'text-emerald-800' : 'text-slate-700'">
                                    {{ p.price > 0 ? money(p.price) : 'بلا سعر' }}
                                </span>
                            </button>
                        </div>
                        <p v-else class="rounded-xl bg-slate-50 px-3 py-2 text-[13px] font-medium text-slate-700">
                            لا باقات متاحة لهذه القاعة.
                        </p>

                        <!-- بنود الباقة المختارة — ما يشمله سعرها، يقرأه الموظف للعميل -->
                        <div v-if="selectedPackage" class="mt-3 rounded-xl border border-emerald-300 bg-emerald-50 px-3 py-2.5">
                            <div class="mb-1.5 text-[13px] font-extrabold text-emerald-900">
                                تشمل باقة «{{ selectedPackage.name }}»
                            </div>

                            <table v-if="selectedPackage.items.length" class="w-full text-[15px]">
                                <tbody>
                                    <tr v-for="it in selectedPackage.items" :key="it.name" class="border-b border-emerald-100 last:border-0">
                                        <td class="py-1 font-bold text-slate-900">{{ it.name }}</td>
                                        <td class="w-24 py-1 text-left">
                                            <span class="rounded-md bg-white px-2 py-0.5 text-sm font-extrabold text-emerald-800" dir="ltr">
                                                {{ it.quantity }}
                                            </span>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                            <p v-else class="text-[13px] font-medium text-slate-700">
                                لا بنود مسجّلة لهذه الباقة — أضفها من إدارة الباقات.
                            </p>
                        </div>

                    </div>

                    <div class="rounded-2xl border border-slate-300 bg-white p-5 shadow-sm">
                        <div class="grid gap-3" :class="vatApplies ? 'sm:grid-cols-3' : 'sm:grid-cols-2'">
                            <div>
                                <label class="mb-1 block text-[15px] font-bold text-slate-900">الخصم</label>
                                <input v-model.number="form.discount_amount" type="number" min="0" step="0.01" class="w-full rounded-xl border border-slate-300 px-3 py-2.5 text-[15px]" />
                            </div>
                            <div>
                                <label class="mb-1 block text-[15px] font-bold text-slate-900">الحالة</label>
                                <select v-model="form.status" class="w-full rounded-xl border border-slate-300 px-3 py-2.5 text-[15px]">
                                    <option v-for="s in meta.statuses" :key="s.key" :value="s.key">{{ s.label }}</option>
                                </select>
                            </div>

                            <!-- A hall let to an exempt body is invoiced without tax
                                 while the same hall is let with it to anyone else — so
                                 the question belongs to the booking, not the unit. -->
                            <div v-if="vatApplies">
                                <label class="mb-1 block text-[15px] font-bold text-slate-900">الضريبة</label>
                                <div class="grid grid-cols-2 gap-1 rounded-xl bg-slate-100 p-1">
                                    <button
                                        type="button"
                                        @click="form.is_taxable = true"
                                        class="rounded-lg px-2 py-2 text-sm font-bold transition"
                                        :class="form.is_taxable ? 'bg-white text-emerald-800 shadow-sm' : 'text-slate-600 hover:text-slate-800'"
                                    >
                                        بضريبة
                                    </button>
                                    <button
                                        type="button"
                                        @click="form.is_taxable = false"
                                        class="rounded-lg px-2 py-2 text-sm font-bold transition"
                                        :class="!form.is_taxable ? 'bg-white text-emerald-800 shadow-sm' : 'text-slate-600 hover:text-slate-800'"
                                    >
                                        بدون ضريبة
                                    </button>
                                </div>
                                <p v-if="form.errors.is_taxable" class="mt-1 text-sm text-red-700">{{ form.errors.is_taxable }}</p>
                            </div>
                        </div>

                        <label class="mb-1 mt-3 block text-[15px] font-bold text-slate-900">ملاحظات</label>
                        <textarea v-model="form.notes" rows="2" class="w-full rounded-xl border border-slate-300 px-3 py-2.5 text-[15px]"></textarea>
                    </div>
                </div>

                <!-- لوحة الإتاحة والتسعير — تلازم الشاشة أثناء التمرير -->
                <aside class="space-y-3 lg:sticky lg:top-4 lg:self-start">
                    <div v-if="checking" class="flex items-center gap-2 rounded-xl bg-white px-4 py-3 text-[15px] font-bold text-slate-700 shadow-sm">
                        <Loader2 class="h-4 w-4 animate-spin" /> جارٍ فحص الإتاحة…
                    </div>

                    <div v-else-if="quoteError" class="rounded-xl border-2 border-amber-300 bg-amber-50 p-4">
                        <div class="mb-1 flex items-center gap-2 text-[15px] font-extrabold text-amber-900">
                            <AlertTriangle class="h-4 w-4 shrink-0" /> تعذّر عرض التسعيرة
                        </div>
                        <p class="text-sm font-medium leading-relaxed text-amber-900">{{ quoteError }}</p>
                    </div>

                    <div v-else-if="blocked" class="rounded-xl border-2 border-red-300 bg-red-50 p-4">
                        <div class="mb-1 flex items-center gap-2 text-[15px] font-extrabold text-red-800">
                            <AlertTriangle class="h-4 w-4 shrink-0" /> الوقت غير متاح
                        </div>
                        <p class="text-sm font-medium leading-relaxed text-red-800">{{ quote?.availability.reason }}</p>
                    </div>

                    <div v-else-if="quote" class="rounded-xl border-2 border-emerald-300 bg-emerald-50 p-4">
                        <div class="flex items-center gap-2 text-lg font-extrabold text-emerald-800">
                            <CheckCircle2 class="h-5 w-5" /> الوقت متاح
                        </div>
                    </div>

                    <div v-if="quote" class="rounded-2xl border border-slate-300 bg-white p-5 shadow-sm">
                        <div class="mb-3 flex flex-wrap gap-1.5">
                            <span v-if="quote.pricing.days > 1" class="rounded-md bg-slate-200 px-2.5 py-1 text-sm font-bold text-slate-900">
                                {{ quote.pricing.days }} أيام — حتى {{ quote.schedule.last_day_date }}
                            </span>
                            <span v-if="quote.pricing.priced_by_event" class="rounded-md bg-emerald-100 px-2.5 py-1 text-sm font-bold text-emerald-800">
                                سعر نوع المناسبة
                            </span>
                            <span v-if="quote.pricing.is_weekend" class="rounded-md bg-amber-100 px-2.5 py-1 text-sm font-bold text-amber-800">نهاية أسبوع</span>
                        </div>

                        <div class="space-y-2 border-b border-slate-200 pb-3 text-[15px]">
                            <div v-for="(l, i) in quote.pricing.lines" :key="i" class="flex justify-between gap-3">
                                <span class="font-medium text-slate-800">{{ l.label }}</span>
                                <span class="shrink-0 font-bold text-slate-900">{{ money(l.amount) }}</span>
                            </div>
                        </div>

                        <div class="space-y-2 pt-3 text-[15px]">
                            <div v-if="quote.pricing.discount_amount > 0" class="flex justify-between text-red-700">
                                <span class="font-medium">الخصم</span>
                                <span class="font-bold">− {{ money(quote.pricing.discount_amount) }}</span>
                            </div>
                            <!-- الضريبة تُضاف فوق المُسعَّر — تُعرَض هنا كما ستخرج في
                                 الفاتورة، فلا يوقّع الموظف عقدًا برقمٍ لم يره. -->
                            <template v-if="quote.pricing.is_taxable">
                                <div class="flex justify-between border-t border-slate-200 pt-2.5">
                                    <span class="font-medium text-slate-700">الإجمالي قبل الضريبة</span>
                                    <span class="font-bold text-slate-900">{{ money(quote.pricing.net_amount) }}</span>
                                </div>
                                <div class="flex justify-between text-emerald-800">
                                    <span class="font-medium">ضريبة القيمة المضافة ({{ quote.pricing.tax_rate }}%)</span>
                                    <span class="font-bold">+ {{ money(quote.pricing.tax_amount) }}</span>
                                </div>
                            </template>
                            <div v-else-if="vatApplies" class="flex justify-between border-t border-slate-200 pt-2.5 text-slate-600">
                                <span class="font-medium">الضريبة</span>
                                <span class="font-bold">حجز بلا ضريبة</span>
                            </div>
                            <div class="flex justify-between border-t border-slate-200 pt-2.5 text-xl">
                                <span class="font-extrabold text-slate-900">{{ quote.pricing.is_taxable ? 'الإجمالي شامل الضريبة' : 'الإجمالي' }}</span>
                                <span class="font-extrabold text-emerald-700">{{ money(quote.pricing.total_amount) }}</span>
                            </div>
                            <div class="flex justify-between text-lg text-amber-800">
                                <span class="font-bold">العربون المطلوب</span>
                                <span class="font-extrabold">{{ money(quote.pricing.deposit_amount) }}</span>
                            </div>
                        </div>
                    </div>

                    <!-- السداد عند الحجز — عند الإنشاء فقط، والتعديل له لوحة الدفعات -->
                    <div v-if="!isEdit && quote" class="rounded-2xl border border-slate-300 bg-white p-5 shadow-sm">
                        <h3 class="mb-3 flex items-center gap-2 text-lg font-extrabold text-slate-900">
                            <Wallet class="h-5 w-5 text-slate-600" /> السداد
                        </h3>

                        <div class="grid grid-cols-3 gap-1.5">
                            <button type="button" @click="setPayChoice('none')"
                                class="rounded-lg py-2.5 text-[15px] font-bold transition"
                                :class="payChoice === 'none' ? 'bg-red-600 text-white' : 'bg-slate-100 text-slate-800 hover:bg-slate-200'"
                            >غير مسدَّد</button>
                            <button type="button" @click="setPayChoice('deposit')"
                                class="rounded-lg py-2.5 text-[15px] font-bold transition"
                                :class="payChoice === 'deposit' ? 'bg-amber-600 text-white' : 'bg-slate-100 text-slate-800 hover:bg-slate-200'"
                            >عربون</button>
                            <button type="button" @click="setPayChoice('full')"
                                class="rounded-lg py-2.5 text-[15px] font-bold transition"
                                :class="payChoice === 'full' ? 'bg-emerald-600 text-white' : 'bg-slate-100 text-slate-800 hover:bg-slate-200'"
                            >مسدَّد كامل</button>
                        </div>

                        <div v-if="form.payment_amount > 0" class="mt-3.5 space-y-3">
                            <div>
                                <label class="mb-1 block text-sm font-bold text-slate-800">المبلغ المقبوض</label>
                                <input v-model.number="form.payment_amount" type="number" min="0" step="0.01" class="w-full rounded-lg border border-slate-300 px-3 py-2.5 text-lg font-bold" />
                                <p v-if="form.errors.payment_amount" class="mt-1 text-sm text-red-700">{{ form.errors.payment_amount }}</p>
                            </div>

                            <div class="grid grid-cols-2 gap-2">
                                <div>
                                    <label class="mb-1 block text-sm font-bold text-slate-800">الطريقة</label>
                                    <select v-model="form.payment_method_id" class="w-full rounded-lg border border-slate-300 px-2.5 py-2.5 text-[15px]">
                                        <option v-for="m in meta.payment_methods" :key="m.id" :value="m.id">{{ m.label }}</option>
                                    </select>
                                </div>
                                <div>
                                    <label class="mb-1 block text-sm font-bold text-slate-800">تاريخ القبض</label>
                                    <input v-model="form.payment_paid_on" type="date" class="w-full rounded-lg border border-slate-300 px-2.5 py-2.5 text-[15px]" />
                                </div>
                            </div>

                            <div class="flex justify-between rounded-lg bg-slate-50 px-3 py-2.5 text-[15px]">
                                <span class="font-bold text-slate-800">المتبقي بعد السداد</span>
                                <span class="font-extrabold text-slate-900">{{ money(Math.max(0, quote.pricing.total_amount - form.payment_amount)) }}</span>
                            </div>

                            <label v-if="form.client_id" class="flex cursor-pointer items-center gap-2 text-sm font-bold text-slate-900">
                                <input type="checkbox" v-model="form.payment_notify" class="h-4 w-4 rounded border-slate-300 text-emerald-700" />
                                إشعار العميل على واتساب
                            </label>
                        </div>

                        <p v-else class="mt-3 text-sm font-medium leading-relaxed text-slate-700">
                            يُحفظ الحجز بلا دفعة، ويبقى المبلغ كاملًا على العميل.
                        </p>
                    </div>

                    <p v-if="form.errors.availability" class="rounded-xl bg-red-50 px-3 py-2 text-sm font-bold text-red-700">{{ form.errors.availability }}</p>

                    <div class="rounded-2xl border border-slate-300 bg-white p-5 shadow-sm">
                        <p v-if="blocked" class="mb-2 text-[15px] font-bold text-red-700">لا يمكن الحفظ ما دام الوقت غير متاح</p>
                        <div class="flex gap-2">
                            <button type="submit" :disabled="form.processing || blocked" class="flex-1 rounded-md bg-blue-600 px-5 py-3 text-lg font-bold text-white shadow-sm transition hover:bg-blue-700 disabled:opacity-50">
                                {{ isEdit ? 'حفظ التعديل' : 'حفظ الحجز' }}
                            </button>
                            <Link href="/admin/bookings/halls" class="rounded-xl border border-slate-300 bg-white px-5 py-3 text-lg font-bold text-slate-800 hover:bg-slate-50">
                                إلغاء
                            </Link>
                        </div>
                    </div>
                </aside>
            </div>
        </form>
    </AppLayout>
</template>
