<script setup lang="ts">
import InstallationContractDocument from '@/components/contracts/InstallationContractDocument.vue';
import MaintenanceContractDocument from '@/components/contracts/MaintenanceContractDocument.vue';
import StandardContractDocument from '@/components/contracts/StandardContractDocument.vue';
import StayContractDocument from '@/components/contracts/StayContractDocument.vue';
import { usePermissions } from '@/composables/usePermissions';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/vue3';
import { ArrowRight, FileDown, FileText, MessageCircle, Pencil, Printer, RefreshCw } from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';

const props = defineProps<{
    contract: {
        id: number; number: string; body: string; terms: string | null;
        status: string; status_label: string;
        contract_date: string | null; contract_date_hijri: string | null;
        client_name: string | null; client_mobile: string | null; client_id_number: string | null;
        client_address: string | null;
        booking_id: number | null; booking_reference: string | null;
        unit_name: string | null; unit_code: string | null; unit_logo_url: string | null; unit_type: string | null;
        event_name: string | null;
        sections: string | null;
        booking_date: string | null; booking_date_hijri: string | null;
        last_day_date: string | null; last_day_date_hijri: string | null;
        days_count: string | null; duration_label: string | null;
        check_in_day: string | null; check_out_day: string | null;
        check_in_time: string | null; check_out_time: string | null;
        period: string | null; starts_at: string | null; ends_at: string | null;
        guests_count: string | null;
        total_amount: string | null; total_amount_words: string | null;
        deposit_amount: string | null; remaining_amount: string | null; security_deposit: string | null;
        // Quotation contracts (pools): the priced lines are the scope of work.
        from_quotation: boolean; subject: string | null;
        /** The pools' installation pad — its own sheet, not the standard one. */
        is_installation_form: boolean;
        /** The pools' monthly-maintenance sheet — likewise its own. */
        is_maintenance_form: boolean;
        first_installment: string | null; second_installment: string | null;
        pool_width: string | null; pool_length: string | null;
        pool_min_depth: string | null; pool_max_depth: string | null;
        quotation_id: number | null; quotation_number: string | null;
        quotation_date: string | null; valid_until: string | null;
        items: { name: string; code: string | null; quantity: number; unit_price: string; total_price: string }[];
        subtotal: string | null; discount_amount: string | null; tax_amount: string | null;
        /** الضريبة كما جُمِّدت يوم الإصدار — عقدٌ قديم بلا ضريبة يبقى بلا سطرها. */
        is_taxable: boolean; tax_rate: string | null;
        sent_at: string | null; signed_at: string | null;
    };
    issuer: {
        business_name: string;
        logo_url: string | null;
        phone: string | null;
        whatsapp: string | null;
        address: string | null;
        tax_number: string | null;
        commercial_register: string | null;
        manager_name: string | null;
        manager_signature_url: string | null;
        stamp_url: string | null;
    };
}>();

const { can } = usePermissions();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'لوحة التحكم', href: '/admin' },
    { title: 'العقود', href: '/admin/contracts' },
    { title: props.contract.number, href: `/admin/contracts/${props.contract.id}` },
];

// شعار الوحدة أولى من شعار المنشأة: العقد يُحرَّر على القاعة التي حُجزت.
const logo = computed(() => props.contract.unit_logo_url ?? props.issuer.logo_url);

// تعذُّر تحميل الشعار لا يترك أيقونة مكسورة على مستند يُطبع ويُوقَّع.
const logoFailed = ref(false);
watch(logo, () => (logoFailed.value = false));

const isStay = computed(() => props.contract.unit_type === 'chalet');

// A quotation contract prints the priced lines it was drawn from instead of the
// rental terms — that list is what the parties actually agreed on.
const isQuotation = computed(() => props.contract.from_quotation);
const isInstallationForm = computed(() => props.contract.is_installation_form);
const isMaintenanceForm = computed(() => props.contract.is_maintenance_form);
const lines = computed(() => props.contract.items ?? []);

// المدى الزمني: المناسبة الممتدة والإقامة يُذكر آخر يومهما، واليوم الواحد يُكتفى بتاريخه.
const spansMoreThanOneDay = computed(
    () => !!props.contract.last_day_date && props.contract.last_day_date !== props.contract.booking_date,
);

const durationLabel = computed(() => {
    const n = Number(props.contract.days_count ?? 0);
    if (!n) return null;
    return isStay.value ? `${n} ليلة` : `${n} يوم`;
});

// العقود المولّدة قبل فصل الشروط تحمل نصها كاملًا في body، فيُعرض هو.
const termsText = computed(() => props.contract.terms ?? props.contract.body);
const showFullBody = ref(false);

// المسودة تُعاد بناؤها من النموذج المعتمد بعد تحريره — العقد المُرسل لا يُمسّ.
const refresh = () => {
    if (confirm('إعادة بناء نص هذه المسودة من نموذج العقد المعتمد؟ سيحلّ نص النموذج الحالي محل النص المجمَّد.')) {
        router.post(`/admin/contracts/${props.contract.id}/refresh`, {}, { preserveScroll: true });
    }
};

