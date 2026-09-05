<script setup lang="ts">
import InstallationContractDocument from '@/components/contracts/InstallationContractDocument.vue';
import MaintenanceContractDocument from '@/components/contracts/MaintenanceContractDocument.vue';
import StandardContractDocument from '@/components/contracts/StandardContractDocument.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ArrowRight, Save } from 'lucide-vue-next';
import { computed, ref } from 'vue';

// Type aliases, not interfaces: Inertia's form data is a Record, and only an
// alias carries the implicit index signature that satisfies it.
type Line = {
    name: string;
    code: string | null;
    quantity: number | string | null;
    unit_price: string;
    total_price: string;
};

type EditForm = {
    client_id: number | null;
    fields: Record<string, string>;
    items: Line[];
    body: string;
    terms: string;
};

type Field = { key: string; label: string; value: string };

const props = defineProps<{
    contract: {
        id: number;
        number: string;
        client_id: number | null;
        items: Line[];
        body: string;
        terms: string | null;
        /** Every printed field, so the sheet is filled from the snapshot. */
        groups: { title: string; fields: Field[] }[];
        quotation_number: string | null;
        booking_reference: string | null;
        is_installation_form: boolean;
        is_maintenance_form: boolean;
        unit_type: string | null;
        unit_logo_url: string | null;
        event_name: string | null;
        from_quotation: boolean;
        is_taxable: boolean;
        quotation_date: string | null;
    };
    issuer: {
        business_name: string;
        logo_url: string | null;
        phone: string | null;
        whatsapp: string | null;
        address: string | null;
        tax_number: string | null;
        /** The maintenance sheet's letterhead carries the CR number. */
        commercial_register: string | null;
        manager_name: string | null;
        manager_signature_url: string | null;
        stamp_url: string | null;
    };
    clients: { id: number; label: string }[];
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'لوحة التحكم', href: '/admin' },
    { title: 'العقود', href: '/admin/contracts' },
    { title: props.contract.number, href: `/admin/contracts/${props.contract.id}` },
    { title: 'تعديل', href: `/admin/contracts/${props.contract.id}/edit` },
];

const blank = (): Line => ({ name: '', code: null, quantity: '', unit_price: '', total_price: '' });

// Each pad prints empty rows to write into, so editing offers the same rows as
// real ones — what stays empty is dropped when the contract is saved.
const ROWS_PER_HALF = 9;
const MAINTENANCE_ROWS = 8;

const startingLines = () => {
    const lines = props.contract.items.map((i) => ({ ...i }));

    // The installation grid is two halves side by side, so its padding is per
    // half: what is typed in a cell stays under the column it was typed in.
    if (props.contract.is_installation_form) {
        const cut = Math.ceil(lines.length / 2);
        const half = Math.max(cut, ROWS_PER_HALF);
        const pad = (side: Line[]) => [...side, ...Array.from({ length: half - side.length }, blank)];

        return [...pad(lines.slice(0, cut)), ...pad(lines.slice(cut))];
    }

    if (props.contract.is_maintenance_form) {
        return [...lines, ...Array.from({ length: Math.max(MAINTENANCE_ROWS - lines.length, 0) }, blank)];
    }

    return lines;
};

const form = useForm<EditForm>({
    client_id: props.contract.client_id,
    fields: Object.fromEntries(props.contract.groups.flatMap((g) => g.fields).map((f) => [f.key, f.value])),
    items: startingLines(),
    body: props.contract.body,
    terms: props.contract.terms ?? '',
});

// The document paints itself from the frozen snapshot; while editing, the form's
// own values are what it shows, so it is one sheet the whole way through.
const typed = (key: string) => form.fields[key] || null;

const sheet = computed(() => ({
    ...props.contract,
    number: props.contract.number,
    body: props.contract.body,
    terms: form.terms,
    items: form.items,
    contract_date: typed('contract_date'),
    contract_date_hijri: typed('contract_date_hijri'),
    client_name: typed('client_name'),
    client_mobile: typed('client_mobile'),
    client_id_number: typed('client_id_number'),
    client_address: typed('client_address'),
    subject: typed('subject'),
    unit_name: typed('unit_name'),
    sections: typed('sections'),
    booking_date: typed('booking_date'),
    last_day_date: typed('last_day_date'),
    days_count: typed('days_count'),
    period: typed('period'),
    starts_at: typed('starts_at'),
    ends_at: typed('ends_at'),
    guests_count: typed('guests_count'),
    quotation_number: typed('quotation_number'),
    total_amount: typed('total_amount'),
    total_amount_words: typed('total_amount_words'),
    subtotal: typed('subtotal'),
    discount_amount: typed('discount_amount'),
    tax_amount: typed('tax_amount'),
    tax_rate: typed('tax_rate'),
    deposit_amount: typed('deposit_amount'),
    remaining_amount: typed('remaining_amount'),
    first_installment: typed('first_installment'),
    second_installment: typed('second_installment'),
    pool_width: typed('pool_width'),
    pool_length: typed('pool_length'),
    pool_min_depth: typed('pool_min_depth'),
    pool_max_depth: typed('pool_max_depth'),
}));

// Validation is reported as one list above the sheet: on a document whose
// fields are its own printed runs, a message beside every run would be noise.
const errors = computed(() => Object.values(form.errors).filter(Boolean));

const showText = ref(false);

