<script setup lang="ts">
import { computed, ref, watch } from 'vue';

/** A priced line; a blank row carries no count until one is written in. */
type Line = {
    name: string;
    code: string | null;
    quantity: number | string | null;
    unit_price: string;
    total_price: string;
};

interface StandardContract {
    number: string;
    body: string;
    terms: string | null;
    contract_date: string | null;
    client_name: string | null;
    client_mobile: string | null;
    client_id_number: string | null;
    unit_name: string | null;
    unit_logo_url: string | null;
    event_name: string | null;
    sections: string | null;
    booking_reference: string | null;
    booking_date: string | null;
    last_day_date: string | null;
    days_count: string | null;
    period: string | null;
    starts_at: string | null;
    ends_at: string | null;
    guests_count: string | null;
    subject: string | null;
    quotation_number: string | null;
    quotation_date: string | null;
    total_amount: string | null;
    subtotal: string | null;
    discount_amount: string | null;
    tax_amount: string | null;
    tax_rate: string | null;
    is_taxable: boolean;
    deposit_amount: string | null;
    remaining_amount: string | null;
    from_quotation: boolean;
    unit_type: string | null;
    items: Line[];
}

interface Issuer {
    business_name: string;
    logo_url: string | null;
    phone: string | null;
    address: string | null;
    tax_number: string | null;
    manager_name: string | null;
    manager_signature_url: string | null;
    stamp_url: string | null;
}

const props = withDefaults(
    defineProps<{
        contract: StandardContract;
        issuer: Issuer;
        /** The same sheet, filled in on screen — see InstallationContractDocument. */
        editable?: boolean;
    }>(),
    { editable: false },
);

// Models, not props: the edit screen owns the values, and a defineModel ref may
// be written to from here without mutating what was handed down.
const fields = defineModel<Record<string, string>>('fields', { default: () => ({}) });
const items = defineModel<Line[]>('items', { default: () => [] });
const terms = defineModel<string>('terms', { default: '' });

// شعار الوحدة أولى من شعار المنشأة: العقد يُحرَّر على القاعة التي حُجزت.
const logo = computed(() => props.contract.unit_logo_url ?? props.issuer.logo_url);
const logoFailed = ref(false);
watch(logo, () => (logoFailed.value = false));

const isQuotation = computed(() => props.contract.from_quotation);
const isStay = computed(() => props.contract.unit_type === 'chalet');

const lines = computed(() => (props.editable ? items.value : props.contract.items) ?? []);

const termsText = computed(() => (props.editable ? terms.value : props.contract.terms ?? props.contract.body));

// المدى الزمني: المناسبة الممتدة والإقامة يُذكر آخر يومهما، واليوم الواحد يُكتفى بتاريخه.
const spansMoreThanOneDay = computed(
    () => !!props.contract.last_day_date && props.contract.last_day_date !== props.contract.booking_date,
);

const durationLabel = computed(() => {
    const n = Number(props.contract.days_count ?? 0);
    if (!n) return null;
    return isStay.value ? `${n} ليلة` : `${n} يوم`;
});

const addLine = () => items.value.push({ name: '', code: null, quantity: '', unit_price: '', total_price: '' });
const removeLine = (i: number) => items.value.splice(i, 1);
</script>

