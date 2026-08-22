<script setup lang="ts">
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
    Gauge,
    Info,
    Phone,
    PiggyBank,
    Receipt,
    TrendingDown,
    TrendingUp,
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

const props = defineProps<{
    canSee: { bookings: boolean; pos: boolean; accounting: boolean; hr: boolean };
    bookings: {
        today: number;
        upcoming: number;
        tentative: number;
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

/* لوحة ألوان القالب: أزرق · فيروزي · برتقالي */
const BLUE = '#1B75BB';
const TEAL = '#1AB3A6';
const ORANGE = '#F0A22E';
const INK = '#3F4A56';
const RED = '#D9534F';

interface Kpi {
    label: string;
    value: string | number;
    sub?: string;
    icon: Component;
    color: string;
    valueColor?: string;
    bar?: number;
}

const kpis = computed<Kpi[]>(() => {
    const b = props.bookings;
    const f = props.finance;
    const c = props.clients;
    const out: Kpi[] = [];

    if (b) {
        out.push(
            { label: 'حجوزات اليوم', value: b.today, sub: `قادمة: ${b.upcoming}`, icon: CalendarDays, color: BLUE },
            { label: 'إشغال الشهر', value: `${b.occupancy}%`, icon: Gauge, color: TEAL, valueColor: TEAL, bar: Math.min(100, b.occupancy) },
            { label: 'قيمة حجوزات الشهر', value: money(b.month_value), sub: `عدد الحجوزات: ${b.month_count}`, icon: Banknote, color: BLUE },
            {
                label: 'مستحق على العملاء',
                value: money(b.outstanding),
                sub: `حجز مبدئي: ${b.tentative}`,
                icon: Coins,
                color: ORANGE,
                valueColor: b.outstanding > 0 ? ORANGE : TEAL,
            },
            {
                label: 'الوحدات المشغولة اليوم',
                value: `${b.units_occupied} / ${b.units_total}`,
                icon: Building2,
                color: BLUE,
                valueColor: BLUE,
                bar: b.units_total ? (b.units_occupied / b.units_total) * 100 : 0,
            },
            { label: 'الوحدات المتاحة اليوم', value: b.units_available, sub: 'جاهزة للحجز الآن', icon: DoorOpen, color: TEAL, valueColor: TEAL },
            {
                label: 'إيرادات اليوم',
                value: money(b.collected_today),
                sub: `قيمة حجوزات اليوم: ${money(b.today_value)}`,
                icon: Wallet,
                color: TEAL,
            },
            {
                label: 'الحجوزات الملغاة هذا الشهر',
                value: b.cancelled_month,
                sub: c ? `${c.total} عميل · ${c.new_this_month} جديد هذا الشهر` : undefined,
                icon: CalendarX,
                color: ORANGE,
                valueColor: b.cancelled_month > 0 ? ORANGE : INK,
            },
        );
    }

    if (f) {
        out.push(
            { label: 'إيرادات الشهر', value: money(f.revenue), icon: TrendingUp, color: TEAL, valueColor: TEAL },
            { label: 'مصروفات الشهر', value: money(f.expense), icon: TrendingDown, color: ORANGE, valueColor: ORANGE },
            { label: 'صافي الربح', value: money(f.profit), icon: PiggyBank, color: BLUE, valueColor: f.profit < 0 ? RED : BLUE },
        );
    }

    return out;
});

const barColor = (c: string) => ({ amber: ORANGE, emerald: TEAL, slate: '#94A3B8', red: RED, rose: RED })[c] ?? '#CBD5E1';

const alertClass = (t: string) =>
    ({
        danger: 'border-[#D9534F] bg-[#FDF2F1] text-[#B23F3B]',
        warning: 'border-[#F0A22E] bg-[#FEF7EC] text-[#A56A12]',
        info: 'border-[#1B75BB] bg-[#EEF5FB] text-[#1B75BB]',
    })[t] ?? 'border-slate-200 bg-slate-50 text-slate-700';
</script>

<template>
    <Head title="لوحة التحكم" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="min-h-full space-y-4 bg-white p-5">
            <!-- ترويسة القالب -->
            <div class="border-b border-slate-200 pb-3 text-center">
                <h1 class="text-2xl font-bold text-slate-800">لوحة تحكم تحليلات الحجوزات والتشغيل</h1>
                <p class="mx-auto mt-1 max-w-3xl text-xs font-medium text-slate-500">
                    تغطي هذه اللوحة المؤشرات التشغيلية والمالية — الحجوزات والإشغال والوحدات والإيرادات والمصروفات ونقطة البيع، محكومة بصلاحياتك
                    ونطاق وحداتك.
                </p>
            </div>

            <!-- تنبيهات تحتاج تصرّفًا -->
            <div v-if="alerts.length" class="grid gap-2 sm:grid-cols-2 lg:grid-cols-4">
                <Link
                    v-for="(a, i) in alerts"
                    :key="i"
                    :href="a.href"
                    class="flex items-center gap-2 rounded-md border px-3 py-2 text-xs font-bold transition hover:brightness-95"
                    :class="alertClass(a.type)"
                >
                    <component :is="a.type === 'info' ? Info : AlertTriangle" class="h-4 w-4 shrink-0" />
                    {{ a.text }}
                </Link>
            </div>

            <!-- بطاقات المؤشرات -->
            <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
                <div v-for="k in kpis" :key="k.label" class="rounded-md border border-slate-200 bg-white px-4 py-3 shadow-sm">
                    <div class="border-b border-slate-200 pb-2 text-center text-[13px] font-bold text-slate-700">{{ k.label }}</div>
                    <div class="mt-3 flex items-center justify-center gap-3">
                        <span
                            class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full border-2"
                            :style="{ borderColor: k.color, color: k.color }"
                        >
                            <component :is="k.icon" class="h-5 w-5" />
                        </span>
                        <div class="min-w-0">
                            <div class="text-2xl leading-tight font-extrabold" :style="{ color: k.valueColor ?? INK }" dir="ltr">{{ k.value }}</div>
                            <div v-if="k.sub" class="mt-0.5 text-[11px] font-medium text-slate-500">{{ k.sub }}</div>
                        </div>
                    </div>
                    <div v-if="k.bar !== undefined" class="mt-3 h-2 overflow-hidden rounded-sm bg-slate-100">
                        <div class="h-full rounded-sm" :style="{ width: `${k.bar}%`, background: k.color }"></div>
                    </div>
                </div>
            </div>

            <div class="grid gap-3 lg:grid-cols-[1fr_340px]">
                <!-- جدول اليوم -->
                <div v-if="canSee.bookings" class="overflow-hidden rounded-md border border-slate-200 bg-white shadow-sm">
                    <div class="flex items-center justify-between gap-3 px-4 py-3">
                        <div class="flex items-center gap-3">
                            <span
                                class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full border-2"
                                :style="{ borderColor: BLUE, color: BLUE }"
                            >
                                <CalendarDays class="h-5 w-5" />
                            </span>
                            <h2 class="border-b border-slate-300 pb-1 text-sm font-bold text-slate-700">جدول اليوم</h2>
                        </div>
                        <div class="flex gap-3">
                            <Link href="/admin/calendar/halls" class="text-xs font-bold hover:underline" :style="{ color: BLUE }">تقويم القاعات</Link>
                            <Link href="/admin/calendar/chalets" class="text-xs font-bold hover:underline" :style="{ color: TEAL }"
                                >تقويم الشاليهات</Link
                            >
                        </div>
                    </div>

                    <div v-if="today.length" class="divide-y divide-slate-100 border-t border-slate-100">
                        <div v-for="r in today" :key="r.id" class="flex flex-wrap items-center gap-3 px-4 py-3">
                            <span class="h-8 w-1 shrink-0 rounded-full" :style="{ background: barColor(r.color) }"></span>
                            <div class="min-w-0 flex-1">
                                <div class="flex flex-wrap items-center gap-2">
                                    <span class="font-bold text-slate-700">{{ r.unit }}</span>
                                    <span class="rounded bg-slate-100 px-1.5 py-0.5 text-[10px] font-bold text-slate-600">{{ r.scope }}</span>
                                    <span class="text-xs font-semibold text-slate-500">{{ r.period }}</span>
                                </div>
                                <div class="mt-0.5 flex flex-wrap items-center gap-2 text-[11px] text-slate-500">
                                    <span class="font-bold text-slate-600">{{ r.client ?? 'بلا عميل' }}</span>
                                    <a
                                        v-if="r.mobile"
                                        :href="`tel:${r.mobile}`"
                                        class="inline-flex items-center gap-0.5"
                                        :style="{ color: BLUE }"
                                        dir="ltr"
                                    >
                                        <Phone class="h-3 w-3" /> {{ r.mobile }}
                                    </a>
                                    <span dir="ltr">{{ r.reference }}</span>
                                </div>
                            </div>
                            <span
                                v-if="r.remaining > 0"
                                class="shrink-0 rounded px-2 py-0.5 text-[11px] font-bold text-white"
                                :style="{ background: ORANGE }"
                            >
                                متبقٍ {{ money(r.remaining) }}
                            </span>
                            <span v-else class="shrink-0 rounded px-2 py-0.5 text-[11px] font-bold text-white" :style="{ background: TEAL }"
                                >مسدَّد</span
                            >
                        </div>
                    </div>
                    <p v-else class="border-t border-slate-100 py-12 text-center text-sm font-medium text-slate-400">لا حجوزات اليوم</p>
                </div>

                <div class="space-y-3">
                    <!-- نقطة البيع -->
                    <div v-if="pos" class="rounded-md border border-slate-200 bg-white p-4 shadow-sm">
                        <div class="mb-3 flex items-center gap-3">
                            <span
                                class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full border-2"
                                :style="{ borderColor: TEAL, color: TEAL }"
                            >
                                <Receipt class="h-5 w-5" />
                            </span>
                            <h2 class="border-b border-slate-300 pb-1 text-sm font-bold text-slate-700">نقطة البيع</h2>
                        </div>
                        <div class="space-y-2 text-sm">
                            <div class="flex justify-between">
                                <span class="text-slate-500">مبيعات اليوم</span
                                ><span class="font-bold" :style="{ color: INK }">{{ money(pos.today_total) }}</span>
                            </div>
                            <div class="flex justify-between">
                                <span class="text-slate-500">عدد الفواتير</span><span class="font-bold text-slate-600">{{ pos.today_count }}</span>
                            </div>
                            <div class="flex justify-between border-t border-slate-100 pt-2">
                                <span class="text-slate-500">مبيعات الشهر</span
                                ><span class="font-bold" :style="{ color: BLUE }">{{ money(pos.month_total) }}</span>
                            </div>
                            <div class="flex justify-between">
                                <span class="text-slate-500">مجمل الربح</span
                                ><span class="font-bold" :style="{ color: TEAL }">{{ money(pos.month_profit) }}</span>
                            </div>
                        </div>
                        <Link
                            href="/admin/pos"
                            class="mt-3 block rounded px-3 py-2 text-center text-xs font-bold text-white hover:brightness-110"
                            :style="{ background: TEAL }"
                        >
                            شاشة الفواتير
                        </Link>
                    </div>

                    <!-- الموارد البشرية -->
                    <div v-if="hr" class="rounded-md border border-slate-200 bg-white p-4 shadow-sm">
                        <div class="mb-3 flex items-center gap-3">
                            <span
                                class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full border-2"
                                :style="{ borderColor: ORANGE, color: ORANGE }"
                            >
                                <Wallet class="h-5 w-5" />
                            </span>
                            <h2 class="border-b border-slate-300 pb-1 text-sm font-bold text-slate-700">الموظفون</h2>
                        </div>
                        <div class="space-y-2 text-sm">
                            <div class="flex justify-between">
                                <span class="text-slate-500">على رأس العمل</span
                                ><span class="font-bold" :style="{ color: INK }">{{ hr.employees }}</span>
                            </div>
                            <div class="flex justify-between">
                                <span class="text-slate-500">كلفة الرواتب الشهرية</span
                                ><span class="font-bold" :style="{ color: BLUE }">{{ money(hr.monthly_cost) }}</span>
                            </div>
                            <div class="flex justify-between">
                                <span class="text-slate-500">وثائق توشك</span
                                ><span class="font-bold" :style="{ color: hr.expiring_docs ? ORANGE : TEAL }">{{ hr.expiring_docs }}</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ربحية الوحدات -->
            <div v-if="canSee.accounting && profitability.length" class="overflow-hidden rounded-md border border-slate-200 bg-white shadow-sm">
                <div class="flex items-center justify-between gap-3 px-4 py-3">
                    <div class="flex items-center gap-3">
                        <span
                            class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full border-2"
                            :style="{ borderColor: BLUE, color: BLUE }"
                        >
                            <TrendingUp class="h-5 w-5" />
                        </span>
                        <h2 class="border-b border-slate-300 pb-1 text-sm font-bold text-slate-700">ربحية الوحدات هذا الشهر</h2>
                    </div>
                    <Link href="/admin/accounting/reports" class="text-xs font-bold hover:underline" :style="{ color: BLUE }">التقارير المالية</Link>
                </div>
                <table class="w-full text-sm">
                    <thead class="bg-slate-50">
                        <tr>
                            <th class="px-4 py-2 text-right text-xs font-bold text-slate-500">الوحدة</th>
                            <th class="px-4 py-2 text-left text-xs font-bold text-slate-500">الإيراد</th>
                            <th class="px-4 py-2 text-left text-xs font-bold text-slate-500">المصروف</th>
                            <th class="px-4 py-2 text-left text-xs font-bold text-slate-500">الربح</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="p in profitability" :key="p.name" class="border-t border-slate-100">
                            <td class="px-4 py-2 font-bold text-slate-700">{{ p.name }}</td>
                            <td class="px-4 py-2 text-left font-bold" :style="{ color: TEAL }" dir="ltr">{{ money(p.revenue) }}</td>
                            <td class="px-4 py-2 text-left font-bold" :style="{ color: ORANGE }" dir="ltr">{{ money(p.expense) }}</td>
                            <td class="px-4 py-2 text-left font-extrabold" :style="{ color: p.profit >= 0 ? BLUE : RED }" dir="ltr">
                                {{ money(p.profit) }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </AppLayout>
</template>
