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
    items: { name: string; code: string | null; quantity: number; unit_price: string; total_price: string }[];
}

interface Issuer {
    business_name: string;
    logo_url: string | null;
    phone: string | null;
    whatsapp: string | null;
    commercial_register: string | null;
}

const props = defineProps<{ contract: MaintenanceContract; issuer: Issuer }>();

const logoFailed = ref(false);
watch(
    () => props.issuer.logo_url,
    () => (logoFailed.value = false),
);

const fill = (value: string | null | undefined) => value || ' ';

// Quantities are printed as written — «2», not 2.00.
const qty = (value: number | null | undefined) => (value === null || value === undefined ? ' ' : String(Number(value)));

// A money row is printed only when it carries an amount: the paper has no
// «الخصم 0.00» line, and a sheet with no VAT shows no VAT row.
const carries = (value: string | null | undefined) => !!value && Number(String(value).replace(/,/g, '')) !== 0;

const termsText = computed(() => props.contract.terms ?? props.contract.body);

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
    const lines = props.contract.items ?? [];
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

        <div class="to">إلى السـادة: {{ fill(contract.client_name) }}</div>

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
                <tr v-for="(line, i) in rows" :key="i">
                    <td dir="ltr">{{ line ? i + 1 : ' ' }}</td>
                    <td class="desc">
                        {{ fill(line?.name) }}
                        <span v-if="line?.code" class="code" dir="ltr">({{ line.code }})</span>
                    </td>
                    <td dir="ltr">{{ qty(line?.quantity) }}</td>
                    <td dir="ltr">{{ fill(line?.unit_price) }}</td>
                    <td dir="ltr">{{ fill(line?.total_price) }}</td>
                </tr>
            </tbody>
        </table>

        <!-- The totals sit alone at the left, as the paper has them. -->
        <table class="totals print-keep">
            <tr>
                <td class="lbl">الاجمــــالي</td>
                <td class="val" dir="ltr">{{ fill(contract.subtotal ?? contract.total_amount) }}</td>
            </tr>
            <tr v-if="carries(contract.discount_amount)">
                <td class="lbl">الخصــــم</td>
                <td class="val" dir="ltr">{{ contract.discount_amount }}</td>
            </tr>
            <tr v-if="carries(contract.tax_amount)">
                <td class="lbl">ضريبة القيمة المضافة</td>
                <td class="val" dir="ltr">{{ contract.tax_amount }}</td>
            </tr>
            <tr>
                <td class="lbl">الاجمالي المستحق</td>
                <td class="val" dir="ltr">{{ fill(contract.total_amount) }}</td>
            </tr>
        </table>

        <div v-if="termsText" class="notes print-keep">
            <span class="lbl">ملاحظـــات :</span>
            <ul v-if="bulleted">
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
