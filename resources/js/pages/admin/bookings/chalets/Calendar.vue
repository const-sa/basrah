<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { statusBarClass, statusDotClass } from '@/lib/bookingStatus';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/vue3';
import { ChevronLeft, ChevronRight, List, LogIn, LogOut, Moon } from 'lucide-vue-next';
import { computed, ref } from 'vue';

interface Day { date: string; day: number; weekday: string; is_weekend: boolean; is_today: boolean }
interface Section { id: number; name: string }
interface Unit { id: number; name: string; code: string; type: string; sections: Section[] }

/** إقامة كما يرسلها الخادم: موضعها في شبكة الشهر محسوب مسبقًا. */
interface Stay {
    id: number; reference: string; unit_id: number;
    scope: 'whole' | 'sections';
    section_ids: number[]; section_names: string[];
    client_name: string | null;
    check_in: string; check_out: string;
    nights: number; schedule_label: string;
    start_index: number; span: number;
    continues_before: boolean; continues_after: boolean;
    status: string; status_label: string; color: string;
    total_amount: number; remaining_amount: number;
}

const props = defineProps<{
    month: string;
    days: Day[];
    units: Unit[];
    bookings: Stay[];
    meta: { statuses: { key: string; label: string; color: string }[] };
    filters: { unit_id: number | null };
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'لوحة التحكم', href: '/admin' },
    { title: 'تقويم الشاليهات', href: '/admin/calendar/chalets' },
];

const money = (n: number) => new Intl.NumberFormat('ar-SA-u-nu-latn', { maximumFractionDigits: 0 }).format(n ?? 0);

const unitFilter = ref<number | null>(props.filters.unit_id);

const go = (month: string) =>
    router.get('/admin/calendar/chalets', { month, unit_id: unitFilter.value }, { preserveState: true, replace: true });

const shiftMonth = (delta: number) => {
    const [y, m] = props.month.split('-').map(Number);
    const d = new Date(y, m - 1 + delta, 1);
    go(`${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}`);
};

const monthLabel = computed(() => {
    const [y, m] = props.month.split('-').map(Number);

    return new Date(y, m - 1, 1).toLocaleDateString('ar-SA-u-nu-latn', { month: 'long', year: 'numeric' });
});

interface Row { key: string; unitId: number; sectionId: number | null; label: string; isUnit: boolean }

const rows = computed<Row[]>(() => {
    const list: Row[] = [];
    const units = unitFilter.value ? props.units.filter((u) => u.id === unitFilter.value) : props.units;

    for (const u of units) {
        list.push({ key: `u-${u.id}`, unitId: u.id, sectionId: null, label: u.name, isUnit: true });
        for (const s of u.sections) {
            list.push({ key: `s-${s.id}`, unitId: u.id, sectionId: s.id, label: s.name, isUnit: false });
        }
    }

    return list;
});

/** إقامات صفٍّ ما — حجز الشاليه كاملًا يظهر في صفه وفي كل صفوف أقسامه لأنه يقفلها. */
const rowStays = (row: Row): Stay[] =>
    props.bookings.filter((b) => {
        if (b.unit_id !== row.unitId) return false;
        if (b.scope === 'whole') return true;

        return row.sectionId !== null && b.section_ids.includes(row.sectionId);
    });

/**
 * توزيع الإقامات على مسارات داخل الصف الواحد.
 *
 * إقامتان متتاليتان (خروج الأولى يوم دخول الثانية) لا تتعارضان، لكن رسمهما
 * على مسار واحد يجعلهما تبدوان إقامة واحدة. والمتداخلتان — وهما ممكنتان في
 * صف الوحدة حين يُحجز قسمان لنزيلين — يجب أن تظهرا معًا لا أن تخفي إحداهما
 * الأخرى. المسارات تحلّ الحالتين: كل إقامة تنزل أول مسار خالٍ عند بدايتها.
 */
const placed = (row: Row) => {
    const stays = [...rowStays(row)].sort((a, b) => a.start_index - b.start_index);
    const laneEnds: number[] = [];

    return stays.map((s) => {
        let lane = laneEnds.findIndex((end) => end <= s.start_index);
        if (lane === -1) lane = laneEnds.length;
        laneEnds[lane] = s.start_index + s.span;

        return { stay: s, lane };
    });
};

/** عدد المسارات التي يحتاجها الصف — يحدد ارتفاعه. */
const laneCount = (row: Row) => Math.max(1, ...placed(row).map((p) => p.lane + 1));

/** نسبة إشغال الصف في الشهر: ليالٍ محجوزة من أصل ليالي الشهر. */
const occupancy = (row: Row) => {
    const booked = rowStays(row).reduce((sum, s) => sum + s.span, 0);

    return props.days.length ? Math.round((booked / props.days.length) * 100) : 0;
};

