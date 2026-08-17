<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link } from '@inertiajs/vue3';
import { AlertTriangle, CalendarDays, Info, Phone, TrendingUp, Wallet } from 'lucide-vue-next';

interface TodayRow {
    id: number; reference: string; unit: string | null; scope: string;
    client: string | null; mobile: string | null;
    period: string; status: string; color: string; remaining: number;
}

defineProps<{
    canSee: { bookings: boolean; pos: boolean; accounting: boolean; hr: boolean };
    bookings: {
        today: number; upcoming: number; tentative: number;
        month_count: number; month_value: number; outstanding: number; occupancy: number;
    } | null;
    today: TodayRow[];
    pos: {
        today_total: number; today_count: number; month_total: number; month_profit: number;
        low_stock: number;
    } | null;
    profitability: { name: string; revenue: number; expense: number; profit: number }[];
    hr: { employees: number; expiring_docs: number; monthly_cost: number } | null;
    alerts: { type: string; text: string; href: string }[];
}>();

const breadcrumbs: BreadcrumbItem[] = [{ title: 'لوحة التحكم', href: '/admin' }];

const money = (n: number) => new Intl.NumberFormat('ar-SA-u-nu-latn', { maximumFractionDigits: 0 }).format(n ?? 0);

const barClass = (c: string) =>
    ({ amber: 'bg-amber-400', emerald: 'bg-emerald-500', slate: 'bg-slate-400', red: 'bg-red-500', rose: 'bg-rose-500' })[c] ?? 'bg-slate-300';

const alertClass = (t: string) =>
    ({
        danger: 'border-red-200 bg-red-50 text-red-800',
        warning: 'border-amber-200 bg-amber-50 text-amber-800',
        info: 'border-sky-200 bg-sky-50 text-sky-800',
    })[t] ?? 'border-slate-200 bg-slate-50 text-slate-700';
</script>

