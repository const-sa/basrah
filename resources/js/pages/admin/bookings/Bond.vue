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
        issued_on_hijri: string;
        client_name: string | null;
        client_mobile: string | null;
        amount: number;
        amount_riyals: number;
        amount_halalas: number;
        amount_words: string;
        total_amount: number;
        remaining_amount: number;
        method_label: string;
        method_kind: 'cash' | 'bank' | null;
        payment_reference: string | null;
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
        whatsapp: string | null;
        email: string | null;
        address: string | null;
        tax_number: string | null;
        manager_name: string | null;
        manager_signature_url: string | null;
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

/** الهللات خانتان دائمًا — «50» لا «5» تحت عنوان الهللة. */
const halalas = computed(() => String(props.bond.amount_halalas).padStart(2, '0'));

/** سطر التواصل: الهاتف والواتساب معًا بلا تكرار إن كانا رقمًا واحدًا. */
const phones = computed(() =>
    [props.issuer.phone, props.issuer.whatsapp]
        .filter((p): p is string => !!p)
        .filter((p, i, all) => all.indexOf(p) === i),
);

/** ما قُبض المبلغ مقابله — سطر «وذلك قيمة» في الدفتر المطبوع. */
const paidFor = computed(() => {
    const what = props.bond.event_name
        ? `مناسبة ${props.bond.event_name}`
        : `حجز ${props.bond.unit_name ?? ''}`.trim();
    const kind = props.bond.payment_type_label ? ` (${props.bond.payment_type_label})` : '';

    return `${what}${kind} بتاريخ ${props.bond.booking_date} — ${props.bond.schedule_label}`;
});

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

            <!--
                السند على هيئة الدفتر المطبوع: ترويسة خارج الإطار، ثم إطارٌ يحمل
                خانة المبلغ والعنوان والتاريخ فوق سطورٍ منقّطة، ثم العنوان تذييلًا.
                الفرق أن السطور تخرج مملوءة من بيانات الحجز لا فارغةً تُكتب باليد.
            -->
            <div class="voucher mx-auto max-w-4xl bg-white p-6 text-black print:max-w-none print:p-0">
                <!-- الترويسة: الهوية يمينًا، الشعار وسطًا، وسائل التواصل يسارًا -->
                <div class="mb-2 grid grid-cols-[1fr_auto_1fr] items-center gap-4">
                    <div class="text-right">
                        <div class="text-xl font-extrabold leading-tight">{{ bond.unit_name ?? issuer.business_name }}</div>
                        <div v-if="bond.unit_name" class="text-xs font-bold text-neutral-700">{{ issuer.business_name }}</div>
                        <div v-if="phones.length" class="mt-0.5 text-sm font-bold" dir="ltr">{{ phones.join(' - ') }}</div>
                    </div>

                    <img
                        v-if="logo && !logoFailed"
                        :src="logo"
                        :alt="bond.unit_name ?? 'الشعار'"
                        class="h-20 w-auto object-contain print:h-16"
                        @error="logoFailed = true"
                    />
                    <div v-else class="h-20 w-20 print:h-16"></div>

                    <div class="text-left text-sm font-bold leading-tight" dir="ltr">
                        <div v-if="issuer.email">{{ issuer.email }}</div>
                        <div v-if="issuer.tax_number" class="text-xs">VAT {{ issuer.tax_number }}</div>
                        <div v-if="bond.unit_code" class="text-xs">{{ bond.unit_code }}</div>
                    </div>
                </div>

                <!-- الإطار — جسم السند كما في الدفتر -->
                <div class="border-2 border-black p-4">
                    <!-- الصف الأول: خانة المبلغ · العنوان · التاريخ -->
                    <div class="grid grid-cols-[1fr_auto_1fr] items-start gap-4 border-b border-black pb-3">
                        <div class="flex items-end gap-2">
                            <div>
                                <div class="mb-0.5 text-center text-sm font-extrabold">ريال . <span dir="ltr">S.R</span></div>
                                <div class="flex h-9 w-40 items-center justify-center border-2 border-black text-lg font-extrabold" dir="ltr">
                                    {{ bond.amount_riyals.toLocaleString('en-US') }}
                                </div>
                            </div>
                            <div>
                                <div class="mb-0.5 text-center text-sm font-extrabold">هـ <span dir="ltr">H.</span></div>
                                <div class="flex h-9 w-12 items-center justify-center border-2 border-black text-lg font-extrabold" dir="ltr">
                                    {{ halalas }}
                                </div>
                            </div>
                        </div>

                        <div class="text-center">
                            <div class="inline-block border-b-2 border-black px-3 pb-0.5 text-2xl font-extrabold tracking-[0.3em]">سند قبض</div>
                            <div class="mt-1 text-sm font-bold italic" dir="ltr">Receipt Voucher</div>
                            <div class="mt-1 text-xs font-bold" dir="ltr">No. {{ bond.reference }}</div>
                        </div>

                        <div class="space-y-1.5 text-sm font-extrabold">
                            <div class="flex items-center gap-2">
                                <span class="w-14 shrink-0">التاريخ</span>
                                <span class="flex-1 border-b border-dotted border-black pb-0.5 text-center" dir="ltr">{{ bond.issued_on_hijri || '—' }}</span>
                            </div>
                            <div class="flex items-center gap-2">
                                <span class="w-14 shrink-0">الموافق</span>
                                <span class="flex-1 border-b border-dotted border-black pb-0.5 text-center" dir="ltr">{{ bond.issued_on ?? '—' }}</span>
                            </div>
                        </div>
                    </div>

                    <!-- سطور السند -->
                    <div class="space-y-3.5 pt-4 text-sm">
                        <div class="flex items-end gap-2">
                            <span class="shrink-0 font-extrabold">استلمنا من المكرم /</span>
                            <span class="flex-1 border-b border-dotted border-black pb-0.5 text-center font-bold">
                                {{ bond.client_name ?? '—' }}
                                <span v-if="bond.client_mobile" class="text-neutral-700" dir="ltr">{{ bond.client_mobile }}</span>
                            </span>
                            <span class="shrink-0 font-bold italic" dir="ltr">Received From</span>
                        </div>

                        <div class="flex items-end gap-2">
                            <span class="shrink-0 font-extrabold">مبلغ وقدره</span>
                            <span class="flex-1 border-b border-dotted border-black pb-0.5 text-center font-bold">{{ bond.amount_words }}</span>
                            <span class="shrink-0 font-bold italic" dir="ltr">The Sum Of</span>
                        </div>

                        <!-- صف الطريقة: المربّع المعلَّم هو ما قُبض به فعلًا -->
                        <div class="flex items-end gap-2">
                            <span class="flex shrink-0 items-center gap-1.5 font-extrabold">
                                <span class="flex h-4 w-4 items-center justify-center border border-black text-[11px] leading-none">{{ bond.method_kind === 'cash' ? '✕' : '' }}</span>
                                نقدًا
                            </span>
                            <span class="flex shrink-0 items-center gap-1.5 font-extrabold">
                                <span class="flex h-4 w-4 items-center justify-center border border-black text-[11px] leading-none">{{ bond.method_kind === 'bank' ? '✕' : '' }}</span>
                                شيك / حوالة رقم
                            </span>
                            <span class="w-24 shrink-0 border-b border-dotted border-black pb-0.5 text-center font-bold" dir="ltr">{{ bond.payment_reference ?? '' }}</span>
                            <span class="shrink-0 font-extrabold">على بنك</span>
                            <span class="flex-1 border-b border-dotted border-black pb-0.5 text-center font-bold">{{ bond.method_label }}</span>
                            <span class="shrink-0 font-bold italic" dir="ltr">Cash / Cheque No. / Bank</span>
                        </div>

                        <div class="flex items-end gap-2">
                            <span class="shrink-0 font-extrabold">وذلك قيمة</span>
                            <span class="flex-1 border-b border-dotted border-black pb-0.5 text-center font-bold">{{ paidFor }}</span>
                            <span class="shrink-0 font-bold italic" dir="ltr">For</span>
                        </div>

                        <!--
                            السطر الذي يمتدّ به الكاتب في الدفتر يحمل هنا إجمالي
                            الحجز والمتبقي: السند إيصالُ ما قُبض، وذكر المتبقي
                            بيانٌ للعميل لا مطالبةً في هذه الورقة.
                        -->
                        <div class="flex items-end gap-2">
                            <span class="flex-1 border-b border-dotted border-black pb-0.5 text-center font-bold">
                                إجمالي الحجز {{ money(bond.total_amount) }} ريال — المتبقي {{ money(bond.remaining_amount) }} ريال
                            </span>
                        </div>
                    </div>

                    <!-- التواقيع -->
                    <div class="mt-6 grid grid-cols-3 gap-4 text-center text-sm">
                        <div>
                            <div class="font-extrabold">المستلم</div>
                            <div class="text-xs font-bold italic" dir="ltr">Received By</div>
                            <div class="mx-auto mt-8 w-4/5 border-t border-dotted border-black pt-1 text-[11px] font-bold text-neutral-700">
                                {{ bond.created_by ?? '' }}
                            </div>
                        </div>
                        <div>
                            <div class="font-extrabold">الختم</div>
                            <div class="text-xs font-bold italic" dir="ltr">Seal</div>
                            <img v-if="issuer.stamp_url" :src="issuer.stamp_url" alt="الختم" class="mx-auto mt-1 h-16 w-auto object-contain print:h-14" />
                            <div v-else class="mx-auto mt-8 w-4/5 border-t border-dotted border-black pt-1 text-[11px]">&nbsp;</div>
                        </div>
                        <div>
                            <div class="font-extrabold">المدير</div>
                            <div class="text-xs font-bold italic" dir="ltr">Manager</div>
                            <img
                                v-if="issuer.manager_signature_url"
                                :src="issuer.manager_signature_url"
                                alt="التوقيع"
                                class="mx-auto mt-1 h-16 w-auto object-contain print:h-14"
                            />
                            <div
                                class="mx-auto w-4/5 border-t border-dotted border-black pt-1 text-[11px] font-bold text-neutral-700"
                                :class="issuer.manager_signature_url ? 'mt-1' : 'mt-8'"
                            >
                                {{ issuer.manager_name ?? '' }}
                            </div>
                        </div>
                    </div>
                </div>

                <!-- التذييل: العنوان تحت الإطار كما في الدفتر -->
                <div v-if="issuer.address" class="mt-2 text-center text-sm font-extrabold">{{ issuer.address }}</div>
            </div>
        </div>
    </AppLayout>
</template>

<style scoped>
/*
    الورقة عرضية: السند أوسع من طوله، والطباعة الطولية تدفع عمود الإنجليزية
    خارج السطر. واللون يُثبَّت أسود لأن الرمادي يخرج باهتًا على الورق.
*/
@media print {
    @page {
        size: A4 landscape;
        margin: 10mm;
    }

    .voucher {
        color: #000;
        font-size: 12px;
    }
}
</style>
