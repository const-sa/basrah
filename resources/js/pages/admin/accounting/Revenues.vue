<script setup lang="ts">
import { usePermissions } from '@/composables/usePermissions';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/vue3';
import { Building2, CalendarRange, Download, Home, Layers, Receipt, Scale, TrendingUp, Wallet, Waves, type LucideIcon } from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';

interface RevenueLine {
    id: number;
    entry_id: number;
    number: string;
    entry_date: string;
    source: string;
    source_label: string;
    account_code: string;
    account: string;
    cost_center_id: number | null;
    center: string | null;
    segment: string;
    segment_label: string;
    description: string | null;
    amount: number;
}

interface SegmentRow {
    key: string;
    label: string;
    amount: number;
    count: number;
    share: number;
}

interface CenterRow {
    cost_center_id: number | null;
    name: string;
    segment: string;
    segment_label: string;
    count: number;
    amount: number;
    share: number;
}

interface Center {
    id: number;
    name: string;
    segment: string;
}

const props = defineProps<{
    lines: { data: RevenueLine[]; links: { url: string | null; label: string; active: boolean }[] };
    filters: Record<string, string | number | null>;
    stats: { total: number; count: number; month: number; average: number };
    bySegment: SegmentRow[];
    byCenter: CenterRow[];
    byAccount: { code: string; name: string; count: number; amount: number; share: number }[];
    segments: { key: string; label: string }[];
    centers: Center[];
    accounts: { id: number; code: string; name: string }[];
    sources: { key: string; label: string }[];
}>();

const { can } = usePermissions();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'لوحة التحكم', href: '/admin' },
    { title: 'الإيرادات', href: '/admin/accounting/revenues' },
];

