<script setup lang="ts">
import { computed, ref, watch } from 'vue';

interface ServicesContract {
    number: string;
    terms: string | null;
    body: string;
    contract_date: string | null;
    contract_date_hijri: string | null;
    client_name: string | null;
    client_mobile: string | null;
    unit_name: string | null;
    unit_logo_url: string | null;
    booking_reference: string | null;
    booking_date: string | null;
    check_in_day: string | null;
    sections: string | null;
    total_amount: string | null;
    deposit_amount: string | null;
    remaining_amount: string | null;
    items: Line[];
}

/** A line of the list: the service, its unit price, its count and its remark. */
type Line = {
    name: string;
    code: string | null;
    quantity: number | string | null;
    unit_price: string;
    total_price: string;
    notes?: string | null;
};

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
        contract: ServicesContract;
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

// The hall's emblem before the business's: the sheet is drawn on the hall booked.
const logo = computed(() => props.contract.unit_logo_url ?? props.issuer.logo_url);
const logoFailed = ref(false);
watch(logo, () => (logoFailed.value = false));

// A blank value stays empty — its dotted underline is the fill-in run.
const fill = (value: string | null | undefined) => value || ' ';

// A count is printed as written — «2», not 2.00 — and a cell holding words
// instead of a count prints the words.
const qty = (value: number | string | null | undefined) => {
    if (value === null || value === undefined || value === '') return ' ';

    return Number.isFinite(Number(value)) ? String(Number(value)) : String(value);
};

const termsText = computed(() => (props.editable ? terms.value : props.contract.terms ?? props.contract.body));

// The pad rules empty rows under its printed services for whatever else was
// agreed; while editing those are real rows, so they can be typed into.
const MIN_ROWS = 12;

const lines = computed(() => (props.editable ? items.value : props.contract.items) ?? []);

