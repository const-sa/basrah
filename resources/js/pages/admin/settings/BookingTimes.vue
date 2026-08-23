<script setup lang="ts">
import SettingsTabs from '@/components/SettingsTabs.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatTime12 } from '@/lib/dates';
import { type BreadcrumbItem } from '@/types';
import { Head, router, useForm } from '@inertiajs/vue3';
import { Clock, LogIn, LogOut, Moon, RotateCcw, Save, Sun } from 'lucide-vue-next';
import { computed } from 'vue';

interface PeriodRow {
    key: string;
    label: string;
    start: string;
    end: string;
    overnight: boolean;
    default_start: string;
    default_end: string;
}

const props = defineProps<{
    periods: PeriodRow[];
    stay: { check_in_time: string; check_out_time: string; max_nights: number };
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'لوحة التحكم', href: '/admin' },
    { title: 'أوقات الحجز', href: '/admin/settings/booking-times' },
];

const form = useForm({
    periods: props.periods.map((p) => ({ key: p.key, start: p.start, end: p.end })),
    check_in_time: props.stay.check_in_time,
    check_out_time: props.stay.check_out_time,
    max_nights: props.stay.max_nights,
});

const labelOf = (key: string) => props.periods.find((p) => p.key === key)?.label ?? key;
const defaultsOf = (key: string) => props.periods.find((p) => p.key === key);

/**
 * Whether a period crosses midnight, worked out here the same way the server
 * does, so the badge updates as the hours are typed instead of after a save.
 */
const crossesMidnight = (row: { start: string; end: string }) => !!row.start && !!row.end && row.end <= row.start;

const changed = computed(() =>
    form.periods.some((row) => {
        const d = defaultsOf(row.key);

        return !d || row.start !== d.default_start || row.end !== d.default_end;
    }),
);

const submit = () => form.post('/admin/settings/booking-times', { preserveScroll: true });

const reset = () => {
    if (confirm('إعادة كل أوقات الحجز إلى الافتراضي؟')) {
        router.post('/admin/settings/booking-times/reset', {}, { preserveScroll: true });
    }
};
</script>

