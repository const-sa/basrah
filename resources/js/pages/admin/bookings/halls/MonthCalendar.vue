<script setup lang="ts">
import PageShortcuts from '@/components/PageShortcuts.vue';
import { usePermissions } from '@/composables/usePermissions';
import AppLayout from '@/layouts/AppLayout.vue';
import { statusRingChipClass } from '@/lib/bookingStatus';
import { toHijri } from '@/lib/hijri';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/vue3';
import { CalendarDays, ChevronLeft, ChevronRight, Eye, Plus, Wallet } from 'lucide-vue-next';
import { computed, ref } from 'vue';

interface Slot {
    period: string;
    label: string;
    state: 'free' | 'partial' | 'taken';
    free_sections: { id: number; name: string }[];
    can_book_whole?: boolean;
}

interface CalBooking {
    id: number; reference: string;
    client_name: string | null; client_mobile: string | null;
    event_type: string | null; event_color: string | null;
    period_label: string; scope: 'whole' | 'sections';
    section_names: string[]; days_count: number;
    status: string; status_label: string; color: string;
    total_amount: number; paid_amount: number; remaining_amount: number;
    notes: string | null;
}

interface UnitRow {
    unit_id: number; unit_name: string;
    bookings: CalBooking[];
    slots: Slot[];
    state: 'free' | 'partial' | 'taken' | 'booked' | 'past';
}

interface DayCell {
    date: string; day: number;
    is_today: boolean; is_past: boolean; is_weekend: boolean;
    units: UnitRow[];
    bookings_count: number;
}

const props = defineProps<{
    month: string;
    weeks: (DayCell | null)[][];
    units: { id: number; name: string; code: string }[];
    periods: { key: string; label: string; start: string; end: string }[];
    filters: { unit_id: number | null };
    summary: { bookings: number; confirmed: number; tentative: number; total_amount: number; remaining_amount: number };
}>();

const { can } = usePermissions();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'لوحة التحكم', href: '/admin' },
    { title: 'التقويم الشهري للقاعات', href: '/admin/calendar/halls/month' },
];

const money = (n: number) => new Intl.NumberFormat('ar-SA-u-nu-latn', { maximumFractionDigits: 0 }).format(n ?? 0);

// الأسبوع يبدأ بالسبت محليًا — والترتيب هنا يطابق ترتيب الخانات من الخادم.
const weekdays = ['السبت', 'الأحد', 'الإثنين', 'الثلاثاء', 'الأربعاء', 'الخميس', 'الجمعة'];

const unitFilter = ref<number | null>(props.filters.unit_id);

const go = (month: string) =>
    router.get('/admin/calendar/halls/month', { month, unit_id: unitFilter.value }, { preserveState: true, replace: true });

const shiftMonth = (delta: number) => {
    const [y, m] = props.month.split('-').map(Number);
    const d = new Date(y, m - 1 + delta, 1);
    go(`${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}`);
};

const thisMonth = () => {
    const now = new Date();
    go(`${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}`);
};

const monthLabel = computed(() => {
    const [y, m] = props.month.split('-').map(Number);
    return new Date(y, m - 1, 1).toLocaleDateString('ar-SA-u-nu-latn', { month: 'long', year: 'numeric' });
});

// الهجري يُعرض للشهر من أول يوم فيه — الشهران لا يتطابقان فيُذكر مبدؤه.
const monthHijri = computed(() => toHijri(`${props.month}-01`));

/** اليوم الهجري وحده (بلا سنة) — الخلية ضيقة والسنة تتكرر في الترويسة. */
const hijriDay = (date: string) => toHijri(date).replace(/\s*\d+\s*هـ$/, '');

/** رابط حجز جديد من خلية اليوم، محمّلًا بما اختاره الموظف في التقويم. */
const bookUrl = (date: string, unitId: number, period: string, sectionId?: number) => {
    const params = new URLSearchParams({ unit_id: String(unitId), booking_date: date, period });
    if (sectionId) params.append('section_ids[]', String(sectionId));
    return `/admin/bookings/halls/create?${params.toString()}`;
};

const stateRing: Record<string, string> = {
    free: 'ring-emerald-200 bg-emerald-50/60',
    partial: 'ring-amber-200 bg-amber-50/60',
    taken: 'ring-rose-200 bg-rose-50/60',
    booked: 'ring-slate-200 bg-slate-50',
    past: 'ring-slate-200 bg-slate-50',
};