const gridStyle = computed(() => ({
    gridTemplateColumns: `repeat(${props.days.length}, minmax(2.75rem, 1fr))`,
}));

const barClass = statusBarClass;
const legendClass = statusDotClass;

const hovered = ref<Stay | null>(null);
</script>

<template>
    <Head title="تقويم الشاليهات" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="min-h-full space-y-4 bg-slate-100 p-5">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h1 class="text-2xl font-extrabold text-slate-900">تقويم الشاليهات</h1>
                    <p class="mt-1 text-sm font-medium text-slate-600">كل إقامة شريط ممتد من ليلة الدخول إلى ليلة المغادرة</p>
                </div>
                <Link href="/admin/bookings/chalets" class="inline-flex items-center gap-1.5 rounded-md border border-slate-200 bg-white px-4 py-2 text-sm font-bold text-slate-700 shadow-sm hover:bg-slate-50">
                    <List class="h-4 w-4" /> سجل الإقامات
                </Link>
            </div>

            <!-- شريط التنقّل والفلتر -->
            <div class="flex flex-wrap items-center justify-between gap-3 rounded-2xl border border-slate-200 bg-white p-3 shadow-sm">
                <div class="flex items-center gap-2">
                    <button type="button" @click="shiftMonth(-1)" class="rounded-lg border border-slate-200 p-2 text-slate-600 hover:bg-slate-50"><ChevronRight class="h-4 w-4" /></button>
                    <span class="min-w-[10rem] text-center text-sm font-extrabold text-slate-800">{{ monthLabel }}</span>
                    <button type="button" @click="shiftMonth(1)" class="rounded-lg border border-slate-200 p-2 text-slate-600 hover:bg-slate-50"><ChevronLeft class="h-4 w-4" /></button>
                </div>

                <select v-model="unitFilter" @change="go(month)" class="rounded-xl border border-slate-200 px-3 py-2 text-sm">
                    <option :value="null">كل الشاليهات</option>
                    <option v-for="u in units" :key="u.id" :value="u.id">{{ u.name }}</option>
                </select>

                <div class="flex flex-wrap items-center gap-2.5">
                    <!-- «متاح» ليست حالة حجز بل الخليّة الخالية، فتُذكر في الدليل وحدها -->
                    <span class="inline-flex items-center gap-1 text-[11px] font-bold text-slate-600">
                        <span class="h-2.5 w-2.5 rounded-sm border border-slate-300 bg-white"></span> متاح
                    </span>
                    <span v-for="s in meta.statuses" :key="s.key" class="inline-flex items-center gap-1 text-[11px] font-bold text-slate-600">
                        <span class="h-2.5 w-2.5 rounded-sm" :class="legendClass(s.color)"></span> {{ s.label }}
                    </span>
                </div>
            </div>

            <!-- شبكة الإقامات -->
            <div class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                <div class="overflow-x-auto">
                    <div class="min-w-max">
                        <!-- ترويسة الأيام -->
                        <div class="flex border-b border-slate-200 bg-slate-100">
                            <div class="sticky z-20 w-48 shrink-0 border-e border-slate-200 bg-slate-100 px-3 py-2 text-right text-xs font-extrabold text-slate-700 ltr:left-0 rtl:right-0">
                                الشاليه / القسم
                            </div>
                            <div class="grid flex-1" :style="gridStyle">
                                <div
                                    v-for="d in days"
                                    :key="d.date"
                                    class="px-1 py-2 text-center"
                                    :class="[d.is_weekend ? 'bg-amber-50' : '', d.is_today && 'ring-2 ring-inset ring-blue-400']"
                                >
                                    <div class="text-[10px] font-bold text-slate-500">{{ d.weekday }}</div>
                                    <div class="text-xs font-extrabold text-slate-800">{{ d.day }}</div>
                                </div>
                            </div>
                        </div>

                        <!-- صف لكل شاليه وقسم -->
                        <div
                            v-for="row in rows"
                            :key="row.key"
                            class="flex border-b border-slate-100"
                            :class="row.isUnit ? 'bg-slate-50' : 'bg-white'"
                        >
                            <div
                                class="sticky z-10 flex w-48 shrink-0 items-center justify-between gap-2 border-e border-slate-200 px-3 py-1.5 ltr:left-0 rtl:right-0"
                                :class="row.isUnit ? 'bg-slate-50' : 'bg-white'"
                            >
                                <span :class="row.isUnit ? 'text-xs font-extrabold text-slate-800' : 'text-xs font-bold text-slate-600'">
                                    <span v-if="!row.isUnit" class="text-slate-300">└ </span>{{ row.label }}
                                </span>
                                <!-- نسبة الإشغال تُقرأ من الصف نفسه: كم ليلة من ليالي الشهر مشغولة -->
                                <span
                                    class="shrink-0 rounded px-1.5 py-0.5 text-[10px] font-extrabold"
                                    :class="occupancy(row) >= 70 ? 'bg-emerald-100 text-emerald-700' : occupancy(row) > 0 ? 'bg-slate-200 text-slate-600' : 'bg-slate-100 text-slate-400'"
                                >{{ occupancy(row) }}%</span>
                            </div>

                            <!-- الارتفاع بأسلوب inline لا بصنف Tailwind: عدد المسارات يُحسب وقت التشغيل -->
                            <div class="relative grid flex-1 gap-y-0.5 py-0.5" :style="{ ...gridStyle, minHeight: `${laneCount(row) * 1.5 + 0.5}rem` }">
                                <!-- خلفية الأيام: خطوط الفصل وتلوين نهاية الأسبوع -->
                                <div
                                    v-for="(d, i) in days"
                                    :key="d.date"
                                    class="border-e border-slate-100"
                                    :style="{ gridColumn: i + 1, gridRow: '1 / -1' }"
                                    :class="[d.is_weekend && 'bg-amber-50/50', d.is_today && 'bg-blue-50/60']"
                                ></div>

                                <!-- أشرطة الإقامات -->
                                <div
                                    v-for="p in placed(row)"
                                    :key="p.stay.id"
                                    class="z-[1] mx-px flex cursor-pointer items-center gap-1 overflow-hidden px-1.5 py-1 text-[10px] font-bold leading-none transition hover:brightness-110"
                                    :class="[
                                        barClass(p.stay.color),
                                        p.stay.continues_before ? 'ltr:rounded-r ltr:rounded-l-none rtl:rounded-l rtl:rounded-r-none' : 'ltr:rounded-l rtl:rounded-r',
                                        p.stay.continues_after ? 'ltr:rounded-l-none rtl:rounded-r-none' : 'ltr:rounded-r rtl:rounded-l',
                                    ]"
                                    :style="{ gridColumn: `${p.stay.start_index + 1} / span ${p.stay.span}`, gridRow: p.lane + 1 }"
                                    :title="`${p.stay.reference} — ${p.stay.client_name ?? 'بدون نزيل'} — ${p.stay.check_in} ← ${p.stay.check_out} — ${p.stay.schedule_label}`"
                                    @mouseenter="hovered = p.stay"
                                    @mouseleave="hovered = null"
                                >
                                    <span v-if="p.stay.continues_before" class="shrink-0 opacity-70">…</span>
                                    <span class="truncate">{{ p.stay.client_name ?? p.stay.reference }}</span>
                                    <span v-if="p.stay.continues_after" class="ms-auto shrink-0 opacity-70">…</span>
                                </div>
                            </div>
                        </div>

                        <div v-if="!rows.length" class="px-4 py-10 text-center text-sm font-medium text-slate-500">لا توجد شاليهات لعرضها</div>
                    </div>
                </div>
            </div>

            <!-- تفاصيل الإقامة عند المرور -->
            <div v-if="hovered" class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
                <div class="flex flex-wrap items-center gap-x-5 gap-y-2 text-sm">
                    <span class="font-extrabold text-slate-800" dir="ltr">{{ hovered.reference }}</span>
                    <span class="font-bold text-slate-700">{{ hovered.client_name ?? 'بدون نزيل' }}</span>
                    <span class="inline-flex items-center gap-1.5 text-slate-600">
                        <LogIn class="h-4 w-4 text-emerald-500" /><span dir="ltr">{{ hovered.check_in }}</span>
                    </span>
                    <span class="inline-flex items-center gap-1.5 text-slate-600">
                        <LogOut class="h-4 w-4 text-rose-500" /><span dir="ltr">{{ hovered.check_out }}</span>
                    </span>
                    <span class="inline-flex items-center gap-1 rounded bg-teal-100 px-2 py-0.5 text-[11px] font-extrabold text-teal-700">
                        <Moon class="h-3 w-3" /> {{ hovered.schedule_label }}
                    </span>
                    <span v-if="hovered.scope === 'whole'" class="rounded bg-violet-100 px-2 py-0.5 text-[11px] font-bold text-violet-700">الشاليه كاملًا</span>
                    <span v-else class="rounded bg-sky-100 px-2 py-0.5 text-[11px] font-bold text-sky-700">{{ hovered.section_names.join('، ') }}</span>
                    <span class="font-extrabold text-slate-800">{{ money(hovered.total_amount) }}</span>
                    <span v-if="hovered.remaining_amount > 0" class="font-bold text-red-600">متبقٍ {{ money(hovered.remaining_amount) }}</span>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
