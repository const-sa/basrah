<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ArrowRight, Plus, Save, Trash2 } from 'lucide-vue-next';

// Type aliases, not interfaces: Inertia's form data is a Record, and only an
// alias carries the implicit index signature that satisfies it.
type Line = { name: string; code: string | null; quantity: number; unit_price: string; total_price: string };

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
        /** Every printed field, boxed by subject — see FIELD_GROUPS. */
        groups: { title: string; fields: Field[] }[];
        quotation_number: string | null;
        booking_reference: string | null;
        is_installation_form: boolean;
    };
    clients: { id: number; label: string }[];
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'لوحة التحكم', href: '/admin' },
    { title: 'العقود', href: '/admin/contracts' },
    { title: props.contract.number, href: `/admin/contracts/${props.contract.id}` },
    { title: 'تعديل', href: `/admin/contracts/${props.contract.id}/edit` },
];

const form = useForm<EditForm>({
    client_id: props.contract.client_id,
    fields: Object.fromEntries(props.contract.groups.flatMap((g) => g.fields).map((f) => [f.key, f.value])),
    items: props.contract.items.map((i) => ({ ...i })),
    body: props.contract.body,
    terms: props.contract.terms ?? '',
});

// The installation pad's grid has no price columns, so its lines are not asked
// for prices that would never be printed.
const priced = !props.contract.is_installation_form;

const addLine = () =>
    form.items.push({ name: '', code: null, quantity: 1, unit_price: '', total_price: '' });

const removeLine = (i: number) => form.items.splice(i, 1);

const submit = () => form.put(`/admin/contracts/${props.contract.id}`);
</script>

<template>
    <Head :title="`تعديل العقد ${contract.number}`" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="mx-auto max-w-4xl space-y-4 p-4">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h1 class="text-xl font-extrabold text-slate-900">تعديل العقد</h1>
                    <p class="text-sm font-medium text-slate-500">
                        رقم العقد <span dir="ltr">{{ contract.number }}</span> — كل ما يُطبع في العقد يُحرَّر هنا، والرقم وحده لا يتغيّر
                    </p>
                </div>
                <Link
                    :href="`/admin/contracts/${contract.id}`"
                    class="inline-flex items-center gap-1.5 rounded-md border border-slate-200 bg-white px-4 py-2 text-sm font-bold text-slate-700 hover:bg-slate-50"
                >
                    <ArrowRight class="h-4 w-4" /> رجوع للعقد
                </Link>
            </div>

            <!-- Editing a contract away from its source document is allowed, but
                 it should be a knowing act rather than a silent one. -->
            <p
                v-if="contract.quotation_number || contract.booking_reference"
                class="rounded-xl bg-amber-50 px-4 py-3 text-xs font-bold text-amber-700"
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

            <form @submit.prevent="submit" class="space-y-4">
                <div class="space-y-3 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                    <h2 class="text-sm font-extrabold text-slate-800">العميل</h2>
                    <select v-model="form.client_id" class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm">
                        <option :value="null">— بلا عميل —</option>
                        <option v-for="c in clients" :key="c.id" :value="c.id">{{ c.label }}</option>
                    </select>
                    <p v-if="form.errors.client_id" class="text-xs text-red-500">{{ form.errors.client_id }}</p>
                    <p class="text-xs font-medium text-slate-500">
                        تبديل العميل يُعيد كتابة اسمه وجواله وهويته وعنوانه في العقد من سجلّه، وما تكتبه أنت في الحقول
                        أدناه يبقى فوقها.
                    </p>
                </div>

                <div v-for="group in contract.groups" :key="group.title" class="space-y-3 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                    <h2 class="text-sm font-extrabold text-slate-800">{{ group.title }}</h2>
                    <div class="grid gap-3 sm:grid-cols-2">
                        <div v-for="field in group.fields" :key="field.key">
                            <label class="mb-1 block text-xs font-bold text-slate-700">{{ field.label }}</label>
                            <input
                                v-model="form.fields[field.key]"
                                type="text"
                                placeholder="—"
                                class="w-full rounded-xl border border-slate-200 px-3 py-2 text-sm"
                            />
                        </div>
                    </div>
                </div>

                <div class="space-y-3 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                    <div class="flex items-center justify-between">
                        <h2 class="text-sm font-extrabold text-slate-800">بنود العقد</h2>
                        <button type="button" @click="addLine" class="inline-flex items-center gap-1 rounded-lg bg-slate-100 px-3 py-1.5 text-xs font-bold text-slate-700 hover:bg-slate-200">
                            <Plus class="h-3.5 w-3.5" /> بند
                        </button>
                    </div>

                    <div v-for="(line, i) in form.items" :key="i" class="flex items-center gap-2">
                        <input v-model="line.name" type="text" placeholder="البيان" class="flex-1 rounded-xl border border-slate-200 px-3 py-2 text-sm" />
                        <input v-model.number="line.quantity" type="number" min="0" step="1" title="الكمية" class="w-20 rounded-xl border border-slate-200 px-2 py-2 text-center text-sm" />
                        <input v-if="priced" v-model="line.unit_price" type="text" placeholder="السعر" title="سعر الوحدة" class="w-24 rounded-xl border border-slate-200 px-2 py-2 text-center text-sm" />
                        <input v-if="priced" v-model="line.total_price" type="text" placeholder="الإجمالي" title="إجمالي البند" class="w-24 rounded-xl border border-slate-200 px-2 py-2 text-center text-sm" />
                        <button type="button" @click="removeLine(i)" class="rounded-lg p-2 text-red-500 hover:bg-red-50">
                            <Trash2 class="h-4 w-4" />
                        </button>
                    </div>

                    <p v-if="!form.items.length" class="text-xs font-medium text-slate-500">
                        لا بنود — يُطبع الجدول فارغًا ليُملأ بخط اليد.
                    </p>
                </div>

                <div class="space-y-2 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                    <h2 class="text-sm font-extrabold text-slate-800">الملاحظات والشروط</h2>
                    <textarea v-model="form.terms" rows="12" class="w-full rounded-xl border border-slate-200 px-3 py-2.5 font-mono text-xs leading-6"></textarea>
                    <p v-if="form.errors.terms" class="text-xs text-red-500">{{ form.errors.terms }}</p>
                </div>

                <div class="space-y-2 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                    <h2 class="text-sm font-extrabold text-slate-800">النص المجمَّد</h2>
                    <textarea v-model="form.body" rows="10" class="w-full rounded-xl border border-slate-200 px-3 py-2.5 font-mono text-xs leading-6"></textarea>
                    <p v-if="form.errors.body" class="text-xs text-red-500">{{ form.errors.body }}</p>
                    <!-- Rewritten text is kept verbatim; text left alone is rebuilt so
                         corrected figures reach the sentences that quote them. -->
                    <p class="text-xs font-medium text-slate-500">
                        ما تكتبه في هذين الصندوقين يُحفظ كما هو في هذا العقد وحده ولا يمسّ النموذج. وما تتركه كما هو
                        يُعاد بناؤه من النموذج بالقيم الجديدة.
                    </p>
                </div>

                <div class="flex justify-end gap-2">
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
            </form>
        </div>
    </AppLayout>
</template>