<template>
    <Head title="لوحة التحكم" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="min-h-full space-y-5 bg-slate-100 p-5">
            <div>
                <h1 class="text-2xl font-extrabold text-slate-900">لوحة المؤشرات</h1>
                <p class="mt-1 text-sm font-medium text-slate-600">ما يخصّك أنت — محكوم بصلاحياتك ونطاق وحداتك</p>
            </div>

            <!-- تنبيهات تحتاج تصرّفًا -->
            <div v-if="alerts.length" class="grid gap-2 sm:grid-cols-2 lg:grid-cols-4">
                <Link
                    v-for="(a, i) in alerts" :key="i" :href="a.href"
                    class="flex items-center gap-2 rounded-xl border-2 px-3 py-2.5 text-sm font-bold transition hover:brightness-95"
                    :class="alertClass(a.type)"
                >
                    <component :is="a.type === 'info' ? Info : AlertTriangle" class="h-4 w-4 shrink-0" />
                    {{ a.text }}
                </Link>
            </div>

            <!-- الحجوزات -->
            <div v-if="bookings" class="grid grid-cols-2 gap-3 lg:grid-cols-4">
                <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
                    <div class="text-xs font-bold text-slate-500">حجوزات اليوم</div>
                    <div class="mt-1 text-3xl font-extrabold text-slate-900">{{ bookings.today }}</div>
                    <div class="mt-0.5 text-[11px] font-medium text-slate-500">{{ bookings.upcoming }} قادمة</div>
                </div>
                <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
                    <div class="text-xs font-bold text-slate-500">إشغال الشهر</div>
                    <div class="mt-1 text-3xl font-extrabold text-emerald-600">{{ bookings.occupancy }}<span class="text-lg">%</span></div>
                    <div class="mt-1 h-1.5 overflow-hidden rounded-full bg-slate-100">
                        <div class="h-full rounded-full bg-emerald-500" :style="{ width: `${Math.min(100, bookings.occupancy)}%` }"></div>
                    </div>
                </div>
                <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
                    <div class="text-xs font-bold text-slate-500">قيمة حجوزات الشهر</div>
                    <div class="mt-1 text-2xl font-extrabold text-slate-900">{{ money(bookings.month_value) }}</div>
                    <div class="mt-0.5 text-[11px] font-medium text-slate-500">{{ bookings.month_count }} حجز</div>
                </div>
                <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
                    <div class="text-xs font-bold text-slate-500">مستحق على العملاء</div>
                    <div class="mt-1 text-2xl font-extrabold" :class="bookings.outstanding > 0 ? 'text-red-600' : 'text-emerald-600'">{{ money(bookings.outstanding) }}</div>
                    <div class="mt-0.5 text-[11px] font-medium text-amber-600">{{ bookings.tentative }} حجز مبدئي</div>
                </div>
            </div>

            <div class="grid gap-4 lg:grid-cols-[1fr_360px]">
                <!-- جدول اليوم -->
                <div v-if="canSee.bookings" class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                    <div class="flex items-center justify-between border-b border-slate-100 px-4 py-3">
                        <h2 class="flex items-center gap-1.5 font-extrabold text-slate-800">
                            <CalendarDays class="h-4 w-4" /> جدول اليوم
                        </h2>
                        <div class="flex gap-3">
                            <Link href="/admin/calendar/halls" class="text-xs font-bold text-blue-600 hover:underline">تقويم القاعات</Link>
                            <Link href="/admin/calendar/chalets" class="text-xs font-bold text-teal-600 hover:underline">تقويم الشاليهات</Link>
                        </div>
                    </div>

                    <div v-if="today.length" class="divide-y divide-slate-100">
                        <div v-for="r in today" :key="r.id" class="flex flex-wrap items-center gap-3 px-4 py-3">
                            <span class="h-8 w-1 shrink-0 rounded-full" :class="barClass(r.color)"></span>
                            <div class="min-w-0 flex-1">
                                <div class="flex flex-wrap items-center gap-2">
                                    <span class="font-extrabold text-slate-800">{{ r.unit }}</span>
                                    <span class="rounded bg-slate-100 px-1.5 py-0.5 text-[10px] font-bold text-slate-600">{{ r.scope }}</span>
                                    <span class="text-xs font-bold text-slate-500">{{ r.period }}</span>
                                </div>
                                <div class="mt-0.5 flex flex-wrap items-center gap-2 text-[11px] text-slate-500">
                                    <span class="font-bold text-slate-700">{{ r.client ?? 'بلا عميل' }}</span>
                                    <a v-if="r.mobile" :href="`tel:${r.mobile}`" class="inline-flex items-center gap-0.5 text-blue-600" dir="ltr">
                                        <Phone class="h-3 w-3" /> {{ r.mobile }}
                                    </a>
                                    <span dir="ltr">{{ r.reference }}</span>
                                </div>
                            </div>
                            <span v-if="r.remaining > 0" class="shrink-0 rounded-md bg-red-100 px-2 py-0.5 text-[11px] font-bold text-red-700">
                                متبقٍ {{ money(r.remaining) }}
                            </span>
                            <span v-else class="shrink-0 rounded-md bg-emerald-100 px-2 py-0.5 text-[11px] font-bold text-emerald-700">مسدَّد</span>
                        </div>
                    </div>
                    <p v-else class="py-12 text-center text-sm font-medium text-slate-400">لا حجوزات اليوم</p>
                </div>

                <div class="space-y-4">
                    <!-- نقطة البيع -->
                    <div v-if="pos" class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
                        <h2 class="mb-3 flex items-center gap-1.5 font-extrabold text-slate-800">
                            <Wallet class="h-4 w-4" /> نقطة البيع
                        </h2>
                        <div class="space-y-2 text-sm">
                            <div class="flex justify-between"><span class="text-slate-600">مبيعات اليوم</span><span class="font-extrabold text-slate-900">{{ money(pos.today_total) }}</span></div>
                            <div class="flex justify-between"><span class="text-slate-600">عدد الفواتير</span><span class="font-bold text-slate-700">{{ pos.today_count }}</span></div>
                            <div class="flex justify-between border-t border-slate-100 pt-2"><span class="text-slate-600">مبيعات الشهر</span><span class="font-extrabold text-slate-900">{{ money(pos.month_total) }}</span></div>
                            <div class="flex justify-between"><span class="text-slate-600">مجمل الربح</span><span class="font-extrabold text-emerald-600">{{ money(pos.month_profit) }}</span></div>
                        </div>
                        <Link href="/admin/pos" class="mt-3 block rounded-lg bg-emerald-50 px-3 py-2 text-center text-xs font-bold text-emerald-700 hover:bg-emerald-100">
                            شاشة الفواتير
                        </Link>
                    </div>

                    <!-- الموارد البشرية -->
                    <div v-if="hr" class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
                        <h2 class="mb-3 font-extrabold text-slate-800">الموظفون</h2>
                        <div class="space-y-2 text-sm">
                            <div class="flex justify-between"><span class="text-slate-600">على رأس العمل</span><span class="font-extrabold text-slate-900">{{ hr.employees }}</span></div>
                            <div class="flex justify-between"><span class="text-slate-600">كلفة الرواتب الشهرية</span><span class="font-extrabold text-slate-900">{{ money(hr.monthly_cost) }}</span></div>
                            <div class="flex justify-between"><span class="text-slate-600">وثائق توشك</span><span class="font-extrabold" :class="hr.expiring_docs ? 'text-red-600' : 'text-emerald-600'">{{ hr.expiring_docs }}</span></div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ربحية الوحدات -->
            <div v-if="canSee.accounting && profitability.length" class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                <div class="flex items-center justify-between border-b border-slate-100 px-4 py-3">
                    <h2 class="flex items-center gap-1.5 font-extrabold text-slate-800">
                        <TrendingUp class="h-4 w-4" /> ربحية الوحدات هذا الشهر
                    </h2>
                    <Link href="/admin/accounting/reports" class="text-xs font-bold text-blue-600 hover:underline">التقارير المالية</Link>
                </div>
                <table class="w-full text-sm">
                    <thead class="bg-slate-50">
                        <tr>
                            <th class="px-4 py-2 text-right text-xs font-extrabold text-slate-700">الوحدة</th>
                            <th class="px-4 py-2 text-left text-xs font-extrabold text-slate-700">الإيراد</th>
                            <th class="px-4 py-2 text-left text-xs font-extrabold text-slate-700">المصروف</th>
                            <th class="px-4 py-2 text-left text-xs font-extrabold text-slate-700">الربح</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="p in profitability" :key="p.name" class="border-t border-slate-100">
                            <td class="px-4 py-2 font-bold text-slate-800">{{ p.name }}</td>
                            <td class="px-4 py-2 text-left font-bold text-emerald-600" dir="ltr">{{ money(p.revenue) }}</td>
                            <td class="px-4 py-2 text-left font-bold text-red-600" dir="ltr">{{ money(p.expense) }}</td>
                            <td class="px-4 py-2 text-left font-extrabold" :class="p.profit >= 0 ? 'text-slate-900' : 'text-red-600'" dir="ltr">{{ money(p.profit) }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </AppLayout>
</template>
