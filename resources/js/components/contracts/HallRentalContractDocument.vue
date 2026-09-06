<script setup lang="ts">
import { computed, ref, watch } from 'vue';

interface HallContract {
    number: string;
    terms: string | null;
    body: string;
    client_name: string | null;
    client_mobile: string | null;
    client_id_number: string | null;
    client_birth_place: string | null;
    unit_name: string | null;
    unit_logo_url: string | null;
    sections: string | null;
    booking_date: string | null;
    booking_date_hijri: string | null;
    check_in_day: string | null;
    check_in_time: string | null;
    guests_count: string | null;
    total_amount: string | null;
    deposit_amount: string | null;
    remaining_amount: string | null;
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

const props = withDefaults(
    defineProps<{
        contract: HallContract;
        issuer: Issuer;
        /** The same sheet, filled in on screen — see InstallationContractDocument. */
        editable?: boolean;
    }>(),
    { editable: false },
);

// Models, not props: the edit screen owns the values, and a defineModel ref may
// be written to from here without mutating what was handed down.
const fields = defineModel<Record<string, string>>('fields', { default: () => ({}) });
const terms = defineModel<string>('terms', { default: '' });

// شعار القاعة أولى من شعار المنشأة: العقد يُحرَّر على القاعة التي حُجزت.
const logo = computed(() => props.contract.unit_logo_url ?? props.issuer.logo_url);
const logoFailed = ref(false);
watch(logo, () => (logoFailed.value = false));

// A blank value stays empty — its dotted underline is the fill-in run.
const fill = (value: string | null | undefined) => value || ' ';

const termsText = computed(() => (props.editable ? terms.value : props.contract.terms ?? props.contract.body));
</script>

<template>
    <div class="doc mx-auto max-w-4xl bg-white p-8 text-slate-900 shadow-sm print:max-w-none print:p-0 print:shadow-none">
        <!-- Letterhead: the hall right, its emblem centre, the accountant left. -->
        <table class="print-keep w-full">
            <tr>
                <td class="w-[36%] align-top">
                    <div class="hall">{{ contract.unit_name ?? issuer.business_name }}</div>
                    <div v-if="issuer.phone" class="tel" dir="ltr">{{ issuer.phone }}</div>
                </td>
                <td class="w-[28%] text-center align-top">
                    <img
                        v-if="logo && !logoFailed"
                        :src="logo"
                        :alt="contract.unit_name ?? 'الشعار'"
                        class="mx-auto max-h-[78px] w-auto object-contain print:max-h-[58px]"
                        @error="logoFailed = true"
                    />
                </td>
                <td class="w-[36%] text-left align-top">
                    <template v-if="issuer.whatsapp">
                        <div class="who">المحاسب</div>
                        <div class="tel" dir="ltr">{{ issuer.whatsapp }}</div>
                    </template>
                </td>
            </tr>
        </table>

        <!-- The title in its rule, the serial beside it in red, as the pad prints. -->
        <table class="banner print-keep w-full">
            <tr>
                <td class="w-[30%]"><span class="serial" dir="ltr">{{ contract.number }}</span></td>
                <td class="w-[40%] text-center"><span class="titlebox">عقد إيجار</span></td>
                <td class="w-[30%]"></td>
            </tr>
        </table>

        <div class="lead">تم الاتفاق بين {{ issuer.business_name }} للاحتفالات والمناسبات</div>

        <!-- One table per LINE, every cell explicitly sized — the same widths the
             PDF uses, so the screen and the generated file stay one document. -->
        <table class="ln"><tr>
            <td class="k" style="width: 20%">الطرف الثاني المستأجر/</td>
            <td class="v" style="width: 46%">
                <input v-if="editable" v-model="fields.client_name" class="fillin" />
                <template v-else>{{ fill(contract.client_name) }}</template>
            </td>
            <td class="k pr-2" style="width: 10%">سجل مدني</td>
            <td class="v" style="width: 24%" dir="ltr">
                <input v-if="editable" v-model="fields.client_id_number" class="fillin" dir="ltr" />
                <template v-else>{{ fill(contract.client_id_number) }}</template>
            </td>
        </tr></table>

