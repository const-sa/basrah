<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link } from '@inertiajs/vue3';
import { ArrowRight, Printer } from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';

interface InvoiceLine {
    name: string;
    note: string | null;
    quantity: number;
    amount: number;
}

const props = defineProps<{
    invoice: {
        booking_id: number;
        number: string;
        issued_on: string | null;
        issued_at: string | null;
        is_taxable: boolean;
        tax_rate: number;
        net_amount: number;
        tax_amount: number;
        total_amount: number;
        paid_amount: number;
        remaining_amount: number;
        payment_status: string;
        lines: InvoiceLine[];
        methods: { label: string; amount: number }[];
        unit_name: string | null;
        unit_code: string | null;
        unit_logo_url: string | null;
        unit_type: string | null;
        client_name: string | null;
        client_mobile: string | null;
        client_tax_number: string | null;
        client_address: string | null;
        event_name: string | null;
        sections: string | null;
        booking_date: string;
        last_day_date: string;
        duration_label: string;
        schedule_label: string;
        guests_count: number | null;
        created_by: string | null;
        back_url: string;
    };
    issuer: {
        business_name: string;
        logo_url: string | null;
        phone: string | null;
        address: string | null;
        email: string | null;
        tax_number: string | null;
        commercial_register: string | null;
        stamp_url: string | null;
        qr: string | null;
    };
}>();

