<script setup lang="ts">
import { StatPill, TableActionButton } from '@/components/data-table';
import SearchableSelect from '@/components/SearchableSelect.vue';
import { usePermissions } from '@/composables/usePermissions';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { BadgeDollarSign, Building2, Home, ImagePlus, Lock, Pencil, Plus, Power, Settings2, Trash2, Unlock, UserCog, Users, X } from 'lucide-vue-next';
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
    description: string | null;
    notes: string | null;
    is_active: boolean;
    bookings_count: number;
    sections: Section[];
    prices: PriceRow[];
}

/** unit_section_id = null يعني سعر الوحدة كاملة. */
interface PriceRow {
    unit_section_id: number | null;
    period: string;
    weekday_price: number | null;
    weekend_price: number | null;
    /** {رقم اليوم: السعر} — 0 الأحد … 6 السبت، وnull يعني «ارجع إلى سعر الأسبوع». */
    day_prices: Record<number, number | null>;
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
        periods: Period[];
        /** فترة الشاليه الوحيدة: الليلة. */
        stay_periods: Period[];
        weekdays: Weekday[];
    };
    stats: { total: number; halls: number; chalets: number; sections: number; active: number; inactive: number };
    /** null = شاشة «كل الوحدات»، وإلا فالشاشة مقصورة على نوع واحد. */
    type: Unit['type'] | null;
}>();

const { canAny, canUnit } = usePermissions();

// نصوص الشاشة تتبع النوع المعروض — نفس المكوّن يخدم القاعات والشاليهات والكل.
const screen = computed(() => {
    if (props.type === 'hall') {
        return {
            title: 'القاعات',
            subtitle: 'قاعات المناسبات وأقسامها، ونمط الحجز وقاعدة الخصوصية ومرافق كل قسم',
            createLabel: 'قاعة جديدة', editLabel: 'تعديل قاعة', emptyLabel: 'لا توجد قاعات بعد',
            href: '/admin/units/halls',
        };
    }
    if (props.type === 'chalet') {
        return {
            title: 'الشاليهات',
            subtitle: 'الشاليهات وأقسامها، ونمط الحجز وقاعدة الخصوصية ومرافق كل قسم',
            createLabel: 'شاليه جديد', editLabel: 'تعديل شاليه', emptyLabel: 'لا توجد شاليهات بعد',
            href: '/admin/units/chalets',
        };
    }
    return {
        title: 'الوحدات والأقسام',
        subtitle: 'القاعات والشاليهات، ونمط الحجز وقاعدة الخصوصية ومرافق كل قسم',
        createLabel: 'وحدة جديدة', editLabel: 'تعديل وحدة', emptyLabel: 'لا توجد وحدات بعد',
        href: '/admin/units',
    };
});

const breadcrumbs = computed<BreadcrumbItem[]>(() => [
    { title: 'لوحة التحكم', href: '/admin' },
    { title: screen.value.title, href: screen.value.href },
]);

const labelOf = (list: Option[], key: string) => list.find((o) => o.key === key)?.label ?? key;
const facilityName = (id: number) => props.options.facilities.find((f) => f.id === id)?.name ?? '';

const showModal = ref(false);
const editingId = ref<number | null>(null);
const logoPreview = ref<string | null>(null);

// النوع يُختار في شاشة «الكل» دائمًا، وفي الشاشة المقصورة عند التعديل فقط — لنقل الوحدة بين القائمتين.
const showTypeField = computed(() => !props.type || editingId.value !== null);

const form = useForm({
    code: '',
    name: '',
    logo: null as File | null,
    remove_logo: false,
    manager_id: null as number | null,
    // شاشة نوع واحد تفرض نوعها على الوحدة الجديدة، وشاشة «الكل» تبدأ بالشاليه.
    type: (props.type ?? 'chalet') as Unit['type'],
    bookable_mode: 'both' as Unit['bookable_mode'],
    privacy_mode: 'exclusive' as Unit['privacy_mode'],
    capacity: null as number | null,
    description: '',
    notes: '',
    is_active: true,
    sections: [] as Section[],
});