const rows = computed(() =>
    Array.from({ length: Math.max(lines.value.length, MIN_ROWS) }, (_, i) => lines.value[i] ?? null),
);
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
                <td class="w-[26%]"><span class="serial" dir="ltr">{{ contract.number }}</span></td>
                <td class="w-[48%] text-center"><span class="titlebox">عقد وقائمة الخدمات للمناسبة</span></td>
                <td class="w-[26%]"></td>
            </tr>
        </table>

        <!-- One table per LINE, every cell explicitly sized — the same widths the
             PDF uses, so the screen and the generated file stay one document. -->
        <table class="ln"><tr>
            <td class="k" style="width: 13%">رقم عقد التأجير</td>
            <td class="v" style="width: 22%" dir="ltr">
                <input v-if="editable" v-model="fields.booking_reference" class="fillin" dir="ltr" />
                <template v-else>{{ fill(contract.booking_reference) }}</template>
            </td>
            <td class="k pr-2" style="width: 6%">اليوم</td>
            <td class="v" style="width: 17%">
                <input v-if="editable" v-model="fields.check_in_day" class="fillin" />
                <template v-else>{{ fill(contract.check_in_day) }}</template>
            </td>
            <td class="k pr-2" style="width: 10%">تاريخ العقد</td>
            <td class="v" style="width: 17%" dir="ltr">
                <input v-if="editable" v-model="fields.contract_date" class="fillin" dir="ltr" />
                <template v-else>{{ fill(contract.contract_date) }}</template>
            </td>
            <td class="k pr-2" style="width: 6%">الموافق</td>
            <td class="v" style="width: 15%" dir="ltr">
                <input v-if="editable" v-model="fields.contract_date_hijri" class="fillin" dir="ltr" />
                <template v-else>{{ fill(contract.contract_date_hijri) }} هـ</template>
            </td>
        </tr></table>

        <table class="ln"><tr>
            <td class="k" style="width: 12%">اسم المستأجر</td>
            <td class="v" style="width: 34%">
                <input v-if="editable" v-model="fields.client_name" class="fillin" />
                <template v-else>{{ fill(contract.client_name) }}</template>
            </td>
            <td class="k pr-2" style="width: 8%">الجوال</td>
            <td class="v" style="width: 20%" dir="ltr">
                <input v-if="editable" v-model="fields.client_mobile" class="fillin" dir="ltr" />
                <template v-else>{{ fill(contract.client_mobile) }}</template>
            </td>
            <td class="k pr-2" style="width: 11%">تاريخ المناسبة</td>
            <td class="v" style="width: 15%" dir="ltr">
                <input v-if="editable" v-model="fields.booking_date" class="fillin" dir="ltr" />
                <template v-else>{{ fill(contract.booking_date) }}</template>
            </td>
        </tr></table>

        <!-- The section box, as the paper rules it. -->
        <table class="boxes print-keep w-full">
            <tr>
                <td class="w-[12%]">قسم رقم :</td>
                <td class="tick w-[20%]">
                    <input v-if="editable" v-model="fields.sections" class="fillin center" />
                    <template v-else>{{ fill(contract.sections) }}</template>
                </td>
                <td class="w-[68%]"></td>
            </tr>
        </table>

        <!-- The services as the pad rules them: the printed list first, then the
             empty runs for whatever else was agreed. -->
        <table class="grid print-keep">
            <thead>
                <tr>
                    <th style="width: 6%">م</th>
                    <th style="width: 33%">الطلـب</th>
                    <th style="width: 14%">السعر الفردي</th>
                    <th style="width: 9%">العدد</th>
                    <th style="width: 15%">السعر الإجمالي</th>
                    <th style="width: 23%">ملاحظات</th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="(row, i) in rows" :key="i">
                    <td class="c">{{ i + 1 }}</td>
                    <!-- While editing, every cell is typed into directly, the empty
                         rows included — that is how the pad is filled. -->
                    <td>
                        <input v-if="editable" v-model="items[i].name" class="fillin" />
                        <template v-else>{{ fill(row?.name) }}</template>
                    </td>
                    <td class="c" dir="ltr">
                        <input v-if="editable" v-model="items[i].unit_price" class="fillin center" dir="ltr" />
                        <template v-else>{{ fill(row?.unit_price) }}</template>
                    </td>
                    <td class="c" dir="ltr">
                        <input v-if="editable" v-model="items[i].quantity" class="fillin center" dir="ltr" />
                        <template v-else>{{ qty(row?.quantity) }}</template>
                    </td>
                    <td class="c" dir="ltr">
                        <input v-if="editable" v-model="items[i].total_price" class="fillin center bold" dir="ltr" />
                        <b v-else dir="ltr">{{ fill(row?.total_price) }}</b>
                    </td>
                    <td>
                        <input v-if="editable" v-model="items[i].notes" class="fillin" />
                        <template v-else>{{ fill(row?.notes) }}</template>
                    </td>
                </tr>
            </tbody>
        </table>

        <!-- The money boxes as the paper prints them, the receipt written by hand. -->
        <table class="totals print-keep">
            <tr>
                <td class="k">الإجمالي</td>
                <td class="v">
                    <input v-if="editable" v-model="fields.total_amount" class="fillin bold" dir="ltr" />
                    <b v-else dir="ltr">{{ fill(contract.total_amount) }}</b>
                </td>
                <td class="k">المدفوع</td>
                <td class="v">
                    <input v-if="editable" v-model="fields.deposit_amount" class="fillin bold" dir="ltr" />
                    <b v-else dir="ltr">{{ fill(contract.deposit_amount) }}</b>
                </td>
                <td class="k">الباقي</td>
                <td class="v">
                    <input v-if="editable" v-model="fields.remaining_amount" class="fillin bold" dir="ltr" />
                    <b v-else dir="ltr">{{ fill(contract.remaining_amount) }}</b>
                </td>
                <td class="k">سند قبض</td>
                <td class="v">{{ fill(null) }}</td>
            </tr>
        </table>

        <div v-if="editable || termsText" class="notes">
            <span class="lbl">ملحوظة</span>
            <textarea v-if="editable" v-model="terms" rows="4" class="notes-body notes-input"></textarea>
            <pre v-else class="notes-body">{{ termsText }}</pre>
        </div>

        <!-- Signatures stay one block: a signature split from its name proves nothing. -->
        <table class="print-keep mt-3 w-full">
            <tr>
                <td class="w-1/2 pl-6 align-top">
                    <table class="ln"><tr>
                        <td class="k" style="width: 30%">اسم المؤجر :</td>
                        <td class="v" style="width: 70%">{{ fill(issuer.manager_name ?? issuer.business_name) }}</td>
                    </tr></table>
                    <table class="ln"><tr>
                        <td class="k" style="width: 30%">التوقيع :</td>
                        <td class="v" style="width: 70%">
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
                        <td class="k" style="width: 32%">اسم المستأجر :</td>
                        <td class="v" style="width: 68%">{{ fill(editable ? fields.client_name : contract.client_name) }}</td>
                    </tr></table>
                    <table class="ln"><tr>
                        <td class="k" style="width: 32%">التوقيع :</td>
                        <td class="v" style="width: 68%">{{ fill(null) }}</td>
                    </tr></table>
                    <table class="ln"><tr>
                        <td class="k" style="width: 32%">الجوال :</td>
                        <td class="v" style="width: 68%" dir="ltr">
                            {{ fill(editable ? fields.client_mobile : contract.client_mobile) }}
                        </td>
                    </tr></table>
                </td>
            </tr>
        </table>

        <div class="band">
            تنبيه / إذا لم يتم كتابة أي خدمة من الخدمات في العقد فهو خارج عن مسؤولية إدارة
            {{ contract.unit_name ?? issuer.business_name }} ويكون تحت مسؤولية المستأجر
        </div>

        <div v-if="issuer.address" class="addr">{{ issuer.address }}</div>
    </div>
