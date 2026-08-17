<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/vue3';
import { CalendarDays, ChevronLeft, ChevronRight, List } from 'lucide-vue-next';
import { computed, ref } from 'vue';

interface Day { date: string; day: number; weekday: string; is_weekend: boolean; is_today: boolean }
interface Section { id: number; name: string }
interface Unit { id: number; name: string; code: string; type: string; sections: Section[] }
interface CalBooking {
    id: number; reference: string; unit_id: number;
    scope: 'whole' | 'sections';
    section_ids: number[]; section_names: string[];
    client_name: string | null;
    event_type: string | null;
    date: string; dates: string[]; days_count: number;
    period: string; period_label: string;
    status: string; status_label: string; color: string;
    total_amount: number; remaining_amount: number;
}

const props = defineProps<{
    month: string;
    days: Day[];
    units: Unit[];
    bookings: CalBooking[];
    meta: { statuses: { key: string; label: string; color: string }[]; periods: { key: string; label: string }[] };
    filters: { unit_id: number | null };
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'لوحة التحكم', href: '/admin' },
    { title: 'تقويم القاعات', href: '/admin/calendar/halls' },
];

const money = (n: number) => new Intl.NumberFormat('ar-SA-u-nu-latn', { maximumFractionDigits: 0 }).format(n ?? 0);

const unitFilter = ref<number | null>(props.filters.unit_id);

const go = (month: string) =>
    router.get('/admin/calendar/halls', { month, unit_id: unitFilter.value }, { preserveState: true, replace: true });

const shiftMonth = (delta: number) => {
    const [y, m] = props.month.split('-').map(Number);
    const d = new Date(y, m - 1 + delta, 1);
    go(`${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}`);
};

const monthLabel = computed(() => {
    const [y, m] = props.month.split('-').map(Number);
    return new Date(y, m - 1, 1).toLocaleDateString('ar-SA-u-nu-latn', { month: 'long', year: 'numeric' });
});

/**
 * صفوف التقويم: صف للوحدة كاملة، وصف لكل قسم تحتها.
 * هذا هو التمثيل الذي يُظهر التعارض الهرمي بصريًا — حجز الوحدة يُلوّن
 * صفها، وحجز القسم يُلوّن صف القسم وحده.
 */
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

/**
 * حجوزات خلية (صف × يوم).
 * حجز الوحدة كاملة يظهر في صف الوحدة وفي كل صفوف أقسامها لأنه يقفلها فعليًا،
 * والمناسبة الممتدة تظهر في أيامها كلها لأنها تقفلها كلها.
 */
const cellBookings = (row: Row, date: string): CalBooking[] =>
    props.bookings.filter((b) => {
        if (b.unit_id !== row.unitId || !b.dates.includes(date)) return false;
        if (b.scope === 'whole') return true;
        return row.sectionId !== null && b.section_ids.includes(row.sectionId);
    });

const barClass = (color: string) =>
    ({
        amber: 'bg-amber-400 text-amber-950',
        emerald: 'bg-emerald-500 text-white',
        slate: 'bg-slate-400 text-white',
        red: 'bg-red-500 text-white',
        rose: 'bg-rose-500 text-white',
    })[color] ?? 'bg-slate-300 text-slate-800';

const legendClass = (color: string) =>
    ({
        amber: 'bg-amber-400',
        emerald: 'bg-emerald-500',
        slate: 'bg-slate-400',
        red: 'bg-red-500',
        rose: 'bg-rose-500',
    })[color] ?? 'bg-slate-300';

const hovered = ref<CalBooking | null>(null);
</script>