const money = (n: number) =>
    new Intl.NumberFormat('ar-SA-u-nu-latn', { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(n ?? 0);

const breadcrumbs = computed<BreadcrumbItem[]>(() => [
    { title: 'لوحة التحكم', href: '/admin' },
    { title: props.invoice.unit_type === 'chalet' ? 'حجوزات الشاليهات' : 'حجوزات القاعات', href: props.invoice.back_url },
    { title: 'فاتورة الحجز', href: `/admin/bookings/${props.invoice.booking_id}/invoice` },
]);

// شعار الوحدة أولى من شعار المنشأة: الفاتورة تُسلَّم في القاعة التي حُجزت.
const logo = computed(() => props.invoice.unit_logo_url ?? props.issuer.logo_url);

// تعذُّر تحميل الشعار يُخفي الصورة بدل أيقونة مكسورة على ورقة تُسلَّم للعميل.
const logoFailed = ref(false);
watch(logo, () => (logoFailed.value = false));

// المناسبة الممتدة يُذكر آخر يومها، واليوم الواحد يُكتفى بتاريخه.
const spansMoreThanOneDay = computed(() => props.invoice.last_day_date !== props.invoice.booking_date);

const statusTone = computed(
    () =>
        ({
            'مسدّدة': 'bg-emerald-100 text-emerald-800',
            'مسدّدة جزئيًا': 'bg-amber-100 text-amber-800',
            'غير مسدّدة': 'bg-red-100 text-red-800',
        })[props.invoice.payment_status] ?? 'bg-slate-200 text-slate-800',
);

const print = () => window.print();
</script>

<template>
    <Head :title="`فاتورة الحجز ${invoice.number}`" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="min-h-full space-y-4 bg-slate-100 p-5 print:bg-white print:p-0">
            <!-- شريط الأدوات — لا يُطبع -->
            <div class="flex flex-wrap items-center justify-between gap-3 print:hidden">
                <div>
                    <h1 class="text-2xl font-extrabold text-slate-900">فاتورة ضريبية مبسّطة</h1>
                    <p class="mt-1 text-sm font-medium text-slate-700">
                        حجز <span dir="ltr">{{ invoice.number }}</span> · {{ invoice.client_name ?? 'بلا عميل' }}
                    </p>
                </div>
                <div class="flex flex-wrap gap-2">
                    <button
                        type="button"
                        @click="print"
                        class="inline-flex items-center gap-1.5 rounded-md bg-blue-600 px-4 py-2 text-sm font-bold text-white shadow-sm hover:bg-blue-700"
                    >
                        <Printer class="h-4 w-4" /> طباعة
                    </button>
                    <Link
                        :href="`/admin/bookings/${invoice.booking_id}/bond`"
                        class="inline-flex items-center gap-1.5 rounded-md border border-slate-300 bg-white px-4 py-2 text-sm font-bold text-slate-800 hover:bg-slate-50"
                    >
                        سند القبض
                    </Link>
                    <Link
                        :href="invoice.back_url"
                        class="inline-flex items-center gap-1.5 rounded-md border border-slate-300 bg-white px-4 py-2 text-sm font-bold text-slate-800 hover:bg-slate-50"
                    >
                        <ArrowRight class="h-4 w-4" /> رجوع
                    </Link>
                </div>
            </div>

            <!-- ورقة الفاتورة — ما يُطبع ويُسلَّم -->
            <div class="mx-auto max-w-4xl space-y-5 rounded-xl border border-slate-300 bg-white px-8 py-6 shadow-sm print:max-w-none print:rounded-none print:border-0 print:px-0 print:py-0 print:shadow-none">
                <!-- الترويسة: هوية القاعة يمينًا وبيانات الفاتورة يسارًا -->
                <header class="grid gap-6 border-b-2 border-slate-800 pb-4 sm:grid-cols-2">
                    <div>
                        <img
                            v-if="logo && !logoFailed"
                            :src="logo"
                            :alt="invoice.unit_name ?? 'الشعار'"
                            class="mb-3 h-20 w-auto object-contain print:h-16"
                            @error="logoFailed = true"
                        />
                        <h2 class="text-xl font-extrabold text-slate-900">{{ invoice.unit_name ?? issuer.business_name }}</h2>
                        <p v-if="invoice.unit_name" class="text-sm font-bold text-slate-700">{{ issuer.business_name }}</p>
                        <div class="mt-1 space-y-0.5 text-[13px] font-medium text-slate-700">
                            <p v-if="issuer.address">{{ issuer.address }}</p>
                            <p v-if="issuer.phone">هاتف: <span dir="ltr">{{ issuer.phone }}</span></p>
                            <p v-if="issuer.tax_number">الرقم الضريبي: <span dir="ltr">{{ issuer.tax_number }}</span></p>
                            <p v-if="issuer.commercial_register">س.ت: <span dir="ltr">{{ issuer.commercial_register }}</span></p>
                        </div>
                    </div>

                    <div class="text-left">
                        <div class="mb-2 inline-block rounded-lg bg-slate-800 px-3 py-1 text-sm font-extrabold text-white">
                            {{ invoice.is_taxable ? 'فاتورة ضريبية مبسّطة' : 'فاتورة حجز' }}
                        </div>
                        <dl class="space-y-1 text-[13px]">
                            <div class="flex justify-between gap-3">
                                <dt class="font-bold text-slate-700">رقم الفاتورة</dt>
                                <dd class="font-extrabold text-slate-900" dir="ltr">{{ invoice.number }}</dd>
                            </div>
                            <div class="flex justify-between gap-3">
                                <dt class="font-bold text-slate-700">التاريخ</dt>
                                <dd class="font-bold text-slate-800" dir="ltr">{{ invoice.issued_on }} {{ invoice.issued_at }}</dd>
                            </div>
                            <div class="flex justify-between gap-3">
                                <dt class="font-bold text-slate-700">العميل</dt>
                                <dd class="font-extrabold text-slate-900">{{ invoice.client_name ?? 'عميل نقدي' }}</dd>
                            </div>
                            <div v-if="invoice.client_mobile" class="flex justify-between gap-3">
                                <dt class="font-bold text-slate-700">الجوال</dt>
                                <dd class="font-bold text-slate-800" dir="ltr">{{ invoice.client_mobile }}</dd>
                            </div>
                            <div v-if="invoice.client_tax_number" class="flex justify-between gap-3">
                                <dt class="font-bold text-slate-700">الرقم الضريبي للعميل</dt>
                                <dd class="font-bold text-slate-800" dir="ltr">{{ invoice.client_tax_number }}</dd>
                            </div>
                            <div v-if="invoice.client_address" class="flex justify-between gap-3">
                                <dt class="font-bold text-slate-700">العنوان</dt>
                                <dd class="font-bold text-slate-800">{{ invoice.client_address }}</dd>
                            </div>
                            <div class="flex justify-between gap-3">
                                <dt class="font-bold text-slate-700">حالة السداد</dt>
                                <dd>
                                    <span class="rounded px-2 py-0.5 text-xs font-extrabold" :class="statusTone">
                                        {{ invoice.payment_status }}
                                    </span>
                                </dd>
                            </div>
                        </dl>
                    </div>
                </header>

                <!-- بيانات الحجز — ما تُحرَّر الفاتورة عليه -->
                <table class="w-full border border-slate-300 text-[13px]">
                    <thead class="bg-slate-100">
                        <tr>
                            <th class="border border-slate-300 px-2 py-2 font-extrabold text-slate-800">رقم الحجز</th>
                            <th class="border border-slate-300 px-2 py-2 font-extrabold text-slate-800">
                                {{ invoice.unit_type === 'chalet' ? 'الشاليه' : 'القاعة' }}
                            </th>
                            <th class="border border-slate-300 px-2 py-2 font-extrabold text-slate-800">المناسبة</th>
                            <th class="border border-slate-300 px-2 py-2 font-extrabold text-slate-800">المدة</th>
                            <th class="border border-slate-300 px-2 py-2 font-extrabold text-slate-800">من</th>
                            <th class="border border-slate-300 px-2 py-2 font-extrabold text-slate-800">إلى</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr class="text-center">
                            <td class="border border-slate-300 px-2 py-1.5 font-extrabold text-slate-900" dir="ltr">{{ invoice.number }}</td>
                            <td class="border border-slate-300 px-2 py-1.5 font-bold text-slate-800">
                                {{ invoice.unit_name ?? '—' }}
                                <span v-if="invoice.sections" class="block text-xs font-medium text-slate-700">{{ invoice.sections }}</span>
                            </td>
                            <td class="border border-slate-300 px-2 py-1.5 font-bold text-slate-800">{{ invoice.event_name ?? '—' }}</td>
                            <td class="border border-slate-300 px-2 py-1.5 font-bold text-slate-800">{{ invoice.duration_label }}</td>
                            <td class="border border-slate-300 px-2 py-1.5 font-bold text-slate-800" dir="ltr">{{ invoice.booking_date }}</td>
                            <td class="border border-slate-300 px-2 py-1.5 font-bold text-slate-800" dir="ltr">
                                {{ spansMoreThanOneDay ? invoice.last_day_date : invoice.booking_date }}
                            </td>
                        </tr>
                    </tbody>
                </table>

                <!-- البنود -->
                <table class="w-full border border-slate-300 text-[13px]">
                    <thead class="bg-slate-100">
                        <tr>
                            <th class="border border-slate-300 px-2 py-2 text-center font-extrabold text-slate-800">#</th>
                            <th class="border border-slate-300 px-2 py-2 text-right font-extrabold text-slate-800">البند</th>
                            <th class="border border-slate-300 px-2 py-2 text-center font-extrabold text-slate-800">الكمية</th>
                            <th class="border border-slate-300 px-2 py-2 text-center font-extrabold text-slate-800">الإجمالي</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="(l, i) in invoice.lines" :key="i">
                            <td class="border border-slate-300 px-2 py-1.5 text-center text-slate-700" dir="ltr">{{ i + 1 }}</td>
                            <td class="border border-slate-300 px-2 py-1.5 font-bold text-slate-900">
                                {{ l.name }}
                                <span v-if="l.note" class="block text-xs font-medium text-slate-700">{{ l.note }}</span>
                            </td>
                            <td class="border border-slate-300 px-2 py-1.5 text-center font-bold" dir="ltr">{{ l.quantity }}</td>
                            <td class="border border-slate-300 px-2 py-1.5 text-center font-extrabold" dir="ltr">{{ money(l.amount) }}</td>
                        </tr>
                        <tr v-if="!invoice.lines.length">
                            <td colspan="4" class="border border-slate-300 px-2 py-3 text-center font-bold text-slate-700">
                                لا بنود مسجّلة على هذا الحجز.
                            </td>
                        </tr>
                    </tbody>
                </table>

                <!-- الإجماليات ورمز الفاتورة -->
                <div class="grid items-start gap-6 sm:grid-cols-2">
                    <div class="order-2 space-y-3 sm:order-1">
                        <figure v-if="issuer.qr" class="text-center sm:text-right">
                            <img :src="issuer.qr" alt="رمز الفاتورة" class="h-28 w-28" />
                            <figcaption class="mt-1 w-28 text-center text-xs font-bold text-slate-700">رمز الفاتورة الضريبية</figcaption>
                        </figure>

                        <!-- المقبوض موزّعًا على طرقه -->
                        <div v-if="invoice.methods.length" class="text-[13px]">
                            <div class="mb-1 font-extrabold text-slate-800">طريقة الدفع</div>
                            <div v-for="(m, i) in invoice.methods" :key="i" class="flex justify-between gap-3 border-b border-slate-200 py-1">
                                <span class="font-bold text-slate-700">{{ m.label }}</span>
                                <span class="font-extrabold text-slate-900" dir="ltr">{{ money(m.amount) }}</span>
                            </div>
                        </div>
                    </div>

                    <dl class="order-1 space-y-1 text-[13px] sm:order-2">
                        <div v-if="invoice.is_taxable" class="flex justify-between border-b border-slate-200 py-1">
                            <dt class="font-bold text-slate-800">الإجمالي قبل الضريبة</dt>
                            <dd class="font-bold text-slate-900" dir="ltr">{{ money(invoice.net_amount) }}</dd>
                        </div>
                        <div v-if="invoice.is_taxable" class="flex justify-between border-b border-slate-200 py-1">
                            <dt class="font-bold text-slate-800">ضريبة القيمة المضافة ({{ invoice.tax_rate }}%)</dt>
                            <dd class="font-bold text-slate-900" dir="ltr">{{ money(invoice.tax_amount) }}</dd>
                        </div>
                        <div class="flex justify-between bg-slate-800 px-2 py-1.5 text-base text-white">
                            <dt class="font-extrabold">{{ invoice.is_taxable ? 'الإجمالي شامل الضريبة' : 'الإجمالي' }}</dt>
                            <dd class="font-extrabold" dir="ltr">{{ money(invoice.total_amount) }}</dd>
                        </div>
                        <div class="flex justify-between border-b border-slate-200 py-1">
                            <dt class="font-bold text-slate-800">المدفوع</dt>
                            <dd class="font-bold text-emerald-700" dir="ltr">{{ money(invoice.paid_amount) }}</dd>
                        </div>
                        <div class="flex justify-between py-1">
                            <dt class="font-bold text-slate-800">المتبقي</dt>
                            <dd class="font-extrabold" :class="invoice.remaining_amount > 0 ? 'text-red-700' : 'text-emerald-700'" dir="ltr">
                                {{ money(invoice.remaining_amount) }}
                            </dd>
                        </div>
                    </dl>
                </div>

                <div class="flex flex-wrap justify-between gap-3 border-t border-slate-300 pt-3 text-xs font-medium text-slate-700">
                    <span v-if="invoice.created_by">حرّرها: {{ invoice.created_by }}</span>
                    <span v-if="invoice.guests_count">عدد الضيوف: {{ invoice.guests_count }}</span>
                    <span v-if="issuer.email" dir="ltr">{{ issuer.email }}</span>
                </div>
            </div>
        </div>
    </AppLayout>
</template>

<style>
/* الطباعة تخرج ورقة الفاتورة وحدها — بلا قوائم الإدارة ولا أزرارها. */
@media print {
    body {
        background: #fff;
    }
}
</style>