        <table class="ln"><tr>
            <td class="k" style="width: 11%">مكان الميلاد</td>
            <td class="v" style="width: 43%">
                <input v-if="editable" v-model="fields.client_birth_place" class="fillin" />
                <template v-else>{{ fill(contract.client_birth_place) }}</template>
            </td>
            <td class="k pr-2" style="width: 10%">جوال رقم</td>
            <td class="v" style="width: 36%" dir="ltr">
                <input v-if="editable" v-model="fields.client_mobile" class="fillin" dir="ltr" />
                <template v-else>{{ fill(contract.client_mobile) }}</template>
            </td>
        </tr></table>

        <table class="ln"><tr>
            <td class="k" style="width: 11%">تاريخ الدخول</td>
            <td class="v" style="width: 21%" dir="ltr">
                <input v-if="editable" v-model="fields.booking_date" class="fillin" dir="ltr" />
                <template v-else>{{ fill(contract.booking_date) }}</template>
            </td>
            <td class="k" style="width: 6%">الموافق</td>
            <td class="v" style="width: 18%" dir="ltr">
                <input v-if="editable" v-model="fields.booking_date_hijri" class="fillin" dir="ltr" />
                <template v-else>{{ fill(contract.booking_date_hijri) }} هـ</template>
            </td>
            <td class="k pr-2" style="width: 10%">قيمة الإيجار</td>
            <td class="v" style="width: 16%">
                <input v-if="editable" v-model="fields.total_amount" class="fillin bold" dir="ltr" />
                <b v-else dir="ltr">{{ fill(contract.total_amount) }}</b>
            </td>
            <td class="k pr-2" style="width: 6%">واصل</td>
            <td class="v" style="width: 12%">
                <input v-if="editable" v-model="fields.deposit_amount" class="fillin bold" dir="ltr" />
                <b v-else dir="ltr">{{ fill(contract.deposit_amount) }}</b>
            </td>
        </tr></table>

        <table class="ln"><tr>
            <td class="k" style="width: 8%">باقي</td>
            <td class="v" style="width: 20%">
                <input v-if="editable" v-model="fields.remaining_amount" class="fillin bold" dir="ltr" />
                <b v-else dir="ltr">{{ fill(contract.remaining_amount) }}</b>
            </td>
            <td class="k pr-2" style="width: 10%">عدد الضيوف</td>
            <td class="v" style="width: 14%">
                <input v-if="editable" v-model="fields.guests_count" class="fillin" dir="ltr" />
                <template v-else>{{ fill(contract.guests_count) }}</template>
            </td>
            <td class="k pr-2" style="width: 10%">وقت الدخول</td>
            <td class="v" style="width: 38%">
                <input v-if="editable" v-model="fields.check_in_time" class="fillin" />
                <template v-else>{{ fill(contract.check_in_time) }}</template>
            </td>
        </tr></table>

        <!-- The section box and the day box, as the paper rules them. -->
        <table class="boxes print-keep w-full">
            <tr>
                <td class="w-[12%]">قسم رقم :</td>
                <td class="tick w-[26%]">
                    <input v-if="editable" v-model="fields.sections" class="fillin center" />
                    <template v-else>{{ fill(contract.sections) }}</template>
                </td>
                <td class="w-[26%]"></td>
                <td class="daybox w-[20%]">
                    <input v-if="editable" v-model="fields.check_in_day" class="fillin center" />
                    <template v-else>{{ fill(contract.check_in_day) }}</template>
                </td>
                <td class="w-[16%]"></td>
            </tr>
        </table>

        <!-- الشروط تمتدّ على ما تحتاجه من أوراق، فلا تُقيَّد بكتلة واحدة -->
        <textarea v-if="editable" v-model="terms" rows="18" class="terms terms-input"></textarea>
        <pre v-else-if="termsText" class="terms">{{ termsText }}</pre>