<template>
    <Head title="تقويم القاعات" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="min-h-full space-y-4 bg-slate-100 p-5">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h1 class="text-2xl font-extrabold text-slate-900">تقويم القاعات</h1>
                    <p class="mt-1 text-sm font-medium text-slate-600">مناسبات الشهر بفتراتها — القاعات وأقسامها بألوان الحالة</p>
                </div>
                <Link href="/admin/bookings/halls" class="inline-flex items-center gap-1.5 rounded-md border border-slate-200 bg-white px-4 py-2 text-sm font-bold text-slate-700 shadow-sm hover:bg-slate-50">
                    <List class="h-4 w-4" /> سجل حجوزات القاعات
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
                    <option :value="null">كل القاعات</option>
                    <option v-for="u in units" :key="u.id" :value="u.id">{{ u.name }}</option>
                </select>

                <div class="flex flex-wrap items-center gap-2.5">
                    <span v-for="s in meta.statuses" :key="s.key" class="inline-flex items-center gap-1 text-[11px] font-bold text-slate-600">
                        <span class="h-2.5 w-2.5 rounded-sm" :class="legendClass(s.color)"></span> {{ s.label }}
                    </span>
                </div>
            </div>

            <!-- الشبكة -->
            <div class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                <div class="overflow-x-auto">
                    <table class="w-full border-collapse text-xs">
                        <thead>
                            <tr>
                                <th class="sticky z-20 min-w-[11rem] border-b border-e border-slate-200 bg-slate-100 px-3 py-2 text-right text-xs font-extrabold text-slate-700 ltr:left-0 rtl:right-0">
                                    القاعة / القسم
                                </th>
                                <th
                                    v-for="d in days"
                                    :key="d.date"
                                    class="min-w-[2.5rem] border-b border-slate-200 px-1 py-2 text-center"
                                    :class="[d.is_weekend ? 'bg-amber-50' : 'bg-slate-100', d.is_today && 'ring-2 ring-inset ring-blue-400']"
                                >
                                    <div class="text-[10px] font-bold text-slate-500">{{ d.weekday }}</div>
                                    <div class="text-xs font-extrabold text-slate-800">{{ d.day }}</div>
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="row in rows" :key="row.key" :class="row.isUnit ? 'bg-slate-50' : ''">
                                <td
                                    class="sticky z-10 border-b border-e border-slate-200 px-3 py-1.5 ltr:left-0 rtl:right-0"
                                    :class="row.isUnit ? 'bg-slate-50 font-extrabold text-slate-800' : 'bg-white font-bold text-slate-600'"
                                >
                                    <span :class="!row.isUnit && 'ltr:pl-4 rtl:pr-4'">
                                        <span v-if="!row.isUnit" class="text-slate-300">└ </span>{{ row.label }}
                                    </span>
                                </td>

                                <td
                                    v-for="d in days"
                                    :key="d.date"
                                    class="border-b border-e border-slate-100 p-0.5 align-top"
                                    :class="d.is_weekend && 'bg-amber-50/40'"
                                >
                                    <div
                                        v-for="b in cellBookings(row, d.date)"
                                        :key="b.id"
                                        class="mb-0.5 cursor-pointer rounded px-1 py-0.5 text-center text-[9px] font-bold leading-tight transition hover:brightness-110"
                                        :class="barClass(b.color)"
                                        :title="`${b.reference} — ${b.client_name ?? 'بدون عميل'} — ${b.period_label} — ${b.status_label}`"
                                        @mouseenter="hovered = b"
                                        @mouseleave="hovered = null"
                                    >
                                        {{ b.period_label.slice(0, 4) }}
                                    </div>
                                </td>
                            </tr>

                            <tr v-if="!rows.length">
                                <td :colspan="days.length + 1" class="px-4 py-10 text-center text-sm font-medium text-slate-500">لا توجد قاعات لعرضها</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- تفاصيل الحجز عند المرور -->
            <div v-if="hovered" class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
                <div class="flex flex-wrap items-center gap-x-5 gap-y-2 text-sm">
                    <span class="inline-flex items-center gap-1.5 font-extrabold text-slate-800">
                        <CalendarDays class="h-4 w-4 text-slate-400" />
                        <span dir="ltr">{{ hovered.reference }}</span>
                    </span>
                    <span class="font-bold text-slate-700">{{ hovered.client_name ?? 'بدون عميل' }}</span>
                    <span class="text-slate-600">{{ hovered.period_label }}</span>
                    <span v-if="hovered.days_count > 1" class="rounded bg-slate-100 px-2 py-0.5 text-[11px] font-bold text-slate-700">
                        {{ hovered.days_count }} أيام — من {{ hovered.dates[0] }} إلى {{ hovered.dates[hovered.dates.length - 1] }}
                    </span>
                    <span v-if="hovered.event_type" class="rounded bg-amber-100 px-2 py-0.5 text-[11px] font-bold text-amber-700">{{ hovered.event_type }}</span>
                    <span v-if="hovered.scope === 'whole'" class="rounded bg-violet-100 px-2 py-0.5 text-[11px] font-bold text-violet-700">الوحدة كاملة</span>
                    <span v-else class="rounded bg-sky-100 px-2 py-0.5 text-[11px] font-bold text-sky-700">{{ hovered.section_names.join('، ') }}</span>
                    <span class="font-extrabold text-slate-800">{{ money(hovered.total_amount) }}</span>
                    <span v-if="hovered.remaining_amount > 0" class="font-bold text-red-600">متبقٍ {{ money(hovered.remaining_amount) }}</span>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