const money = (n: number) => new Intl.NumberFormat('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(n ?? 0);

/* لوحة القالب نفسها التي عليها لوحة المؤشرات — أزرق وفيروزي وبرتقالي. */
const NAVY = '#0D1F38';
const TEAL = '#0FA396';
const BLUE = '#2E86D6';
const ORANGE = '#C08320';
const SLATE = '#64748B';
const RED = '#E05552';

/**
 * لكل نطاق لونه الثابت في كل الشاشة: البطاقة والشريط والشارة في الجدول.
 * ثبات اللون هو ما يجعل القراءة بالعين ممكنة بلا رجوعٍ إلى العنوان.
 */
const PALETTE: Record<string, { mark: string; icon: LucideIcon }> = {
    halls: { mark: BLUE, icon: Building2 },
    chalets: { mark: ORANGE, icon: Home },
    pools: { mark: TEAL, icon: Waves },
    other: { mark: SLATE, icon: Layers },
};

const paint = (key: string) => PALETTE[key] ?? PALETTE.other;

/* هالة الأيقونة: خلفية شفيفة وإطار داخلي من لون المؤشر نفسه. */
const halo = (color: string) => ({ background: color + '1A', color, boxShadow: 'inset 0 0 0 1px ' + color + '38' });

const filters = ref({
    from: (props.filters.from as string) ?? '',
    to: (props.filters.to as string) ?? '',
    segment: (props.filters.segment as string) ?? '',
    cost_center_id: props.filters.cost_center_id ? String(props.filters.cost_center_id) : '',
    account_id: props.filters.account_id ? String(props.filters.account_id) : '',
    source: (props.filters.source as string) ?? '',
    search: (props.filters.search as string) ?? '',
});

let timer: ReturnType<typeof setTimeout> | undefined;
watch(
    filters,
    (value) => {
        clearTimeout(timer);
        timer = setTimeout(() => {
            router.get('/admin/accounting/revenues', { ...value }, { preserveState: true, replace: true, preserveScroll: true });
        }, 300);
    },
    { deep: true },
);

// الوحدة المختارة تتبع النطاق: من اختار «الشاليهات» لا تُعرض عليه قاعة،
// ومن غيّر النطاق تسقط وحدةٌ لم تعد تنتمي إليه.
const visibleCenters = computed(() => (filters.value.segment ? props.centers.filter((c) => c.segment === filters.value.segment) : props.centers));

watch(
    () => filters.value.segment,
    (segment) => {
        if (!segment || !filters.value.cost_center_id) return;

        const current = props.centers.find((c) => String(c.id) === filters.value.cost_center_id);
        if (current && current.segment !== segment) filters.value.cost_center_id = '';
    },
);

// اختيار وحدةٍ بعينها يستدعي نطاقها، فلا يبقى مرشّحان يتناقضان.
const pickCenter = (center: CenterRow) => {
    if (!center.cost_center_id) return;

    filters.value.segment = center.segment;
    filters.value.cost_center_id = String(center.cost_center_id);
};

const toggleSegment = (key: string) => {
    filters.value.cost_center_id = '';
    filters.value.segment = filters.value.segment === key ? '' : key;
};

const exportUrl = computed(() => {
    const params = new URLSearchParams(Object.entries(filters.value).filter(([, v]) => v !== '' && v !== null) as [string, string][]);
    return `/admin/accounting/revenues/export?${params.toString()}`;
});

const grandTotal = computed(() => props.bySegment.reduce((sum, s) => sum + s.amount, 0));

const activeSegmentLabel = computed(() => {
    if (!filters.value.segment) return 'كل النشاطات';

    const center = props.centers.find((c) => String(c.id) === filters.value.cost_center_id);
    const segment = props.segments.find((s) => s.key === filters.value.segment)?.label ?? '';

    return center ? `${segment} — ${center.name}` : segment;
});

const kpis = computed(() => [
    { label: 'إيراد المدة — ' + activeSegmentLabel.value, value: money(props.stats.total), icon: Wallet, mark: TEAL },
    { label: 'عدد الحركات', value: String(props.stats.count), icon: Receipt, mark: BLUE },
    { label: 'إيراد الشهر الحالي', value: money(props.stats.month), icon: CalendarRange, mark: ORANGE },
    { label: 'متوسط الحركة', value: money(props.stats.average), icon: Scale, mark: SLATE },
]);

const CARD = 'rounded-xl border border-slate-200 bg-white shadow-[0_10px_30px_-24px_rgba(13,31,56,0.55)]';
</script>

<template>
    <Head title="الإيرادات" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="min-h-full space-y-4 overflow-x-hidden bg-slate-100 p-5">
            <!-- الترويسة -->
            <div class="flex flex-wrap items-start justify-between gap-3 border-b-2 pb-3" :style="{ borderColor: TEAL + '33' }">
                <div class="min-w-0">
                    <h1 class="flex items-center gap-2 text-2xl font-extrabold" :style="{ color: NAVY }">
                        <span class="grid h-9 w-9 place-items-center rounded-lg" :style="halo(TEAL)">
                            <TrendingUp class="h-5 w-5" />
                        </span>
                        الإيرادات
                    </h1>
                    <p class="mt-1.5 max-w-3xl text-xs font-medium text-slate-500">
                        ما دخل من الحجوزات والفواتير والسندات، مقروءًا من القيود المرحَّلة — للكل أو لقاعةٍ أو شاليهٍ أو المسابح وحدها.
                    </p>
                </div>
                <a
                    v-if="can('revenues.export')"
                    :href="exportUrl"
                    class="inline-flex shrink-0 items-center gap-1.5 rounded-lg px-3.5 py-2 text-sm font-bold text-white transition hover:brightness-110"
                    :style="{ background: NAVY }"
                >
                    <Download class="h-4 w-4" /> تصدير
                </a>
            </div>

            <!-- النطاق: الكل أو نشاطٌ بعينه -->
            <div class="grid grid-cols-2 gap-3 md:grid-cols-3 xl:grid-cols-5">
                <!-- «الكل» بطاقة داكنة: هي المجموع الذي تُقاس عليه بقية النطاقات -->
                <button
                    type="button"
                    @click="toggleSegment('')"
                    class="rounded-xl p-4 text-right text-slate-200 transition hover:brightness-110"
                    :style="{
                        background: NAVY,
                        boxShadow: filters.segment === '' ? `0 0 0 3px ${TEAL}` : '0 10px 30px -20px rgba(13,31,56,0.9)',
                    }"
                >
                    <div class="flex items-center justify-between gap-2">
                        <span class="text-[11px] font-bold text-slate-400">الكل</span>
                        <span class="grid h-8 w-8 place-items-center rounded-lg" :style="halo('#3FC9BA')">
                            <TrendingUp class="h-4 w-4" />
                        </span>
                    </div>
                    <div class="mt-2 text-xl font-extrabold leading-none" :style="{ color: '#3FC9BA' }" dir="ltr">{{ money(grandTotal) }}</div>
                    <div class="mt-2 h-1.5 rounded-full bg-white/10">
                        <div class="h-full rounded-full" :style="{ width: '100%', background: TEAL }" />
                    </div>
                    <div class="mt-1.5 text-[11px] font-bold text-slate-500">كل النشاطات</div>
                </button>

                <button
                    v-for="s in bySegment"
                    :key="s.key"
                    type="button"
                    @click="toggleSegment(s.key)"
                    :class="CARD"
                    class="p-4 text-right transition hover:-translate-y-0.5"
                    :style="{
                        background: filters.segment === s.key ? paint(s.key).mark + '0F' : '#FFFFFF',
                        boxShadow: filters.segment === s.key ? `0 0 0 2px ${paint(s.key).mark}` : undefined,
                    }"
                >
                    <div class="flex items-center justify-between gap-2">
                        <span class="truncate text-[11px] font-bold text-slate-500">{{ s.label }}</span>
                        <span class="grid h-8 w-8 shrink-0 place-items-center rounded-lg" :style="halo(paint(s.key).mark)">
                            <component :is="paint(s.key).icon" class="h-4 w-4" />
                        </span>
                    </div>
                    <div class="mt-2 text-xl font-extrabold leading-none" :style="{ color: paint(s.key).mark }" dir="ltr">{{ money(s.amount) }}</div>
                    <div class="mt-2 h-1.5 overflow-hidden rounded-full bg-slate-100">
                        <div class="h-full rounded-full" :style="{ width: `${s.share}%`, background: paint(s.key).mark }" />
                    </div>
                    <div class="mt-1.5 text-[11px] font-bold text-slate-400" dir="ltr">{{ s.share }}% · {{ s.count }} حركة</div>
                </button>
            </div>

            <!-- المؤشرات -->
            <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
                <div v-for="k in kpis" :key="k.label" :class="CARD" class="flex items-start justify-between gap-2 p-4">
                    <div class="min-w-0">
                        <div class="truncate text-[11px] font-bold text-slate-500">{{ k.label }}</div>
                        <div class="mt-2 text-2xl font-extrabold leading-none" :style="{ color: k.mark }" dir="ltr">{{ k.value }}</div>
                    </div>
                    <span class="grid h-10 w-10 shrink-0 place-items-center rounded-lg" :style="halo(k.mark)">
                        <component :is="k.icon" class="h-5 w-5" />
                    </span>
                </div>
            </div>

            <div class="grid gap-3 lg:grid-cols-2">
                <!-- من أين جاء الإيراد -->
                <div :class="CARD" class="overflow-hidden">
                    <h2 class="flex items-center gap-2 border-b px-4 py-3 text-sm font-extrabold" :style="{ color: NAVY, borderColor: BLUE + '26' }">
                        <span class="grid h-7 w-7 place-items-center rounded-md" :style="halo(BLUE)"><Building2 class="h-4 w-4" /></span>
                        من أيّ وحدة؟
                    </h2>
                    <div v-if="byCenter.length" class="space-y-1 p-3">
                        <button
                            v-for="row in byCenter"
                            :key="row.cost_center_id ?? row.name"
                            type="button"
                            @click="pickCenter(row)"
                            class="flex w-full items-center gap-3 rounded-lg px-2 py-1.5 text-right transition hover:bg-slate-50"
                        >
                            <span class="w-36 shrink-0 truncate text-xs font-bold" :style="{ color: NAVY }">
                                {{ row.name }}
                                <span class="block text-[10px] font-bold" :style="{ color: paint(row.segment).mark }">{{ row.segment_label }}</span>
                            </span>
                            <span class="h-2.5 flex-1 overflow-hidden rounded-full bg-slate-100">
                                <span class="block h-full rounded-full" :style="{ width: `${row.share}%`, background: paint(row.segment).mark }" />
                            </span>
                            <span class="w-11 shrink-0 text-left text-[11px] font-bold text-slate-400" dir="ltr">{{ row.share }}%</span>
                            <span class="w-24 shrink-0 text-left text-xs font-extrabold" :style="{ color: NAVY }" dir="ltr">{{
                                money(row.amount)
                            }}</span>
                        </button>
                    </div>
                    <p v-else class="px-4 py-10 text-center text-xs font-medium text-slate-400">لا إيراد في هذه المدة</p>
                </div>

                <!-- على أي حساب قُيّد -->
                <div :class="CARD" class="overflow-hidden">
                    <h2 class="flex items-center gap-2 border-b px-4 py-3 text-sm font-extrabold" :style="{ color: NAVY, borderColor: TEAL + '26' }">
                        <span class="grid h-7 w-7 place-items-center rounded-md" :style="halo(TEAL)"><Receipt class="h-4 w-4" /></span>
                        على أيّ حساب؟
                    </h2>
                    <div v-if="byAccount.length" class="space-y-1 p-3">
                        <div v-for="row in byAccount" :key="row.code" class="flex items-center gap-3 px-2 py-1.5">
                            <span class="w-36 shrink-0 truncate text-xs font-bold" :style="{ color: NAVY }">
                                {{ row.name }}
                                <span class="block text-[10px] font-medium text-slate-400" dir="ltr">{{ row.code }}</span>
                            </span>
                            <div class="h-2.5 flex-1 overflow-hidden rounded-full bg-slate-100">
                                <div class="h-full rounded-full" :style="{ width: `${row.share}%`, background: TEAL }" />
                            </div>
                            <span class="w-11 shrink-0 text-left text-[11px] font-bold text-slate-400" dir="ltr">{{ row.share }}%</span>
                            <span class="w-24 shrink-0 text-left text-xs font-extrabold" :style="{ color: NAVY }" dir="ltr">{{
                                money(row.amount)
                            }}</span>
                        </div>
                    </div>
                    <p v-else class="px-4 py-10 text-center text-xs font-medium text-slate-400">لا إيراد في هذه المدة</p>
                </div>
            </div>

            <!-- المرشّحات -->
            <div :class="CARD" class="grid gap-2 p-3 md:grid-cols-3 xl:grid-cols-6">
                <input
                    v-model="filters.search"
                    type="search"
                    placeholder="بحث بالبيان أو رقم القيد"
                    class="rounded-lg border border-slate-200 bg-slate-50 px-3 py-2.5 text-sm outline-none focus:border-transparent focus:bg-white focus:ring-2 focus:ring-[#0FA396] xl:col-span-2"
                />
                <select
                    v-model="filters.segment"
                    class="rounded-lg border border-slate-200 bg-slate-50 px-3 py-2.5 text-sm font-bold outline-none focus:ring-2 focus:ring-[#0FA396]"
                    :style="filters.segment ? { color: paint(filters.segment).mark } : undefined"
                >
                    <option value="">كل النشاطات</option>
                    <option v-for="s in segments" :key="s.key" :value="s.key">{{ s.label }}</option>
                </select>
                <select
                    v-model="filters.cost_center_id"
                    class="rounded-lg border border-slate-200 bg-slate-50 px-3 py-2.5 text-sm outline-none focus:ring-2 focus:ring-[#0FA396]"
                >
                    <option value="">كل الوحدات</option>
                    <option v-for="c in visibleCenters" :key="c.id" :value="String(c.id)">{{ c.name }}</option>
                </select>
                <select
                    v-model="filters.account_id"
                    class="rounded-lg border border-slate-200 bg-slate-50 px-3 py-2.5 text-sm outline-none focus:ring-2 focus:ring-[#0FA396]"
                >
                    <option value="">كل حسابات الإيراد</option>
                    <option v-for="a in accounts" :key="a.id" :value="String(a.id)">{{ a.name }}</option>
                </select>
                <select
                    v-model="filters.source"
                    class="rounded-lg border border-slate-200 bg-slate-50 px-3 py-2.5 text-sm outline-none focus:ring-2 focus:ring-[#0FA396]"
                >
                    <option value="">كل المصادر</option>
                    <option v-for="s in sources" :key="s.key" :value="s.key">{{ s.label }}</option>
                </select>
                <div class="flex gap-2 md:col-span-2">
                    <input
                        v-model="filters.from"
                        type="date"
                        class="w-full rounded-lg border border-slate-200 bg-slate-50 px-2 py-2.5 text-xs outline-none focus:ring-2 focus:ring-[#0FA396]"
                    />
                    <input
                        v-model="filters.to"
                        type="date"
                        class="w-full rounded-lg border border-slate-200 bg-slate-50 px-2 py-2.5 text-xs outline-none focus:ring-2 focus:ring-[#0FA396]"
                    />
                </div>
            </div>

            <!-- الحركات -->
            <div :class="CARD" class="overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead :style="{ background: NAVY }">
                            <tr class="text-slate-300">
                                <th class="px-4 py-3 text-right text-xs font-extrabold">القيد</th>
                                <th class="px-4 py-3 text-right text-xs font-extrabold">المصدر</th>
                                <th class="px-4 py-3 text-right text-xs font-extrabold">الحساب</th>
                                <th class="px-4 py-3 text-right text-xs font-extrabold">الوحدة / القسم</th>
                                <th class="px-4 py-3 text-right text-xs font-extrabold">البيان</th>
                                <th class="px-4 py-3 text-left text-xs font-extrabold">المبلغ</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="line in lines.data"
                                :key="line.id"
                                class="border-t border-slate-100 odd:bg-slate-50/60 hover:bg-[#0FA396]/[0.06]"
                            >
                                <!-- شريط لون النطاق على حافة الصف: يُقرأ الصف بلونه قبل نصّه -->
                                <td class="border-r-4 px-4 py-2.5" :style="{ borderRightColor: paint(line.segment).mark }">
                                    <div class="font-bold" :style="{ color: NAVY }">{{ line.number }}</div>
                                    <div class="text-[11px] text-slate-400" dir="ltr">{{ line.entry_date }}</div>
                                </td>
                                <td class="px-4 py-2.5">
                                    <span class="rounded-md px-2 py-0.5 text-[11px] font-bold" :style="halo(BLUE)">{{ line.source_label }}</span>
                                </td>
                                <td class="px-4 py-2.5 text-xs text-slate-700">
                                    {{ line.account }}
                                    <span class="block text-[10px] text-slate-400" dir="ltr">{{ line.account_code }}</span>
                                </td>
                                <td class="px-4 py-2.5 text-xs text-slate-700">
                                    {{ line.center ?? '—' }}
                                    <span
                                        class="mt-0.5 inline-flex rounded-md px-1.5 py-0.5 text-[10px] font-bold"
                                        :style="halo(paint(line.segment).mark)"
                                    >
                                        {{ line.segment_label }}
                                    </span>
                                </td>
                                <td class="px-4 py-2.5 text-xs text-slate-500">{{ line.description ?? '—' }}</td>
                                <!-- السالب مرتجعٌ أو استرداد: يُعرض بلونه حتى لا يُقرأ إيرادًا. -->
                                <td class="px-4 py-2.5 text-left font-extrabold" :style="{ color: line.amount < 0 ? RED : TEAL }" dir="ltr">
                                    {{ money(line.amount) }}
                                </td>
                            </tr>
                            <tr v-if="!lines.data.length">
                                <td colspan="6" class="px-4 py-14 text-center">
                                    <span class="mx-auto grid h-12 w-12 place-items-center rounded-xl" :style="halo(SLATE)">
                                        <Wallet class="h-6 w-6" />
                                    </span>
                                    <p class="mt-3 text-sm font-bold text-slate-500">لا إيرادات في هذه المدة</p>
                                    <p class="mt-1 text-xs font-medium text-slate-400">
                                        الإيراد يظهر هنا بعد ترحيل قيده — من إثبات إيراد حجز، أو فاتورة مبيعات، أو سند قبض.
                                    </p>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <div v-if="lines.links.length > 3" class="flex flex-wrap justify-center gap-1">
                <Link
                    v-for="link in lines.links"
                    :key="link.label"
                    :href="link.url ?? '#'"
                    preserve-scroll
                    class="rounded-lg px-3 py-1.5 text-xs font-bold transition"
                    :style="link.active ? { background: NAVY, color: '#FFFFFF' } : undefined"
                    :class="
                        link.active
                            ? ''
                            : link.url
                              ? 'bg-white text-slate-600 ring-1 ring-slate-200 hover:bg-slate-50'
                              : 'cursor-default bg-white text-slate-300'
                    "
                    v-html="link.label"
                />
            </div>
        </div>
    </AppLayout>
</template>
