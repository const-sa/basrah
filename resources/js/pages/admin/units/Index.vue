<script setup lang="ts">
import { StatPill, TableActionButton } from '@/components/data-table';
import SearchableSelect from '@/components/SearchableSelect.vue';
import { usePermissions } from '@/composables/usePermissions';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { BadgeDollarSign, Building2, ChevronDown, Home, ImagePlus, Lock, Pencil, Plus, Power, Search, Settings2, Trash2, Unlock, UserCog, Users, X } from 'lucide-vue-next';
import { computed, ref } from 'vue';

interface Section {
    id?: number | null;
    name: string;
    gender: 'men' | 'women' | 'mixed';
    is_active: boolean;
    facility_ids: number[];
    shared_facility_ids: number[];
    facility_names?: string[];
}

interface Unit {
    id: number;
    code: string;
    name: string;
    logo_url: string | null;
    manager_id: number | null;
    manager_name: string | null;
    type: 'hall' | 'chalet';
    bookable_mode: 'whole' | 'sections' | 'both';
    privacy_mode: 'open' | 'exclusive';
    capacity: number | null;
    /** The refundable deposit this unit usually takes — null means none. */
    security_deposit: number | null;
    description: string | null;
    notes: string | null;
    is_active: boolean;
    bookings_count: number;
    staff_ids: number[];
    staff_names: string[];
    sections: Section[];
    prices: PriceRow[];
}

/**
 * A staff candidate — a login account, since only an account can open a unit.
 * The role is read-only here; it is edited on the users screen.
 */
interface TeamMember {
    id: number;
    name: string;
    email: string;
    role_name: string | null;
    is_active: boolean;
    is_demo: boolean;
    sees_all_units: boolean;
}

/** unit_section_id = null يعني سعر الوحدة كاملة. */
interface PriceRow {
    unit_section_id: number | null;
    period: string;
    weekday_price: number | null;
    weekend_price: number | null;
    /** {رقم اليوم: السعر} — 0 الأحد … 6 السبت، وnull يعني «ارجع إلى سعر الأسبوع». */
    day_prices: Record<number, number | null>;
    /** Deposit charged on this row. A fixed amount wins over the percentage. */
    deposit_amount: number | null;
    deposit_percent: number | null;
}

interface Option { key: string; label: string; hint?: string }
interface Facility { id: number; name: string; icon: string | null }
interface Period { key: string; label: string; start: string; end: string }
interface Weekday { key: number; label: string; is_weekend: boolean }

const props = defineProps<{
    units: Unit[];
    options: {
        types: Option[];
        bookable_modes: Option[];
        privacy_modes: Option[];
        genders: Option[];
        facilities: Facility[];
        managers: { id: number; name: string }[];
        staff: TeamMember[];
        periods: Period[];
        /** فترة الشاليه الوحيدة: الليلة. */
        stay_periods: Period[];
        weekdays: Weekday[];
    };
    stats: { total: number; halls: number; chalets: number; sections: number; active: number; inactive: number };
    /** null = شاشة «كل الوحدات»، وإلا فالشاشة مقصورة على نوع واحد. */
    type: Unit['type'] | null;
}>();

const { can, canAny, canUnit } = usePermissions();

// نصوص الشاشة تتبع النوع المعروض — نفس المكوّن يخدم القاعات والشاليهات والكل.
const screen = computed(() => {
    if (props.type === 'hall') {
        return {
            title: 'القاعات',
            subtitle: 'قاعات المناسبات وأقسامها وأسعارها',
            createLabel: 'قاعة جديدة', editLabel: 'تعديل قاعة', emptyLabel: 'لا توجد قاعات بعد',
            href: '/admin/units/halls',
        };
    }
    if (props.type === 'chalet') {
        return {
            title: 'الشاليهات',
            subtitle: 'الشاليهات وأقسامها وأسعارها',
            createLabel: 'شاليه جديد', editLabel: 'تعديل شاليه', emptyLabel: 'لا توجد شاليهات بعد',
            href: '/admin/units/chalets',
        };
    }
    return {
        title: 'الوحدات والأقسام',
        subtitle: 'القاعات والشاليهات وأقسامها وأسعارها',
        createLabel: 'وحدة جديدة', editLabel: 'تعديل وحدة', emptyLabel: 'لا توجد وحدات بعد',
        href: '/admin/units',
    };
});

const breadcrumbs = computed<BreadcrumbItem[]>(() => [
    { title: 'لوحة التحكم', href: '/admin' },
    { title: screen.value.title, href: screen.value.href },
]);

const labelOf = (list: Option[], key: string) => list.find((o) => o.key === key)?.label ?? key;

const showModal = ref(false);
const editingId = ref<number | null>(null);
const logoPreview = ref<string | null>(null);

// Staff search — account lists get long, and scrolling alone doesn't find a name.
const teamQuery = ref('');

const teamMatches = computed(() => {
    const q = teamQuery.value.trim().toLowerCase();
    if (!q) return props.options.staff;

    return props.options.staff.filter((e) =>
        [e.name, e.email, e.role_name].filter(Boolean).join(' ').toLowerCase().includes(q),
    );
});

const form = useForm({
    code: '',
    name: '',
    logo: null as File | null,
    remove_logo: false,
    manager_id: null as number | null,
    // Type is not picked in the form: the screen dictates it, and the combined
    // screen starts new units as halls.
    type: (props.type ?? 'hall') as Unit['type'],
    // Booking mode, privacy rule and capacity were dropped from the form. New
    // units take the widest and safest defaults, existing ones keep what they
    // have — all three stay in the payload because the server requires them.
    bookable_mode: 'both' as Unit['bookable_mode'],
    privacy_mode: 'exclusive' as Unit['privacy_mode'],
    capacity: null as number | null,
    description: '',
    notes: '',
    is_active: true,
    sections: [] as Section[],
    /** Unit staff, by account id. Roles and permissions stay on the users screen. */
    staff_ids: [] as number[],
});

const emptySection = (name = '', gender: Section['gender'] = 'mixed'): Section => ({
    id: null, name, gender, is_active: true, facility_ids: [], shared_facility_ids: [],
});

/* useForm widens every array field to FormDataConvertible[], which drops
 * .length/.includes. Same array by reference, so mutating it edits the form. */
const memberIds = computed(() => form.staff_ids as unknown as number[]);
const sectionRows = computed(() => form.sections as unknown as Section[]);

const teamCount = computed(() => memberIds.value.length);
const isMember = (id: number) => memberIds.value.includes(id);

const toggleMember = (id: number) => {
    const i = memberIds.value.indexOf(id);

    if (i === -1) {
        memberIds.value.push(id);
    } else {
        memberIds.value.splice(i, 1);
    }
};

