<script setup lang="ts">
import StayContractDocument from '@/components/contracts/StayContractDocument.vue';
import { usePermissions } from '@/composables/usePermissions';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/vue3';
import { ArrowRight, FileDown, FileText, MessageCircle, Printer, RefreshCw } from 'lucide-vue-next';
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
        quotation_id: number | null; quotation_number: string | null;
        quotation_date: string | null; valid_until: string | null;
        items: { name: string; code: string | null; quantity: number; unit_price: string; total_price: string }[];
        subtotal: string | null; discount_amount: string | null; tax_amount: string | null;
        sent_at: string | null; signed_at: string | null;
    };
    issuer: {
        business_name: string;
        logo_url: string | null;
        phone: string | null;
        whatsapp: string | null;
        address: string | null;
        tax_number: string | null;
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

            <!-- العقد نفسه — ما يُطبع ويُوقَّع -->
            <div
                v-else
                class="mx-auto max-w-4xl rounded-xl border border-slate-900 bg-white p-7 print:max-w-none print:rounded-none print:border-0 print:p-0 print:text-[11.5px]"
            >
                <!-- الترويسة: موضوع العقد ورقمه وتاريخه.
                     تتكرّر أعلى كل ورقة عند تعدّدها فلا تخرج ورقةٌ بلا هويّة. -->
                <div class="print-keep flex flex-wrap items-baseline justify-between gap-2 border-b border-slate-900 pb-3 print:pb-2">
                    <h2 class="text-lg font-extrabold text-slate-900 print:text-[13px]">
                        <template v-if="isQuotation">
                            عقد {{ contract.subject ?? 'توريد وخدمات' }}
                        </template>
                        <template v-else>
                            <template v-if="isStay">عقد إيجار شاليه</template>
                            <template v-else>عقد إيجار قاعة</template>
                            {{ contract.unit_name ?? issuer.business_name }}
                        </template>
                        — رقم العقد (<span dir="ltr">{{ contract.number }}</span>)
                    </h2>
                    <div class="text-sm font-bold text-slate-700">
                        تاريخ العقد — <span dir="ltr">{{ contract.contract_date ?? '—' }}</span>
                    </div>
                </div>

                <!-- الطرفان والشعار بينهما — كتلة لا تُقسَم بين ورقتين -->
                <div class="print-keep my-4 grid gap-3 md:grid-cols-3 print:my-3 print:grid-cols-3 print:gap-2">
                    <div class="rounded-lg border border-slate-300 p-3 print:p-2">
                        <h3 class="mb-2 rounded border border-slate-300 p-1.5 text-center text-sm font-extrabold text-slate-900">
                            بيانات الطرف الأول
                        </h3>
                        <div class="space-y-1 text-sm text-slate-800">
                            <p class="font-extrabold">{{ (isQuotation ? null : contract.unit_name) ?? issuer.business_name }}</p>
                            <p v-if="issuer.address" class="font-medium">{{ issuer.address }}</p>
                            <p v-if="issuer.phone">
                                <span class="font-extrabold">جوال: </span><span dir="ltr">{{ issuer.phone }}</span>
                            </p>
                            <p v-if="issuer.tax_number">
                                <span class="font-extrabold">الرقم الضريبي: </span><span dir="ltr">{{ issuer.tax_number }}</span>
                            </p>
                        </div>
                    </div>

                    <div class="flex items-center justify-center p-3 print:p-1">
                        <img
                            v-if="logo && !logoFailed"
                            :src="logo"
                            :alt="contract.unit_name ?? 'الشعار'"
                            class="max-h-28 w-auto rounded border border-slate-200 object-contain p-1 print:max-h-16"
                            @error="logoFailed = true"
                        />
                    </div>

                    <div class="rounded-lg border border-slate-300 p-3 print:p-2">
                        <h3 class="mb-2 rounded border border-slate-300 p-1.5 text-center text-sm font-extrabold text-slate-900">
                            بيانات الطرف الثاني
                        </h3>
                        <div class="space-y-1 text-sm text-slate-800">
                            <p class="font-extrabold">{{ contract.client_name ?? '—' }}</p>
                            <p>
                                <span class="font-extrabold">الجوال: </span><span dir="ltr">{{ contract.client_mobile ?? '—' }}</span>
                            </p>
                            <p>
                                <span class="font-extrabold">رقم الهوية: </span
                                ><span dir="ltr">{{ contract.client_id_number ?? '—' }}</span>
                            </p>
                            <p v-if="isQuotation">
                                <span class="font-extrabold">رقم عرض السعر: </span
                                ><span dir="ltr">{{ contract.quotation_number ?? '—' }}</span>
                            </p>
                            <p v-else>
                                <span class="font-extrabold">رقم الحجز: </span><span dir="ltr">{{ contract.booking_reference ?? '—' }}</span>
                            </p>
                        </div>
                    </div>
                </div>

                <!-- أولاً: موضوع العقد — بنود العرض هي نطاق العمل المتفق عليه -->
                <section v-if="isQuotation" class="mb-4 text-sm text-slate-800 print:mb-3">
                    <h3 class="mb-2 font-extrabold text-slate-900">أولاً: موضوع العقد ونطاق العمل</h3>
                    <p class="mb-2 leading-7">
                        يتعهد الطرف الأول بتنفيذ أعمال
                        <span class="font-bold">{{ contract.subject ?? 'التوريد والخدمات' }}</span>
                        للطرف الثاني وفق البنود والأسعار المبيّنة أدناه، والمحرَّرة على عرض السعر رقم
                        <span class="font-bold" dir="ltr">{{ contract.quotation_number ?? '—' }}</span
                        ><template v-if="contract.quotation_date">
                            بتاريخ <span dir="ltr">{{ contract.quotation_date }}</span> </template
                        >.
                    </p>

                    <div class="overflow-x-auto">
                        <table class="w-full border border-slate-300 text-sm">
                            <thead class="bg-slate-100">
                                <tr>
                                    <th class="border border-slate-300 px-2 py-1.5 text-right text-xs font-extrabold text-slate-800">م</th>
                                    <th class="border border-slate-300 px-2 py-1.5 text-right text-xs font-extrabold text-slate-800">البند</th>
                                    <th class="border border-slate-300 px-2 py-1.5 text-center text-xs font-extrabold text-slate-800">الكمية</th>
                                    <th class="border border-slate-300 px-2 py-1.5 text-center text-xs font-extrabold text-slate-800">سعر الوحدة</th>
                                    <th class="border border-slate-300 px-2 py-1.5 text-center text-xs font-extrabold text-slate-800">الإجمالي</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="(l, i) in lines" :key="i">
                                    <td class="border border-slate-300 px-2 py-1.5 text-center">{{ i + 1 }}</td>
                                    <td class="border border-slate-300 px-2 py-1.5">
                                        <span class="font-bold">{{ l.name }}</span>
                                        <span v-if="l.code" class="text-[11px] text-slate-500" dir="ltr"> ({{ l.code }})</span>
                                    </td>
                                    <td class="border border-slate-300 px-2 py-1.5 text-center" dir="ltr">{{ l.quantity }}</td>
                                    <td class="border border-slate-300 px-2 py-1.5 text-center" dir="ltr">{{ l.unit_price }}</td>
                                    <td class="border border-slate-300 px-2 py-1.5 text-center font-bold" dir="ltr">{{ l.total_price }}</td>
                                </tr>
                                <tr v-if="!lines.length">
                                    <td colspan="5" class="border border-slate-300 px-2 py-4 text-center text-slate-500">لا بنود</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </section>

                <!-- أولاً: موضوع العقد -->
                <section v-else class="print-keep mb-4 text-sm text-slate-800 print:mb-3">
                    <h3 class="mb-2 font-extrabold text-slate-900">أولاً: موضوع العقد</h3>
                    <p class="mb-2 leading-7">
                        يتعهد الطرف الأول بتأجير
                        <template v-if="isStay">شاليه</template><template v-else>قاعة</template>
                        <span class="font-bold"> {{ contract.unit_name ?? '—' }} </span>
                        للطرف الثاني
                        <template v-if="contract.event_name">لإقامة مناسبة <span class="font-bold">{{ contract.event_name }}</span></template>
                        <template v-else-if="isStay">للإقامة</template>
                        <template v-else>لإقامة مناسبته</template>.
                    </p>

                    <ul class="space-y-1.5 leading-7">
                        <li>
                            🔹 <span class="font-extrabold">التاريخ:</span>
                            <span dir="ltr">{{ contract.booking_date ?? '—' }}</span>
                            <template v-if="spansMoreThanOneDay">
                                — <template v-if="isStay">الخروج</template><template v-else>حتى</template>
                                <span dir="ltr">{{ contract.last_day_date }}</span>
                            </template>
                            <span v-if="durationLabel" class="mr-1 rounded bg-slate-100 px-1.5 py-0.5 font-bold text-slate-800">
                                {{ durationLabel }}
                            </span>
                        </li>
                        <li v-if="contract.period">
                            🔹 <span class="font-extrabold">الفترة:</span> {{ contract.period }}
                            <span v-if="contract.starts_at && contract.ends_at" class="text-slate-600">
                                (من <span dir="ltr">{{ contract.starts_at }}</span> إلى <span dir="ltr">{{ contract.ends_at }}</span>)
                            </span>
                        </li>
                        <li v-if="contract.sections">🔹 <span class="font-extrabold">النطاق المحجوز:</span> {{ contract.sections }}</li>
                        <li v-if="contract.guests_count && contract.guests_count !== '—'">
                            🔹 <span class="font-extrabold">عدد الضيوف:</span>
                            <span class="rounded bg-slate-100 px-1.5 py-0.5 font-bold text-slate-800">{{ contract.guests_count }}</span>
                        </li>
                    </ul>
                </section>

                <!-- ثانيًا: القيمة والدفعات المالية — المبالغ لا تُفرَّق عن بعضها -->
                <section class="print-keep mb-4 text-sm text-slate-800 print:mb-3">
                    <h3 class="mb-2 font-extrabold text-slate-900">ثانيًا: القيمة والدفعات المالية</h3>
                    <!-- تفصيل العرض قبل إجماليه: الخصم والضريبة جزءٌ ممّا وُقّع عليه -->
                    <div v-if="isQuotation" class="mb-2 grid gap-2 sm:grid-cols-3 print:grid-cols-3">
                        <div class="rounded-lg border border-slate-200 px-3 py-1.5 text-xs">
                            <span class="font-bold text-slate-600">المجموع قبل الخصم: </span>
                            <span class="font-extrabold text-slate-800" dir="ltr">{{ contract.subtotal ?? '—' }}</span>
                        </div>
                        <div class="rounded-lg border border-slate-200 px-3 py-1.5 text-xs">
                            <span class="font-bold text-slate-600">الخصم: </span>
                            <span class="font-extrabold text-slate-800" dir="ltr">{{ contract.discount_amount ?? '—' }}</span>
                        </div>
                        <div class="rounded-lg border border-slate-200 px-3 py-1.5 text-xs">
                            <span class="font-bold text-slate-600">الضريبة: </span>
                            <span class="font-extrabold text-slate-800" dir="ltr">{{ contract.tax_amount ?? '—' }}</span>
                        </div>
                    </div>
                    <div class="grid gap-2 sm:grid-cols-3 print:grid-cols-3">
                        <div class="rounded-lg border border-slate-300 px-3 py-2 print:py-1.5">
                            <div class="text-xs font-bold text-slate-600">{{ isQuotation ? 'قيمة العقد' : 'قيمة الإيجار' }}</div>
                            <div class="font-extrabold text-slate-900">{{ contract.total_amount ?? '—' }} ريال</div>
                        </div>
                        <div class="rounded-lg border border-slate-300 px-3 py-2 print:py-1.5">
                            <div class="text-xs font-bold text-slate-600">{{ isQuotation ? 'المدفوع' : 'العربون المدفوع' }}</div>
                            <div class="font-extrabold text-emerald-700">{{ contract.deposit_amount ?? '—' }} ريال</div>
                        </div>
                        <div class="rounded-lg border border-slate-300 px-3 py-2 print:py-1.5">
                            <div class="text-xs font-bold text-slate-600">المبلغ المتبقي</div>
                            <div class="font-extrabold text-red-700">{{ contract.remaining_amount ?? '—' }} ريال</div>
                        </div>
                    </div>
                </section>

                <!-- ثالثًا: الشروط والأحكام — تمتدّ على ما تحتاجه من أوراق،
                     فلا تُقيَّد بـ print-keep وإلا قفزت كتلةً وتركت ورقة بيضاء -->
                <section v-if="termsText" class="mb-5 text-sm text-slate-800 print:mb-3">
                    <h3 class="mb-2 font-extrabold text-slate-900">ثالثًا: الشروط والأحكام</h3>
                    <pre class="whitespace-pre-wrap font-sans text-[13px] leading-7 text-slate-800 print:text-[10.5px] print:leading-[1.7]">{{ termsText }}</pre>
                </section>

                <!-- التواقيع — كتلة واحدة لا تُقسَم: توقيعٌ في ورقة واسمه في أخرى لا يصلح سندًا -->
                <div class="print-keep mt-4 grid gap-4 border-t border-slate-900 pt-4 sm:grid-cols-2 print:grid-cols-2 print:pt-3">
                    <div>
                        <div class="font-extrabold text-slate-900">توقيع الطرف الأول</div>
                        <div class="mt-1 text-sm text-slate-800">
                            <span class="font-extrabold">الاسم/ </span>{{ issuer.manager_name ?? issuer.business_name }}
                        </div>
                        <div class="mt-1 text-sm font-extrabold text-slate-800">التوقيع/</div>
                        <div class="mt-1 flex items-end gap-3">
                            <img
                                v-if="issuer.manager_signature_url"
                                :src="issuer.manager_signature_url"
                                alt="التوقيع"
                                class="h-16 w-auto object-contain print:h-12"
                            />
                            <span v-else class="text-slate-400">........................</span>
                            <img v-if="issuer.stamp_url" :src="issuer.stamp_url" alt="الختم" class="h-16 w-auto object-contain print:h-12" />
                        </div>
                    </div>

                    <div>
                        <div class="font-extrabold text-slate-900">توقيع الطرف الثاني (العميل)</div>
                        <div class="mt-1 text-sm text-slate-800">
                            <span class="font-extrabold">الاسم/ </span>{{ contract.client_name ?? '—' }}
                        </div>
                        <div class="mt-1 text-sm font-extrabold text-slate-800">التوقيع/</div>
                        <div class="mt-1 text-slate-400">........................</div>
                    </div>
                </div>

                <div v-if="issuer.phone || issuer.tax_number" class="print-keep mt-5 border-t border-slate-200 pt-2 text-center text-[11px] font-medium text-slate-500 print:mt-3">
                    <span v-if="issuer.phone" dir="ltr">{{ issuer.phone }}</span>
                    <span v-if="issuer.phone && issuer.tax_number"> · </span>
                    <span v-if="issuer.tax_number">الرقم الضريبي: <span dir="ltr">{{ issuer.tax_number }}</span></span>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
