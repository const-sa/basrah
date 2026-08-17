<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link } from '@inertiajs/vue3';
import { ArrowRight, Printer } from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';

const props = defineProps<{
    bond: {
        booking_id: number;
        reference: string;
        unit_name: string | null;
        unit_code: string | null;
        unit_logo_url: string | null;
        unit_type: string | null;
        issued_on: string | null;
        client_name: string | null;
        client_mobile: string | null;
        amount: number;
        amount_words: string;
        total_amount: number;
        remaining_amount: number;
        method_label: string;
        payment_type_label: string | null;
        event_name: string | null;
        booking_date: string;
        schedule_label: string;
        created_by: string | null;
        back_url: string;
    };
    issuer: {
        business_name: string;
        logo_url: string | null;
        phone: string | null;
        address: string | null;
        tax_number: string | null;
        manager_name: string | null;
        stamp_url: string | null;
    };
}>();

const money = (n: number) => new Intl.NumberFormat('ar-SA-u-nu-latn', { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(n ?? 0);

const breadcrumbs = computed<BreadcrumbItem[]>(() => [
    { title: 'لوحة التحكم', href: '/admin' },
    { title: props.bond.unit_type === 'chalet' ? 'حجوزات الشاليهات' : 'حجوزات القاعات', href: props.bond.back_url },
    { title: 'سند قبض', href: `/admin/bookings/${props.bond.booking_id}/bond` },
]);

// شعار الوحدة أولى من شعار المنشأة: السند يُسلَّم في القاعة التي حُجزت.
const logo = computed(() => props.bond.unit_logo_url ?? props.issuer.logo_url);

// تعذُّر تحميل الشعار (ملف محذوف أو رابط قديم) يُخفي الصورة بدل أن يترك
// أيقونة مكسورة على سند يُطبع ويُسلَّم للعميل.
const logoFailed = ref(false);

watch(logo, () => (logoFailed.value = false));

const print = () => window.print();
</script>

<template>
    <Head :title="`سند قبض ${bond.reference}`" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="min-h-full space-y-4 bg-slate-100 p-5 print:bg-white print:p-0">
            <div class="flex flex-wrap items-center justify-between gap-3 print:hidden">
                <div>
                    <h1 class="text-2xl font-extrabold text-slate-900">سند قبض</h1>
                    <p class="mt-1 text-sm font-medium text-slate-600">
                        حجز <span dir="ltr">{{ bond.reference }}</span> · {{ bond.client_name ?? 'بلا عميل' }}
                    </p>
                </div>
                <div class="flex gap-2">
                    <button type="button" @click="print" class="inline-flex items-center gap-1.5 rounded-md bg-blue-600 px-4 py-2 text-sm font-bold text-white shadow-sm hover:bg-blue-700">
                        <Printer class="h-4 w-4" /> طباعة
                    </button>
                    <Link :href="bond.back_url" class="inline-flex items-center gap-1.5 rounded-md border border-slate-200 bg-white px-4 py-2 text-sm font-bold text-slate-700 hover:bg-slate-50">
                        <ArrowRight class="h-4 w-4" /> رجوع
                    </Link>
                </div>
            </div>

            <!-- السند نفسه — ما يُطبع ويُسلَّم للعميل -->
            <div class="mx-auto max-w-3xl rounded-xl border border-slate-900 bg-white p-7 print:max-w-none print:rounded-none print:border print:p-4 print:shadow-none">
                <!-- ترويسة: اسم القاعة وشعارها -->
                <div class="mb-5 rounded-lg border border-slate-900 p-3">
                    <div class="flex items-center justify-between gap-4">
                        <div>
                            <h2 class="text-xl font-extrabold text-slate-900">{{ bond.unit_name ?? issuer.business_name }}</h2>
                            <p class="text-sm font-bold text-slate-700">
                                للاحتفالات والمناسبات
                                <span v-if="bond.unit_code" class="text-slate-600" dir="ltr">· {{ bond.unit_code }}</span>
                            </p>
                            <p v-if="issuer.address" class="text-xs font-medium text-slate-600">{{ issuer.address }}</p>
                        </div>
                        <img
                            v-if="logo && !logoFailed"
                            :src="logo"
                            :alt="bond.unit_name ?? 'الشعار'"
                            class="h-20 w-auto shrink-0 object-contain print:h-16"
                            @error="logoFailed = true"
                        />
                    </div>

                    <div class="mt-3 text-center">
                        <h3 class="text-lg font-extrabold text-slate-900">سند قبض</h3>
                        <p class="text-xs font-bold text-slate-600" dir="ltr">Receipt Voucher</p>
                        <p class="mt-1 text-sm font-bold text-slate-800">
                            رقم: <span dir="ltr">{{ bond.reference }}</span>
                        </p>
                    </div>

                    <div class="mt-2 text-sm font-medium text-slate-800">
                        <span class="font-extrabold">التاريخ:</span> <span dir="ltr">{{ bond.issued_on }}</span>
                    </div>
                </div>

                <!-- بنود السند: كل بند سطر عربي وما يقابله إنجليزيًا -->
                <div class="space-y-3 text-sm text-slate-800">
                    <div class="flex items-start justify-between gap-4">
                        <div>
                            <span class="font-extrabold">استلمنا من المكرم:</span> {{ bond.client_name ?? '—' }}
                            <span v-if="bond.client_mobile" class="text-slate-700" dir="ltr">({{ bond.client_mobile }})</span>
                        </div>
                        <div class="shrink-0 font-extrabold text-slate-600" dir="ltr">:Received From</div>
                    </div>

                    <div class="flex items-start justify-between gap-4">
                        <div><span class="font-extrabold">مبلغ وقدره:</span> {{ bond.amount_words }}</div>
                        <div class="shrink-0 font-extrabold text-slate-500" dir="ltr">:The Sum of</div>
                    </div>

                    <div class="flex items-start justify-between gap-4">
                        <div><span class="font-extrabold">مبلغ وقدره:</span> {{ money(bond.amount) }} ريال</div>
                        <div class="shrink-0 font-extrabold text-slate-500" dir="ltr">:The Sum of</div>
                    </div>

                    <div class="flex items-start justify-between gap-4">
                        <div>
                            <span class="font-extrabold">طريقة الدفع:</span> {{ bond.method_label }}
                            <span v-if="bond.payment_type_label" class="text-slate-700">— {{ bond.payment_type_label }}</span>
                        </div>
                        <div class="shrink-0 font-extrabold text-slate-600" dir="ltr">:Payment Method</div>
                    </div>

                    <div class="flex items-start justify-between gap-4">
                        <div>
                            <span class="font-extrabold">وذلك مقابل:</span>
                            <template v-if="bond.event_name">مناسبة {{ bond.event_name }}</template>
                            <template v-else>حجز {{ bond.unit_name }}</template>
                            يوم <span dir="ltr">{{ bond.booking_date }}</span> — {{ bond.schedule_label }}
                        </div>
                        <div class="shrink-0 font-extrabold text-slate-500" dir="ltr">:Paid For</div>
                    </div>

                    <!-- الإجمالي والمتبقي بيانٌ للعميل: السند إيصالُ ما قُبض، لا مطالبة -->
                    <div class="flex flex-wrap gap-4 rounded-lg bg-slate-50 px-3 py-2 text-sm print:bg-white">
                        <div><span class="font-extrabold">إجمالي الحجز:</span> {{ money(bond.total_amount) }} ريال</div>
                        <div :class="bond.remaining_amount > 0 ? 'text-red-700' : 'text-emerald-700'">
                            <span class="font-extrabold">المتبقي:</span> {{ money(bond.remaining_amount) }} ريال
                        </div>
                    </div>
                </div>

                <div class="my-5 border-b border-slate-900"></div>

                <!-- التواقيع -->
                <div class="grid grid-cols-4 gap-3 text-center text-sm">
                    <div>
                        <div class="font-extrabold text-slate-800">المستلم</div>
                        <div class="mt-2 text-slate-400">....................</div>
                    </div>
                    <div>
                        <div class="font-extrabold text-slate-800">الختم</div>
                        <img v-if="issuer.stamp_url" :src="issuer.stamp_url" alt="الختم" class="mx-auto mt-1 h-16 w-auto object-contain print:h-12" />
                        <div v-else class="mt-2 text-slate-400">....................</div>
                    </div>
                    <div>
                        <div class="font-extrabold text-slate-800">المدير</div>
                        <div class="mt-2" :class="issuer.manager_name ? 'font-bold text-slate-700' : 'text-slate-400'">
                            {{ issuer.manager_name ?? '....................' }}
                        </div>
                    </div>
                    <div>
                        <div class="font-extrabold text-slate-800">المستخدم</div>
                        <div class="mt-2 font-bold text-slate-700">{{ bond.created_by ?? '—' }}</div>
                    </div>
                </div>

                <div v-if="issuer.phone || issuer.tax_number" class="mt-5 border-t border-slate-200 pt-2 text-center text-[11px] font-medium text-slate-500">
                    <span v-if="issuer.phone" dir="ltr">{{ issuer.phone }}</span>
                    <span v-if="issuer.phone && issuer.tax_number"> · </span>
                    <span v-if="issuer.tax_number">الرقم الضريبي: <span dir="ltr">{{ issuer.tax_number }}</span></span>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