const openCreate = () => {
    editingId.value = null;
    logoPreview.value = null;
    teamQuery.value = '';
    form.reset();
    form.clearErrors();
    form.sections = [emptySection('قسم الرجال', 'men'), emptySection('قسم النساء', 'women')];
    showModal.value = true;
};

const openEdit = (u: Unit) => {
    editingId.value = u.id;
    logoPreview.value = u.logo_url;
    teamQuery.value = '';
    form.clearErrors();
    form.code = u.code;
    form.name = u.name;
    form.logo = null;
    form.remove_logo = false;
    form.manager_id = u.manager_id;
    form.type = u.type;
    form.bookable_mode = u.bookable_mode;
    form.privacy_mode = u.privacy_mode;
    form.capacity = u.capacity;
    form.description = u.description ?? '';
    form.notes = u.notes ?? '';
    form.is_active = u.is_active;
    form.staff_ids = [...u.staff_ids];
    form.sections = u.sections.map((s) => ({
        ...s,
        facility_ids: [...s.facility_ids],
        shared_facility_ids: [...s.shared_facility_ids],
    }));
    showModal.value = true;
};

const onLogoPick = (e: Event) => {
    const file = (e.target as HTMLInputElement).files?.[0] ?? null;
    form.logo = file;
    form.remove_logo = false;
    logoPreview.value = file ? URL.createObjectURL(file) : null;
};

const clearLogo = () => {
    form.logo = null;
    form.remove_logo = true;
    logoPreview.value = null;
};

const addSection = () => form.sections.push(emptySection());
const removeSection = (i: number) => form.sections.splice(i, 1);

const submit = () => {
    const opts = {
        preserveScroll: true,
        forceFormData: true, // رفع الشعار يستلزم multipart
        onSuccess: () => (showModal.value = false),
    };

    if (editingId.value) {
        // PUT لا يحمل ملفات في المتصفح، فيُرسل POST مع _method
        form.transform((d) => ({ ...d, _method: 'put' })).post(`/admin/units/${editingId.value}`, opts);
    } else {
        form.post('/admin/units', opts);
    }
};

const toggle = (u: Unit) => router.patch(`/admin/units/${u.id}/toggle`, {}, { preserveScroll: true });

/* ── الأسعار ─────────────────────────────────────────────────────────────
 * القاعة: صفّان لكل فترة — أيام الأسبوع، والجمعة والسبت.
 * الشاليه: يُباع بالليلة، فسعرٌ مستقل لكل يوم من أيام الأسبوع، لأن طلب لياليه
 * يتدرّج من الأحد إلى الجمعة ولا تحتمله ثنائية «أسبوع/نهاية أسبوع».
 * لا نِسَب مواسم فوق ذلك — فرق الأعياد يُدخَل في السعر مباشرة.
 */
const showPrices = ref(false);
const pricedUnit = ref<Unit | null>(null);

const priceForm = useForm({ security_deposit: null as number | null, prices: [] as PriceRow[] });

/** التسعير اليومي للشاليهات وحدها؛ القاعة تبقى على صفّي الأسبوع. */
const pricedByDay = computed(() => pricedUnit.value?.type === 'chalet');

const weekdays = computed(() => props.options.weekdays);

/** فترات التسعير المعروضة: فترات اليوم للقاعة، والليلة وحدها للشاليه. */
const pricedPeriods = (u: Unit): Period[] => (u.type === 'chalet' ? props.options.stay_periods : props.options.periods);

const emptyDayPrices = (): Record<number, number | null> =>
    Object.fromEntries(props.options.weekdays.map((d) => [d.key, null]));

/** صفوف التسعير: الوحدة كاملة ثم كل قسم — مبدوءة بما هو محفوظ أو بصفر. */
const buildPriceRows = (u: Unit): PriceRow[] => {
    // A chalet is priced whole and by room both, whichever it is let by today.
    // Its scope is derived from its rooms (Unit::allowsSectionBooking), so
    // narrowing the rows to the scope in force would leave the other side
    // unpriceable — and adding a room to a whole-priced chalet would silently
    // put it on sale at nothing.
    const targets: (number | null)[] = u.type === 'chalet'
        ? [null, ...u.sections.map((s) => s.id as number)]
        : [
            ...(u.bookable_mode === 'sections' ? [] : [null]),
            ...(u.bookable_mode === 'whole' ? [] : u.sections.map((s) => s.id as number)),
        ];

    return targets.flatMap((sectionId) =>
        pricedPeriods(u).map((p) => {
            const saved = u.prices?.find((r) => r.unit_section_id === sectionId && r.period === p.key);

            return {
                unit_section_id: sectionId,
                period: p.key,
                weekday_price: saved ? Number(saved.weekday_price) : null,
                weekend_price: saved ? Number(saved.weekend_price) : null,
                day_prices: { ...emptyDayPrices(), ...(saved?.day_prices ?? {}) },
                deposit_amount: saved?.deposit_amount ?? null,
                deposit_percent: saved?.deposit_percent ?? null,
            };
        }),
    );
};

const money = (n: number) => new Intl.NumberFormat('ar-SA-u-nu-latn', { maximumFractionDigits: 2 }).format(n ?? 0);

/* useForm widens array fields; read the rows back through their real type. */
const priceRows = computed(() => priceForm.prices as unknown as PriceRow[]);

/** One key per (section, period) — the unit of work in this modal. */
const rowKey = (r: PriceRow) => `${r.unit_section_id ?? 'whole'}:${r.period}`;

/**
 * Mirrors UnitPrice::hasAnyPrice() on the server: a row that exists but holds
 * no number is not a price, and the booking screens will not offer its period.
 */
const hasAnyPrice = (r: PriceRow) =>
    weekdays.value.some((d) => Number(r.day_prices[d.key]) > 0)
    || Number(r.weekday_price) > 0
    || Number(r.weekend_price) > 0;

/* Which section is on screen, which periods are expanded, and which of them
 * the operator marked as sold. Kept out of the payload — «sold» is derived
 * from the numbers on the server, and this only decides what is shown. */
const activeGroup = ref<number | null>(null);
const expanded = ref<Record<string, boolean>>({});
const dayGridOpen = ref<Record<string, boolean>>({});
const enabled = ref<Record<string, boolean>>({});

const openPrices = (u: Unit) => {
    pricedUnit.value = u;
    priceForm.clearErrors();
    priceForm.security_deposit = u.security_deposit;
    priceForm.prices = buildPriceRows(u);

    enabled.value = {};
    expanded.value = {};
    dayGridOpen.value = {};

    priceRows.value.forEach((r) => {
        enabled.value[rowKey(r)] = hasAnyPrice(r);
        // A period priced on specific days opens with that grid already
        // showing, so the exceptions are not hidden behind another click.
        dayGridOpen.value[rowKey(r)] = weekdays.value.some((d) => isSet(r.day_prices[d.key]));
    });

    activeGroup.value = priceGroups.value[0]?.sectionId ?? null;

    // Open the first unpriced period — that is the one still needing work.
    // Everything already priced stays collapsed behind its summary line.
    const rows = priceGroups.value[0]?.rows ?? [];
    const first = rows.find((r) => !enabled.value[rowKey(r)]) ?? rows[0];

    if (first) expanded.value[rowKey(first)] = true;

    showPrices.value = true;
};

