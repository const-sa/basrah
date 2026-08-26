<script setup lang="ts">
import { computed, ref, watch } from 'vue';

interface StayContract {
    number: string;
    terms: string | null;
    body: string;
    contract_date: string | null;
    contract_date_hijri: string | null;
    client_name: string | null;
    client_mobile: string | null;
    client_id_number: string | null;
    client_address: string | null;
    booking_reference: string | null;
    unit_name: string | null;
    unit_code: string | null;
    unit_logo_url: string | null;
    event_name: string | null;
    sections: string | null;
    booking_date: string | null;
    booking_date_hijri: string | null;
    last_day_date: string | null;
    last_day_date_hijri: string | null;
    days_count: string | null;
    duration_label: string | null;
    check_in_day: string | null;
    check_out_day: string | null;
    check_in_time: string | null;
    check_out_time: string | null;
    guests_count: string | null;
    total_amount: string | null;
    total_amount_words: string | null;
    /** الضريبة كما جُمِّدت يوم الإصدار — داخل المبلغ لا فوقه. */
    is_taxable: boolean;
    tax_rate: string | null;
    tax_amount: string | null;
    deposit_amount: string | null;
    remaining_amount: string | null;
    security_deposit: string | null;
}

interface Issuer {
    business_name: string;
    logo_url: string | null;
    phone: string | null;
    whatsapp: string | null;
    address: string | null;
    manager_name: string | null;
    manager_signature_url: string | null;
    stamp_url: string | null;
}

const props = defineProps<{ contract: StayContract; issuer: Issuer }>();

// The unit's logo comes first: the contract is drawn on the chalet booked.
const logo = computed(() => props.contract.unit_logo_url ?? props.issuer.logo_url);
const logoFailed = ref(false);
watch(logo, () => (logoFailed.value = false));

// A blank value stays empty — its dotted underline is the fill-in run.
const fill = (value: string | null | undefined) => value || ' ';

const termsText = computed(() => props.contract.terms ?? props.contract.body);

// A deposit of zero is no deposit — the row is dropped, not printed as 0.00.
const hasSecurityDeposit = computed(() => Number((props.contract.security_deposit ?? '0').replace(/,/g, '')) > 0);
</script>

