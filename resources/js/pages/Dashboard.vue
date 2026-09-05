<script setup lang="ts">
import DonutChart from '@/components/charts/DonutChart.vue';
import RadialGauge from '@/components/charts/RadialGauge.vue';
import TrendArea from '@/components/charts/TrendArea.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link } from '@inertiajs/vue3';
import {
    AlertTriangle,
    Banknote,
    Building2,
    CalendarDays,
    CalendarX,
    Coins,
    DoorOpen,
    Info,
    Phone,
    PiggyBank,
    Receipt,
    TrendingDown,
    TrendingUp,
    Users,
    Wallet,
} from 'lucide-vue-next';
import { computed, type Component } from 'vue';

interface TodayRow {
    id: number;
    reference: string;
    unit: string | null;
    scope: string;
    client: string | null;
    mobile: string | null;
    period: string;
    status: string;
    color: string;
    remaining: number;
}

interface SeriesRow {
    date: string;
    label: string;
    bookings: number;
    value: number;
    collected: number;
}

const props = defineProps<{
    canSee: { bookings: boolean; pos: boolean; accounting: boolean; hr: boolean };
    bookings: {
        today: number;
        upcoming: number;
        deposit_paid: number;
        month_count: number;
        month_value: number;
        outstanding: number;
        occupancy: number;
        units_total: number;
        units_occupied: number;
        units_available: number;
        today_value: number;
        collected_today: number;
        cancelled_month: number;
    } | null;
    finance: { revenue: number; expense: number; profit: number } | null;
    clients: { total: number; new_this_month: number } | null;
    today: TodayRow[];
    series: SeriesRow[];
    pos: {
        today_total: number;
        today_count: number;
        month_total: number;
        month_profit: number;
        low_stock: number;
    } | null;
    profitability: { name: string; revenue: number; expense: number; profit: number }[];
    hr: { employees: number; expiring_docs: number; monthly_cost: number } | null;
    alerts: { type: string; text: string; href: string }[];
}>();

const breadcrumbs: BreadcrumbItem[] = [{ title: 'لوحة التحكم', href: '/admin' }];

const money = (n: number) => new Intl.NumberFormat('ar-SA-u-nu-latn', { maximumFractionDigits: 0 }).format(n ?? 0);

/*
 * لوحة داكنة: ألوان القالب مُعاد ضبطها على سطحٍ غامق (#0D1F38).
 * درجات القالب الفاتحة تسقط تحت حدّ التباين هنا، فلكل لون درجتان:
 * mark للأشكال المرسومة، وsoft للأيقونات والنصوص الصغيرة.
 */
const BLUE = '#2E86D6';
const TEAL = '#0FA396';
const ORANGE = '#C08320';
const RED = '#E05552';
const BLUE_S = '#7CBBF0';
const TEAL_S = '#3FC9BA';
const ORANGE_S = '#E5B05A';
const RED_S = '#F58F8C';

interface Kpi {
    label: string;
    value: string | number;
    sub?: string;
    icon: Component;
    mark: string;
    soft: string;
    valueColor?: string;
    bar?: number;
    spark?: number[];
}

const bookingSpark = computed(() => props.series.map((r) => r.bookings));
const collectedSpark = computed(() => props.series.map((r) => r.collected));
const seriesLabels = computed(() => props.series.map((r) => r.label));

