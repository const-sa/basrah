<script setup lang="ts">
import { computed, ref, watch } from 'vue';

interface MaintenanceContract {
    number: string;
    contract_date?: string | null;
    quotation_number?: string | null;
    client_mobile?: string | null;
    terms: string | null;
    body: string;
    client_name: string | null;
    subtotal: string | null;
    discount_amount: string | null;
    tax_amount: string | null;
    total_amount: string | null;
    /** ما قُبض على العقد وما بقي — من سندات القبض لا من اللقطة. */
    deposit_amount: string | null;
    remaining_amount: string | null;
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
    email?: string | null;
    address?: string | null;
    tax_number?: string | null;
    commercial_register: string | null;
    manager_name?: string | null;
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

const termsText = computed(() => (props.editable ? terms.value : (props.contract.terms ?? props.contract.body)));

// The notes are written one bullet per line, so they are printed as a list;
// wording that is not bulleted is printed as it stands.
const bulleted = computed(() => termsText.value?.includes('•') ?? false);
const bullets = computed(() =>
    (termsText.value ?? '')
        .split(/\r?\n/)
        .map((line) => line.trim().replace(/^[•\-–*\s]+/, ''))
        .filter(Boolean),
);

// Read as the PDF prints them; while editing, every line is a row to type into.
const rows = computed(() => (props.editable ? items.value : props.contract.items) ?? []);

const money = (value: string | null | undefined) => (value ? `${value} ريال` : '—');
</script>

<template>
    <div class="doc mx-auto max-w-4xl bg-white p-8 text-slate-900 shadow-sm print:max-w-none print:p-0 print:shadow-none">
        <!-- Header: the issuer on the right, the document's box on the left — as the quotation. -->
        <div class="print-keep flex items-start justify-between gap-6">
            <div class="flex min-w-0 items-center gap-3">
                <img
                    v-if="issuer.logo_url && !logoFailed"
                    :src="issuer.logo_url"
                    :alt="issuer.business_name"
                    class="max-h-[80px] w-auto max-w-[140px] object-contain"
                    @error="logoFailed = true"
                />
                <div class="min-w-0">
                    <div class="brand">{{ issuer.business_name }}</div>
                    <div v-if="issuer.address" class="muted">{{ issuer.address }}</div>
                    <div v-if="issuer.phone || issuer.whatsapp" class="muted">
                        هاتف:
                        <span dir="ltr"
                            >{{ issuer.phone
                            }}<template v-if="issuer.whatsapp && issuer.whatsapp !== issuer.phone"> - {{ issuer.whatsapp }}</template></span
                        >
                    </div>
                    <div v-if="issuer.email" class="muted">{{ issuer.email }}</div>
                    <div v-if="issuer.tax_number" class="muted">الرقم الضريبي: {{ issuer.tax_number }}</div>
                    <div v-if="issuer.commercial_register" class="muted">السجل التجاري: {{ issuer.commercial_register }}</div>
                </div>
            </div>

            <div class="w-[38%] shrink-0">
                <div class="doc-title">
                    <div class="t">عرض سعر صيانة مسابح شهريًا</div>
                    <div class="sub">MONTHLY MAINTENANCE</div>
                </div>
                <table class="meta">
                    <tr>
                        <td class="k">رقم العقد</td>
                        <td class="v" dir="ltr">{{ contract.number }}</td>
                    </tr>
                    <tr v-if="contract.contract_date">
                        <td class="k">تاريخ العقد</td>
                        <td class="v" dir="ltr">{{ contract.contract_date }}</td>
                    </tr>
                    <tr v-if="contract.quotation_number">
                        <td class="k">عرض السعر</td>
                        <td class="v" dir="ltr">{{ contract.quotation_number }}</td>
                    </tr>
                </table>
            </div>
        </div>

        <div class="rule"></div>

        <!-- The client card. -->
        <div class="print-keep">
            <div class="card-title">مقدَّم إلى</div>
            <div class="card-body">
                <div class="flex-1">
                    <span class="muted">العميل:</span>
                    <input v-if="editable" v-model="fields.client_name" class="fillin client-input" />
                    <b v-else>{{ fill(contract.client_name) }}</b>
                </div>
                <div v-if="contract.client_mobile" class="flex-1">
                    <span class="muted">الجوال:</span> <b dir="ltr">{{ contract.client_mobile }}</b>
                </div>
            </div>
        </div>