<template>
    <div
        class="mx-auto max-w-4xl rounded-xl border border-slate-900 bg-white p-7 print:max-w-none print:rounded-none print:border-0 print:p-0 print:text-[11.5px]"
    >
        <!-- الترويسة: موضوع العقد ورقمه وتاريخه.
             تتكرّر أعلى كل ورقة عند تعدّدها فلا تخرج ورقةٌ بلا هويّة. -->
        <div class="print-keep flex flex-wrap items-baseline justify-between gap-2 border-b border-slate-900 pb-3 print:pb-2">
            <h2 class="flex flex-wrap items-baseline gap-1 text-lg font-extrabold text-slate-900 print:text-[13px]">
                <template v-if="isQuotation">
                    عقد
                    <input v-if="editable" v-model="fields.subject" class="fillin w-56" />
                    <template v-else>{{ contract.subject ?? 'توريد وخدمات' }}</template>
                </template>
                <template v-else>
                    <template v-if="isStay">عقد إيجار شاليه</template>
                    <template v-else>عقد إيجار قاعة</template>
                    <input v-if="editable" v-model="fields.unit_name" class="fillin w-48" />
                    <template v-else>{{ contract.unit_name ?? issuer.business_name }}</template>
                </template>
                — رقم العقد (<span dir="ltr">{{ contract.number }}</span>)
            </h2>
            <div class="text-sm font-bold text-slate-700">
                تاريخ العقد —
                <input v-if="editable" v-model="fields.contract_date" class="fillin w-32" dir="ltr" />
                <span v-else dir="ltr">{{ contract.contract_date ?? '—' }}</span>
            </div>
        </div>

        <!-- الطرفان والشعار بينهما — كتلة لا تُقسَم بين ورقتين -->
        <div class="print-keep my-4 grid gap-3 md:grid-cols-3 print:my-3 print:grid-cols-3 print:gap-2">
            <div class="rounded-lg border border-slate-300 p-3 print:p-2">
                <h3 class="mb-2 rounded border border-slate-300 p-1.5 text-center text-sm font-extrabold text-slate-900">
                    بيانات الطرف الأول
                </h3>
                <div class="space-y-1 text-sm text-slate-800">
                    <input v-if="editable" v-model="fields.org_name" class="fillin font-extrabold" :placeholder="issuer.business_name" />
                    <p v-else class="font-extrabold">{{ (isQuotation ? null : contract.unit_name) ?? issuer.business_name }}</p>
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
                    <input v-if="editable" v-model="fields.client_name" class="fillin font-extrabold" />
                    <p v-else class="font-extrabold">{{ contract.client_name ?? '—' }}</p>
                    <p>
                        <span class="font-extrabold">الجوال: </span>
                        <input v-if="editable" v-model="fields.client_mobile" class="fillin w-32" dir="ltr" />
                        <span v-else dir="ltr">{{ contract.client_mobile ?? '—' }}</span>
                    </p>
                    <p>
                        <span class="font-extrabold">رقم الهوية: </span>
                        <input v-if="editable" v-model="fields.client_id_number" class="fillin w-32" dir="ltr" />
                        <span v-else dir="ltr">{{ contract.client_id_number ?? '—' }}</span>
                    </p>
                    <p v-if="isQuotation">
                        <span class="font-extrabold">رقم عرض السعر: </span>
                        <input v-if="editable" v-model="fields.quotation_number" class="fillin w-28" dir="ltr" />
                        <span v-else dir="ltr">{{ contract.quotation_number ?? '—' }}</span>
                    </p>
                    <p v-else>
                        <span class="font-extrabold">رقم الحجز: </span>
                        <input v-if="editable" v-model="fields.booking_reference" class="fillin w-28" dir="ltr" />
                        <span v-else dir="ltr">{{ contract.booking_reference ?? '—' }}</span>
                    </p>
                </div>
            </div>
        </div>

        <!-- أولاً: موضوع العقد — بنود العرض هي نطاق العمل المتفق عليه -->
        <section v-if="isQuotation" class="mb-4 text-sm text-slate-800 print:mb-3">
            <div class="mb-2 flex items-center justify-between">
                <h3 class="font-extrabold text-slate-900">أولاً: موضوع العقد ونطاق العمل</h3>
                <button
                    v-if="editable"
                    type="button"
                    @click="addLine"
                    class="rounded-lg bg-slate-100 px-3 py-1 text-xs font-bold text-slate-700 hover:bg-slate-200 print:hidden"
                >
                    + بند
                </button>
            </div>
            <p class="mb-2 leading-7">
                يتعهد الطرف الأول بتنفيذ أعمال
                <span class="font-bold">{{ (editable ? fields.subject : contract.subject) || 'التوريد والخدمات' }}</span>
                للطرف الثاني وفق البنود والأسعار المبيّنة أدناه، والمحرَّرة على عرض السعر رقم
                <span class="font-bold" dir="ltr">{{ (editable ? fields.quotation_number : contract.quotation_number) || '—' }}</span
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
                            <th v-if="editable" class="border border-slate-300 px-2 py-1.5 print:hidden"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="(l, i) in lines" :key="i">
                            <td class="border border-slate-300 px-2 py-1.5 text-center">{{ i + 1 }}</td>
                            <td class="border border-slate-300 px-2 py-1.5">
                                <input v-if="editable" v-model="items[i].name" class="fillin" />
                                <template v-else>
                                    <span class="font-bold">{{ l.name }}</span>
                                    <span v-if="l.code" class="text-[11px] text-slate-500" dir="ltr"> ({{ l.code }})</span>
                                </template>
                            </td>
                            <td class="border border-slate-300 px-2 py-1.5 text-center" dir="ltr">
                                <input v-if="editable" v-model="items[i].quantity" class="fillin center" dir="ltr" />
                                <template v-else>{{ l.quantity }}</template>
                            </td>
                            <td class="border border-slate-300 px-2 py-1.5 text-center" dir="ltr">
                                <input v-if="editable" v-model="items[i].unit_price" class="fillin center" dir="ltr" />
                                <template v-else>{{ l.unit_price }}</template>
                            </td>
                            <td class="border border-slate-300 px-2 py-1.5 text-center font-bold" dir="ltr">
                                <input v-if="editable" v-model="items[i].total_price" class="fillin center" dir="ltr" />
                                <template v-else>{{ l.total_price }}</template>
                            </td>
                            <td v-if="editable" class="border border-slate-300 px-1 text-center print:hidden">
                                <button type="button" @click="removeLine(i)" class="px-1 text-red-500">✕</button>
                            </td>
                        </tr>
                        <tr v-if="!lines.length">
                            <td colspan="6" class="border border-slate-300 px-2 py-4 text-center text-slate-500">لا بنود</td>
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
                <span class="font-bold"> {{ (editable ? fields.unit_name : contract.unit_name) || '—' }} </span>
                للطرف الثاني
                <template v-if="contract.event_name">لإقامة مناسبة <span class="font-bold">{{ contract.event_name }}</span></template>
                <template v-else-if="isStay">للإقامة</template>
                <template v-else>لإقامة مناسبته</template>.
            </p>

            <ul class="space-y-1.5 leading-7">
                <li>
                    🔹 <span class="font-extrabold">التاريخ:</span>
                    <input v-if="editable" v-model="fields.booking_date" class="fillin w-32" dir="ltr" />
                    <span v-else dir="ltr">{{ contract.booking_date ?? '—' }}</span>
                    <template v-if="editable">
                        — <span class="font-extrabold">حتى</span>
                        <input v-model="fields.last_day_date" class="fillin w-32" dir="ltr" />
                    </template>
                    <template v-else-if="spansMoreThanOneDay">
                        — <template v-if="isStay">الخروج</template><template v-else>حتى</template>
                        <span dir="ltr">{{ contract.last_day_date }}</span>
                    </template>
                    <span v-if="!editable && durationLabel" class="mr-1 rounded bg-slate-100 px-1.5 py-0.5 font-bold text-slate-800">
                        {{ durationLabel }}
                    </span>
                </li>
                <li v-if="editable || contract.period">
                    🔹 <span class="font-extrabold">الفترة:</span>
                    <input v-if="editable" v-model="fields.period" class="fillin w-40" />
                    <template v-else>{{ contract.period }}</template>
                    <span v-if="!editable && contract.starts_at && contract.ends_at" class="text-slate-600">
                        (من <span dir="ltr">{{ contract.starts_at }}</span> إلى <span dir="ltr">{{ contract.ends_at }}</span>)
                    </span>
                </li>
                <li v-if="editable || contract.sections">
                    🔹 <span class="font-extrabold">النطاق المحجوز:</span>
                    <input v-if="editable" v-model="fields.sections" class="fillin w-64" />
                    <template v-else>{{ contract.sections }}</template>
                </li>
                <li v-if="editable || (contract.guests_count && contract.guests_count !== '—')">
                    🔹 <span class="font-extrabold">عدد الضيوف:</span>
                    <input v-if="editable" v-model="fields.guests_count" class="fillin w-20" dir="ltr" />
                    <span v-else class="rounded bg-slate-100 px-1.5 py-0.5 font-bold text-slate-800">{{ contract.guests_count }}</span>
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
                    <input v-if="editable" v-model="fields.subtotal" class="fillin w-20" dir="ltr" />
                    <span v-else class="font-extrabold text-slate-800" dir="ltr">{{ contract.subtotal ?? '—' }}</span>
                </div>
                <div class="rounded-lg border border-slate-200 px-3 py-1.5 text-xs">
                    <span class="font-bold text-slate-600">الخصم: </span>
                    <input v-if="editable" v-model="fields.discount_amount" class="fillin w-20" dir="ltr" />
                    <span v-else class="font-extrabold text-slate-800" dir="ltr">{{ contract.discount_amount ?? '—' }}</span>
                </div>
                <div class="rounded-lg border border-slate-200 px-3 py-1.5 text-xs">
                    <span class="font-bold text-slate-600">الضريبة: </span>
                    <input v-if="editable" v-model="fields.tax_amount" class="fillin w-20" dir="ltr" />
                    <span v-else class="font-extrabold text-slate-800" dir="ltr">{{ contract.tax_amount ?? '—' }}</span>
                </div>
            </div>
            <div class="grid gap-2 sm:grid-cols-3 print:grid-cols-3">
                <div class="rounded-lg border border-slate-300 px-3 py-2 print:py-1.5">
                    <div class="text-xs font-bold text-slate-600">{{ isQuotation ? 'قيمة العقد' : 'قيمة الإيجار' }}</div>
                    <div class="font-extrabold text-slate-900">
                        <input v-if="editable" v-model="fields.total_amount" class="fillin w-24" dir="ltr" />
                        <template v-else>{{ contract.total_amount ?? '—' }}</template>
                        ريال
                    </div>
                    <!-- الضريبة داخل قيمة الإيجار لا فوقها: تُذكَر ولا تُغيّر المبلغ الموقَّع -->
                    <div v-if="!editable && !isQuotation && contract.is_taxable" class="text-[11px] font-bold text-slate-500">
                        شاملة ضريبة القيمة المضافة ({{ contract.tax_rate }}%): <span dir="ltr">{{ contract.tax_amount }}</span>
                    </div>
                </div>
                <div class="rounded-lg border border-slate-300 px-3 py-2 print:py-1.5">
                    <div class="text-xs font-bold text-slate-600">{{ isQuotation ? 'المدفوع' : 'العربون المدفوع' }}</div>
                    <div class="font-extrabold text-emerald-700">
                        <input v-if="editable" v-model="fields.deposit_amount" class="fillin w-24" dir="ltr" />
                        <template v-else>{{ contract.deposit_amount ?? '—' }}</template>
                        ريال
                    </div>
                </div>
                <div class="rounded-lg border border-slate-300 px-3 py-2 print:py-1.5">
                    <div class="text-xs font-bold text-slate-600">المبلغ المتبقي</div>
                    <div class="font-extrabold text-red-700">
                        <input v-if="editable" v-model="fields.remaining_amount" class="fillin w-24" dir="ltr" />
                        <template v-else>{{ contract.remaining_amount ?? '—' }}</template>
                        ريال
                    </div>
                </div>
            </div>
        </section>

        <!-- ثالثًا: الشروط والأحكام — تمتدّ على ما تحتاجه من أوراق،
             فلا تُقيَّد بـ print-keep وإلا قفزت كتلةً وتركت ورقة بيضاء -->
        <section v-if="editable || termsText" class="mb-5 text-sm text-slate-800 print:mb-3">
            <h3 class="mb-2 font-extrabold text-slate-900">ثالثًا: الشروط والأحكام</h3>
            <textarea v-if="editable" v-model="terms" rows="14" class="fillin block w-full font-sans text-[13px] leading-7"></textarea>
            <pre v-else class="whitespace-pre-wrap font-sans text-[13px] leading-7 text-slate-800 print:text-[10.5px] print:leading-[1.7]">{{ termsText }}</pre>
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
                    <span class="font-extrabold">الاسم/ </span>{{ (editable ? fields.client_name : contract.client_name) || '—' }}
                </div>
                <div class="mt-1 text-sm font-extrabold text-slate-800">التوقيع/</div>
                <div class="mt-1 text-slate-400">........................</div>
            </div>
        </div>

        <div
            v-if="issuer.phone || issuer.tax_number"
            class="print-keep mt-5 border-t border-slate-200 pt-2 text-center text-[11px] font-medium text-slate-500 print:mt-3"
        >
            <span v-if="issuer.phone" dir="ltr">{{ issuer.phone }}</span>
            <span v-if="issuer.phone && issuer.tax_number"> · </span>
            <span v-if="issuer.tax_number">الرقم الضريبي: <span dir="ltr">{{ issuer.tax_number }}</span></span>
        </div>
    </div>
</template>

<style scoped>
/* An input on the printed line: the paper's run stays, the box does not. */
.fillin {
    padding: 0 2px;
    border: 0;
    border-bottom: 1px dotted #94a3b8;
    background: #f6f9ff;
    font: inherit;
    color: inherit;
    outline: none;
}
.fillin:focus {
    background: #e6efff;
}
.fillin.center {
    text-align: center;
}
input.fillin {
    max-width: 100%;
}
</style>