        <!-- Signatures stay one block: a signature split from its name proves nothing. -->
        <table class="print-keep mt-3 w-full">
            <tr>
                <td class="w-1/2 pl-6 align-top">
                    <table class="ln"><tr>
                        <td class="k" style="width: 32%">الطرف الأول المؤجر :</td>
                        <td class="v" style="width: 68%">{{ fill(issuer.manager_name ?? issuer.business_name) }}</td>
                    </tr></table>
                    <table class="ln"><tr>
                        <td class="k" style="width: 32%">التوقيع :</td>
                        <td class="v" style="width: 68%">
                            <img
                                v-if="issuer.manager_signature_url"
                                :src="issuer.manager_signature_url"
                                alt="التوقيع"
                                class="inline h-9 w-auto object-contain print:h-7"
                            />
                            <img
                                v-if="issuer.stamp_url"
                                :src="issuer.stamp_url"
                                alt="الختم"
                                class="mr-2 inline h-9 w-auto object-contain print:h-7"
                            />
                            <span v-if="!issuer.manager_signature_url && !issuer.stamp_url">{{ fill(null) }}</span>
                        </td>
                    </tr></table>
                </td>
                <td class="w-1/2 pr-6 align-top">
                    <table class="ln"><tr>
                        <td class="k" style="width: 34%">الطرف الثاني المستأجر :</td>
                        <td class="v" style="width: 66%">{{ fill(editable ? fields.client_name : contract.client_name) }}</td>
                    </tr></table>
                    <table class="ln"><tr>
                        <td class="k" style="width: 34%">التوقيع :</td>
                        <td class="v" style="width: 66%">{{ fill(null) }}</td>
                    </tr></table>
                    <div class="read">التوقيع بالقراءة والعلم</div>
                </td>
            </tr>
        </table>

        <div v-if="issuer.address" class="band">{{ issuer.address }}</div>
    </div>
</template>

<style scoped>
/* Mirrors resources/views/pdf/contract-hall.blade.php so the screen and the
   generated PDF stay the same document. */
.doc {
    color: #16215b;
    font-size: 13px;
    line-height: 1.5;
}
.hall {
    font-size: 22px;
    font-weight: 700;
    line-height: 1.2;
}
.tel {
    font-size: 15px;
    font-weight: 700;
}
.who {
    font-size: 14px;
    font-weight: 700;
}
.banner {
    margin: 8px 0 6px;
    border-top: 1.5px solid #16215b;
    border-bottom: 1.5px solid #16215b;
    padding: 4px 0;
}
.titlebox {
    font-size: 19px;
    font-weight: 700;
    letter-spacing: 1px;
}
.serial {
    font-size: 17px;
    font-weight: 700;
    color: #c8102e;
}
.lead {
    margin: 6px 0 3px;
    font-size: 13.5px;
    font-weight: 700;
}
.ln {
    width: 100%;
    table-layout: fixed;
    border-collapse: collapse;
}
.ln td {
    padding: 4px 2px 1px;
    vertical-align: bottom;
    font-size: 13px;
}
.ln td.k {
    font-weight: 700;
    padding-left: 5px;
}
.ln td.v {
    border-bottom: 1px dotted #6b6b6b;
}
.boxes {
    margin-top: 6px;
    border-collapse: separate;
    border-spacing: 4px 0;
}
.boxes td {
    font-size: 13px;
    font-weight: 700;
    vertical-align: middle;
}
.tick {
    border: 1px solid #16215b;
    padding: 3px 10px;
    text-align: center;
}
.daybox {
    border: 1px solid #16215b;
    border-radius: 14px;
    padding: 4px 20px;
    text-align: center;
}
.terms {
    margin-top: 10px;
    font-family: inherit;
    font-size: 12px;
    line-height: 1.9;
    text-align: justify;
    white-space: pre-wrap;
}
.read {
    margin-top: 6px;
    text-align: left;
    font-size: 12.5px;
    font-weight: 700;
}
.band {
    margin: 12px -32px 0;
    background: #1b3a93;
    color: #ffffff;
    text-align: center;
    padding: 6px 10px;
    font-size: 12px;
    font-weight: 700;
}
/* An input on the printed run: the paper's line stays, the box does not. */
.fillin {
    width: 100%;
    padding: 0 2px;
    border: 0;
    background: #f6f9ff;
    font: inherit;
    color: inherit;
    outline: none;
}
.fillin:focus {
    background: #e6efff;
}
.fillin.bold {
    font-weight: 700;
}
.fillin.center {
    text-align: center;
}
.terms-input {
    display: block;
    width: 100%;
    background: #f6f9ff;
    resize: vertical;
}

@media print {
    .doc {
        font-size: 10pt;
    }
    .hall {
        font-size: 17pt;
    }
    .tel {
        font-size: 12pt;
    }
    .titlebox {
        font-size: 15pt;
    }
    .ln td,
    .boxes td,
    .lead {
        font-size: 10pt;
    }
    .terms {
        font-size: 9pt;
    }
    .band {
        margin-left: 0;
        margin-right: 0;
        font-size: 9pt;
        -webkit-print-color-adjust: exact;
        print-color-adjust: exact;
    }
}
</style>