        <table class="items print-keep">
            <thead>
                <tr>
                    <th style="width: 6%">#</th>
                    <th style="width: 50%; text-align: right">البيان</th>
                    <th style="width: 12%">العدد</th>
                    <th style="width: 16%">السعر</th>
                    <th style="width: 16%">الإجمالي</th>
                </tr>
            </thead>
            <tbody>
                <!-- While editing, every cell is typed into directly, the empty rows included. -->
                <tr v-for="(line, i) in rows" :key="i" :class="{ alt: i % 2 === 1 }">
                    <td class="c">{{ i + 1 }}</td>
                    <td>
                        <input v-if="editable" v-model="items[i].name" class="fillin" />
                        <template v-else>
                            <b>{{ fill(line?.name) }}</b>
                            <div v-if="line?.code" class="muted">{{ line.code }}</div>
                        </template>
                    </td>
                    <td class="c" dir="ltr">
                        <input v-if="editable" v-model="items[i].quantity" class="fillin center" dir="ltr" />
                        <template v-else>{{ qty(line?.quantity) }}</template>
                    </td>
                    <td class="c" dir="ltr">
                        <input v-if="editable" v-model="items[i].unit_price" class="fillin center" dir="ltr" />
                        <template v-else>{{ fill(line?.unit_price) }}</template>
                    </td>
                    <td class="c" dir="ltr">
                        <input v-if="editable" v-model="items[i].total_price" class="fillin center" dir="ltr" />
                        <b v-else>{{ fill(line?.total_price) }}</b>
                    </td>
                </tr>
                <tr v-if="!rows.length">
                    <td colspan="5" class="c muted">لا بنود</td>
                </tr>
            </tbody>
        </table>

        <!-- The totals, on the left of the sheet. -->
        <div class="flex justify-end">
            <table class="totals print-keep">
                <tr>
                    <td class="k">الإجمالي</td>
                    <td class="v" dir="ltr">
                        <input v-if="editable" v-model="fields.subtotal" class="fillin center" dir="ltr" />
                        <template v-else>{{ money(contract.subtotal ?? contract.total_amount) }}</template>
                    </td>
                </tr>
                <!-- A zero row is not printed, but it is offered while editing —
                     that is where a discount is written in. -->
                <tr v-if="editable || carries(contract.discount_amount)" class="discount">
                    <td class="k">الخصم</td>
                    <td class="v" dir="ltr">
                        <input v-if="editable" v-model="fields.discount_amount" class="fillin center" dir="ltr" />
                        <template v-else>{{ money(contract.discount_amount) }}</template>
                    </td>
                </tr>
                <tr v-if="editable || carries(contract.tax_amount)">
                    <td class="k">ضريبة القيمة المضافة</td>
                    <td class="v" dir="ltr">
                        <input v-if="editable" v-model="fields.tax_amount" class="fillin center" dir="ltr" />
                        <template v-else>{{ money(contract.tax_amount) }}</template>
                    </td>
                </tr>
                <tr class="grand">
                    <td>الإجمالي المستحق</td>
                    <td class="v" dir="ltr">
                        <input v-if="editable" v-model="fields.total_amount" class="fillin center" dir="ltr" />
                        <template v-else>{{ money(contract.total_amount) }}</template>
                    </td>
                </tr>
                <!-- المدفوع والمتبقي: دفتر سندات القبض، لا خانتان تُكتبان باليد —
                     read-only even while the sheet is filled in: a typed figure
                     here would print an amount the till never saw. -->
                <tr v-if="carries(contract.deposit_amount)" class="paid">
                    <td class="k">المدفوع</td>
                    <td class="v" dir="ltr">{{ money(contract.deposit_amount) }}</td>
                </tr>
                <tr v-if="carries(contract.deposit_amount) && contract.remaining_amount">
                    <td class="k">المتبقي</td>
                    <td class="v" dir="ltr">{{ money(contract.remaining_amount) }}</td>
                </tr>
            </table>
        </div>

        <div v-if="editable || termsText" class="notes print-keep">
            <div class="notes-title">الشروط والملاحظات</div>
            <textarea v-if="editable" v-model="terms" rows="8" class="fillin notes-input"></textarea>
            <ul v-else-if="bulleted">
                <li v-for="(bullet, i) in bullets" :key="i">{{ bullet }}</li>
            </ul>
            <pre v-else class="plain">{{ termsText }}</pre>
        </div>