const kpis = computed<Kpi[]>(() => {
    const b = props.bookings;
    const f = props.finance;
    const c = props.clients;
    const out: Kpi[] = [];

    if (b) {
        out.push(
            {
                label: 'حجوزات اليوم',
                value: b.today,
                sub: 'قادمة: ' + b.upcoming,
                icon: CalendarDays,
                mark: BLUE,
                soft: BLUE_S,
                spark: bookingSpark.value,
            },
            {
                label: 'قيمة حجوزات الشهر',
                value: money(b.month_value),
                sub: 'عدد الحجوزات: ' + b.month_count,
                icon: Banknote,
                mark: BLUE,
                soft: BLUE_S,
            },
            {
                label: 'مستحق على العملاء',
                value: money(b.outstanding),
                sub: 'مدفوع العربون: ' + b.deposit_paid,
                icon: Coins,
                mark: ORANGE,
                soft: ORANGE_S,
                valueColor: b.outstanding > 0 ? ORANGE_S : undefined,
            },
            {
                label: 'إيرادات اليوم',
                value: money(b.collected_today),
                sub: 'قيمة حجوزات اليوم: ' + money(b.today_value),
                icon: Wallet,
                mark: TEAL,
                soft: TEAL_S,
                spark: collectedSpark.value,
            },
            {
                label: 'الوحدات المتاحة اليوم',
                value: b.units_available,
                sub: 'من ' + b.units_total + ' وحدة نشطة',
                icon: DoorOpen,
                mark: TEAL,
                soft: TEAL_S,
                bar: b.units_total ? (b.units_available / b.units_total) * 100 : 0,
            },
            {
                label: 'الحجوزات الملغاة هذا الشهر',
                value: b.cancelled_month,
                sub: 'خلال الشهر الجاري',
                icon: CalendarX,
                mark: ORANGE,
                soft: ORANGE_S,
                valueColor: b.cancelled_month > 0 ? ORANGE_S : undefined,
            },
        );
    }

    if (c) {
        out.push({
            label: 'العملاء',
            value: c.total,
            sub: c.new_this_month + ' جديد هذا الشهر',
            icon: Users,
            mark: BLUE,
            soft: BLUE_S,
        });
    }

    if (f) {
        out.push({
            label: 'صافي الربح',
            value: money(f.profit),
            sub: 'إيراد الشهر ناقص مصروفه',
            icon: PiggyBank,
            mark: BLUE,
            soft: BLUE_S,
            valueColor: f.profit < 0 ? RED_S : undefined,
        });
    }

    return out;
});

/* خطٌّ مصغّر داخل البطاقة: شكل الاتجاه فقط بلا محاور ولا أرقام. */
const spark = (values: number[]) => {
    const max = Math.max(1, ...values);
    const step = values.length > 1 ? 100 / (values.length - 1) : 100;

    return values.map((v, i) => (i ? 'L' : 'M') + (i * step).toFixed(1) + ',' + (26 - (v / max) * 24).toFixed(1)).join(' ');
};

/* الإيراد والمصروف والربح: أعمدة أفقية على سُلَّمٍ واحد ليصحّ التقارن. */
const financeBars = computed(() => {
    const f = props.finance;

    if (!f) {
        return [];
    }

    const max = Math.max(1, Math.abs(f.revenue), Math.abs(f.expense), Math.abs(f.profit));

    return [
        { label: 'إيرادات الشهر', value: f.revenue, color: TEAL, soft: TEAL_S, icon: TrendingUp },
        { label: 'مصروفات الشهر', value: f.expense, color: ORANGE, soft: ORANGE_S, icon: TrendingDown },
        { label: 'صافي الربح', value: f.profit, color: f.profit < 0 ? RED : BLUE, soft: f.profit < 0 ? RED_S : BLUE_S, icon: PiggyBank },
    ].map((r) => ({ ...r, width: (Math.abs(r.value) / max) * 100 }));
});

const profitMax = computed(() => Math.max(1, ...props.profitability.map((p) => Math.max(p.revenue, p.expense))));

const barColor = (c: string) => ({ amber: ORANGE_S, emerald: TEAL_S, slate: '#7C8FA8', red: RED_S, rose: RED_S })[c] ?? '#5A6E88';

const alertClass = (t: string) =>
    ({
        danger: 'border-[#E05552]/45 bg-[#E05552]/10 text-[#F58F8C]',
        warning: 'border-[#C08320]/50 bg-[#C08320]/10 text-[#E5B05A]',
        info: 'border-[#2E86D6]/45 bg-[#2E86D6]/10 text-[#7CBBF0]',
    })[t] ?? 'border-white/10 bg-white/5 text-slate-300';

