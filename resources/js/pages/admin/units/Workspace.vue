<script setup lang="ts">
import { usePermissions } from '@/composables/usePermissions';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { CalendarDays, ImagePlus, Loader2, Lock, Phone, Power, Receipt, Settings2, TrendingUp, UserCog, Users, X } from 'lucide-vue-next';
import { computed } from 'vue';

interface Section { id: number; name: string; gender: string; is_active: boolean; facilities: string[] }

interface Booking {
    id: number; reference: string; client: string | null; mobile: string | null;
    date: string; period: string; scope: string; status: string; color: string;
    total: number; remaining: number;
}

interface Invoice {
    id: number; number: string; type: string; client: string | null;
    total: number; method: string; date: string;
}

const props = defineProps<{
    unit: {
        id: number; code: string; name: string; type: string; type_label: string;
        logo_url: string | null; capacity: number | null; is_active: boolean;
        privacy_mode: string; bookable_mode: string;
        manager: { name: string; phone: string | null } | null;
        notes: string | null;
        sections: Section[];
    };
    stats: {
        today: number; upcoming: number; month_count: number; month_value: number;
        outstanding: number; occupancy: number;
        revenue: number; expense: number; profit: number; invoices_total: number;
    };
    upcoming: Booking[];
    invoices: Invoice[];
    canSeeMoney: boolean;
}>();

const breadcrumbs = computed<BreadcrumbItem[]>(() => [
    { title: 'لوحة التحكم', href: '/admin' },
    {
        title: props.unit.type === 'hall' ? 'القاعات' : 'الشاليهات',
        href: props.unit.type === 'hall' ? '/admin/units/halls' : '/admin/units/chalets',
    },
    { title: props.unit.name, href: `/admin/units/${props.unit.id}/workspace` },
]);

const money = (n: number) => new Intl.NumberFormat('ar-SA-u-nu-latn', { maximumFractionDigits: 0 }).format(n ?? 0);

// شاشتا الحجز والتقويم منفصلتان بحسب نوع الوحدة — القاعة تُحجز بفترة
// والشاليه بليالٍ، ولكلٍّ شاشته. الروابط تتبع نوع الوحدة المعروضة.
const kind = computed(() => (props.unit.type === 'hall' ? 'halls' : 'chalets'));
const bookingsHref = computed(() => `/admin/bookings/${kind.value}?unit_id=${props.unit.id}`);
const calendarHref = computed(() => `/admin/calendar/${kind.value}?unit_id=${props.unit.id}`);

const barClass = (c: string) =>
    ({ amber: 'bg-amber-500', emerald: 'bg-emerald-600', slate: 'bg-slate-500', red: 'bg-red-600', rose: 'bg-rose-600' })[c] ?? 'bg-slate-400';

const genderClass = (g: string) =>
    ({ men: 'bg-blue-200 text-blue-900', women: 'bg-pink-200 text-pink-900', mixed: 'bg-violet-200 text-violet-900' })[g] ?? 'bg-slate-200 text-slate-800';

/* ── شعار الوحدة ─────────────────────────────────────────────────────────
 * يُرفع من هنا مباشرة بنقطة نهاية مستقلة، فلا حاجة لفتح نموذج الوحدة كاملًا
 * في شاشة الإعدادات لتغيير صورة واحدة.
 */
const { can } = usePermissions();
const canEditLogo = computed(() => can('units.edit'));

const logoForm = useForm({ logo: null as File | null, remove_logo: false as boolean });
const logoEndpoint = computed(() => `/admin/units/${props.unit.id}/logo`);

const submitLogo = (reset?: () => void) =>
    logoForm.post(logoEndpoint.value, {
        preserveScroll: true,
        forceFormData: true, // رفع ملف يستلزم multipart
        onFinish: () => {
            logoForm.logo = null;
            logoForm.remove_logo = false;
            reset?.();
        },
    });

const onLogoPick = (e: Event) => {
    const input = e.target as HTMLInputElement;
    const file = input.files?.[0] ?? null;
    if (!file) return;

    logoForm.logo = file;
    logoForm.remove_logo = false;
    // تفريغ الحقل بعد الرفع حتى يُقبل اختيار الملف نفسه مجددًا.
    submitLogo(() => (input.value = ''));
};

const removeLogo = () => {
    if (!confirm(`حذف شعار «${props.unit.name}»؟`)) return;

    logoForm.logo = null;
    logoForm.remove_logo = true;
    submitLogo();
};
</script>

