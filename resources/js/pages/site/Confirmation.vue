<script setup lang="ts">
import SiteLayout, { type SiteOrg } from '@/layouts/SiteLayout.vue';
import { Head, Link } from '@inertiajs/vue3';
import { CheckCircle2, Home, Printer } from 'lucide-vue-next';

defineProps<{
    org: SiteOrg;
    booking: {
        reference: string; unit_name: string | null; is_stay: boolean;
        scope: string; booking_date: string; check_out_date: string | null;
        schedule: string; status_label: string;
        total_amount: number; tax_amount: number; deposit_amount: number;
        client_name: string | null;
    };
}>();

const money = (n: number) => new Intl.NumberFormat('ar-SA-u-nu-latn', { maximumFractionDigits: 2 }).format(n ?? 0);

// `window` غير متاح داخل قالب Vue، فتُغلَّف الطباعة في دالة.
const print = () => window.print();
</script>

<template>
    <Head :title="`طلب الحجز ${booking.reference}`" />

    <SiteLayout :org="org">
        <div class="mx-auto max-w-2xl px-4 py-10">
            <div class="rounded-2xl border border-emerald-200 bg-emerald-50 p-6 text-center print:border-slate-300 print:bg-white">
                <CheckCircle2 class="mx-auto h-12 w-12 text-emerald-600" />
                <h1 class="mt-3 text-2xl font-extrabold text-slate-900">تم استلام طلب حجزك</h1>
                <p class="mt-1 text-sm font-medium text-slate-600">
                    احتفظ برقم الحجز — تراجع به طلبك وتذكره عند التواصل معنا.
                </p>
                <div class="mt-4 inline-block rounded-xl border border-emerald-300 bg-white px-6 py-3">
                    <div class="text-[11px] font-bold text-slate-500">رقم الحجز</div>
                    <div class="text-2xl font-extrabold tracking-wider text-slate-900" dir="ltr">{{ booking.reference }}</div>
                </div>
            </div>

            <div class="mt-4 rounded-2xl border border-slate-200 bg-white p-5">
                <h2 class="mb-3 text-lg font-extrabold text-slate-900">تفاصيل الطلب</h2>
                <dl class="divide-y divide-slate-100 text-sm">
                    <div class="flex justify-between gap-3 py-2">
                        <dt class="text-slate-600">مقدّم الطلب</dt>
                        <dd class="font-bold text-slate-800">{{ booking.client_name ?? '—' }}</dd>
                    </div>
                    <div class="flex justify-between gap-3 py-2">
                        <dt class="text-slate-600">الوحدة</dt>
                        <dd class="font-bold text-slate-800">{{ booking.unit_name ?? '—' }}</dd>
                    </div>
                    <div class="flex justify-between gap-3 py-2">
                        <dt class="text-slate-600">النطاق</dt>
                        <dd class="font-bold text-slate-800">{{ booking.scope }}</dd>
                    </div>
                    <div class="flex justify-between gap-3 py-2">
                        <dt class="text-slate-600">{{ booking.is_stay ? 'الدخول' : 'التاريخ' }}</dt>
                        <dd class="font-bold text-slate-800" dir="ltr">{{ booking.booking_date }}</dd>
                    </div>
                    <div v-if="booking.check_out_date" class="flex justify-between gap-3 py-2">
                        <dt class="text-slate-600">الخروج</dt>
                        <dd class="font-bold text-slate-800" dir="ltr">{{ booking.check_out_date }}</dd>
                    </div>
                    <div class="flex justify-between gap-3 py-2">
                        <dt class="text-slate-600">المدة / الفترة</dt>
                        <dd class="font-bold text-slate-800">{{ booking.schedule }}</dd>
                    </div>
                    <div class="flex justify-between gap-3 py-2">
                        <dt class="text-slate-600">الحالة</dt>
                        <dd class="rounded-md bg-orange-100 px-2 py-0.5 text-xs font-bold text-orange-700">{{ booking.status_label }}</dd>
                    </div>
                    <div class="flex justify-between gap-3 py-2">
                        <dt class="text-slate-600">{{ booking.tax_amount > 0 ? 'الإجمالي شامل الضريبة' : 'الإجمالي' }}</dt>
                        <dd class="font-extrabold text-slate-900" dir="ltr">{{ money(booking.total_amount) }} ريال</dd>
                    </div>
                    <div v-if="booking.deposit_amount > 0" class="flex justify-between gap-3 py-2">
                        <dt class="text-slate-600">العربون المطلوب</dt>
                        <dd class="font-extrabold text-emerald-700" dir="ltr">{{ money(booking.deposit_amount) }} ريال</dd>
                    </div>
                </dl>

                <p class="mt-4 rounded-xl bg-slate-50 p-3 text-xs font-medium leading-6 text-slate-600">
                    الموعد محجوز لك مؤقتًا بانتظار سداد العربون. ستتواصل معك الإدارة لاستكمال السداد وإصدار العقد.
                    وللاستفسار العاجل تواصل معنا على
                    <span v-if="org.phone" dir="ltr" class="font-bold">{{ org.phone }}</span>.
                </p>
            </div>

            <div class="mt-4 flex flex-wrap justify-center gap-2 print:hidden">
                <button
                    type="button" @click="print"
                    class="inline-flex items-center gap-1.5 rounded-lg border border-slate-300 bg-white px-4 py-2.5 text-sm font-bold text-slate-700 hover:bg-slate-50"
                >
                    <Printer class="h-4 w-4" /> طباعة
                </button>
                <Link href="/" class="inline-flex items-center gap-1.5 rounded-lg bg-slate-900 px-4 py-2.5 text-sm font-bold text-white hover:bg-slate-800">
                    <Home class="h-4 w-4" /> الصفحة الرئيسية
                </Link>
            </div>
        </div>
    </SiteLayout>
</template>