</template>

<style scoped>
/* Mirrors resources/views/pdf/contract-hall-services.blade.php so the screen and
   the generated PDF stay the same document. */
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
    font-size: 18px;
    font-weight: 700;
    letter-spacing: 1px;
}
.serial {
    font-size: 17px;
    font-weight: 700;
    color: #c8102e;
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
/* Not «grid» as a utility: display:grid on a table drops its columns. */
.grid {
    width: 100%;
    table-layout: fixed;
    border-collapse: collapse;
    border: 1px solid #16215b;
    margin-top: 8px;
}
.grid th {
    border: 1px solid #16215b;
    background: #eef2fb;
    padding: 4px;
    font-size: 12px;
    font-weight: 700;
    text-align: center;
}
.grid td {
    border: 1px solid #16215b;
    padding: 3px 5px;
    font-size: 12px;
    height: 21px;
}
.grid td.c {
    text-align: center;
}
.totals {
    width: 100%;
    table-layout: fixed;
    border-collapse: collapse;
    border: 1px solid #16215b;
    border-top: 0;
}
.totals td {
    border: 1px solid #16215b;
    padding: 4px 6px;
    font-size: 12.5px;
    text-align: center;
}
.totals td.k {
    background: #eef2fb;
    font-weight: 700;
    width: 11%;
}
.notes .lbl {
    font-size: 13px;
    font-weight: 700;
}
.notes-body {
    border: 1px solid #c3cde6;
    padding: 6px 8px;
    margin-top: 3px;
    font-family: inherit;
    font-size: 12px;
    line-height: 1.7;
    white-space: pre-wrap;
}
.notes-input {
    display: block;
    width: 100%;
    background: #f6f9ff;
    resize: vertical;
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
.band {
    margin: 10px -32px 0;
    background: #1b3a93;
    color: #ffffff;
    text-align: center;
    padding: 6px 10px;
    font-size: 12px;
    font-weight: 700;
}
.addr {
    margin-top: 8px;
    text-align: center;
    font-size: 12.5px;
    font-weight: 700;
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
        font-size: 14pt;
    }
    .ln td,
    .boxes td {
        font-size: 10pt;
    }
    .grid th,
    .grid td,
    .totals td {
        font-size: 9pt;
    }
    .notes-body {
        font-size: 9pt;
    }
    .band {
        margin-left: 0;
        margin-right: 0;
        font-size: 9pt;
        -webkit-print-color-adjust: exact;
        print-color-adjust: exact;
    }
    .grid th,
    .totals td.k {
        -webkit-print-color-adjust: exact;
        print-color-adjust: exact;
    }
}
</style>