const emptySection = (name = '', gender: Section['gender'] = 'mixed'): Section => ({
    id: null, name, gender, is_active: true, facility_ids: [], shared_facility_ids: [],
});

const openCreate = () => {
    editingId.value = null;
    logoPreview.value = null;
    form.reset();
    form.clearErrors();
    form.sections = [emptySection('قسم الرجال', 'men'), emptySection('قسم النساء', 'women')];
    showModal.value = true;
};

const openEdit = (u: Unit) => {
    editingId.value = u.id;
    logoPreview.value = u.logo_url;
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

/** إسناد المرفق للقسم؛ ورفعه يرفع معه علامة «مشترك». */
const toggleFacility = (section: Section, id: number) => {
    const i = section.facility_ids.indexOf(id);
    if (i === -1) {
        section.facility_ids.push(id);
    } else {
        section.facility_ids.splice(i, 1);
        const s = section.shared_facility_ids.indexOf(id);
        if (s !== -1) section.shared_facility_ids.splice(s, 1);
    }
};

/** المرفق المشترك يجعل خصوصية الأقسام مسألة تشغيلية لا شكلية. */
const toggleShared = (section: Section, id: number) => {
    if (!section.facility_ids.includes(id)) return;
    const i = section.shared_facility_ids.indexOf(id);
    i === -1 ? section.shared_facility_ids.push(id) : section.shared_facility_ids.splice(i, 1);
};

const sectionsRelevant = computed(() => form.bookable_mode !== 'whole');

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
const money = (n: number) => new Intl.NumberFormat('ar-SA-u-nu-latn', { maximumFractionDigits: 2 }).format(n ?? 0);

const showPrices = ref(false);
const pricedUnit = ref<Unit | null>(null);

const priceForm = useForm({ prices: [] as PriceRow[] });

/** التسعير اليومي للشاليهات وحدها؛ القاعة تبقى على صفّي الأسبوع. */
const pricedByDay = computed(() => pricedUnit.value?.type === 'chalet');

const weekdays = computed(() => props.options.weekdays);

/** فترات التسعير المعروضة: فترات اليوم للقاعة، والليلة وحدها للشاليه. */
const pricedPeriods = (u: Unit): Period[] => (u.type === 'chalet' ? props.options.stay_periods : props.options.periods);

const emptyDayPrices = (): Record<number, number | null> =>
    Object.fromEntries(props.options.weekdays.map((d) => [d.key, null]));

/** صفوف التسعير: الوحدة كاملة ثم كل قسم — مبدوءة بما هو محفوظ أو بصفر. */
const buildPriceRows = (u: Unit): PriceRow[] => {
    const targets: (number | null)[] = [
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
            };
        }),
    );
};