/** صفوف مجموعة حسب الهدف (الوحدة كاملة / كل قسم) لعرضها كجداول منفصلة. */
const priceGroups = computed(() => {
    const u = pricedUnit.value;
    if (!u) return [];

    const targets = [...new Set(priceForm.prices.map((r) => r.unit_section_id))];

    return targets.map((sectionId) => ({
        sectionId,
        label: sectionId === null ? 'الوحدة كاملة' : (u.sections.find((s) => s.id === sectionId)?.name ?? 'قسم'),
        rows: priceForm.prices.filter((r) => r.unit_section_id === sectionId),
    }));
});

/**
 * How this unit is let, as its card states it.
 *
 * A hall reads it off bookable_mode. A chalet has no such setting to read: it
 * is let by the room when it has rooms and whole when it has none, so the
 * badge says which rather than repeating a field that no longer governs it.
 */
const scopeLabel = (u: Unit): string => {
    if (u.type !== 'chalet') return labelOf(props.options.bookable_modes, u.bookable_mode);

    return u.sections.some((s) => s.is_active) ? 'يُحجز بالأقسام' : 'يُحجز كاملًا';
};

const periodLabel = (key: string) =>
    [...props.options.periods, ...props.options.stay_periods].find((p) => p.key === key)?.label ?? key;

const activeRows = computed(() => priceGroups.value.find((g) => g.sectionId === activeGroup.value)?.rows ?? []);

/** How many of a section's periods are actually on sale — shown on its tab. */
const groupPriced = (rows: PriceRow[]) => rows.filter((r) => enabled.value[rowKey(r)] && hasAnyPrice(r)).length;

const pricedCount = computed(() => groupPriced(priceRows.value));

/** Marked as sold but left blank — the one state that silently does nothing. */
const blankCount = computed(
    () => priceRows.value.filter((r) => enabled.value[rowKey(r)] && !hasAnyPrice(r)).length,
);

/**
 * Turning a period off clears its numbers rather than just hiding them: the
 * server reads «no price» as «not offered when booking», so numbers left
 * behind would quietly put the period back on sale on the next save.
 */
const togglePeriod = (r: PriceRow) => {
    const key = rowKey(r);
    const next = !enabled.value[key];

    enabled.value[key] = next;
    expanded.value[key] = next;

    if (next) return;

    weekdays.value.forEach((d) => (r.day_prices[d.key] = null));
    r.weekday_price = null;
    r.weekend_price = null;
    r.deposit_amount = null;
    r.deposit_percent = null;
    dayGridOpen.value[key] = false;
};

/** نسخ سعر أيام الأسبوع إلى الجمعة والسبت — يختصر الإدخال ثم يُعدَّل يدويًا. */
const copyWeekdayToWeekend = (r: PriceRow) => (r.weekend_price = r.weekday_price);

/** تعميم السعر الافتراضي على أيام الأسبوع السبعة، ليُعدَّل منها ما يُستثنى. */
const spreadDefaultsToDays = (r: PriceRow) => {
    weekdays.value.forEach((d) => (r.day_prices[d.key] = d.is_weekend ? r.weekend_price : r.weekday_price));
};

const clearDayPrices = (r: PriceRow) => weekdays.value.forEach((d) => (r.day_prices[d.key] = null));

/**
 * تعميم تسعيرة فترة على بقية فترات القسم — أكثر الوحدات تبدأ بسعر واحد
 * لكل الفترات ثم يُرفع في واحدة أو اثنتين.
 */
const spreadPeriodToOthers = (source: PriceRow) => {
    activeRows.value
        .filter((r) => r.period !== source.period)
        .forEach((r) => {
            r.weekday_price = source.weekday_price;
            r.weekend_price = source.weekend_price;
            r.deposit_amount = source.deposit_amount;
            r.deposit_percent = source.deposit_percent;
            weekdays.value.forEach((d) => (r.day_prices[d.key] = source.day_prices[d.key]));

            enabled.value[rowKey(r)] = enabled.value[rowKey(source)];
        });
};

/** تعميم تسعيرة القسم المعروض على بقية الأقسام — يختصر وحدةً متماثلة الأقسام. */
const spreadGroupToOthers = () => {
    priceGroups.value
        .filter((g) => g.sectionId !== activeGroup.value)
        .forEach((g) => g.rows.forEach((r) => {
            const source = activeRows.value.find((s) => s.period === r.period);
            if (!source) return;

            r.weekday_price = source.weekday_price;
            r.weekend_price = source.weekend_price;
            r.deposit_amount = source.deposit_amount;
            r.deposit_percent = source.deposit_percent;
            weekdays.value.forEach((d) => (r.day_prices[d.key] = source.day_prices[d.key]));

            enabled.value[rowKey(r)] = enabled.value[rowKey(source)];
        }));
};

/** An emptied number input yields '' rather than null, so test for both. */
const isSet = (v: number | null | undefined | string) => v !== null && v !== undefined && v !== '';

/** ما يُطلب عربونًا على هذه الفترة، مقروءًا كما سيُحتسب. */
const depositLabel = (r: PriceRow) => {
    if (isSet(r.deposit_amount)) return money(Number(r.deposit_amount));
    if (isSet(r.deposit_percent)) return `${r.deposit_percent}٪`;

    return '';
};

/**
 * The collapsed line for a period, so the operator reads its terms without
 * opening it — the whole point of collapsing the card in the first place.
 */
const rowSummary = (r: PriceRow) => {
    if (!enabled.value[rowKey(r)]) return 'لا تُعرض في الحجز';
    if (!hasAnyPrice(r)) return 'مفعّلة بلا سعر — لن تُعرض حتى يُدخَل سعر';

    const parts: string[] = [];

    if (isSet(r.weekday_price)) parts.push(`أيام الأسبوع ${money(Number(r.weekday_price))}`);
    if (isSet(r.weekend_price)) parts.push(`الجمعة والسبت ${money(Number(r.weekend_price))}`);

    const overrides = weekdays.value.filter((d) => isSet(r.day_prices[d.key])).length;

    if (overrides) parts.push(`${overrides} يوم بسعر خاص`);

    const deposit = depositLabel(r);

    if (deposit) parts.push(`عربون ${deposit}`);

    return parts.join(' · ');
};

/** ما يرثه يوم تُرك فارغًا — يُعرض في مكان الكتابة فلا يُخمَّن. */
const dayFallback = (r: PriceRow, isWeekend: boolean) => {
    const value = isWeekend ? r.weekend_price : r.weekday_price;

    return isSet(value) ? money(Number(value)) : '—';
};

