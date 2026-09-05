<script setup lang="ts">
import { computed, ref, watch } from 'vue';

interface MaintenanceContract {
    number: string;
    terms: string | null;
    body: string;
    client_name: string | null;
    subtotal: string | null;
    discount_amount: string | null;
    tax_amount: string | null;
    total_amount: string | null;
    items: Line[];
}

/** A priced line of the sheet; a blank row carries no count until one is typed. */
type Line = {
    name: string;
    code: string | null;
    /** A count, or the words written in its place — «حسب الاتفاق». */
    quantity: number | string | null;
    unit_price: string;
    total_price: string;
};

interface Issuer {
    business_name: string;
    logo_url: string | null;
    phone: string | null;
    whatsapp: string | null;
    commercial_register: string | null;
}

const props = withDefaults(
    defineProps<{
        contract: MaintenanceContract;
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

const logoFailed = ref(false);
watch(
    () => props.issuer.logo_url,
    () => (logoFailed.value = false),
);

const fill = (value: string | null | undefined) => value || ' ';

// Quantities are printed as written — «2», not 2.00 — and a cell holding words
// instead of a count prints the words.
const qty = (value: number | string | null | undefined) => {
    if (value === null || value === undefined || value === '') return ' ';

    return Number.isFinite(Number(value)) ? String(Number(value)) : String(value);
};

// A money row is printed only when it carries an amount: the paper has no
// «الخصم 0.00» line, and a sheet with no VAT shows no VAT row.
const carries = (value: string | null | undefined) => !!value && Number(String(value).replace(/,/g, '')) !== 0;

const termsText = computed(() => (props.editable ? terms.value : props.contract.terms ?? props.contract.body));

// The notes are written one bullet per line, so they are printed as a list;
// wording that is not bulleted is printed as it stands.
const bulleted = computed(() => termsText.value?.includes('•') ?? false);
const bullets = computed(() =>
    (termsText.value ?? '')
        .split(/\r?\n/)
        .map((line) => line.trim().replace(/^[•\-–*\s]+/, ''))
        .filter(Boolean),
);

// The pad is printed with room to write in, so a short sheet still rules
// enough lines for the visits to be listed by hand.
const rows = computed(() => {
    const lines = (props.editable ? items.value : props.contract.items) ?? [];
    return Array.from({ length: Math.max(lines.length, 4) }, (_, i) => lines[i] ?? null);
});
</script>

<template>
    <div class="doc mx-auto max-w-4xl bg-white p-8 text-slate-900 shadow-sm print:max-w-none print:p-0 print:shadow-none">
        <!-- Letterhead: emblem, name, phones and the CR number, all centred. -->
        <div class="print-keep text-center">
            <img
                v-if="issuer.logo_url && !logoFailed"
                :src="issuer.logo_url"
                :alt="issuer.business_name"
                class="mx-auto max-h-[110px] w-auto object-contain print:max-h-[80px]"
                @error="logoFailed = true"
            />
            <div class="biz">{{ issuer.business_name }}</div>
            <div v-if="issuer.phone || issuer.whatsapp" class="line">
                رقم الجوال:
                <span dir="ltr"
                    >{{ issuer.phone }}<template v-if="issuer.whatsapp"> - {{ issuer.whatsapp }}</template></span
                >
            </div>
            <div v-if="issuer.commercial_register" class="line">
                س ت: <span dir="ltr">{{ issuer.commercial_register }}</span>
            </div>
        </div>

        <div class="to">
            إلى السـادة:
            <input v-if="editable" v-model="fields.client_name" class="fillin to-input" />
            <template v-else>{{ fill(contract.client_name) }}</template>
        </div>

        <div class="subject">عرض سعر صيانة مسابح شهريًا</div>

        <table class="items print-keep">
            <thead>
                <tr>
                    <th style="width: 7%">م</th>
                    <th style="width: 48%">البيان</th>
                    <th style="width: 13%">العدد</th>
                    <th style="width: 16%">السعر</th>
                    <th style="width: 16%">الاجمالي</th>
                </tr>
            </thead>
            <tbody>
                <!-- While editing, every cell of the sheet is typed into directly,
                     the empty rows included — that is how the pad is filled. -->
                <tr v-for="(line, i) in rows" :key="i">
                    <td dir="ltr">{{ line || editable ? i + 1 : ' ' }}</td>
                    <td class="desc">
                        <input v-if="editable" v-model="items[i].name" class="fillin" />
                        <template v-else>
                            {{ fill(line?.name) }}
                            <span v-if="line?.code" class="code" dir="ltr">({{ line.code }})</span>
                        </template>
                    </td>
                    <td dir="ltr">
                        <input v-if="editable" v-model="items[i].quantity" class="fillin center" dir="ltr" />
                        <template v-else>{{ qty(line?.quantity) }}</template>
                    </td>
                    <td dir="ltr">
                        <input v-if="editable" v-model="items[i].unit_price" class="fillin center" dir="ltr" />
                        <template v-else>{{ fill(line?.unit_price) }}</template>
                    </td>
                    <td dir="ltr">
                        <input v-if="editable" v-model="items[i].total_price" class="fillin center" dir="ltr" />
                        <template v-else>{{ fill(line?.total_price) }}</template>
                    </td>
                </tr>
            </tbody>
        </table>

        <!-- The totals sit alone at the left, as the paper has them. -->
        <table class="totals print-keep">
            <tr>
                <td class="lbl">الاجمــــالي</td>
                <td class="val" dir="ltr">
                    <input v-if="editable" v-model="fields.subtotal" class="fillin center" dir="ltr" />
                    <template v-else>{{ fill(contract.subtotal ?? contract.total_amount) }}</template>
                </td>
            </tr>
            <!-- A zero row is not printed on the paper, but it is offered while
                 editing — that is where a discount is written in. -->
            <tr v-if="editable || carries(contract.discount_amount)">
                <td class="lbl">الخصــــم</td>
                <td class="val" dir="ltr">
                    <input v-if="editable" v-model="fields.discount_amount" class="fillin center" dir="ltr" />
                    <template v-else>{{ contract.discount_amount }}</template>
                </td>
            </tr>
            <tr v-if="editable || carries(contract.tax_amount)">
                <td class="lbl">ضريبة القيمة المضافة</td>
                <td class="val" dir="ltr">
                    <input v-if="editable" v-model="fields.tax_amount" class="fillin center" dir="ltr" />
                    <template v-else>{{ contract.tax_amount }}</template>
                </td>
            </tr>
            <tr>
                <td class="lbl">الاجمالي المستحق</td>
                <td class="val" dir="ltr">
                    <input v-if="editable" v-model="fields.total_amount" class="fillin center" dir="ltr" />
                    <template v-else>{{ fill(contract.total_amount) }}</template>
                </td>
            </tr>
        </table>

        <div v-if="editable || termsText" class="notes print-keep">
            <span class="lbl">ملاحظـــات :</span>
            <textarea v-if="editable" v-model="terms" rows="8" class="fillin notes-input"></textarea>
            <ul v-else-if="bulleted">
                <li v-for="(bullet, i) in bullets" :key="i">{{ bullet }}</li>
            </ul>
            <pre v-else class="plain">{{ termsText }}</pre>
        </div>
    </div>
</template>

<style scoped>
/* Mirrors resources/views/pdf/contract-maintenance.blade.php so the screen and
   the generated PDF stay the same document. */
.doc {
    color: #14224a;
    font-size: 13px;
    line-height: 1.6;
}
.biz {
    font-size: 17px;
    font-weight: 700;
    margin-top: 10px;
}
.line {
    font-size: 15px;
    font-weight: 700;
    margin-top: 5px;
}
.to {
    font-size: 16px;
    font-weight: 700;
    margin: 20px 0 10px;
}
.subject {
    text-align: center;
    font-size: 16px;
    font-weight: 700;
    margin-bottom: 18px;
}
.items {
    width: 100%;
    table-layout: fixed;
    border-collapse: collapse;
    border: 1px solid #000000;
}
.items th,
.items td {
    border: 1px solid #000000;
    padding: 5px 6px;
    font-size: 13px;
    text-align: center;
    height: 26px;
}
.items th {
    font-weight: 700;
}
.items td.desc {
    text-align: right;
}
.code {
    font-size: 10px;
    color: #6b7a99;
}
.totals {
    width: 45%;
    table-layout: fixed;
    border-collapse: collapse;
    border: 1px solid #000000;
    /* Floated, because a plain table on an RTL page hugs the right margin. */
    float: left;
    margin-top: 12px;
}
.totals td {
    border: 1px solid #000000;
    padding: 5px 7px;
    font-size: 13px;
    height: 26px;
}
.totals td.lbl {
    text-align: center;
    font-weight: 700;
    width: 55%;
}
.totals td.val {
    text-align: center;
    width: 45%;
}
.notes {
    clear: both;
    padding-top: 34px;
}
.notes .lbl {
    font-size: 16px;
    font-weight: 700;
}
.notes ul {
    margin: 10px 28px 0 0;
    padding: 0;
    list-style: disc;
}
.notes li {
    font-size: 14px;
    margin-bottom: 8px;
}
.plain {
    font-family: inherit;
    font-size: 14px;
    white-space: pre-wrap;
    margin-top: 10px;
}
/* An input on the printed line: the paper's rule stays, the box does not. */
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
.fillin.center {
    text-align: center;
}
.to-input {
    width: 60%;
}
.notes-input {
    display: block;
    margin-top: 10px;
    font-size: 14px;
    line-height: 1.7;
    resize: vertical;
}

@media print {
    .doc {
        font-size: 10pt;
    }
    .biz,
    .to,
    .subject,
    .notes .lbl {
        font-size: 12pt;
    }
    .line,
    .notes li,
    .plain {
        font-size: 11pt;
    }
    .items th,
    .items td,
    .totals td {
        font-size: 10pt;
    }
}
</style>