        <!-- Approval: the manager, the stamp and the client. -->
        <div class="sign print-keep">
            <div>{{ issuer.manager_name || 'المدير المسؤول' }}</div>
            <div>الختم</div>
            <div>العميل: {{ fill(editable ? fields.client_name : contract.client_name) }}</div>
        </div>
    </div>
</template>

<style scoped>
/* Mirrors resources/views/pdf/contract-maintenance.blade.php — and through it
   the quotation sheet — so the screen and the PDF stay the same document. */
.doc {
    --navy: #1e3a8a;
    color: #0f172a;
    font-size: 13px;
    line-height: 1.6;
}
.brand {
    font-size: 18px;
    font-weight: 700;
    color: var(--navy);
    line-height: 1.4;
}
.muted {
    color: #64748b;
    font-size: 12px;
}
.doc-title {
    background: var(--navy);
    color: #ffffff;
    text-align: center;
    padding: 6px 10px;
}
.doc-title .t {
    font-size: 17px;
    font-weight: 700;
}
.doc-title .sub {
    font-size: 10px;
    color: #c7d2fe;
    letter-spacing: 3px;
}
.meta {
    width: 100%;
    border-collapse: collapse;
}
.meta td {
    padding: 4px 10px;
    border-bottom: 1px solid #e2e8f0;
    font-size: 12px;
}
.meta .k {
    color: #64748b;
}
.meta .v {
    text-align: left;
    font-weight: 700;
}
.rule {
    border-bottom: 2px solid var(--navy);
    margin: 14px 0;
}
.card-title {
    background: #f1f5f9;
    color: var(--navy);
    font-weight: 700;
    padding: 5px 10px;
    border: 1px solid #cbd5e1;
}
.card-body {
    display: flex;
    gap: 16px;
    padding: 8px 10px;
    border: 1px solid #cbd5e1;
    border-top: 0;
}
.client-input {
    width: 70%;
    font-weight: 700;
}
.items {
    width: 100%;
    margin-top: 16px;
    table-layout: fixed;
    border-collapse: collapse;
}
.items th {
    background: var(--navy);
    color: #ffffff;
    padding: 8px 6px;
    font-size: 12px;
    font-weight: 700;
    text-align: center;
    border: 1px solid var(--navy);
}
.items td {
    padding: 8px 6px;
    border: 1px solid #cbd5e1;
    font-size: 12px;
}
.items tr.alt td {
    background: #f8fafc;
}
.c {
    text-align: center;
}
.totals {
    width: 45%;
    margin-top: 14px;
    table-layout: fixed;
    border-collapse: collapse;
}
.totals td {
    padding: 6px 10px;
    border: 1px solid #cbd5e1;
    font-size: 13px;
}
.totals .k {
    color: #475569;
    font-weight: 700;
}
.totals .v {
    text-align: left;
    font-weight: 700;
}
.totals .discount td {
    color: #b91c1c;
}
.totals .paid td {
    color: #047857;
}
.totals .grand td {
    background: var(--navy);
    color: #ffffff;
    font-size: 15px;
    font-weight: 700;
    border-color: var(--navy);
}
.notes {
    margin-top: 18px;
    padding: 10px 12px;
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    font-size: 12px;
}
.notes-title {
    color: var(--navy);
    font-weight: 700;
}
.notes ul {
    margin: 4px 18px 0 0;
    padding: 0;
    list-style: disc;
}
.plain {
    font-family: inherit;
    white-space: pre-wrap;
    margin-top: 4px;
}
.sign {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 24px;
    margin-top: 80px;
    text-align: center;
    font-size: 12px;
    color: #475569;
}
.sign > div {
    border-top: 1px solid #94a3b8;
    padding-top: 5px;
}
/* An input on the printed line: the sheet's rule stays, the box does not. */
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
.grand .fillin {
    background: rgba(255, 255, 255, 0.15);
}
.notes-input {
    display: block;
    margin-top: 6px;
    line-height: 1.7;
    resize: vertical;
}

@media print {
    .doc {
        font-size: 9.5pt;
    }
    .items th,
    .items td,
    .totals td,
    .notes {
        font-size: 9pt;
    }
    .doc-title,
    .items th,
    .items tr.alt td,
    .card-title,
    .notes,
    .totals .grand td {
        -webkit-print-color-adjust: exact;
        print-color-adjust: exact;
    }
}
</style>