<template>
    <Head :title="unit.name" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="min-h-full space-y-5 bg-slate-100 p-5">
            <!-- ترويسة الوحدة -->
            <div class="overflow-hidden rounded-2xl border bg-white shadow-sm" :class="unit.is_active ? 'border-slate-300' : 'border-red-300'">
                <div class="h-1.5" :class="unit.is_active ? 'brand-gradient' : 'bg-red-600'"></div>

                <div v-if="!unit.is_active" class="flex items-center gap-1.5 bg-red-100 px-5 py-2 text-xs font-extrabold text-red-800">
                    <Power class="h-3.5 w-3.5" /> هذه الوحدة موقوفة — لا تقبل حجوزات جديدة
                </div>

                <div class="flex flex-wrap items-start gap-4 p-5">
                    <!-- الشعار — قابل للاستبدال بالنقر لمن يملك صلاحية تعديل الوحدات -->
                    <div class="relative shrink-0">
                        <component
                            :is="canEditLogo ? 'label' : 'span'"
                            class="group relative flex h-16 w-16 items-center justify-center overflow-hidden rounded-2xl"
                            :class="[
                                unit.logo_url ? 'ring-1 ring-slate-300' : 'brand-gradient text-2xl font-extrabold text-white',
                                canEditLogo ? 'cursor-pointer' : '',
                            ]"
                            :title="canEditLogo ? 'تغيير الشعار' : undefined"
                        >
                            <img v-if="unit.logo_url" :src="unit.logo_url" :alt="unit.name" class="h-full w-full object-cover" />
                            <template v-else>{{ unit.name.charAt(0) }}</template>

                            <span
                                v-if="canEditLogo"
                                class="absolute inset-0 flex items-center justify-center bg-black/50 text-white opacity-0 transition group-hover:opacity-100"
                            >
                                <ImagePlus class="h-5 w-5" />
                            </span>

                            <span v-if="logoForm.processing" class="absolute inset-0 flex items-center justify-center bg-black/60 text-white">
                                <Loader2 class="h-5 w-5 animate-spin" />
                            </span>

                            <input v-if="canEditLogo" type="file" accept="image/*" class="hidden" :disabled="logoForm.processing" @change="onLogoPick" />
                        </component>

                        <button
                            v-if="canEditLogo && unit.logo_url"
                            type="button"
                            :disabled="logoForm.processing"
                            @click="removeLogo"
                            class="absolute -top-1.5 rounded-full bg-red-600 p-1 text-white shadow ltr:-right-1.5 rtl:-left-1.5 hover:bg-red-700 disabled:opacity-50"
                            title="حذف الشعار"
                        >
                            <X class="h-3 w-3" />
                        </button>

                        <p v-if="logoForm.errors.logo" class="absolute top-full mt-1 w-40 text-[11px] font-bold text-red-600">{{ logoForm.errors.logo }}</p>
                    </div>

                    <div class="min-w-0 flex-1">
                        <div class="flex flex-wrap items-center gap-2">
                            <h1 class="text-2xl font-extrabold text-slate-900">{{ unit.name }}</h1>
                            <span class="rounded-md bg-slate-200 px-2 py-0.5 text-[11px] font-bold text-slate-800">{{ unit.type_label }}</span>
                            <span class="font-mono text-[11px] text-slate-500" dir="ltr">{{ unit.code }}</span>
                        </div>

                        <div class="mt-2 flex flex-wrap items-center gap-3 text-xs">
                            <span v-if="unit.manager" class="inline-flex items-center gap-1.5 rounded-md bg-indigo-100 px-2 py-1 font-bold text-indigo-800">
                                <UserCog class="h-3.5 w-3.5" /> {{ unit.manager.name }}
                                <a v-if="unit.manager.phone" :href="`tel:${unit.manager.phone}`" class="inline-flex items-center gap-0.5 text-indigo-700 underline-offset-2 hover:underline" dir="ltr">
                                    <Phone class="h-3 w-3" /> {{ unit.manager.phone }}
                                </a>
                            </span>
                            <span v-if="unit.capacity" class="inline-flex items-center gap-1 font-bold text-slate-700">
                                <Users class="h-3.5 w-3.5" /> السعة {{ unit.capacity }}
                            </span>
                            <span v-if="unit.privacy_mode === 'exclusive'" class="inline-flex items-center gap-1 rounded-md bg-amber-200 px-2 py-0.5 font-bold text-amber-900">
                                <Lock class="h-3 w-3" /> خصوصية مغلقة
                            </span>
                        </div>

                        <div v-if="unit.sections.length" class="mt-2 flex flex-wrap gap-1.5">
                            <span v-for="s in unit.sections" :key="s.id" class="inline-flex items-center gap-1 rounded-md px-2 py-0.5 text-[11px] font-bold" :class="genderClass(s.gender)">
                                {{ s.name }}
                                <span v-if="!s.is_active" class="rounded bg-red-300 px-1 text-[9px] font-bold text-red-900">موقوف</span>
                                <span v-if="s.facilities.length" class="font-medium opacity-80">· {{ s.facilities.join('، ') }}</span>
                            </span>
                        </div>
                    </div>

                    <div class="flex shrink-0 flex-wrap gap-2">
                        <Link :href="bookingsHref" class="inline-flex items-center gap-1.5 rounded-md bg-blue-600 px-4 py-2 text-sm font-bold text-white hover:bg-blue-700">
                            <CalendarDays class="h-4 w-4" /> {{ unit.type === 'hall' ? 'حجوزات القاعة' : 'إقامات الشاليه' }}
                        </Link>
                        <Link :href="calendarHref" class="inline-flex items-center gap-1.5 rounded-md border border-slate-300 bg-white px-4 py-2 text-sm font-bold text-slate-800 hover:bg-slate-100">
                            التقويم
                        </Link>
                        <Link href="/admin/units" class="inline-flex items-center gap-1.5 rounded-md border border-slate-300 bg-white px-4 py-2 text-sm font-bold text-slate-800 hover:bg-slate-100">
                            <Settings2 class="h-4 w-4" /> الإعدادات
                        </Link>
                    </div>
                </div>
            </div>

            <!-- المؤشرات -->
            <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
                <div class="rounded-2xl border border-slate-300 bg-white p-4 shadow-sm">
                    <div class="text-xs font-bold text-slate-600">حجوزات اليوم</div>
                    <div class="mt-1 text-3xl font-extrabold text-slate-900">{{ stats.today }}</div>
                    <div class="mt-0.5 text-[11px] font-bold text-slate-600">{{ stats.upcoming }} قادمة</div>
                </div>
                <div class="rounded-2xl border border-slate-300 bg-white p-4 shadow-sm">
                    <div class="text-xs font-bold text-slate-600">إشغال الشهر</div>
                    <div class="mt-1 text-3xl font-extrabold text-emerald-700">{{ stats.occupancy }}<span class="text-lg">%</span></div>
                    <div class="mt-1 h-1.5 overflow-hidden rounded-full bg-slate-200">
                        <div class="h-full rounded-full bg-emerald-600" :style="{ width: `${Math.min(100, stats.occupancy)}%` }"></div>
                    </div>
                </div>
                <div class="rounded-2xl border border-slate-300 bg-white p-4 shadow-sm">
                    <div class="text-xs font-bold text-slate-600">قيمة حجوزات الشهر</div>
                    <div class="mt-1 text-2xl font-extrabold text-slate-900">{{ money(stats.month_value) }}</div>
                    <div class="mt-0.5 text-[11px] font-bold text-slate-600">{{ stats.month_count }} حجز</div>
                </div>
                <div class="rounded-2xl border border-slate-300 bg-white p-4 shadow-sm">
                    <div class="text-xs font-bold text-slate-600">مستحق على العملاء</div>
                    <div class="mt-1 text-2xl font-extrabold" :class="stats.outstanding > 0 ? 'text-red-700' : 'text-emerald-700'">{{ money(stats.outstanding) }}</div>
                    <div class="mt-0.5 text-[11px] font-bold text-slate-600">فواتير الوحدة {{ money(stats.invoices_total) }}</div>
                </div>
            </div>

            <!-- ربحية الوحدة -->
            <div v-if="canSeeMoney" class="rounded-2xl border border-slate-300 bg-white p-4 shadow-sm">
                <h2 class="mb-3 flex items-center gap-1.5 font-extrabold text-slate-900">
                    <TrendingUp class="h-4 w-4" /> ربحية الوحدة هذا الشهر
                </h2>
                <div class="grid grid-cols-3 gap-3 text-center">
                    <div class="rounded-xl bg-emerald-100 p-3">
                        <div class="text-[11px] font-bold text-emerald-800">الإيراد</div>
                        <div class="text-xl font-extrabold text-emerald-900">{{ money(stats.revenue) }}</div>
                    </div>
                    <div class="rounded-xl bg-red-100 p-3">
                        <div class="text-[11px] font-bold text-red-800">المصروف</div>
                        <div class="text-xl font-extrabold text-red-900">{{ money(stats.expense) }}</div>
                    </div>
                    <div class="rounded-xl p-3" :class="stats.profit >= 0 ? 'bg-slate-200' : 'bg-red-200'">
                        <div class="text-[11px] font-bold" :class="stats.profit >= 0 ? 'text-slate-700' : 'text-red-800'">الربح</div>
                        <div class="text-xl font-extrabold" :class="stats.profit >= 0 ? 'text-slate-900' : 'text-red-900'">{{ money(stats.profit) }}</div>
                    </div>
                </div>
                <p class="mt-2 text-[11px] font-medium text-slate-600">من مركز تكلفة الوحدة — يشمل إيراد الحجوزات ومبيعاتها ورواتب موظفيها.</p>
            </div>

            <div class="grid gap-4 lg:grid-cols-2">
                <!-- الحجوزات القادمة -->
                <div class="overflow-hidden rounded-2xl border border-slate-300 bg-white shadow-sm">
                    <div class="flex items-center justify-between border-b border-slate-200 bg-slate-100 px-4 py-3">
                        <h2 class="flex items-center gap-1.5 font-extrabold text-slate-900">
                            <CalendarDays class="h-4 w-4" /> الحجوزات القادمة
                        </h2>
                        <Link :href="bookingsHref" class="text-xs font-bold text-blue-700 hover:underline">الكل</Link>
                    </div>

                    <div v-if="upcoming.length" class="divide-y divide-slate-200">
                        <div v-for="b in upcoming" :key="b.id" class="flex flex-wrap items-center gap-3 px-4 py-2.5">
                            <span class="h-8 w-1 shrink-0 rounded-full" :class="barClass(b.color)"></span>
                            <div class="min-w-0 flex-1">
                                <div class="flex flex-wrap items-center gap-2 text-sm">
                                    <span class="font-bold text-slate-900" dir="ltr">{{ b.date }}</span>
                                    <span class="text-xs font-bold text-slate-700">{{ b.period }}</span>
                                    <span class="rounded bg-slate-200 px-1.5 py-0.5 text-[10px] font-bold text-slate-800">{{ b.scope }}</span>
                                </div>
                                <div class="mt-0.5 flex flex-wrap items-center gap-2 text-[11px] text-slate-600">
                                    <span class="font-bold text-slate-800">{{ b.client ?? 'بلا عميل' }}</span>
                                    <a v-if="b.mobile" :href="`tel:${b.mobile}`" class="font-bold text-blue-700" dir="ltr">{{ b.mobile }}</a>
                                    <span dir="ltr">{{ b.reference }}</span>
                                </div>
                            </div>
                            <span v-if="b.remaining > 0" class="shrink-0 rounded-md bg-red-200 px-2 py-0.5 text-[11px] font-bold text-red-900">
                                متبقٍ {{ money(b.remaining) }}
                            </span>
                            <span v-else class="shrink-0 rounded-md bg-emerald-200 px-2 py-0.5 text-[11px] font-bold text-emerald-900">مسدَّد</span>
                        </div>
                    </div>
                    <p v-else class="py-10 text-center text-sm font-bold text-slate-500">لا حجوزات قادمة</p>
                </div>

                <!-- فواتير الوحدة -->
                <div class="overflow-hidden rounded-2xl border border-slate-300 bg-white shadow-sm">
                    <div class="flex items-center justify-between border-b border-slate-200 bg-slate-100 px-4 py-3">
                        <h2 class="flex items-center gap-1.5 font-extrabold text-slate-900">
                            <Receipt class="h-4 w-4" /> فواتير الوحدة
                        </h2>
                        <Link href="/admin/pos" class="text-xs font-bold text-blue-700 hover:underline">فاتورة جديدة</Link>
                    </div>

                    <table v-if="invoices.length" class="w-full text-sm">
                        <tbody>
                            <tr v-for="i in invoices" :key="i.id" class="border-b border-slate-200 last:border-0">
                                <td class="px-4 py-2.5">
                                    <div class="font-extrabold text-slate-900" dir="ltr">{{ i.number }}</div>
                                    <div class="text-[11px] font-medium text-slate-600">{{ i.client ?? 'بلا عميل' }} · {{ i.method }}</div>
                                </td>
                                <td class="px-4 py-2.5 text-xs font-bold text-slate-600" dir="ltr">{{ i.date }}</td>
                                <td class="px-4 py-2.5 text-left">
                                    <span class="font-extrabold" :class="i.type === 'return' ? 'text-red-700' : 'text-slate-900'" dir="ltr">
                                        {{ i.type === 'return' ? '−' : '' }}{{ money(i.total) }}
                                    </span>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                    <p v-else class="py-10 text-center text-sm font-bold text-slate-500">لا فواتير على هذه الوحدة</p>
                </div>
            </div>

            <p v-if="unit.notes" class="rounded-2xl border border-amber-300 bg-amber-100 px-4 py-3 text-sm font-bold text-amber-900">{{ unit.notes }}</p>
        </div>
    </AppLayout>
</template>
