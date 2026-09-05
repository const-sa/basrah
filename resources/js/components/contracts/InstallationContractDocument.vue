<script setup lang="ts">
import { computed, ref, watch } from 'vue';

interface InstallationContract {
    number: string;
    terms: string | null;
    body: string;
    contract_date: string | null;
    contract_date_hijri: string | null;
    client_name: string | null;
    client_mobile: string | null;
    client_id_number: string | null;
    client_address: string | null;
    total_amount: string | null;
    total_amount_words: string | null;
    first_installment: string | null;
    second_installment: string | null;
    items: { name: string; code: string | null; quantity: number }[];
}

interface Issuer {
    business_name: string;
    logo_url: string | null;
    phone: string | null;
    whatsapp: string | null;
    address: string | null;
    tax_number: string | null;
    manager_name: string | null;
    manager_signature_url: string | null;
    stamp_url: string | null;
}

const props = defineProps<{ contract: InstallationContract; issuer: Issuer }>();

const logoFailed = ref(false);
watch(() => props.issuer.logo_url, () => (logoFailed.value = false));

// A blank value stays empty — its dotted underline is the fill-in run.
const fill = (value: string | null | undefined) => value || ' ';

// Quantities are printed as written, not as 1.00 — the paper's grid holds
// hand-written counts and «1» is what the salesman would put there.
const qty = (value: number | null | undefined) =>
    value === null || value === undefined ? ' ' : String(Number(value));

const termsText = computed(() => props.contract.terms ?? props.contract.body);

// The equipment grid is two halves side by side, the lines split between them,
// and padded to the pad's row count so a short contract still prints a grid
// with room to write in.
const grid = computed(() => {
    const lines = props.contract.items ?? [];
    const half = Math.ceil(lines.length / 2);
    const right = lines.slice(0, half);
    const left = lines.slice(half);
    const rows = Math.max(right.length, left.length, 9);

    return Array.from({ length: rows }, (_, i) => ({ right: right[i] ?? null, left: left[i] ?? null }));
});
</script>