const statusChip = statusRingChipClass;
</script>

<template>
    <Head title="التقويم الشهري للقاعات" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="min-h-full space-y-4 bg-slate-100 p-5 print:bg-white print:p-0">
            <!-- الترويسة: الشهر وحصيلته وأدوات التنقّل -->
            <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm print:border-0 print:shadow-none">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <h1 class="text-2xl font-extrabold text-slate-900">{{ monthLabel }}</h1>
                        <p class="mt-0.5 text-sm font-bold text-slate-500">{{ monthHijri }}</p>
                    </div>

                    <!-- دليل الألوان — الخلية تُقرأ بلونها قبل نصّها -->
                    <div class="flex flex-wrap items-center gap-2 text-xs font-bold print:hidden">
                        <span class="inline-flex items-center gap-1.5 rounded-full bg-emerald-50 px-2.5 py-1 text-emerald-700 ring-1 ring-emerald-200">
                            <span class="h-2 w-2 rounded-full bg-emerald-500"></span> متاحة
                        </span>
                        <span class="inline-flex items-center gap-1.5 rounded-full bg-amber-50 px-2.5 py-1 text-amber-700 ring-1 ring-amber-200">
                            <span class="h-2 w-2 rounded-full bg-amber-500"></span> محجوزة جزئيًا
                        </span>
                        <span class="inline-flex items-center gap-1.5 rounded-full bg-rose-50 px-2.5 py-1 text-rose-700 ring-1 ring-rose-200">
                            <span class="h-2 w-2 rounded-full bg-rose-500"></span> مكتملة
                        </span>
                    </div>
                </div>

                <!-- حصيلة الشهر -->
                <div class="mt-3 grid gap-2 sm:grid-cols-2 lg:grid-cols-4">
                    <div class="rounded-xl bg-slate-50 px-3 py-2">
                        <div class="text-xs font-bold text-slate-500">حجوزات الشهر</div>
                        <div class="text-lg font-extrabold text-slate-900">{{ summary.bookings }}</div>
                    </div>
                    <div class="rounded-xl bg-emerald-50 px-3 py-2">
                        <div class="text-xs font-bold text-emerald-700">مؤكدة</div>
                        <div class="text-lg font-extrabold text-emerald-800">{{ summary.confirmed }}</div>
                    </div>
                    <div class="rounded-xl bg-sky-50 px-3 py-2">
                        <div class="text-xs font-bold text-sky-700">إجمالي القيمة</div>
                        <div class="text-lg font-extrabold text-sky-800">{{ money(summary.total_amount) }} ريال</div>
                    </div>
                    <div class="rounded-xl bg-rose-50 px-3 py-2">
                        <div class="text-xs font-bold text-rose-700">المتبقي على العملاء</div>
                        <div class="text-lg font-extrabold text-rose-800">{{ money(summary.remaining_amount) }} ريال</div>
                    </div>
                </div>

                <!-- الأدوات -->
                <div class="mt-3 flex flex-wrap items-center justify-between gap-2 border-t border-slate-100 pt-3 print:hidden">
                    <div class="flex flex-wrap items-center gap-2">
                        <select
                            v-model="unitFilter"
                            @change="go(month)"
                            class="rounded-lg border border-slate-300 px-3 py-2 text-sm font-bold text-slate-700"
                        >
                            <option :value="null">جميع القاعات</option>
                            <option v-for="u in units" :key="u.id" :value="u.id">{{ u.name }}</option>
                        </select>

                        <!-- اختصارات شاشات القاعات — تُشتق من القائمة فلا تحتاج صيانة -->
                        <PageShortcuts />
                    </div>

                    <div class="flex items-center gap-2">
                        <button type="button" @click="shiftMonth(-1)" title="الشهر السابق" class="rounded-lg border border-slate-300 bg-white p-2 text-slate-700 hover:bg-slate-50">
                            <ChevronRight class="h-4 w-4" />
                        </button>
                        <button type="button" @click="thisMonth" class="inline-flex items-center gap-1.5 rounded-lg bg-blue-600 px-3 py-2 text-sm font-bold text-white hover:bg-blue-700">
                            <CalendarDays class="h-4 w-4" /> هذا الشهر
                        </button>
                        <button type="button" @click="shiftMonth(1)" title="الشهر التالي" class="rounded-lg border border-slate-300 bg-white p-2 text-slate-700 hover:bg-slate-50">
                            <ChevronLeft class="h-4 w-4" />
                        </button>
                    </div>
                </div>
            </div>

            <!-- الشبكة -->
            <div class="overflow-x-auto rounded-2xl border border-slate-200 bg-white p-3 shadow-sm print:border-0 print:shadow-none">
                <div class="min-w-[1100px]">
                    <!-- شريط أيام الأسبوع — تدرّج سماوي كما في عامرة، وخطّه 16px -->
                    <div class="grid grid-cols-7 gap-2">
                        <div
                            v-for="w in weekdays"
                            :key="w"
                            class="rounded-lg py-3.5 text-center text-base font-extrabold text-white"
                            style="background: linear-gradient(135deg, #38bdf8 0%, #09adce 100%)"
                        >
                            {{ w }}
                        </div>
                    </div>

                    <div v-for="(week, wi) in weeks" :key="wi" class="mt-2 grid grid-cols-7 gap-2">
                        <template v-for="(cell, ci) in week" :key="ci">
                            <!-- خانة خارج الشهر -->
                            <div v-if="!cell" class="min-h-[190px] rounded-xl bg-slate-50/60"></div>

                            <div
                                v-else
                                class="min-h-[190px] rounded-xl border p-2.5 transition hover:-translate-y-0.5 hover:shadow-lg"
                                :class="[
                                    cell.is_today ? 'border-blue-500 ring-2 ring-blue-200' : 'border-slate-200',
                                    cell.is_past ? 'bg-slate-50/70' : cell.is_weekend ? 'bg-sky-50/40' : 'bg-white',
                                ]"
                            >
                                <!-- رأس اليوم — الرقم 24px كما في عامرة -->
                                <div class="mb-2 flex items-baseline justify-between gap-1 border-b border-slate-100 pb-1.5">
                                    <div class="flex items-baseline gap-2">
                                        <span class="text-2xl font-extrabold leading-none" :class="cell.is_past ? 'text-slate-400' : 'text-slate-900'">
                                            {{ cell.day }}
                                        </span>
                                        <span class="text-[11px] font-bold text-slate-500">{{ hijriDay(cell.date) }}</span>
                                    </div>
                                    <span v-if="cell.is_today" class="rounded-full bg-blue-600 px-2 py-0.5 text-[10px] font-extrabold text-white">
                                        اليوم
                                    </span>
                                </div>

                                <div class="space-y-2">
                                    <div
                                        v-for="row in cell.units"
                                        :key="row.unit_id"
                                        class="rounded-lg p-2 ring-1"
                                        :class="stateRing[row.state] ?? 'ring-slate-200 bg-slate-50'"
                                    >
                                        <div class="mb-1.5 truncate rounded bg-slate-100 px-1.5 py-1 text-[11px] font-extrabold text-slate-600" :title="row.unit_name">
                                            {{ row.unit_name }}
                                        </div>

                                        <!-- الحجوزات القائمة -->
                                        <div
                                            v-for="b in row.bookings"
                                            :key="b.id"
                                            class="mb-2 rounded-[10px] bg-white p-2.5 text-xs shadow-sm ring-1 ring-slate-200 last:mb-0"
                                        >
                                            <div class="flex items-center justify-between gap-1 border-b border-slate-100 pb-1.5">
                                                <span class="truncate text-[13px] font-extrabold text-slate-800" dir="ltr">{{ b.reference }}</span>
                                                <span
                                                    class="shrink-0 rounded px-1.5 py-0.5 text-[10px] font-extrabold ring-1"
                                                    :class="statusChip(b.color)"
                                                >
                                                    {{ b.status_label }}
                                                </span>
                                            </div>

                                            <div class="mt-1.5 truncate text-[12px] font-bold text-slate-800">
                                                {{ b.client_name ?? 'بلا عميل' }}
                                            </div>
                                            <div v-if="b.client_mobile" class="mt-0.5 text-[11px] font-medium text-slate-500" dir="ltr">
                                                {{ b.client_mobile }}
                                            </div>

                                            <div class="mt-1.5 flex flex-wrap gap-1">
                                                <span class="rounded bg-slate-100 px-1.5 py-0.5 text-[11px] font-bold text-slate-700">{{ b.period_label }}</span>
                                                <span v-if="b.event_type" class="rounded bg-violet-100 px-1.5 py-0.5 text-[11px] font-bold text-violet-700">
                                                    {{ b.event_type }}
                                                </span>
                                                <span v-if="b.days_count > 1" class="rounded bg-indigo-100 px-1.5 py-0.5 text-[11px] font-bold text-indigo-700">
                                                    {{ b.days_count }} أيام
                                                </span>
                                            </div>

                                            <!-- النطاق: الوحدة كاملة أم أقسام بعينها -->
                                            <div v-if="b.scope === 'sections' && b.section_names.length" class="mt-1 flex flex-wrap gap-1">
                                                <span
                                                    v-for="s in b.section_names"
                                                    :key="s"
                                                    class="rounded bg-teal-100 px-1.5 py-0.5 text-[11px] font-bold text-teal-800"
                                                >
                                                    {{ s }}
                                                </span>
                                            </div>
                                            <div v-else class="mt-1 text-[11px] font-bold text-slate-500">الوحدة كاملة</div>

                                            <div class="mt-1.5 border-t border-slate-100 pt-1.5 text-[11px] font-bold text-slate-600">
                                                الإجمالي {{ money(b.total_amount) }} · المدفوع {{ money(b.paid_amount) }}
                                            </div>
                                            <div v-if="b.remaining_amount > 0" class="mt-0.5 text-[12px] font-extrabold text-rose-700">
                                                المتبقي {{ money(b.remaining_amount) }} ريال
                                            </div>
                                            <div v-else class="mt-0.5 text-[12px] font-extrabold text-emerald-700">مسدَّد بالكامل</div>

                                            <div class="mt-2 flex gap-1.5 print:hidden">
                                                <Link
                                                    :href="`/admin/bookings/halls/${b.id}/edit`"
                                                    title="فتح الحجز"
                                                    class="inline-flex items-center rounded-md bg-slate-700 p-1.5 text-white hover:bg-slate-800"
                                                >
                                                    <Eye class="h-4 w-4" />
                                                </Link>
                                                <Link
                                                    v-if="b.remaining_amount > 0"
                                                    :href="`/admin/bookings/${b.id}/bond`"
                                                    title="سند القبض"
                                                    class="inline-flex items-center rounded-md bg-emerald-600 p-1.5 text-white hover:bg-emerald-700"
                                                >
                                                    <Wallet class="h-4 w-4" />
                                                </Link>
                                            </div>
                                        </div>

                                        <!-- ما بقي متاحًا — الحجز يُفتح من الخلية نفسها -->
                                        <template v-if="can('hall_bookings.create')">
                                            <template v-for="slot in row.slots" :key="slot.period">
                                                <!-- الوحدة كاملة — حين تقبلها الوحدة ولا يشغلها شيء -->
                                                <Link
                                                    v-if="slot.can_book_whole"
                                                    :href="bookUrl(cell.date, row.unit_id, slot.period)"
                                                    class="mb-1.5 flex items-center justify-center gap-1.5 rounded-lg bg-emerald-600 px-2 py-2 text-xs font-extrabold text-white transition hover:scale-[1.03] hover:bg-emerald-700 last:mb-0"
                                                >
                                                    <Plus class="h-3.5 w-3.5" /> {{ slot.label }}
                                                </Link>

                                                <!-- وإلا فما بقي من أقسامها -->
                                                <Link
                                                    v-for="s in slot.free_sections"
                                                    :key="`${slot.period}-${s.id}`"
                                                    :href="bookUrl(cell.date, row.unit_id, slot.period, s.id)"
                                                    class="mb-1.5 flex items-center justify-center gap-1.5 rounded-lg px-2 py-2 text-xs font-extrabold text-white transition hover:scale-[1.03] last:mb-0"
                                                    :class="slot.state === 'partial' ? 'bg-amber-500 hover:bg-amber-600' : 'bg-teal-600 hover:bg-teal-700'"
                                                >
                                                    <Plus class="h-3.5 w-3.5" /> {{ slot.label }} — {{ s.name }}
                                                </Link>
                                            </template>
                                        </template>
                                    </div>

                                    <p v-if="!cell.units.length" class="text-xs font-bold text-slate-400">لا قاعات</p>
                                </div>
                            </div>
                        </template>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