const submit = () =>
    form.put(`/admin/contracts/${props.contract.id}`, {
        onError: () => window.scrollTo({ top: 0, behavior: 'smooth' }),
    });
</script>

<template>
    <Head :title="`تعديل العقد ${contract.number}`" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="min-h-full space-y-4 bg-slate-100 p-5">
            <div class="mx-auto flex max-w-4xl flex-wrap items-center justify-between gap-3">
                <div>
                    <h1 class="text-xl font-extrabold text-slate-900">تعديل العقد</h1>
                    <p class="text-sm font-medium text-slate-500">
                        رقم العقد <span dir="ltr">{{ contract.number }}</span> — اكتب في الورقة نفسها، والرقم وحده لا يتغيّر
                    </p>
                </div>
                <div class="flex flex-wrap gap-2">
                    <button
                        type="submit"
                        form="contract-edit"
                        :disabled="form.processing"
                        class="inline-flex items-center gap-1.5 rounded-md bg-blue-600 px-5 py-2 text-sm font-bold text-white hover:bg-blue-700 disabled:opacity-50"
                    >
                        <Save class="h-4 w-4" /> حفظ التعديل
                    </button>
                    <Link
                        :href="`/admin/contracts/${contract.id}`"
                        class="inline-flex items-center gap-1.5 rounded-md border border-slate-200 bg-white px-4 py-2 text-sm font-bold text-slate-700 hover:bg-slate-50"
                    >
                        <ArrowRight class="h-4 w-4" /> رجوع للعقد
                    </Link>
                </div>
            </div>

            <div v-if="errors.length" class="mx-auto max-w-4xl rounded-xl border border-red-200 bg-red-50 px-4 py-3">
                <p class="text-sm font-extrabold text-red-700">تعذّر حفظ التعديل:</p>
                <ul class="mt-1 space-y-0.5 text-xs font-bold text-red-600">
                    <li v-for="(message, i) in errors" :key="i">— {{ message }}</li>
                </ul>
            </div>

            <!-- Editing a contract away from its source document is allowed, but it
                 should be a knowing act rather than a silent one. -->
            <p
                v-if="contract.quotation_number || contract.booking_reference"
                class="mx-auto max-w-4xl rounded-xl bg-amber-50 px-4 py-3 text-xs font-bold text-amber-700"
            >
                هذا العقد محرَّر على
                <template v-if="contract.quotation_number">
                    عرض السعر <span dir="ltr">{{ contract.quotation_number }}</span>
                </template>
                <template v-else>
                    الحجز <span dir="ltr">{{ contract.booking_reference }}</span>
                </template>
                — وما يُعدَّل هنا يخصّ ورقة العقد وحدها، ولا يعود على المستند الذي حُرِّر منه.
            </p>

            <form id="contract-edit" @submit.prevent="submit" class="space-y-4">
                <!-- The sheet as it prints, its runs turned into the inputs that fill
                     them — one document, whether it is read or written. -->
                <div class="mx-auto max-w-4xl">
                    <InstallationContractDocument
                        v-if="contract.is_installation_form"
                        editable
                        :contract="sheet"
                        :issuer="issuer"
                        v-model:fields="form.fields"
                        v-model:items="form.items"
                        v-model:terms="form.terms"
                    />
                    <MaintenanceContractDocument
                        v-else-if="contract.is_maintenance_form"
                        editable
                        :contract="sheet"
                        :issuer="issuer"
                        v-model:fields="form.fields"
                        v-model:items="form.items"
                        v-model:terms="form.terms"
                    />
                    <StandardContractDocument
                        v-else
                        editable
                        :contract="sheet"
                        :issuer="issuer"
                        v-model:fields="form.fields"
                        v-model:items="form.items"
                        v-model:terms="form.terms"
                    />
                </div>

                <div class="mx-auto max-w-4xl space-y-4">
                    <!-- The frozen text is what the contract was issued as; it is
                         rebuilt from the template on save unless it is rewritten here. -->
                    <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                        <button type="button" @click="showText = !showText" class="text-sm font-extrabold text-slate-800">
                            {{ showText ? '− ' : '+ ' }}النص المجمَّد
                        </button>
                        <div v-if="showText" class="mt-3 space-y-2">
                            <textarea v-model="form.body" rows="10" class="w-full rounded-xl border border-slate-200 px-3 py-2.5 font-mono text-xs leading-6"></textarea>
                            <p class="text-xs font-medium text-slate-500">
                                ما تكتبه هنا يُحفظ كما هو في هذا العقد وحده ولا يمسّ النموذج، وما تتركه كما هو يُعاد
                                بناؤه من النموذج بالقيم الجديدة.
                            </p>
                        </div>
                    </div>

                    <div class="flex justify-end gap-2 pb-6">
                        <Link :href="`/admin/contracts/${contract.id}`" class="rounded-xl border border-slate-200 bg-white px-4 py-2 text-sm font-bold text-slate-600">
                            إلغاء
                        </Link>
                        <button
                            type="submit"
                            :disabled="form.processing"
                            class="inline-flex items-center gap-1.5 rounded-md bg-blue-600 px-5 py-2 text-sm font-bold text-white hover:bg-blue-700 disabled:opacity-50"
                        >
                            <Save class="h-4 w-4" /> حفظ التعديل
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </AppLayout>
</template>