/**
 * Both deposit boxes filled — the fixed amount silently wins in
 * UnitPrice::depositFor(), so say so instead of letting it surprise.
 */
const depositClash = (r: PriceRow) => isSet(r.deposit_amount) && isSet(r.deposit_percent);

const submitPrices = () => {
    if (!pricedUnit.value) return;

    priceForm.put(`/admin/units/${pricedUnit.value.id}/prices`, {
        preserveScroll: true,
        onSuccess: () => (showPrices.value = false),
    });
};

const destroy = (u: Unit) => {
    if (confirm(`حذف الوحدة «${u.name}»؟`)) {
        router.delete(`/admin/units/${u.id}`, { preserveScroll: true });
    }
};
</script>

<template>
    <Head :title="screen.title" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="min-h-full space-y-5 bg-slate-100 p-5">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h1 class="text-2xl font-extrabold text-slate-900">{{ screen.title }}</h1>
                    <p class="mt-1 text-sm font-medium text-slate-600">{{ screen.subtitle }}</p>
                </div>
                <div class="flex flex-wrap gap-2">
                    <Link href="/admin/units-facilities" class="inline-flex items-center gap-1.5 rounded-md border border-slate-200 bg-white px-4 py-2 text-sm font-bold text-slate-700 hover:bg-slate-50">
                        <Settings2 class="h-4 w-4" /> إدارة المرافق
                    </Link>
                    <button v-if="type ? canUnit(type, 'create') : canAny('halls.create', 'chalets.create')" type="button" @click="openCreate" class="inline-flex items-center gap-1.5 rounded-md bg-blue-600 px-4 py-2 text-sm font-bold text-white shadow-sm transition hover:bg-blue-700">
                        <Plus class="h-4 w-4" /> {{ screen.createLabel }}
                    </button>
                </div>
            </div>

            <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
                <StatPill :label="`إجمالي ${screen.title}`" :value="stats.total" variant="primary" />
                <template v-if="type">
                    <StatPill label="متاحة للحجز" :value="stats.active" variant="success" />
                    <StatPill label="الأقسام" :value="stats.sections" variant="info" />
                </template>
                <template v-else>
                    <StatPill label="القاعات" :value="stats.halls" variant="info" />
                    <StatPill label="الشاليهات" :value="stats.chalets" variant="success" />
                </template>
                <StatPill label="موقوفة عن الحجز" :value="stats.inactive" variant="danger" />
            </div>

            <div class="grid grid-cols-1 gap-4 lg:grid-cols-2 xl:grid-cols-3">
                <div v-for="u in units" :key="u.id" class="relative flex flex-col overflow-hidden rounded-2xl border bg-white shadow-sm transition hover:shadow-lg" :class="u.is_active ? 'border-slate-200' : 'border-red-200'">
                    <div class="h-1.5" :class="u.is_active ? 'brand-gradient' : 'bg-red-400'"></div>

                    <!-- شارة الإيقاف: الوحدة الموقوفة لا تقبل حجزًا -->
                    <div v-if="!u.is_active" class="flex items-center gap-1.5 bg-red-50 px-4 py-1.5 text-[11px] font-extrabold text-red-700">
                        <Power class="h-3 w-3" /> موقوفة — لا تقبل حجوزات جديدة
                    </div>

                    <div class="flex flex-1 flex-col p-5">
                        <div class="mb-3 flex items-start justify-between gap-2">
                            <div class="flex min-w-0 items-center gap-3">
                                <span v-if="u.logo_url" class="h-11 w-11 shrink-0 overflow-hidden rounded-2xl ring-1 ring-slate-200">
                                    <img :src="u.logo_url" :alt="u.name" class="h-full w-full object-cover" />
                                </span>
                                <span v-else class="flex h-11 w-11 shrink-0 items-center justify-center rounded-2xl brand-gradient text-white shadow-md">
                                    <component :is="u.type === 'hall' ? Building2 : Home" class="h-5 w-5" />
                                </span>
                                <div class="min-w-0">
                                    <div class="truncate text-base font-extrabold text-slate-900">{{ u.name }}</div>
                                    <div class="text-[11px] font-bold text-slate-500" dir="ltr">{{ u.code }}</div>
                                </div>
                            </div>
                            <div class="flex shrink-0 items-center gap-1">
                                <TableActionButton v-if="canUnit(u.type, 'edit')" variant="success" :icon="BadgeDollarSign" title="الأسعار" @click="openPrices(u)" />
                                <TableActionButton v-if="canUnit(u.type, 'edit')" variant="edit" :icon="Pencil" title="تعديل" @click="openEdit(u)" />
                                <TableActionButton v-if="canUnit(u.type, 'edit')" :variant="u.is_active ? 'warning' : 'success'" :icon="Power" :title="u.is_active ? 'إيقاف عن الحجز' : 'تفعيل'" @click="toggle(u)" />
                                <TableActionButton v-if="canUnit(u.type, 'delete')" variant="danger" :icon="Trash2" title="حذف" @click="destroy(u)" />
                            </div>
                        </div>

                        <div v-if="u.manager_name" class="mb-2 inline-flex items-center gap-1.5 self-start rounded-md bg-indigo-50 px-2 py-1 text-[11px] font-bold text-indigo-700">
                            <UserCog class="h-3 w-3" /> المدير: {{ u.manager_name }}
                        </div>

                        <div v-if="u.staff_names.length" class="mb-2 flex flex-wrap items-center gap-1">
                            <span class="text-[10px] font-extrabold text-slate-400">الطاقم</span>
                            <span v-for="n in u.staff_names" :key="n" class="rounded-md bg-slate-100 px-1.5 py-0.5 text-[10px] font-bold text-slate-600">{{ n }}</span>
                        </div>

                        <div class="mb-3 flex flex-wrap gap-1.5 text-[11px] font-bold">
                            <!-- النوع مفهوم ضمنًا في الشاشة المقصورة على نوع واحد -->
                            <span v-if="!type" class="rounded-md bg-slate-100 px-2 py-0.5 text-slate-700">{{ labelOf(options.types, u.type) }}</span>
                            <span class="rounded-md bg-sky-100 px-2 py-0.5 text-sky-700">{{ scopeLabel(u) }}</span>
                            <span
                                class="inline-flex items-center gap-1 rounded-md px-2 py-0.5"
                                :class="u.privacy_mode === 'exclusive' ? 'bg-amber-100 text-amber-700' : 'bg-emerald-100 text-emerald-700'"
                            >
                                <component :is="u.privacy_mode === 'exclusive' ? Lock : Unlock" class="h-3 w-3" />
                                {{ labelOf(options.privacy_modes, u.privacy_mode) }}
                            </span>
                            <span v-if="u.capacity" class="inline-flex items-center gap-1 rounded-md bg-slate-100 px-2 py-0.5 text-slate-700">
                                <Users class="h-3 w-3" /> {{ u.capacity }}
                            </span>
                        </div>

                        <div class="mb-3 flex-1">
                            <div class="mb-1.5 text-[11px] font-extrabold text-slate-500">الأقسام ({{ u.sections.length }})</div>
                            <div v-if="u.sections.length" class="space-y-1.5">
                                <div v-for="s in u.sections" :key="s.id ?? s.name" class="flex flex-wrap items-center gap-1">
                                    <span
                                        class="rounded-md px-2 py-0.5 text-[11px] font-bold"
                                        :class="{
                                            'bg-blue-100 text-blue-700': s.gender === 'men',
                                            'bg-pink-100 text-pink-700': s.gender === 'women',
                                            'bg-violet-100 text-violet-700': s.gender === 'mixed',
                                        }"
                                    >{{ s.name }}</span>
                                    <span v-if="!s.is_active" class="rounded bg-red-100 px-1.5 py-0.5 text-[10px] font-bold text-red-600">موقوف</span>
                                    <span v-for="n in (s.facility_names ?? [])" :key="n" class="rounded bg-slate-100 px-1.5 py-0.5 text-[10px] font-medium text-slate-600">{{ n }}</span>
                                </div>
                            </div>
                            <span v-else class="text-[11px] font-medium text-slate-400">لا أقسام</span>
                        </div>

                        <p v-if="u.notes" class="mb-2 rounded-lg bg-amber-50 px-2.5 py-1.5 text-[11px] font-medium text-amber-800">{{ u.notes }}</p>

                        <div class="border-t border-slate-100 pt-2.5 text-xs font-bold text-slate-600">
                            {{ u.bookings_count }} حجز قائم
                        </div>
                    </div>
                </div>
            </div>

            <p v-if="!units.length" class="rounded-2xl border border-dashed border-slate-300 bg-white py-10 text-center text-sm font-bold text-slate-500">
                {{ screen.emptyLabel }}
            </p>
        </div>

        <!-- نموذج الوحدة -->
        <div v-if="showModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4" @click.self="showModal = false">
            <div class="flex max-h-[92vh] w-full max-w-3xl flex-col rounded-2xl bg-white shadow-2xl">
                <div class="flex items-center justify-between border-b border-slate-100 px-6 py-4">
                    <h2 class="text-lg font-extrabold text-slate-900">{{ editingId ? screen.editLabel : screen.createLabel }}</h2>
                    <button type="button" @click="showModal = false" class="rounded-lg p-1 text-slate-400 hover:bg-slate-100"><X class="h-5 w-5" /></button>
                </div>

                <form @submit.prevent="submit" class="flex min-h-0 flex-1 flex-col">
                    <div class="min-h-0 flex-1 space-y-4 overflow-y-auto px-6 py-4">
                        <!-- الشعار + البيانات الأساسية -->
                        <div class="flex flex-wrap gap-4">
                            <div class="shrink-0">
                                <label class="mb-1 block text-sm font-bold text-slate-700">شعار الوحدة</label>
                                <div class="relative">
                                    <label class="flex h-24 w-24 cursor-pointer items-center justify-center overflow-hidden rounded-2xl border-2 border-dashed border-slate-300 bg-slate-50 transition hover:border-emerald-400 hover:bg-emerald-50">
                                        <img v-if="logoPreview" :src="logoPreview" class="h-full w-full object-cover" alt="شعار" />
                                        <ImagePlus v-else class="h-7 w-7 text-slate-400" />
                                        <input type="file" accept="image/*" class="hidden" @change="onLogoPick" />
                                    </label>
                                    <button
                                        v-if="logoPreview" type="button" @click="clearLogo"
                                        class="absolute -top-1.5 rounded-full bg-red-500 p-1 text-white shadow ltr:-right-1.5 rtl:-left-1.5"
                                        title="إزالة الشعار"
                                    >
                                        <X class="h-3 w-3" />
                                    </button>
                                </div>
                                <p v-if="form.errors.logo" class="mt-1 max-w-24 text-[11px] text-red-500">{{ form.errors.logo }}</p>
                            </div>

                            <div class="min-w-[16rem] flex-1 space-y-3">
                                <div class="grid gap-3 sm:grid-cols-3">
                                    <div>
                                        <label class="mb-1 block text-sm font-bold text-slate-700">الترميز</label>
                                        <input v-model="form.code" dir="ltr" class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm focus:border-emerald-400 focus:outline-none focus:ring-2 focus:ring-emerald-100" placeholder="CH-BSR1" />
                                        <p v-if="form.errors.code" class="mt-1 text-xs text-red-500">{{ form.errors.code }}</p>
                                    </div>
                                    <div class="sm:col-span-2">
                                        <label class="mb-1 block text-sm font-bold text-slate-700">اسم الوحدة</label>
                                        <input v-model="form.name" class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm focus:border-emerald-400 focus:outline-none focus:ring-2 focus:ring-emerald-100" />
                                        <p v-if="form.errors.name" class="mt-1 text-xs text-red-500">{{ form.errors.name }}</p>
                                    </div>
                                </div>

                                <div class="grid gap-3 sm:grid-cols-2">
                                    <div>
                                        <label class="mb-1 block text-sm font-bold text-slate-700">مدير الوحدة</label>
                                        <SearchableSelect v-model="form.manager_id" :options="options.managers" placeholder="— بلا مدير —" />
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- حالة الوحدة -->
                        <label class="flex cursor-pointer items-start gap-2 rounded-xl border-2 p-3 transition" :class="form.is_active ? 'border-emerald-200 bg-emerald-50' : 'border-red-200 bg-red-50'">
                            <input type="checkbox" v-model="form.is_active" class="mt-0.5 h-4 w-4 rounded border-slate-300 text-emerald-600" />
                            <span class="text-sm font-bold text-slate-700">
                                الوحدة فعّالة وتقبل الحجز
                                <span class="block text-[11px] font-medium" :class="form.is_active ? 'text-emerald-600' : 'text-red-600'">
                                    {{ form.is_active ? 'تظهر في التقويم وتقبل حجوزات جديدة.' : 'موقوفة: لن تقبل أي حجز جديد، والحجوزات القائمة تبقى كما هي.' }}
                                </span>
                            </span>
                        </label>

                        <!-- طاقم الوحدة -->
                        <div>
                            <div class="mb-1 flex flex-wrap items-center justify-between gap-2">
                                <label class="flex items-center gap-1.5 text-sm font-bold text-slate-700">
                                    موظفو الوحدة
                                    <span v-if="teamCount" class="rounded-md bg-emerald-100 px-1.5 py-0.5 text-[11px] font-extrabold text-emerald-700">
                                        {{ teamCount }}
                                    </span>
                                </label>
                                <Link v-if="can('employees.view')" href="/admin/employees" class="text-[11px] font-bold text-blue-600 hover:underline">
                                    إدارة الأدوار والصلاحيات
                                </Link>
                            </div>
                            <p class="mb-2 text-[11px] font-medium text-slate-500">
                                الإسناد يفتح هذه الوحدة لحساب الموظف ويقصره على وحداته المسندة — والمدير العام وحده يرى كل الوحدات، فلا يظهر هنا. أما <b>ما</b> يفعله فيها فمن دوره في شاشة المستخدمين.
                            </p>

                            <div class="rounded-xl border border-slate-200">
                                <div class="relative border-b border-slate-200">
                                    <Search class="absolute top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400 ltr:left-2.5 rtl:right-2.5" />
                                    <input
                                        v-model="teamQuery" type="text" placeholder="بحث بالاسم أو البريد أو الدور…"
                                        class="w-full border-0 bg-transparent py-2 text-sm focus:outline-none focus:ring-0 ltr:pl-8 ltr:pr-2 rtl:pl-2 rtl:pr-8"
                                    />
                                </div>

                                <ul class="max-h-56 overflow-y-auto p-1.5">
                                    <li v-for="e in teamMatches" :key="e.id">
                                        <label class="flex cursor-pointer items-center gap-2 rounded-lg px-2 py-1.5 transition hover:bg-slate-50">
                                            <input
                                                type="checkbox" :checked="isMember(e.id)" @change="toggleMember(e.id)"
                                                class="h-4 w-4 shrink-0 rounded border-slate-300 text-emerald-600"
                                            />
                                            <span class="min-w-0 flex-1 truncate text-sm font-bold text-slate-800">{{ e.name }}</span>
                                            <span class="hidden shrink-0 truncate text-[11px] font-medium text-slate-400 sm:block" dir="ltr">{{ e.email }}</span>

                                            <span v-if="!e.is_active" class="shrink-0 rounded bg-red-50 px-1.5 py-0.5 text-[10px] font-bold text-red-600" title="حساب موقوف — لا يدخل النظام أصلًا">موقوف</span>
                                            <span v-if="e.is_demo" class="shrink-0 rounded bg-slate-100 px-1.5 py-0.5 text-[10px] font-bold text-slate-500">تجريبي</span>
                                            <span v-if="e.sees_all_units" class="shrink-0 rounded bg-indigo-50 px-1.5 py-0.5 text-[10px] font-bold text-indigo-600" title="يرى كل الوحدات الآن — والإسناد يقصره على وحداته">كل الوحدات</span>

                                            <!-- No role means no permission anywhere, so the posting opens an empty unit -->
                                            <span v-if="e.role_name" class="shrink-0 rounded bg-emerald-50 px-1.5 py-0.5 text-[10px] font-bold text-emerald-700">{{ e.role_name }}</span>
                                            <span v-else class="shrink-0 rounded bg-amber-50 px-1.5 py-0.5 text-[10px] font-bold text-amber-700" title="حساب بلا دور — لا يملك أي صلاحية">بلا دور</span>
                                        </label>
                                    </li>

                                    <li v-if="!teamMatches.length" class="px-2 py-4 text-center text-[11px] font-medium text-slate-400">
                                        {{ options.staff.length ? 'لا حساب مطابق' : 'لا حسابات — أضفها من شاشة المستخدمين.' }}
                                    </li>
                                </ul>
                            </div>
                        </div>

                        <!-- الأقسام -->
                        <div>
                            <div class="mb-2 flex items-center justify-between">
                                <label class="text-sm font-bold text-slate-700">الأقسام</label>
                                <button type="button" @click="addSection" class="inline-flex items-center gap-1 rounded-lg border border-slate-200 px-2.5 py-1 text-[11px] font-bold text-slate-600 hover:border-emerald-300 hover:bg-emerald-50 hover:text-emerald-600">
                                    <Plus class="h-3.5 w-3.5" /> إضافة قسم
                                </button>
                            </div>

                            <div class="space-y-2">
                                <div v-for="(s, i) in sectionRows" :key="i" class="rounded-xl border border-slate-200 bg-slate-50 p-3">
                                    <div class="grid items-end gap-2 sm:grid-cols-[1fr_auto_auto_auto]">
                                        <div>
                                            <label class="mb-1 block text-[11px] font-bold text-slate-600">اسم القسم</label>
                                            <input v-model="s.name" class="w-full rounded-lg border border-slate-200 px-2.5 py-2 text-sm" />
                                        </div>
                                        <div>
                                            <label class="mb-1 block text-[11px] font-bold text-slate-600">الفئة</label>
                                            <select v-model="s.gender" class="rounded-lg border border-slate-200 px-2.5 py-2 text-sm">
                                                <option v-for="g in options.genders" :key="g.key" :value="g.key">{{ g.label }}</option>
                                            </select>
                                        </div>
                                        <label class="flex cursor-pointer items-center gap-1.5 pb-2 text-[11px] font-bold text-slate-600">
                                            <input type="checkbox" v-model="s.is_active" class="h-3.5 w-3.5 rounded border-slate-300 text-emerald-600" />
                                            فعّال
                                        </label>
                                        <button type="button" @click="removeSection(i)" class="rounded-lg p-2 text-red-500 hover:bg-red-50" title="حذف القسم">
                                            <Trash2 class="h-4 w-4" />
                                        </button>
                                    </div>
                                </div>
                                <p v-if="!sectionRows.length" class="rounded-xl bg-slate-50 py-4 text-center text-xs font-medium text-slate-500">لا أقسام — أضف قسمًا من زر «إضافة قسم».</p>
                            </div>
                        </div>

                        <div>
                            <label class="mb-1 block text-sm font-bold text-slate-700">ملاحظات</label>
                            <textarea v-model="form.notes" rows="2" class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm"></textarea>
                        </div>
                    </div>

                    <div class="flex justify-end gap-2 border-t border-slate-100 px-6 py-4">
                        <button type="button" @click="showModal = false" class="rounded-xl border border-slate-200 bg-white px-4 py-2 text-sm font-bold text-slate-600 hover:bg-slate-50">إلغاء</button>
                        <button type="submit" :disabled="form.processing" class="rounded-md bg-blue-600 px-5 py-2 text-sm font-bold text-white shadow-sm transition hover:bg-blue-700 disabled:opacity-60">حفظ</button>
                    </div>
                </form>
            </div>
        </div>

        <!-- الأسعار: تسعيرة الوحدة والأقسام -->
        <div v-if="showPrices && pricedUnit" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4" @click.self="showPrices = false">
            <div class="flex max-h-[92vh] w-full max-w-3xl flex-col rounded-2xl bg-white shadow-2xl">
                <div class="flex items-start justify-between gap-3 border-b border-slate-100 px-6 py-4">
                    <div>
                        <div class="flex flex-wrap items-center gap-2">
                            <h2 class="text-lg font-extrabold text-slate-900">الأسعار — {{ pricedUnit.name }}</h2>
                            <span class="rounded-md bg-emerald-100 px-2 py-0.5 text-[11px] font-extrabold text-emerald-700">
                                {{ pricedCount }} فترة معروضة للحجز
                            </span>
                            <span v-if="blankCount" class="rounded-md bg-amber-100 px-2 py-0.5 text-[11px] font-extrabold text-amber-700">
                                {{ blankCount }} بلا سعر
                            </span>
                        </div>
                        <p class="mt-1 text-[11px] font-medium text-slate-500">
                            الفترة المطفأة لا تُعرض في الحجز. «الجمعة والسبت» هي نهاية الأسبوع المعتمدة.
                        </p>
                    </div>
                    <button type="button" @click="showPrices = false" class="shrink-0 rounded-lg p-1 text-slate-400 hover:bg-slate-100"><X class="h-5 w-5" /></button>
                </div>

                <!--
                    One figure for the whole unit, sitting above the periods
                    rather than inside them: the deposit answers for damage to
                    the chalet, and damage does not care which period the guest
                    booked. It is held, never charged, so it stays out of the
                    price rows entirely.
                -->
                <div class="flex flex-wrap items-center gap-3 border-b border-slate-100 bg-indigo-50/40 px-6 py-3">
                    <label class="flex items-center gap-2">
                        <span class="text-xs font-extrabold text-indigo-800">التأمين المسترد</span>
                        <input
                            v-model.number="priceForm.security_deposit"
                            type="number" min="0" step="any" dir="ltr" placeholder="—"
                            class="w-28 rounded-lg border border-indigo-200 px-2.5 py-1.5 text-sm focus:border-indigo-400 focus:outline-none focus:ring-2 focus:ring-indigo-100"
                        />
                    </label>
                    <p class="min-w-0 flex-1 text-[11px] font-medium text-slate-500">
                        يُقبض ضمانًا للتلفيات ويُعاد عند الخروج — لا يدخل في سعر الحجز ولا في المتبقي على النزيل.
                        اتركه فارغًا إن كانت الوحدة بلا تأمين.
                    </p>
                    <p v-if="priceForm.errors.security_deposit" class="text-[11px] font-bold text-red-600">
                        {{ priceForm.errors.security_deposit }}
                    </p>
                </div>

                <!-- الأقسام كتبويبات: عرضها مكدّسةً يجعل النافذة شريطًا لا ينتهي -->
                <div v-if="priceGroups.length > 1" class="flex flex-wrap items-center gap-1.5 border-b border-slate-100 bg-slate-50 px-6 py-2.5">
                    <button
                        v-for="g in priceGroups" :key="g.sectionId ?? 'whole'"
                        type="button" @click="activeGroup = g.sectionId"
                        class="inline-flex items-center gap-1.5 rounded-lg px-3 py-1.5 text-xs font-bold transition"
                        :class="activeGroup === g.sectionId ? 'bg-blue-600 text-white shadow-sm' : 'bg-white text-slate-600 ring-1 ring-slate-200 hover:bg-slate-100'"
                    >
                        {{ g.label }}
                        <span
                            class="rounded px-1.5 text-[10px] font-extrabold"
                            :class="activeGroup === g.sectionId ? 'bg-white/20 text-white' : 'bg-slate-100 text-slate-500'"
                        >{{ groupPriced(g.rows) }}/{{ g.rows.length }}</span>
                    </button>

                    <button
                        type="button" @click="spreadGroupToOthers"
                        class="ms-auto rounded-lg border border-slate-200 bg-white px-2.5 py-1.5 text-[11px] font-bold text-slate-600 hover:border-emerald-300 hover:text-emerald-600"
                        title="نسخ تسعيرة هذا القسم إلى بقية الأقسام"
                    >
                        تعميم على بقية الأقسام
                    </button>
                </div>

                <div class="min-h-0 flex-1 space-y-2 overflow-y-auto bg-slate-50/60 px-6 py-4">
                    <!-- بطاقة لكل فترة: مطويّة على ملخّصها، تُفتح للتحرير وحدها -->
                    <div
                        v-for="r in activeRows" :key="r.period"
                        class="overflow-hidden rounded-xl border bg-white transition"
                        :class="enabled[rowKey(r)] ? 'border-slate-200' : 'border-slate-200 bg-slate-50'"
                    >
                        <div class="flex items-center gap-3 px-3 py-2.5">
                            <!-- المفتاح يقول ما يفعله الحفظ: المطفأة تُمسح أسعارها -->
                            <button
                                type="button" @click="togglePeriod(r)"
                                class="relative h-5 w-9 shrink-0 rounded-full transition"
                                :class="enabled[rowKey(r)] ? 'bg-emerald-500' : 'bg-slate-300'"
                                :title="enabled[rowKey(r)] ? 'إطفاء الفترة — تُمسح أسعارها ولا تُعرض في الحجز' : 'تفعيل الفترة'"
                            >
                                <span
                                    class="absolute top-0.5 h-4 w-4 rounded-full bg-white shadow transition-all"
                                    :class="enabled[rowKey(r)] ? 'right-0.5' : 'right-4.5'"
                                ></span>
                            </button>

                            <button type="button" @click="expanded[rowKey(r)] = !expanded[rowKey(r)]" class="flex min-w-0 flex-1 items-center gap-2 text-start">
                                <span class="shrink-0 text-sm font-extrabold" :class="enabled[rowKey(r)] ? 'text-slate-900' : 'text-slate-400'">
                                    {{ periodLabel(r.period) }}
                                </span>
                                <span
                                    class="min-w-0 flex-1 truncate text-[11px] font-bold"
                                    :class="!enabled[rowKey(r)] ? 'text-slate-400' : hasAnyPrice(r) ? 'text-slate-500' : 'text-amber-600'"
                                >{{ rowSummary(r) }}</span>
                                <ChevronDown class="h-4 w-4 shrink-0 text-slate-400 transition" :class="expanded[rowKey(r)] && 'rotate-180'" />
                            </button>
                        </div>

                        <div v-if="enabled[rowKey(r)] && expanded[rowKey(r)]" class="space-y-3 border-t border-slate-100 px-3 py-3">
                            <!-- السعر الافتراضي أولًا: هو الأساس، وأسعار الأيام استثناءات عليه -->
                            <div class="flex flex-wrap items-end gap-3">
                                <label class="block">
                                    <span class="mb-1 block text-[11px] font-bold text-slate-600">أيام الأسبوع</span>
                                    <input
                                        v-model.number="r.weekday_price"
                                        type="number" min="0" step="any" dir="ltr" placeholder="0"
                                        class="w-28 rounded-lg border border-slate-300 px-2.5 py-1.5 text-sm focus:border-emerald-400 focus:outline-none focus:ring-2 focus:ring-emerald-100"
                                    />
                                </label>

                                <button
                                    type="button" @click="copyWeekdayToWeekend(r)"
                                    class="mb-1 rounded-lg border border-slate-200 px-2 py-1 text-[11px] font-bold text-slate-500 hover:border-emerald-300 hover:text-emerald-600"
                                    title="نسخ سعر أيام الأسبوع إلى الجمعة والسبت"
                                >←</button>

                                <label class="block">
                                    <span class="mb-1 block text-[11px] font-bold text-amber-700">الجمعة والسبت</span>
                                    <input
                                        v-model.number="r.weekend_price"
                                        type="number" min="0" step="any" dir="ltr" placeholder="0"
                                        class="w-28 rounded-lg border border-amber-300 bg-amber-50/40 px-2.5 py-1.5 text-sm focus:border-amber-400 focus:outline-none focus:ring-2 focus:ring-amber-100"
                                    />
                                </label>

                                <div class="mb-1 h-6 w-px bg-slate-200"></div>

                                <!-- العربون بجوار السعر الذي يُحتسب عليه -->
                                <label class="block">
                                    <span class="mb-1 block text-[11px] font-bold text-indigo-700">عربون ثابت</span>
                                    <input
                                        v-model.number="r.deposit_amount"
                                        type="number" min="0" step="any" dir="ltr" placeholder="—"
                                        class="w-24 rounded-lg border px-2.5 py-1.5 text-sm focus:outline-none focus:ring-2"
                                        :class="depositClash(r) ? 'border-amber-300 bg-amber-50 focus:ring-amber-100' : 'border-indigo-200 focus:border-indigo-400 focus:ring-indigo-100'"
                                    />
                                </label>
                                <label class="block">
                                    <span class="mb-1 block text-[11px] font-bold" :class="depositClash(r) ? 'text-slate-400' : 'text-indigo-700'">أو ٪</span>
                                    <input
                                        v-model.number="r.deposit_percent"
                                        type="number" min="0" max="100" step="any" dir="ltr" placeholder="—"
                                        class="w-20 rounded-lg border px-2.5 py-1.5 text-sm focus:outline-none focus:ring-2"
                                        :class="depositClash(r) ? 'border-amber-300 bg-amber-50 text-slate-400 focus:ring-amber-100' : 'border-indigo-200 focus:border-indigo-400 focus:ring-indigo-100'"
                                    />
                                </label>
                            </div>

                            <p v-if="depositClash(r)" class="text-[11px] font-bold text-amber-600">
                                المبلغ الثابت يتقدّم على النسبة — امسح أحدهما ليكون العربون واضحًا.
                            </p>

                            <!-- أسعار أيام بعينها: استثناءٌ لا يحتاجه أكثر الوحدات، فيُطوى -->
                            <div v-if="pricedByDay" class="rounded-lg border border-slate-200">
                                <button
                                    type="button" @click="dayGridOpen[rowKey(r)] = !dayGridOpen[rowKey(r)]"
                                    class="flex w-full items-center gap-2 px-2.5 py-2 text-start"
                                >
                                    <ChevronDown class="h-3.5 w-3.5 shrink-0 text-slate-400 transition" :class="dayGridOpen[rowKey(r)] && 'rotate-180'" />
                                    <span class="text-[11px] font-bold text-slate-600">أسعار أيام بعينها</span>
                                    <span class="text-[11px] font-medium text-slate-400">
                                        اليوم المتروك فارغًا يأخذ السعر الافتراضي أعلاه
                                    </span>
                                </button>

                                <div v-if="dayGridOpen[rowKey(r)]" class="border-t border-slate-100 px-2.5 py-2.5">
                                    <div class="mb-2 flex flex-wrap gap-1.5">
                                        <button type="button" @click="spreadDefaultsToDays(r)" class="rounded-lg border border-slate-200 px-2 py-1 text-[11px] font-bold text-slate-600 hover:border-emerald-300 hover:text-emerald-600">
                                            تعبئة من السعر الافتراضي
                                        </button>
                                        <button type="button" @click="clearDayPrices(r)" class="rounded-lg border border-slate-200 px-2 py-1 text-[11px] font-bold text-slate-600 hover:border-red-300 hover:text-red-600">
                                            تفريغ الأيام
                                        </button>
                                    </div>

                                    <div class="grid grid-cols-2 gap-2 sm:grid-cols-4 lg:grid-cols-7">
                                        <label v-for="d in weekdays" :key="d.key" class="block">
                                            <span class="mb-1 block text-[11px] font-bold" :class="d.is_weekend ? 'text-amber-600' : 'text-slate-500'">{{ d.label }}</span>
                                            <input
                                                v-model.number="r.day_prices[d.key]"
                                                type="number" min="0" step="any" dir="ltr"
                                                :placeholder="dayFallback(r, d.is_weekend)"
                                                class="w-full rounded-lg border px-2.5 py-1.5 text-sm placeholder:text-slate-300 focus:outline-none focus:ring-2"
                                                :class="d.is_weekend
                                                    ? 'border-amber-200 bg-amber-50/40 focus:border-amber-400 focus:ring-amber-100'
                                                    : 'border-slate-200 focus:border-emerald-400 focus:ring-emerald-100'"
                                            />
                                        </label>
                                    </div>
                                </div>
                            </div>

                            <button
                                v-if="activeRows.length > 1"
                                type="button" @click="spreadPeriodToOthers(r)"
                                class="rounded-lg border border-slate-200 px-2.5 py-1 text-[11px] font-bold text-slate-600 hover:border-emerald-300 hover:text-emerald-600"
                            >
                                تعميم هذه التسعيرة على بقية الفترات
                            </button>
                        </div>
                    </div>

                    <p v-if="!priceGroups.length" class="rounded-xl bg-white py-4 text-center text-xs font-medium text-slate-500">
                        لا يوجد ما يُسعَّر — أضف أقسامًا أو غيّر نمط الحجز.
                    </p>
                </div>

                <div class="flex flex-wrap items-center justify-between gap-2 border-t border-slate-100 px-6 py-4">
                    <p v-if="blankCount" class="text-[11px] font-bold text-amber-600">
                        {{ blankCount }} فترة مفعّلة بلا سعر — أطفئها أو أدخل سعرها، وإلا لن تُعرض في الحجز.
                    </p>
                    <span v-else></span>

                    <div class="flex gap-2">
                        <button type="button" @click="showPrices = false" class="rounded-xl border border-slate-200 bg-white px-4 py-2 text-sm font-bold text-slate-600 hover:bg-slate-50">إغلاق</button>
                        <button type="button" @click="submitPrices" :disabled="priceForm.processing || !priceGroups.length" class="rounded-md bg-blue-600 px-5 py-2 text-sm font-bold text-white shadow-sm transition hover:bg-blue-700 disabled:opacity-60">حفظ الأسعار</button>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