const openPrices = (u: Unit) => {
    pricedUnit.value = u;
    priceForm.clearErrors();
    priceForm.prices = buildPriceRows(u);
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

const periodLabel = (key: string) =>
    [...props.options.periods, ...props.options.stay_periods].find((p) => p.key === key)?.label ?? key;

/** نسخ سعر أيام الأسبوع إلى الجمعة والسبت — يختصر الإدخال ثم يُعدَّل يدويًا. */
const copyWeekdayToWeekend = (sectionId: number | null) => {
    priceForm.prices
        .filter((r) => r.unit_section_id === sectionId)
        .forEach((r) => (r.weekend_price = r.weekday_price));
};

/**
 * تعبئة بقية أيام الأسبوع بسعر أول يوم مُدخَل — سبعة حقول لكل قسم إدخالٌ طويل،
 * وأكثر الشاليهات تبدأ بسعر واحد ثم تُرفع فيه ليلتان أو ثلاث.
 */
const fillDaysFromFirst = (sectionId: number | null) => {
    priceForm.prices
        .filter((r) => r.unit_section_id === sectionId)
        .forEach((r) => {
            const source = weekdays.value.map((d) => r.day_prices[d.key]).find((v) => v !== null && v !== undefined);
            if (source === undefined) return;

            weekdays.value.forEach((d) => (r.day_prices[d.key] = source as number));
        });
};

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

                        <div class="mb-3 flex flex-wrap gap-1.5 text-[11px] font-bold">
                            <!-- النوع مفهوم ضمنًا في الشاشة المقصورة على نوع واحد -->
                            <span v-if="!type" class="rounded-md bg-slate-100 px-2 py-0.5 text-slate-700">{{ labelOf(options.types, u.type) }}</span>
                            <span class="rounded-md bg-sky-100 px-2 py-0.5 text-sky-700">{{ labelOf(options.bookable_modes, u.bookable_mode) }}</span>
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

                                <div class="grid gap-3" :class="showTypeField ? 'sm:grid-cols-3' : 'sm:grid-cols-2'">
                                    <!-- شاشة نوع واحد تفرض نوعها عند الإضافة، ويبقى التغيير متاحًا عند التعديل لنقل الوحدة بين القائمتين. -->
                                    <div v-if="showTypeField">
                                        <label class="mb-1 block text-sm font-bold text-slate-700">النوع</label>
                                        <select v-model="form.type" class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm">
                                            <option v-for="o in options.types" :key="o.key" :value="o.key">{{ o.label }}</option>
                                        </select>
                                    </div>
                                    <div>
                                        <label class="mb-1 block text-sm font-bold text-slate-700">السعة</label>
                                        <input v-model.number="form.capacity" type="number" min="1" class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm" />
                                    </div>
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

                        <div>
                            <label class="mb-1.5 block text-sm font-bold text-slate-700">نمط الحجز</label>
                            <div class="grid gap-2 sm:grid-cols-3">
                                <label v-for="o in options.bookable_modes" :key="o.key" class="cursor-pointer rounded-xl border-2 p-3 transition" :class="form.bookable_mode === o.key ? 'border-emerald-400 bg-emerald-50' : 'border-slate-200 hover:border-slate-300'">
                                    <input v-model="form.bookable_mode" type="radio" :value="o.key" class="sr-only" />
                                    <div class="text-sm font-extrabold text-slate-800">{{ o.label }}</div>
                                    <div class="mt-0.5 text-[11px] font-medium text-slate-500">{{ o.hint }}</div>
                                </label>
                            </div>
                        </div>

                        <div>
                            <label class="mb-1.5 block text-sm font-bold text-slate-700">قاعدة الخصوصية بين الأقسام</label>
                            <div class="grid gap-2 sm:grid-cols-2">
                                <label v-for="o in options.privacy_modes" :key="o.key" class="cursor-pointer rounded-xl border-2 p-3 transition" :class="form.privacy_mode === o.key ? 'border-amber-400 bg-amber-50' : 'border-slate-200 hover:border-slate-300'">
                                    <input v-model="form.privacy_mode" type="radio" :value="o.key" class="sr-only" />
                                    <div class="text-sm font-extrabold text-slate-800">{{ o.label }}</div>
                                    <div class="mt-0.5 text-[11px] font-medium text-slate-500">{{ o.hint }}</div>
                                </label>
                            </div>
                        </div>

                        <!-- الأقسام -->
                        <div v-if="sectionsRelevant">
                            <div class="mb-2 flex items-center justify-between">
                                <label class="text-sm font-bold text-slate-700">الأقسام</label>
                                <button type="button" @click="addSection" class="inline-flex items-center gap-1 rounded-lg border border-slate-200 px-2.5 py-1 text-[11px] font-bold text-slate-600 hover:border-emerald-300 hover:bg-emerald-50 hover:text-emerald-600">
                                    <Plus class="h-3.5 w-3.5" /> إضافة قسم
                                </button>
                            </div>

                            <div class="space-y-2">
                                <div v-for="(s, i) in form.sections" :key="i" class="rounded-xl border border-slate-200 bg-slate-50 p-3">
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

                                    <div class="mt-2">
                                        <div class="mb-1 flex items-center gap-2 text-[11px] font-bold text-slate-600">
                                            <span>مرافق القسم</span>
                                            <span class="text-[10px] font-medium text-slate-400">اضغط المرفق لإسناده · اضغط «مشترك» إن كان يُشارك بقية الأقسام</span>
                                        </div>
                                        <div class="flex flex-wrap gap-1">
                                            <span
                                                v-for="f in options.facilities" :key="f.id"
                                                class="inline-flex items-center overflow-hidden rounded-md text-[11px] font-bold ring-1"
                                                :class="s.facility_ids.includes(f.id) ? 'ring-emerald-300' : 'ring-slate-200'"
                                            >
                                                <button
                                                    type="button" @click="toggleFacility(s, f.id)"
                                                    class="px-2 py-0.5 transition"
                                                    :class="s.facility_ids.includes(f.id) ? 'bg-emerald-600 text-white' : 'bg-white text-slate-600 hover:bg-slate-100'"
                                                >{{ f.name }}</button>
                                                <button
                                                    v-if="s.facility_ids.includes(f.id)"
                                                    type="button" @click="toggleShared(s, f.id)"
                                                    class="px-1.5 py-0.5 text-[10px] transition"
                                                    :class="s.shared_facility_ids.includes(f.id) ? 'bg-amber-500 text-white' : 'bg-emerald-50 text-emerald-700 hover:bg-amber-100'"
                                                    :title="s.shared_facility_ids.includes(f.id) ? 'مشترك مع بقية الأقسام' : 'خاص بهذا القسم'"
                                                >{{ s.shared_facility_ids.includes(f.id) ? 'مشترك' : 'خاص' }}</button>
                                            </span>
                                            <span v-if="!options.facilities.length" class="text-[11px] font-medium text-slate-400">
                                                لا مرافق معرّفة — أضفها من «إدارة المرافق».
                                            </span>
                                        </div>
                                    </div>
                                </div>
                                <p v-if="!form.sections.length" class="rounded-xl bg-slate-50 py-4 text-center text-xs font-medium text-slate-500">لا أقسام — أضف قسمًا أو غيّر نمط الحجز إلى «الوحدة كاملة فقط».</p>
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
                <div class="flex items-center justify-between border-b border-slate-100 px-6 py-4">
                    <div>
                        <h2 class="text-lg font-extrabold text-slate-900">الأسعار — {{ pricedUnit.name }}</h2>
                        <p v-if="pricedByDay" class="text-[11px] font-medium text-slate-500">سعر الليلة بالريال لكل يوم من أيام الأسبوع — اليوم المتروك فارغًا يأخذ سعر أيام الأسبوع.</p>
                        <p v-else class="text-[11px] font-medium text-slate-500">السعر بالريال لكل فترة. «الجمعة والسبت» هي نهاية الأسبوع المعتمدة.</p>
                    </div>
                    <button type="button" @click="showPrices = false" class="rounded-lg p-1 text-slate-400 hover:bg-slate-100"><X class="h-5 w-5" /></button>
                </div>

                <div class="min-h-0 flex-1 space-y-5 overflow-y-auto px-6 py-4">
                    <!-- تسعيرة الوحدة والأقسام -->
                    <div v-for="g in priceGroups" :key="g.sectionId ?? 'whole'" class="overflow-hidden rounded-xl border border-slate-200">
                        <div class="flex items-center justify-between gap-2 bg-slate-50 px-3 py-2">
                            <span class="text-sm font-extrabold text-slate-800">{{ g.label }}</span>
                            <button v-if="pricedByDay" type="button" @click="fillDaysFromFirst(g.sectionId)" class="rounded-lg border border-slate-200 bg-white px-2 py-1 text-[11px] font-bold text-slate-600 hover:border-emerald-300 hover:text-emerald-600">
                                تعميم أول سعر على بقية الأيام
                            </button>
                            <button v-else type="button" @click="copyWeekdayToWeekend(g.sectionId)" class="rounded-lg border border-slate-200 bg-white px-2 py-1 text-[11px] font-bold text-slate-600 hover:border-emerald-300 hover:text-emerald-600">
                                نسخ سعر الأسبوع إلى الجمعة والسبت
                            </button>
                        </div>

                        <!-- الشاليه: سعر لكل يوم من أيام الأسبوع -->
                        <div v-if="pricedByDay" class="space-y-3 px-3 py-3">
                            <div v-for="r in g.rows" :key="r.period" class="space-y-2">
                                <div class="text-[11px] font-extrabold text-slate-500">{{ periodLabel(r.period) }}</div>
                                <div class="grid grid-cols-2 gap-2 sm:grid-cols-4 lg:grid-cols-7">
                                    <label v-for="d in weekdays" :key="d.key" class="block">
                                        <span class="mb-1 block text-[11px] font-bold" :class="d.is_weekend ? 'text-amber-600' : 'text-slate-500'">{{ d.label }}</span>
                                        <input
                                            v-model.number="r.day_prices[d.key]"
                                            type="number" min="0" step="any" dir="ltr" placeholder="0"
                                            class="w-full rounded-lg border px-2.5 py-1.5 text-sm focus:outline-none focus:ring-2"
                                            :class="d.is_weekend
                                                ? 'border-amber-200 bg-amber-50/40 focus:border-amber-400 focus:ring-amber-100'
                                                : 'border-slate-200 focus:border-emerald-400 focus:ring-emerald-100'"
                                        />
                                    </label>
                                </div>

                                <!-- السعر الافتراضي لأي يوم يُترك فارغًا -->
                                <div class="flex flex-wrap items-center gap-2 rounded-lg bg-slate-50 px-2.5 py-2">
                                    <span class="text-[11px] font-bold text-slate-500">سعر افتراضي — أيام الأسبوع</span>
                                    <input v-model.number="r.weekday_price" type="number" min="0" step="any" dir="ltr" placeholder="0" class="w-24 rounded-lg border border-slate-200 bg-white px-2 py-1 text-xs focus:border-emerald-400 focus:outline-none focus:ring-2 focus:ring-emerald-100" />
                                    <span class="text-[11px] font-bold text-slate-500">الجمعة والسبت</span>
                                    <input v-model.number="r.weekend_price" type="number" min="0" step="any" dir="ltr" placeholder="0" class="w-24 rounded-lg border border-amber-200 bg-white px-2 py-1 text-xs focus:border-amber-400 focus:outline-none focus:ring-2 focus:ring-amber-100" />
                                </div>
                            </div>
                        </div>

                        <!-- القاعة: صفّان لكل فترة -->
                        <table v-else class="w-full text-sm">
                            <thead>
                                <tr class="border-y border-slate-100 text-[11px] font-extrabold text-[#1e3a8a]">
                                    <th class="px-3 py-2 text-start">الفترة</th>
                                    <th class="px-3 py-2 text-start">أيام الأسبوع</th>
                                    <th class="px-3 py-2 text-start">الجمعة والسبت</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="r in g.rows" :key="r.period" class="border-b border-slate-50 last:border-0">
                                    <td class="px-3 py-2 font-bold text-slate-700">{{ periodLabel(r.period) }}</td>
                                    <td class="px-3 py-2">
                                        <input v-model.number="r.weekday_price" type="number" min="0" step="any" dir="ltr" placeholder="0" class="w-28 rounded-lg border border-slate-200 px-2.5 py-1.5 text-sm focus:border-emerald-400 focus:outline-none focus:ring-2 focus:ring-emerald-100" />
                                    </td>
                                    <td class="px-3 py-2">
                                        <input v-model.number="r.weekend_price" type="number" min="0" step="any" dir="ltr" placeholder="0" class="w-28 rounded-lg border border-amber-200 bg-amber-50/40 px-2.5 py-1.5 text-sm focus:border-amber-400 focus:outline-none focus:ring-2 focus:ring-amber-100" />
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <p v-if="!priceGroups.length" class="rounded-xl bg-slate-50 py-4 text-center text-xs font-medium text-slate-500">
                        لا يوجد ما يُسعَّر — أضف أقسامًا أو غيّر نمط الحجز.
                    </p>
                </div>

                <div class="flex justify-end gap-2 border-t border-slate-100 px-6 py-4">
                    <button type="button" @click="showPrices = false" class="rounded-xl border border-slate-200 bg-white px-4 py-2 text-sm font-bold text-slate-600 hover:bg-slate-50">إغلاق</button>
                    <button type="button" @click="submitPrices" :disabled="priceForm.processing || !priceGroups.length" class="rounded-md bg-blue-600 px-5 py-2 text-sm font-bold text-white shadow-sm transition hover:bg-blue-700 disabled:opacity-60">حفظ الأسعار</button>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