<template>
    <div class="doc mx-auto max-w-4xl bg-white p-8 text-slate-900 shadow-sm print:max-w-none print:p-0 print:shadow-none">
        <div class="basmala">بسم الله الرحمن الرحيم</div>

        <!-- Letterhead: name and phones right, emblem centre, date box left. -->
        <table class="print-keep w-full">
            <tr>
                <td class="w-[40%] align-top">
                    <div class="biz">{{ contract.unit_name ?? issuer.business_name }}</div>
                    <div v-if="issuer.phone" class="tel">الإدارة <span dir="ltr">{{ issuer.phone }}</span></div>
                    <div v-if="issuer.whatsapp" class="tel">واتساب <span dir="ltr">{{ issuer.whatsapp }}</span></div>
                </td>
                <td class="w-[22%] text-center align-top">
                    <img
                        v-if="logo && !logoFailed"
                        :src="logo"
                        :alt="contract.unit_name ?? 'الشعار'"
                        class="mx-auto max-h-[70px] w-auto object-contain print:max-h-[52px]"
                        @error="logoFailed = true"
                    />
                </td>
                <td class="w-[38%] align-top">
                    <table class="datebox">
                        <tr>
                            <td class="k">التاريخ</td>
                            <td class="v" dir="ltr">{{ contract.contract_date ?? '' }}</td>
                        </tr>
                        <tr>
                            <td class="k">الموافق</td>
                            <td class="v" dir="ltr">{{ fill(contract.contract_date_hijri) }}</td>
                        </tr>
                    </table>
                </td>
            </tr>
        </table>

        <!-- The title and the serial sit on one line, each in its own small box. -->
        <table class="print-keep my-2 w-full">
            <tr>
                <td class="w-[32%]"></td>
                <td class="w-[36%] text-center"><span class="titlebox">عقد إيجار يومي</span></td>
                <td class="w-[32%]"><span class="nobox">No.&nbsp;<span class="accent" dir="ltr">{{ contract.number }}</span></span></td>
            </tr>
        </table>

        <div class="lead">تم بعون الله الاتفاق بين:</div>

        <!-- One table per LINE, every cell explicitly sized — the same widths the
             PDF uses, so the screen and the generated file stay one document. -->
        <table class="ln"><tr>
            <td class="k" style="width: 22%">الطرف الأول (المؤجر):</td>
            <td class="v" style="width: 78%"><bdi>{{ contract.unit_name ?? issuer.business_name }}</bdi></td>
        </tr></table>

        <table class="ln"><tr>
            <td class="k" style="width: 24%">الطرف الثاني (المستأجر):</td>
            <td class="v" style="width: 76%">{{ fill(contract.client_name) }}</td>
        </tr></table>

        <table class="ln"><tr>
            <td class="k" style="width: 20%">رقم البطاقة / الإقامة:</td>
            <td class="v" style="width: 32%" dir="ltr">{{ fill(contract.client_id_number) }}</td>
            <td class="g" style="width: 8%">جوال:</td>
            <td class="v" style="width: 40%" dir="ltr">{{ fill(contract.client_mobile) }}</td>
        </tr></table>

        <table class="ln"><tr>
            <td class="k" style="width: 16%">العنوان الكامل:</td>
            <td class="v" style="width: 84%">{{ fill(contract.client_address) }}</td>
        </tr></table>

        <table class="ln mt-1"><tr>
            <td class="k" style="width: 40%">على أن يستأجر الطرف الثاني من الطرف الأول شاليه رقم:</td>
            <!-- bdi, not a bare span: a name ending in a Latin digit otherwise merges into the code. -->
            <td class="v" style="width: 32%">
                <bdi>{{ contract.unit_name ?? '' }}</bdi>
                <bdi v-if="contract.unit_code" dir="ltr">({{ contract.unit_code }})</bdi>
            </td>
            <td class="g" style="width: 8%">الحجز:</td>
            <td class="v" style="width: 20%" dir="ltr">{{ fill(contract.booking_reference) }}</td>
        </tr></table>

        <table v-if="contract.sections" class="ln"><tr>
            <td class="k" style="width: 16%">النطاق المحجوز:</td>
            <td class="v" style="width: 84%">{{ contract.sections }}</td>
        </tr></table>

        <table class="ln tight"><tr>
            <td class="k" style="width: 12%">تبدأ من يوم</td>
            <td class="v" style="width: 12%">{{ fill(contract.check_in_day) }}</td>
            <td class="g" style="width: 7%">بتاريخ</td>
            <td class="v" style="width: 31%" dir="ltr">{{ fill(contract.booking_date) }} — {{ fill(contract.booking_date_hijri) }}</td>
            <td class="g" style="width: 18%">وقت الدخول الساعة</td>
            <td class="v" style="width: 20%">{{ fill(contract.check_in_time) }}</td>
        </tr></table>

        <table class="ln tight"><tr>
            <td class="k" style="width: 12%">إلى يوم</td>
            <td class="v" style="width: 12%">{{ fill(contract.check_out_day) }}</td>
            <td class="g" style="width: 7%">بتاريخ</td>
            <td class="v" style="width: 31%" dir="ltr">{{ fill(contract.last_day_date) }} — {{ fill(contract.last_day_date_hijri) }}</td>
            <td class="g" style="width: 18%">وقت الخروج الساعة</td>
            <td class="v" style="width: 20%">{{ fill(contract.check_out_time) }}</td>
        </tr></table>

        <table class="ln"><tr>
            <td class="k" style="width: 14%">مدة الإقامة:</td>
            <td class="v" style="width: 30%">{{ fill(contract.duration_label ?? contract.days_count) }}</td>
            <td class="g" style="width: 14%">عدد الضيوف:</td>
            <td class="v" style="width: 42%">{{ fill(contract.guests_count) }}</td>
        </tr></table>

        <table class="ln"><tr>
            <td class="k" style="width: 18%">مبلغ إجمالي وقدره:</td>
            <td class="v" style="width: 82%">
                <b dir="ltr">{{ contract.total_amount ?? '' }}</b> ريال
                <span v-if="contract.total_amount_words" class="words">({{ contract.total_amount_words }})</span>
                <span v-if="contract.is_taxable" class="words">
                    — شامل ضريبة القيمة المضافة ({{ contract.tax_rate }}%) وقدرها <b dir="ltr">{{ contract.tax_amount }}</b> ريال
                </span>
            </td>
        </tr></table>

        <table class="ln"><tr>
            <td class="k" style="width: 18%">العربون المدفوع:</td>
            <td class="v" style="width: 30%"><b dir="ltr">{{ contract.deposit_amount ?? '' }}</b> ريال</td>
            <td class="g" style="width: 12%">المتبقي:</td>
            <td class="v" style="width: 40%"><b dir="ltr">{{ contract.remaining_amount ?? '' }}</b> ريال</td>
        </tr></table>

        <table v-if="hasSecurityDeposit" class="ln"><tr>
            <td class="k" style="width: 18%">التأمين المسترد:</td>
            <td class="v" style="width: 82%">
                <b dir="ltr">{{ contract.security_deposit }}</b> ريال
                <span class="words">— يُعاد كاملًا عند التسليم بلا ملاحظات</span>
            </td>
        </tr></table>

        <!-- Terms run over as many pages as they need, so they are not kept together. -->
        <pre v-if="termsText" class="terms">{{ termsText }}</pre>

        <div class="print-keep mt-2">
            <span class="lead">ملاحظة:</span>
            <div class="noteline"></div>
        </div>

        <!-- Signatures stay one block: a signature split from its name proves nothing. -->
        <table class="print-keep mt-3 w-full">
            <tr>
                <td class="w-1/2 pl-6 align-top">
                    <div class="who">الإدارة</div>
                    <table class="ln"><tr>
                        <td class="k" style="width: 20%">إسم:</td>
                        <td class="v" style="width: 80%">{{ fill(issuer.manager_name ?? issuer.business_name) }}</td>
                    </tr></table>
                    <table class="ln"><tr>
                        <td class="k" style="width: 20%">التوقيع:</td>
                        <td class="v" style="width: 80%">
                            <img v-if="issuer.manager_signature_url" :src="issuer.manager_signature_url" alt="التوقيع" class="inline h-9 w-auto object-contain print:h-7" />
                            <img v-if="issuer.stamp_url" :src="issuer.stamp_url" alt="الختم" class="mr-2 inline h-9 w-auto object-contain print:h-7" />
                            <span v-if="!issuer.manager_signature_url && !issuer.stamp_url">{{ fill(null) }}</span>
                        </td>
                    </tr></table>
                </td>
                <td class="w-1/2 pr-6 align-top">
                    <div class="who">المستأجر</div>
                    <table class="ln"><tr>
                        <td class="k" style="width: 20%">إسم:</td>
                        <td class="v" style="width: 80%">{{ fill(contract.client_name) }}</td>
                    </tr></table>
                    <table class="ln"><tr>
                        <td class="k" style="width: 20%">التوقيع:</td>
                        <td class="v" style="width: 80%">{{ fill(null) }}</td>
                    </tr></table>
                </td>
            </tr>
        </table>

        <div class="foot">
            <span v-if="issuer.phone" dir="ltr">{{ issuer.phone }}</span>
            <span v-if="issuer.phone && issuer.whatsapp"> - </span>
            <span v-if="issuer.whatsapp" dir="ltr">{{ issuer.whatsapp }}</span>
            <span v-if="issuer.address"> - {{ issuer.address }}</span>
        </div>
    </div>