<template>
    <div class="doc mx-auto max-w-4xl bg-white p-8 text-slate-900 shadow-sm print:max-w-none print:p-0 print:shadow-none">
        <!-- Letterhead: name and activity right, emblem centre, phones left. -->
        <table class="print-keep w-full">
            <tr>
                <td class="w-[40%] align-top">
                    <div class="biz">{{ issuer.business_name }}</div>
                    <div v-if="issuer.tax_number" class="sub">
                        الرقم الضريبي: <span dir="ltr">{{ issuer.tax_number }}</span>
                    </div>
                </td>
                <td class="w-[22%] text-center align-top">
                    <img
                        v-if="issuer.logo_url && !logoFailed"
                        :src="issuer.logo_url"
                        :alt="issuer.business_name"
                        class="mx-auto max-h-[70px] w-auto object-contain print:max-h-[52px]"
                        @error="logoFailed = true"
                    />
                </td>
                <td class="w-[38%] text-left align-top">
                    <div v-if="issuer.phone" class="tel">ج: <span dir="ltr">{{ issuer.phone }}</span></div>
                    <div v-if="issuer.whatsapp" class="tel">واتساب: <span dir="ltr">{{ issuer.whatsapp }}</span></div>
                </td>
            </tr>
        </table>

        <div class="rule"></div>

        <!-- One table per LINE, every cell explicitly sized — the same widths the
             PDF uses, so the screen and the generated file stay one document. -->
        <table class="ln"><tr>
            <td class="k" style="width: 11%">رقم العقد<span class="en">Cont. No.</span></td>
            <td class="v serial" style="width: 21%" dir="ltr">{{ contract.number }}</td>
            <td class="k pr-3" style="width: 12%">تاريخ العقد<span class="en">Cont. Date</span></td>
            <td class="v" style="width: 24%" dir="ltr">{{ fill(contract.contract_date_hijri) }}</td>
            <td class="k" style="width: 8%">هـ الموافق</td>
            <td class="v" style="width: 24%" dir="ltr">{{ fill(contract.contract_date) }}</td>
        </tr></table>

        <table class="ln"><tr>
            <td class="k" style="width: 10%">طرف أول<span class="en">1st Part</span></td>
            <td class="v" style="width: 90%"><bdi>{{ issuer.business_name }}</bdi></td>
        </tr></table>

        <table class="ln"><tr>
            <td class="k" style="width: 19%">طرف ثاني: السادة / المكرم<span class="en">M/S</span></td>
            <td class="v" style="width: 41%">{{ fill(contract.client_name) }}</td>
            <td class="k pr-3" style="width: 8%">جوال<span class="en">Mob.</span></td>
            <td class="v" style="width: 32%" dir="ltr">{{ fill(contract.client_mobile) }}</td>
        </tr></table>

        <table class="ln"><tr>
            <td class="k" style="width: 11%">عنوان الموقع<span class="en">Place</span></td>
            <td class="v" style="width: 61%">{{ fill(contract.client_address) }}</td>
            <td class="k pr-3" style="width: 12%">الهوية / السجل</td>
            <td class="v" style="width: 16%" dir="ltr">{{ fill(contract.client_id_number) }}</td>
        </tr></table>

        <table class="ln"><tr>
            <td class="k" style="width: 14%">قيمة العقد الكلية<span class="en">Total Amount</span></td>
            <td class="v" style="width: 30%"><b dir="ltr">{{ contract.total_amount ?? '' }}</b> ريال</td>
            <td class="k pr-3" style="width: 10%">الدفعة الأولى</td>
            <td class="v" style="width: 20%"><b dir="ltr">{{ fill(contract.first_installment) }}</b></td>
            <td class="k pr-3" style="width: 10%">الدفعة الثانية</td>
            <td class="v" style="width: 16%"><b dir="ltr">{{ fill(contract.second_installment) }}</b></td>
        </tr></table>

        <table v-if="contract.total_amount_words" class="ln"><tr>
            <td class="k" style="width: 10%">فقط وقدره</td>
            <td class="v" style="width: 90%">{{ contract.total_amount_words }}</td>
        </tr></table>

        <!-- Pool dimensions are measured on site, so the form prints them blank -->
        <table class="ln"><tr>
            <td class="k" style="width: 10%">عرض المسبح<span class="en">Showing pool</span></td>
            <td class="v" style="width: 15%">{{ fill(null) }}</td>
            <td class="k pr-2" style="width: 6%">الطول<span class="en">Length</span></td>
            <td class="v" style="width: 15%">{{ fill(null) }}</td>
            <td class="k pr-2" style="width: 8%">أقل عمق<span class="en">Less depth</span></td>
            <td class="v" style="width: 15%">{{ fill(null) }}</td>
            <td class="k pr-2" style="width: 10%">أقصى عمق<span class="en">Maximum depth</span></td>
            <td class="v" style="width: 15%">{{ fill(null) }}</td>
        </tr></table>

        <!-- Not «grid»: that is a Tailwind utility, and display:grid on a table
             drops its columns. -->
        <table class="eqgrid print-keep">
            <thead>
                <tr>
                    <th style="width: 11%">الكمية<span class="en">Qty.</span></th>
                    <th style="width: 39%">البيان<span class="en">Description</span></th>
                    <th style="width: 11%">الكمية<span class="en">Qty.</span></th>
                    <th style="width: 39%">البيان<span class="en">Description</span></th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="(row, i) in grid" :key="i">
                    <td class="q" dir="ltr">{{ qty(row.right?.quantity) }}</td>
                    <td>
                        {{ fill(row.right?.name) }}
                        <span v-if="row.right?.code" class="code" dir="ltr">({{ row.right.code }})</span>
                    </td>
                    <td class="q" dir="ltr">{{ qty(row.left?.quantity) }}</td>
                    <td>
                        {{ fill(row.left?.name) }}
                        <span v-if="row.left?.code" class="code" dir="ltr">({{ row.left.code }})</span>
                    </td>
                </tr>
            </tbody>
        </table>

        <div class="split">50 % عند التعاقد &nbsp;&nbsp;&nbsp; 50 % عند طلب توريد المعدات</div>

        <div v-if="termsText" class="notes">
            <span class="lbl">ملاحظات أو شروط أخرى<span class="en">Notes</span></span>
            <pre class="notes-body">{{ termsText }}</pre>
        </div>

        <!-- Signatures stay one block: a signature split from its name proves nothing. -->
        <table class="print-keep mt-3 w-full">
            <tr>
                <td class="w-[38%] pl-4 align-top">
                    <div class="who">طرف أول<span class="en">1st Part</span></div>
                    <table class="ln"><tr>
                        <td class="k" style="width: 22%">الاسم:<span class="en">Name</span></td>
                        <td class="v" style="width: 78%">{{ fill(issuer.manager_name ?? issuer.business_name) }}</td>
                    </tr></table>
                    <table class="ln"><tr>
                        <td class="k" style="width: 22%">التوقيع:<span class="en">Sign.</span></td>
                        <td class="v" style="width: 78%">
                            <img
                                v-if="issuer.manager_signature_url"
                                :src="issuer.manager_signature_url"
                                alt="التوقيع"
                                class="inline h-9 w-auto object-contain print:h-7"
                            />
                            <span v-else>{{ fill(null) }}</span>
                        </td>
                    </tr></table>
                </td>
                <td class="w-[24%] text-center align-middle">
                    <img
                        v-if="issuer.stamp_url"
                        :src="issuer.stamp_url"
                        alt="الختم"
                        class="mx-auto h-16 w-auto object-contain print:h-12"
                    />
                    <div v-else class="who">الختم<span class="en">STAMP</span></div>
                </td>
                <td class="w-[38%] pr-4 align-top">
                    <div class="who">طرف ثاني<span class="en">2nd Part</span></div>
                    <table class="ln"><tr>
                        <td class="k" style="width: 22%">الاسم:<span class="en">Name</span></td>
                        <td class="v" style="width: 78%">{{ fill(contract.client_name) }}</td>
                    </tr></table>
                    <table class="ln"><tr>
                        <td class="k" style="width: 22%">التوقيع:<span class="en">Sign.</span></td>
                        <td class="v" style="width: 78%">{{ fill(null) }}</td>
                    </tr></table>
                </td>
            </tr>
        </table>

        <div v-if="issuer.address" class="addr">{{ issuer.address }}</div>

        <div class="band">
            يعتبر العقد ساري المفعول من تاريخ توقيع العقد ولمدة سنة كاملة، وإذا حصل تغيير بعد عام من تاريخ توقيع العقد
            بالأسعار يلتزم الطرف الثاني بدفع الفرق
        </div>
    </div>