/* هالة الأيقونة: خلفية 15% وإطار داخلي 35% من لون المؤشر نفسه. */
const halo = (c: string) => ({ background: c + '26', color: c, boxShadow: 'inset 0 0 0 1px ' + c + '59' });

const CARD = 'rounded-xl border border-white/10 bg-[#0D1F38] shadow-[0_10px_30px_-18px_rgba(0,0,0,0.9)]';
</script>

<template>
    <Head title="لوحة التحكم" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="min-h-full space-y-4 bg-[#071427] p-5 text-slate-200">
            <!-- الترويسة -->
            <div class="border-b border-white/10 pb-3 text-center">
                <h1 class="text-2xl font-bold text-slate-50">لوحة تحكم تحليلات الحجوزات والتشغيل</h1>
                <p class="mx-auto mt-1 max-w-3xl text-xs font-medium text-slate-400">
                    تغطي هذه اللوحة المؤشرات التشغيلية والمالية — الحجوزات والإشغال والوحدات والإيرادات والمصروفات ونقطة البيع، محكومة بصلاحياتك ونطاق
                    وحداتك.
                </p>
            </div>

            <!-- تنبيهات تحتاج تصرّفًا -->
            <div v-if="alerts.length" class="grid gap-2 sm:grid-cols-2 lg:grid-cols-4">
                <Link
                    v-for="(a, i) in alerts"
                    :key="i"
                    :href="a.href"
                    class="flex items-center gap-2 rounded-lg border px-3 py-2 text-xs font-bold transition hover:brightness-125"
                    :class="alertClass(a.type)"
                >
                    <component :is="a.type === 'info' ? Info : AlertTriangle" class="h-4 w-4 shrink-0" />
                    {{ a.text }}
                </Link>
            </div>

            <!-- بطاقات المؤشرات -->
            <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
                <div v-for="k in kpis" :key="k.label" :class="CARD" class="p-4">
                    <div class="flex items-start justify-between gap-2">
                        <div class="min-w-0">
                            <div class="text-[11px] font-bold text-slate-400">{{ k.label }}</div>
                            <div class="mt-2 text-2xl font-extrabold leading-none" :style="{ color: k.valueColor ?? '#F1F5F9' }" dir="ltr">
                                {{ k.value }}
                            </div>
                            <div v-if="k.sub" class="mt-1.5 text-[11px] font-medium text-slate-500">{{ k.sub }}</div>
                        </div>
                        <span class="grid h-10 w-10 shrink-0 place-items-center rounded-lg" :style="halo(k.soft)">
                            <component :is="k.icon" class="h-5 w-5" />
                        </span>
                    </div>

                    <svg v-if="k.spark?.length" viewBox="0 0 100 28" preserveAspectRatio="none" class="mt-3 h-7 w-full" aria-hidden="true">
                        <path :d="spark(k.spark)" fill="none" :stroke="k.mark" stroke-width="2" vector-effect="non-scaling-stroke" />
                    </svg>
                    <div v-else-if="k.bar !== undefined" class="mt-3 h-1.5 overflow-hidden rounded-full bg-white/10">
                        <div class="h-full rounded-full" :style="{ width: k.bar + '%', background: k.mark }"></div>
                    </div>
                </div>
            </div>

            <!-- الرسوم -->
            <div class="grid gap-3 lg:grid-cols-4">
                <div v-if="canSee.bookings && series.length" :class="CARD" class="p-4 lg:col-span-2">
                    <div class="mb-3 flex items-center justify-between gap-2">
                        <h2 class="text-sm font-bold text-slate-200">حركة آخر أربعة عشر يومًا</h2>
                        <span class="text-[11px] font-bold text-slate-500">مرِّر فوق الرسم لقراءة اليوم</span>
                    </div>
                    <div class="space-y-3">
                        <TrendArea :values="bookingSpark" :labels="seriesLabels" :color="BLUE" title="عدد الحجوزات" :height="118" />
                        <TrendArea :values="collectedSpark" :labels="seriesLabels" :color="TEAL" title="المتحصَّل" :height="118" />
                    </div>
                </div>

                <div v-if="bookings" :class="CARD" class="flex flex-col items-center justify-center gap-2 p-4">
                    <h2 class="self-start text-sm font-bold text-slate-200">إشغال الشهر</h2>
                    <RadialGauge :value="bookings.occupancy" :color="TEAL" caption="من ليالي الشهر" />
                    <p class="text-center text-[11px] font-medium text-slate-500">ليالٍ محجوزة ÷ (الوحدات × أيام الشهر)</p>
                </div>

                <div v-if="bookings" :class="CARD" class="flex flex-col items-center justify-center gap-2 p-4">
                    <h2 class="self-start text-sm font-bold text-slate-200">وحدات اليوم</h2>
                    <DonutChart
                        :segments="[
                            { label: 'مشغولة', value: bookings.units_occupied, color: BLUE },
                            { label: 'متاحة', value: bookings.units_available, color: TEAL },
                        ]"
                        :center-value="bookings.units_occupied + '/' + bookings.units_total"
                        center-label="مشغولة الآن"
                    />
                </div>
            </div>

            <!-- الملخص المالي -->
            <div v-if="financeBars.length" :class="CARD" class="p-4">
                <div class="mb-3 flex items-center justify-between gap-2">
                    <h2 class="text-sm font-bold text-slate-200">الملخص المالي للشهر</h2>
                    <Link href="/admin/accounting/reports" class="text-xs font-bold hover:underline" :style="{ color: BLUE_S }">
                        التقارير المالية
                    </Link>
                </div>
                <div class="space-y-3">
                    <div v-for="r in financeBars" :key="r.label">
                        <div class="mb-1 flex items-center justify-between gap-2 text-[11px] font-bold">
                            <span class="flex items-center gap-1.5 text-slate-400">
                                <component :is="r.icon" class="h-3.5 w-3.5" :style="{ color: r.soft }" />
                                {{ r.label }}
                            </span>
                            <span :style="{ color: r.soft }" dir="ltr">{{ money(r.value) }}</span>
                        </div>
                        <div class="h-2 overflow-hidden rounded-full bg-white/[0.07]">
                            <div class="h-full rounded-full" :style="{ width: r.width + '%', background: r.color }"></div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="grid gap-3 lg:grid-cols-[1fr_340px]">
                <!-- جدول اليوم -->
                <div v-if="canSee.bookings" :class="CARD" class="overflow-hidden">
                    <div class="flex items-center justify-between gap-3 border-b border-white/10 px-4 py-3">
                        <div class="flex items-center gap-3">
                            <span class="grid h-9 w-9 shrink-0 place-items-center rounded-lg" :style="halo(BLUE_S)">
                                <CalendarDays class="h-5 w-5" />
                            </span>
                            <h2 class="text-sm font-bold text-slate-200">جدول اليوم</h2>
                        </div>
                        <div class="flex gap-3">
                            <Link href="/admin/calendar/halls" class="text-xs font-bold hover:underline" :style="{ color: BLUE_S }">
                                تقويم القاعات
                            </Link>
                            <Link href="/admin/calendar/chalets" class="text-xs font-bold hover:underline" :style="{ color: TEAL_S }">
                                تقويم الشاليهات
                            </Link>
                        </div>
                    </div>

                    <div v-if="today.length" class="divide-y divide-white/5">
                        <div v-for="r in today" :key="r.id" class="flex flex-wrap items-center gap-3 px-4 py-3 transition hover:bg-white/[0.03]">
                            <span class="h-8 w-1 shrink-0 rounded-full" :style="{ background: barColor(r.color) }"></span>
                            <div class="min-w-0 flex-1">
                                <div class="flex flex-wrap items-center gap-2">
                                    <span class="font-bold text-slate-100">{{ r.unit }}</span>
                                    <span class="rounded bg-white/10 px-1.5 py-0.5 text-[10px] font-bold text-slate-300">{{ r.scope }}</span>
                                    <span class="text-xs font-semibold text-slate-400">{{ r.period }}</span>
                                </div>
                                <div class="mt-0.5 flex flex-wrap items-center gap-2 text-[11px] text-slate-500">
                                    <span class="font-bold text-slate-400">{{ r.client ?? 'بلا عميل' }}</span>
                                    <a
                                        v-if="r.mobile"
                                        :href="`tel:${r.mobile}`"
                                        class="inline-flex items-center gap-0.5"
                                        :style="{ color: BLUE_S }"
                                        dir="ltr"
                                    >
                                        <Phone class="h-3 w-3" /> {{ r.mobile }}
                                    </a>
                                    <span dir="ltr">{{ r.reference }}</span>
                                </div>
                            </div>
                            <span v-if="r.remaining > 0" class="shrink-0 rounded-md px-2 py-0.5 text-[11px] font-bold" :style="halo(ORANGE_S)">
                                متبقٍ {{ money(r.remaining) }}
                            </span>
                            <span v-else class="shrink-0 rounded-md px-2 py-0.5 text-[11px] font-bold" :style="halo(TEAL_S)">مسدَّد</span>
                        </div>
                    </div>
                    <p v-else class="py-12 text-center text-sm font-medium text-slate-500">لا حجوزات اليوم</p>
                </div>

                <div class="space-y-3">
                    <!-- نقطة البيع -->
                    <div v-if="pos" :class="CARD" class="p-4">
                        <div class="mb-3 flex items-center gap-3">
                            <span class="grid h-9 w-9 shrink-0 place-items-center rounded-lg" :style="halo(TEAL_S)">
                                <Receipt class="h-5 w-5" />
                            </span>
                            <h2 class="text-sm font-bold text-slate-200">نقطة البيع</h2>
                        </div>
                        <div class="space-y-2 text-sm">
                            <div class="flex justify-between">
                                <span class="text-slate-400">مبيعات اليوم</span>
                                <span class="font-bold text-slate-100" dir="ltr">{{ money(pos.today_total) }}</span>
                            </div>
                            <div class="flex justify-between">
                                <span class="text-slate-400">عدد الفواتير</span>
                                <span class="font-bold text-slate-300" dir="ltr">{{ pos.today_count }}</span>
                            </div>
                            <div class="flex justify-between border-t border-white/10 pt-2">
                                <span class="text-slate-400">مبيعات الشهر</span>
                                <span class="font-bold" :style="{ color: BLUE_S }" dir="ltr">{{ money(pos.month_total) }}</span>
                            </div>
                            <div class="flex justify-between">
                                <span class="text-slate-400">مجمل الربح</span>
                                <span class="font-bold" :style="{ color: TEAL_S }" dir="ltr">{{ money(pos.month_profit) }}</span>
                            </div>
                        </div>
                        <Link
                            href="/admin/pos"
                            class="mt-3 block rounded-lg px-3 py-2 text-center text-xs font-bold text-white hover:brightness-110"
                            :style="{ background: TEAL }"
                        >
                            شاشة الفواتير
                        </Link>
                    </div>

                    <!-- الموارد البشرية -->
                    <div v-if="hr" :class="CARD" class="p-4">
                        <div class="mb-3 flex items-center gap-3">
                            <span class="grid h-9 w-9 shrink-0 place-items-center rounded-lg" :style="halo(ORANGE_S)">
                                <Wallet class="h-5 w-5" />
                            </span>
                            <h2 class="text-sm font-bold text-slate-200">الموظفون</h2>
                        </div>
                        <div class="space-y-2 text-sm">
                            <div class="flex justify-between">
                                <span class="text-slate-400">على رأس العمل</span>
                                <span class="font-bold text-slate-100" dir="ltr">{{ hr.employees }}</span>
                            </div>
                            <div class="flex justify-between">
                                <span class="text-slate-400">كلفة الرواتب الشهرية</span>
                                <span class="font-bold" :style="{ color: BLUE_S }" dir="ltr">{{ money(hr.monthly_cost) }}</span>
                            </div>
                            <div class="flex justify-between">
                                <span class="text-slate-400">وثائق توشك</span>
                                <span class="font-bold" :style="{ color: hr.expiring_docs ? ORANGE_S : TEAL_S }" dir="ltr">
                                    {{ hr.expiring_docs }}
                                </span>
                            </div>
                        </div>
                    </div>

                    <!-- الوحدات بالأرقام -->
                    <div v-if="bookings" :class="CARD" class="p-4">
                        <div class="mb-3 flex items-center gap-3">
                            <span class="grid h-9 w-9 shrink-0 place-items-center rounded-lg" :style="halo(BLUE_S)">
                                <Building2 class="h-5 w-5" />
                            </span>
                            <h2 class="text-sm font-bold text-slate-200">الوحدات</h2>
                        </div>
                        <div class="space-y-2 text-sm">
                            <div class="flex justify-between">
                                <span class="text-slate-400">وحدات نشطة</span>
                                <span class="font-bold text-slate-100" dir="ltr">{{ bookings.units_total }}</span>
                            </div>
                            <div class="flex justify-between">
                                <span class="text-slate-400">مشغولة اليوم</span>
                                <span class="font-bold" :style="{ color: BLUE_S }" dir="ltr">{{ bookings.units_occupied }}</span>
                            </div>
                            <div class="flex justify-between">
                                <span class="text-slate-400">مدفوع العربون</span>
                                <span class="font-bold" :style="{ color: bookings.deposit_paid ? ORANGE_S : TEAL_S }" dir="ltr">
                                    {{ bookings.deposit_paid }}
                                </span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ربحية الوحدات -->
            <div v-if="canSee.accounting && profitability.length" :class="CARD" class="overflow-hidden">
                <div class="flex flex-wrap items-center justify-between gap-3 border-b border-white/10 px-4 py-3">
                    <div class="flex items-center gap-3">
                        <span class="grid h-9 w-9 shrink-0 place-items-center rounded-lg" :style="halo(BLUE_S)">
                            <TrendingUp class="h-5 w-5" />
                        </span>
                        <h2 class="text-sm font-bold text-slate-200">ربحية الوحدات هذا الشهر</h2>
                    </div>
                    <ul class="flex items-center gap-3 text-[11px] font-bold text-slate-400">
                        <li class="flex items-center gap-1.5"><span class="h-2.5 w-2.5 rounded-sm" :style="{ background: TEAL }"></span>الإيراد</li>
                        <li class="flex items-center gap-1.5"><span class="h-2.5 w-2.5 rounded-sm" :style="{ background: ORANGE }"></span>المصروف</li>
                    </ul>
                </div>

                <div class="divide-y divide-white/5">
                    <div v-for="p in profitability" :key="p.name" class="px-4 py-3">
                        <div class="mb-2 flex items-center justify-between gap-2">
                            <span class="text-sm font-bold text-slate-100">{{ p.name }}</span>
                            <span class="text-sm font-extrabold" :style="{ color: p.profit >= 0 ? BLUE_S : RED_S }" dir="ltr">
                                {{ money(p.profit) }}
                            </span>
                        </div>
                        <div class="flex items-center gap-2">
                            <span class="w-12 shrink-0 text-[10px] font-bold text-slate-500">الإيراد</span>
                            <div class="h-2 flex-1 overflow-hidden rounded-full bg-white/[0.07]">
                                <div class="h-full rounded-full" :style="{ width: (p.revenue / profitMax) * 100 + '%', background: TEAL }"></div>
                            </div>
                            <span class="w-20 shrink-0 text-left text-[11px] font-bold text-slate-300" dir="ltr">{{ money(p.revenue) }}</span>
                        </div>
                        <div class="mt-1.5 flex items-center gap-2">
                            <span class="w-12 shrink-0 text-[10px] font-bold text-slate-500">المصروف</span>
                            <div class="h-2 flex-1 overflow-hidden rounded-full bg-white/[0.07]">
                                <div class="h-full rounded-full" :style="{ width: (p.expense / profitMax) * 100 + '%', background: ORANGE }"></div>
                            </div>
                            <span class="w-20 shrink-0 text-left text-[11px] font-bold text-slate-300" dir="ltr">{{ money(p.expense) }}</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
