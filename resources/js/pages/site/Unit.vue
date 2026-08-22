<script setup lang="ts">
import SiteLayout, { type SiteOrg } from '@/layouts/SiteLayout.vue';
import { Head, Link } from '@inertiajs/vue3';
import { ArrowRight, Building2, CalendarPlus, Home as HomeIcon, Users } from 'lucide-vue-next';

interface Section { id: number; name: string; facilities: string[] }
interface PriceRow { period: string; label: string; weekday_price: number; weekend_price: number }

defineProps<{
    org: SiteOrg;
    unit: {
        id: number; name: string; type: 'hall' | 'chalet';
        capacity: number | null; logo_url: string | null;
        description: string | null;
        allows_whole: boolean; allows_sections: boolean;
        sections: Section[];
    };
    isStay: boolean;
    prices: PriceRow[];
    periods: { key: string; label: string; time: string }[];
}>();

const money = (n: number) => new Intl.NumberFormat('ar-SA-u-nu-latn', { maximumFractionDigits: 2 }).format(n ?? 0);
</script>

<template>
    <Head :title="unit.name" />

    <SiteLayout :org="org">
        <div class="mx-auto max-w-5xl space-y-5 px-4 py-8">
            <Link href="/" class="inline-flex items-center gap-1 text-sm font-bold text-slate-500 hover:text-slate-800">
                <ArrowRight class="h-4 w-4" /> كل الوحدات
            </Link>

            <!-- الترويسة -->
            <div class="flex flex-wrap items-center gap-4 rounded-2xl border border-slate-200 bg-white p-5">
                <div class="flex h-24 w-24 shrink-0 items-center justify-center rounded-xl bg-slate-100">
                    <img v-if="unit.logo_url" :src="unit.logo_url" :alt="unit.name" class="max-h-20 w-auto object-contain p-1" />
                    <component :is="isStay ? HomeIcon : Building2" v-else class="h-10 w-10 text-slate-300" />
                </div>
                <div class="flex-1">
                    <h1 class="text-2xl font-extrabold text-slate-900">{{ unit.name }}</h1>
                    <div class="mt-2 flex flex-wrap gap-2 text-xs font-bold text-slate-600">
                        <span class="rounded-md bg-slate-100 px-2 py-1">{{ isStay ? 'شاليه — يُحجز بالليالي' : 'قاعة مناسبات' }}</span>
                        <span v-if="unit.capacity" class="inline-flex items-center gap-1 rounded-md bg-slate-100 px-2 py-1">
                            <Users class="h-3.5 w-3.5" /> تتسع لـ {{ unit.capacity }}
                        </span>
                        <span v-if="unit.allows_sections" class="rounded-md bg-slate-100 px-2 py-1">يمكن حجز قسم منفرد</span>
                    </div>
                </div>
                <Link
                    :href="`/book/${unit.id}`"
                    class="inline-flex items-center gap-1.5 rounded-lg bg-emerald-600 px-5 py-3 text-sm font-extrabold text-white hover:bg-emerald-700"
                >
                    <CalendarPlus class="h-4 w-4" /> احجز الآن
                </Link>
            </div>

            <div v-if="unit.description" class="rounded-2xl border border-slate-200 bg-white p-5">
                <h2 class="mb-2 text-lg font-extrabold text-slate-900">عن الوحدة</h2>
                <p class="whitespace-pre-wrap text-sm leading-7 text-slate-700">{{ unit.description }}</p>
            </div>

            <!-- الأقسام والمرافق -->
            <div v-if="unit.sections.length" class="rounded-2xl border border-slate-200 bg-white p-5">
                <h2 class="mb-3 text-lg font-extrabold text-slate-900">الأقسام والمرافق</h2>
                <div class="grid gap-3 sm:grid-cols-2">
                    <div v-for="s in unit.sections" :key="s.id" class="rounded-xl border border-slate-200 p-3">
                        <div class="font-extrabold text-slate-800">{{ s.name }}</div>
                        <div v-if="s.facilities.length" class="mt-1.5 flex flex-wrap gap-1">
                            <span v-for="f in s.facilities" :key="f" class="rounded-md bg-sky-50 px-2 py-0.5 text-[11px] font-bold text-sky-700">
                                {{ f }}
                            </span>
                        </div>
                        <p v-else class="mt-1 text-xs text-slate-400">لا مرافق مسجّلة</p>
                    </div>
                </div>
            </div>

            <!-- الأسعار -->
            <div class="rounded-2xl border border-slate-200 bg-white p-5">
                <h2 class="mb-1 text-lg font-extrabold text-slate-900">الأسعار</h2>
                <p class="mb-3 text-xs font-medium text-slate-500">
                    الأسعار للوحدة كاملة. سعر النطاق المحدد وأي خدمات إضافية يظهر في صفحة الحجز قبل تأكيد الطلب.
                </p>

                <table v-if="prices.length" class="w-full overflow-hidden rounded-xl text-sm">
                    <thead class="bg-slate-100">
                        <tr>
                            <th class="px-4 py-2.5 text-right text-xs font-extrabold text-[#1e3a8a]">{{ isStay ? 'الوحدة' : 'الفترة' }}</th>
                            <th class="px-4 py-2.5 text-left text-xs font-extrabold text-[#1e3a8a]">أيام الأسبوع</th>
                            <th class="px-4 py-2.5 text-left text-xs font-extrabold text-[#1e3a8a]">نهاية الأسبوع</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="p in prices" :key="p.period" class="border-t border-slate-100">
                            <td class="px-4 py-2.5 font-bold text-slate-800">{{ p.label }}</td>
                            <td class="px-4 py-2.5 text-left font-extrabold text-slate-900" dir="ltr">{{ money(p.weekday_price) }}</td>
                            <td class="px-4 py-2.5 text-left font-extrabold text-slate-900" dir="ltr">{{ money(p.weekend_price) }}</td>
                        </tr>
                    </tbody>
                </table>
                <p v-else class="py-6 text-center text-sm text-slate-500">
                    الأسعار غير معلنة لهذه الوحدة — تواصل معنا لمعرفة السعر.
                </p>
            </div>

            <!-- الفترات -->
            <div v-if="!isStay && periods.length" class="rounded-2xl border border-slate-200 bg-white p-5">
                <h2 class="mb-3 text-lg font-extrabold text-slate-900">فترات الحجز</h2>
                <div class="grid gap-2 sm:grid-cols-3">
                    <div v-for="p in periods" :key="p.key" class="rounded-xl border border-slate-200 p-3 text-center">
                        <div class="font-extrabold text-slate-800">{{ p.label }}</div>
                        <div class="mt-0.5 text-xs text-slate-500" dir="ltr">{{ p.time }}</div>
                    </div>
                </div>
            </div>
        </div>
    </SiteLayout>
</template>