</template>

<style scoped>
/* Mirrors resources/views/pdf/contract-stay.blade.php so the screen and the
   generated PDF stay the same document. */
.doc {
    color: #141414;
    font-size: 13px;
    line-height: 1.5;
}
.basmala {
    color: #8a8a8a;
    font-size: 11px;
    text-align: center;
    margin-bottom: 4px;
}
.biz {
    color: #c8102e;
    font-size: 27px;
    font-weight: 700;
    line-height: 1.15;
}
.tel {
    color: #c8102e;
    font-size: 13px;
    font-weight: 700;
}
.accent {
    color: #c8102e;
}
.datebox {
    width: 100%;
    border-collapse: collapse;
    border: 1px solid #141414;
}
.datebox td {
    border: 1px solid #9a9a9a;
    padding: 3px 5px;
    font-size: 11px;
}
.datebox .k {
    width: 36%;
    font-weight: 700;
}
.datebox .v {
    text-align: center;
}
.titlebox {
    display: inline-block;
    border: 1.5px solid #141414;
    padding: 5px 22px;
    font-size: 17px;
    font-weight: 700;
}
.nobox {
    display: inline-block;
    border: 1.5px solid #141414;
    padding: 5px 12px;
    font-size: 14px;
    font-weight: 700;
}
.lead {
    font-size: 14px;
    font-weight: 700;
}
.ln {
    width: 100%;
    table-layout: fixed;
    border-collapse: collapse;
}
/* Wrapping, not clipping: mpdf wraps a long value, and a name quietly cut
   off by an ellipsis is worse than one that takes a second line. */
.ln td {
    padding: 3px 2px 2px;
    vertical-align: bottom;
    font-size: 13px;
}
.ln td.k {
    font-weight: 700;
    padding-left: 6px;
}
.ln td.g {
    font-weight: 700;
    padding: 3px 10px 2px 6px;
}
.ln td.v {
    border-bottom: 1px dotted #6b6b6b;
}
.ln.tight td {
    font-size: 11.5px;
}
.words {
    color: #4a4a4a;
    font-size: 11.5px;
}
.terms {
    margin-top: 9px;
    font-family: inherit;
    font-size: 11px;
    line-height: 1.5;
    text-align: justify;
    white-space: pre-wrap;
}
.noteline {
    border-bottom: 1px dotted #6b6b6b;
    height: 15px;
    margin-top: 2px;
}
.who {
    color: #c8102e;
    font-size: 17px;
    font-weight: 700;
    margin-bottom: 4px;
}
.foot {
    margin: 14px -32px 0;
    background: #141414;
    color: #ffffff;
    text-align: center;
    padding: 6px 10px;
    font-size: 12px;
    font-weight: 700;
}

@media print {
    .doc {
        font-size: 10pt;
    }
    .biz {
        font-size: 20pt;
    }
    .tel,
    .ln td,
    .lead {
        font-size: 10pt;
    }
    .ln.tight td {
        font-size: 8.5pt;
    }
    .terms {
        font-size: 8.5pt;
    }
    .titlebox {
        font-size: 13pt;
    }
    .who {
        font-size: 13pt;
    }
    .foot {
        margin-left: 0;
        margin-right: 0;
        font-size: 9pt;
        -webkit-print-color-adjust: exact;
        print-color-adjust: exact;
    }
}
</style>