const send = () => {
    if (!props.contract.client_mobile) {
        alert('لا يوجد رقم جوال للعميل.');
        return;
    }
    if (confirm(`إرسال العقد ${props.contract.number} على واتساب ${props.contract.client_mobile}؟`)) {
        router.post(`/admin/contracts/${props.contract.id}/send`, {}, { preserveScroll: true });
    }
};

const print = () => window.print();
</script>

<template>
    <Head :title="`العقد ${contract.number}`" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="min-h-full space-y-4 bg-slate-100 p-5 print:bg-white print:p-0">
            <!-- شريط الأدوات — لا يُطبع -->
            <div class="flex flex-wrap items-center justify-between gap-3 print:hidden">
                <div>
                    <h1 class="text-2xl font-extrabold text-slate-900" dir="ltr">{{ contract.number }}</h1>
                    <p class="mt-1 text-sm font-medium text-slate-600">
                        {{ contract.client_name ?? 'بلا عميل' }} ·
                        <template v-if="isQuotation">
                            عرض سعر <span dir="ltr">{{ contract.quotation_number ?? '—' }}</span>
                        </template>
                        <template v-else> حجز <span dir="ltr">{{ contract.booking_reference }}</span> </template>
                        · {{ contract.status_label }}
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
                    <!-- المستند يُبنى على الخادم لا في المتصفح: ما يُنزَّل هنا
                         هو نفسه ما يصل العميل على واتساب، لا صورةً أخرى منه. -->
                    <a
                        v-if="can('contracts.export')"
                        :href="`/admin/contracts/${contract.id}/pdf?download=1`"
                        class="inline-flex items-center gap-1.5 rounded-md bg-slate-900 px-4 py-2 text-sm font-bold text-white shadow-sm hover:bg-slate-800"
                    >
                        <FileDown class="h-4 w-4" /> تحميل PDF
                    </a>
                    <button
                        v-if="can('contracts.send') && contract.status !== 'cancelled'"
                        type="button"
                        @click="send"
                        class="inline-flex items-center gap-1.5 rounded-md bg-emerald-600 px-4 py-2 text-sm font-bold text-white shadow-sm hover:bg-emerald-700"
                    >
                        <MessageCircle class="h-4 w-4" /> إرسال عبر واتساب
                    </button>
                    <!-- تعديل العقد نفسه — بخلاف «تحديث من النموذج» الذي يعيد قراءة صياغته -->
                    <Link
                        v-if="can('contracts.edit') && contract.status === 'draft'"
                        :href="`/admin/contracts/${contract.id}/edit`"
                        class="inline-flex items-center gap-1.5 rounded-md border border-slate-300 bg-white px-4 py-2 text-sm font-bold text-slate-700 hover:bg-slate-50"
                    >
                        <Pencil class="h-4 w-4" /> تعديل
                    </Link>
                    <button
                        v-if="can('contracts.edit') && contract.status === 'draft'"
                        type="button"
                        @click="refresh"
                        class="inline-flex items-center gap-1.5 rounded-md border border-slate-300 bg-white px-4 py-2 text-sm font-bold text-slate-700 hover:bg-slate-50"
                    >
                        <RefreshCw class="h-4 w-4" /> تحديث من النموذج
                    </button>
                    <button
                        type="button"
                        @click="showFullBody = !showFullBody"
                        class="inline-flex items-center gap-1.5 rounded-md border border-slate-200 bg-white px-4 py-2 text-sm font-bold text-slate-700 hover:bg-slate-50"
                    >
                        <FileText class="h-4 w-4" /> {{ showFullBody ? 'إخفاء النص المجمَّد' : 'النص المجمَّد' }}
                    </button>
                    <Link
                        href="/admin/contracts"
                        class="inline-flex items-center gap-1.5 rounded-md border border-slate-200 bg-white px-4 py-2 text-sm font-bold text-slate-700 hover:bg-slate-50"
                    >
                        <ArrowRight class="h-4 w-4" /> رجوع
                    </Link>
                </div>
            </div>

            <!-- النص المجمَّد كما وُلّد — مرجعٌ عند الشك، لا يُطبع -->
            <div v-if="showFullBody" class="mx-auto max-w-4xl rounded-xl border border-slate-300 bg-white p-6 print:hidden">
                <h3 class="mb-2 text-sm font-extrabold text-slate-800">النص المجمَّد وقت التوليد</h3>
                <pre class="whitespace-pre-wrap font-sans text-xs leading-7 text-slate-700">{{ contract.body }}</pre>
            </div>

            <!-- A chalet is let on its own daily-rental form, the same document the PDF prints. -->
            <StayContractDocument v-if="isStay" :contract="contract" :issuer="issuer" />

            <!-- Pool piping and installation is sold on its own pad, likewise. -->
            <InstallationContractDocument v-else-if="isInstallationForm" :contract="contract" :issuer="issuer" />

            <!-- Monthly pool maintenance is offered on its own sheet. -->
            <MaintenanceContractDocument v-else-if="isMaintenanceForm" :contract="contract" :issuer="issuer" />

            <!-- العقد نفسه — ما يُطبع ويُوقَّع -->
            <StandardContractDocument v-else :contract="contract" :issuer="issuer" />
        </div>
    </AppLayout>
</template>