</template>

<style scoped>
/* Mirrors resources/views/pdf/contract-installation.blade.php so the screen and
   the generated PDF stay the same document. */
.doc {
    color: #14224a;
    font-size: 13px;
    line-height: 1.45;
}
.biz {
    color: #1b3a93;
    font-size: 21px;
    font-weight: 700;
    line-height: 1.2;
}
.sub,
.tel {
    color: #1b3a93;
    font-size: 12.5px;
    font-weight: 700;
}
.rule {
    border-bottom: 1px solid #1b3a93;
    margin: 7px 0 8px;
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
.serial {
    color: #c8102e;
    font-weight: 700;
}
/* The English half of a bilingual label, under its Arabic. */
.en {
    display: block;
    font-size: 9px;
    font-weight: 400;
    color: #6b7a99;
}
.eqgrid {
    width: 100%;
    table-layout: fixed;
    border-collapse: collapse;
    border: 1px solid #1b3a93;
    margin-top: 8px;
}
.eqgrid th {
    border: 1px solid #1b3a93;
    background: #eef2fb;
    padding: 4px;
    font-size: 12px;
    font-weight: 700;
    text-align: center;
}
.eqgrid td {
    border: 1px solid #1b3a93;
    padding: 4px 5px;
    font-size: 12px;
    height: 22px;
}
.eqgrid td.q {
    text-align: center;
}
.code {
    font-size: 10px;
    color: #6b7a99;
}
.split {
    margin: 7px 0 2px;
    text-align: center;
    font-size: 13px;
    font-weight: 700;
}
.notes .lbl {
    font-size: 13px;
    font-weight: 700;
    color: #1b3a93;
}
.notes-body {
    border: 1px solid #c3cde6;
    padding: 6px 8px;
    margin-top: 3px;
    font-family: inherit;
    font-size: 12px;
    line-height: 1.6;
    text-align: justify;
    white-space: pre-wrap;
}
.who {
    color: #1b3a93;
    font-size: 14px;
    font-weight: 700;
    margin-bottom: 4px;
}
.addr {
    margin-top: 12px;
    text-align: center;
    font-size: 12.5px;
    font-weight: 700;
    color: #1b3a93;
}
.band {
    margin: 4px -32px 0;
    background: #1b3a93;
    color: #ffffff;
    text-align: center;
    padding: 6px 10px;
    font-size: 11.5px;
    font-weight: 700;
}

@media print {
    .doc {
        font-size: 10pt;
    }
    .biz {
        font-size: 16pt;
    }
    .sub,
    .tel,
    .ln td,
    .split {
        font-size: 10pt;
    }
    .eqgrid th,
    .eqgrid td {
        font-size: 9.5pt;
    }
    .notes-body {
        font-size: 9pt;
    }
    .who {
        font-size: 11pt;
    }
    .band {
        margin-left: 0;
        margin-right: 0;
        font-size: 8.5pt;
        -webkit-print-color-adjust: exact;
        print-color-adjust: exact;
    }
    .eqgrid th {
        -webkit-print-color-adjust: exact;
        print-color-adjust: exact;
    }
}
</style>