<template>
    <Head title="أوقات الحجز" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="min-h-full space-y-5 bg-slate-100 p-5">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <SettingsTabs />
                <div class="text-start">
                    <h1 class="text-2xl font-extrabold text-slate-900">أوقات الحجز</h1>
                    <p class="mt-1 text-sm font-medium text-slate-600">فترات اليوم وأوقات إقامة الشاليه</p>
                </div>
            </div>

            <!-- هذه الأوقات تبني مدى كل حجز، وعليها يُفحص التعارض -->
            <div class="rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-xs font-medium leading-relaxed text-amber-800">
                هذه الأوقات ليست وصفًا فحسب: منها يُبنى مدى كل حجز، وعليه يُكشف التعارض بين الحجوزات.
                تعديلها لا يغيّر الحجوزات القائمة، ويسري على ما يُنشأ بعده.
            </div>

            <form @submit.prevent="submit" class="space-y-5">
                <!-- فترات اليوم -->
                <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                    <h2 class="mb-1 flex items-center gap-1.5 text-sm font-extrabold text-slate-800">
                        <Sun class="h-4 w-4 text-amber-500" /> فترات اليوم
                    </h2>
                    <p class="mb-4 text-[11px] font-medium text-slate-500">
                        تُستعمل في حجوزات القاعات، وفي حجز الشاليه بالفترة. الفترة التي تنتهي قبل بدايتها تعبر منتصف الليل.
                    </p>

                    <div class="space-y-3">
                        <div
                            v-for="row in form.periods"
                            :key="row.key"
                            class="grid items-end gap-3 rounded-xl border border-slate-100 bg-slate-50/60 p-3 sm:grid-cols-[1fr_auto_auto]"
                        >
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="text-sm font-extrabold text-slate-800">{{ labelOf(row.key) }}</span>
                                <!-- القراءة بنظام 12 ساعة كما تظهر للموظف في الشاشات -->
                                <span class="rounded bg-white px-1.5 py-0.5 text-[11px] font-bold text-slate-600 ring-1 ring-slate-200">
                                    {{ formatTime12(row.start) }} – {{ formatTime12(row.end) }}
                                </span>
                                <span
                                    v-if="crossesMidnight(row)"
                                    class="inline-flex items-center gap-1 rounded bg-indigo-100 px-1.5 py-0.5 text-[10px] font-bold text-indigo-700"
                                >
                                    <Moon class="h-3 w-3" /> يمتد بعد منتصف الليل
                                </span>
                            </div>

                            <div>
                                <label class="mb-1 block text-[11px] font-bold text-slate-600">من</label>
                                <input v-model="row.start" type="time" dir="ltr" class="w-32 rounded-xl border border-slate-200 px-3 py-2 text-sm focus:border-blue-400 focus:outline-none focus:ring-2 focus:ring-blue-100" />
                            </div>

                            <div>
                                <label class="mb-1 block text-[11px] font-bold text-slate-600">إلى</label>
                                <input v-model="row.end" type="time" dir="ltr" class="w-32 rounded-xl border border-slate-200 px-3 py-2 text-sm focus:border-blue-400 focus:outline-none focus:ring-2 focus:ring-blue-100" />
                            </div>
                        </div>
                    </div>

                    <p v-if="form.errors.periods" class="mt-2 text-xs text-red-500">{{ form.errors.periods }}</p>
                </div>

                <!-- إقامة الشاليه -->
                <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                    <h2 class="mb-1 flex items-center gap-1.5 text-sm font-extrabold text-slate-800">
                        <Clock class="h-4 w-4 text-teal-500" /> إقامة الشاليه
                    </h2>
                    <p class="mb-4 text-[11px] font-medium text-slate-500">
                        تُستعمل في الحجز بالليالي — وتترك مهلة التنظيف بين إقامتين.
                    </p>

                    <div class="grid gap-3 sm:grid-cols-3">
                        <div>
                            <label class="mb-1 flex items-center gap-1 text-[11px] font-bold text-slate-600">
                                <LogIn class="h-3.5 w-3.5 text-emerald-500" /> وقت الدخول <span class="font-medium text-slate-400">({{ formatTime12(form.check_in_time) }})</span>
                            </label>
                            <input v-model="form.check_in_time" type="time" dir="ltr" class="w-full rounded-xl border border-slate-200 px-3 py-2 text-sm focus:border-blue-400 focus:outline-none focus:ring-2 focus:ring-blue-100" />
                            <p v-if="form.errors.check_in_time" class="mt-1 text-xs text-red-500">{{ form.errors.check_in_time }}</p>
                        </div>
                        <div>
                            <label class="mb-1 flex items-center gap-1 text-[11px] font-bold text-slate-600">
                                <LogOut class="h-3.5 w-3.5 text-rose-500" /> وقت الخروج <span class="font-medium text-slate-400">({{ formatTime12(form.check_out_time) }})</span>
                            </label>
                            <input v-model="form.check_out_time" type="time" dir="ltr" class="w-full rounded-xl border border-slate-200 px-3 py-2 text-sm focus:border-blue-400 focus:outline-none focus:ring-2 focus:ring-blue-100" />
                            <p v-if="form.errors.check_out_time" class="mt-1 text-xs text-red-500">{{ form.errors.check_out_time }}</p>
                        </div>
                        <div>
                            <label class="mb-1 block text-[11px] font-bold text-slate-600">أقصى عدد ليالٍ</label>
                            <input v-model.number="form.max_nights" type="number" min="1" max="365" dir="ltr" class="w-full rounded-xl border border-slate-200 px-3 py-2 text-sm focus:border-blue-400 focus:outline-none focus:ring-2 focus:ring-blue-100" />
                            <p class="mt-1 text-[11px] font-medium text-slate-500">حارس خطأ إدخال لا سياسة تسعير.</p>
                            <p v-if="form.errors.max_nights" class="mt-1 text-xs text-red-500">{{ form.errors.max_nights }}</p>
                        </div>
                    </div>
                </div>

                <div class="flex flex-wrap items-center gap-2">
                    <button type="submit" :disabled="form.processing" class="inline-flex items-center gap-1.5 rounded-md bg-blue-600 px-5 py-2.5 text-sm font-bold text-white shadow-sm transition hover:bg-blue-700 disabled:opacity-60">
                        <Save class="h-4 w-4" /> حفظ الأوقات
                    </button>
                    <button v-if="changed" type="button" @click="reset" class="inline-flex items-center gap-1.5 rounded-xl border border-slate-200 bg-white px-4 py-2.5 text-sm font-bold text-slate-600 transition hover:bg-slate-50">
                        <RotateCcw class="h-4 w-4" /> إعادة الافتراضي
                    </button>
                </div>
            </form>
        </div>
    </AppLayout>
</template>
