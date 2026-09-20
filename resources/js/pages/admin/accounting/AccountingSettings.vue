<script setup lang="ts">
import SectionBackLink from '@/components/SectionBackLink.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, router, useForm } from '@inertiajs/vue3';
import { Landmark, RefreshCcw, Save, TrendingUp, Wallet } from 'lucide-vue-next';
import { computed, ref } from 'vue';

interface SectionRow {
    key: string;
    label: string;
    stream: string;
    hint: string;
    account_id: number | null;
    effective_account_id: number | null;
    default_code: string;
    default_name: string | null;
    deposit_account_id: number | null;
    effective_deposit_account_id: number | null;
    payment_accounts: Record<number, number | null>;
}

interface AccountOption {
    id: number;
    code: string;
    name: string;
}

interface DepositAccountOption extends AccountOption {
    is_cash_family: boolean;
}

interface PaymentMethodOption {
    id: number;
    code: string;
    name: string;
    default_account_code: string;
}

const props = defineProps<{
    sections: SectionRow[];
    accounts: AccountOption[];
    deposit_accounts: DepositAccountOption[];
    payment_methods: PaymentMethodOption[];
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'لوحة التحكم', href: '/admin' },
    { title: 'المحاسبة', href: '/admin/accounting' },
    { title: 'إعدادات المحاسبة', href: '/admin/accounting/settings' },
];

const form = useForm({
    sections: props.sections.map((s) => ({
        key: s.key,
        account_id: s.account_id,
        deposit_account_id: s.deposit_account_id,
        payment_accounts: props.payment_methods.map((m) => ({
            payment_method_id: m.id,
            account_id: s.payment_accounts[m.id] ?? null,
        })),
    })),
});

const metaOf = (key: string) => props.sections.find((s) => s.key === key);

const accountOf = (id: number | null) => (id ? props.accounts.find((a) => a.id === id) : undefined);

const depositAccountOf = (id: number | null) => (id ? props.deposit_accounts.find((a) => a.id === id) : undefined);

const cashAccounts = computed(() => props.deposit_accounts.filter((a) => a.is_cash_family));
const otherAssets = computed(() => props.deposit_accounts.filter((a) => !a.is_cash_family));

const depositAccountLabel = (key: string) => (key === 'chalets' ? 'حساب العربون' : 'حساب إيداع القسم');

const effectiveLabel = (key: string, chosen: number | null) => {
    const account = accountOf(chosen);

    if (account) {
        return `${account.code} — ${account.name}`;
    }

    const meta = metaOf(key);

    return meta ? `${meta.default_code} — ${meta.default_name ?? 'الحساب الافتراضي'}` : '—';
};

const depositLabel = (chosen: number | null) => {
    const account = depositAccountOf(chosen);

    return account ? `${account.code} — ${account.name}` : null;
};

const methodDefaultLabel = (method: PaymentMethodOption) => {
    const account = props.deposit_accounts.find((a) => a.code === method.default_account_code);

    return account ? `${account.code} — ${account.name}` : method.default_account_code;
};

const methodEffectiveLabel = (row: (typeof form.sections)[number], method: PaymentMethodOption) => {
    const chosen = row.payment_accounts.find((p) => p.payment_method_id === method.id)?.account_id ?? null;
    const chosenAccount = depositAccountOf(chosen);

    if (chosenAccount) {
        return `${chosenAccount.code} — ${chosenAccount.name}`;
    }

    return depositLabel(row.deposit_account_id) ?? methodDefaultLabel(method);
};

const errorFor = (index: number, field: 'account_id' | 'deposit_account_id') =>
    (form.errors as Record<string, string | undefined>)[`sections.${index}.${field}`];

const paymentErrorFor = (sectionIndex: number, methodIndex: number) =>
    (form.errors as Record<string, string | undefined>)[`sections.${sectionIndex}.payment_accounts.${methodIndex}.account_id`];

const submit = () => form.post('/admin/accounting/settings', { preserveScroll: true });

const remapping = ref(false);

const remap = () => {
    const sure = confirm(
        'سيُعدَّل حساب كل سطور القيود المرحَّلة سابقًا — إيرادًا وإيداعًا — لتطابق الحسابات المختارة هنا الآن. هذا تعديل مباشر على قيود سابقة ولا يُعاد تلقائيًا. هل تريد المتابعة؟',
    );

    if (!sure) {
        return;
    }

    router.post(
        '/admin/accounting/settings/remap',
        {},
        {
            preserveScroll: true,
            onStart: () => (remapping.value = true),
            onFinish: () => (remapping.value = false),
        },
    );
};
</script>

<template>
    <Head title="إعدادات المحاسبة" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="min-h-full space-y-5 bg-slate-100 p-5">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <SectionBackLink />
                <div class="text-start">
                    <h1 class="text-2xl font-extrabold text-slate-900">إعدادات المحاسبة</h1>
                    <p class="mt-1 text-sm font-medium text-slate-600">حساب الإيراد وحساب الإيداع لكل نشاط، وحساب كل طريقة دفع داخله</p>
                </div>
            </div>

            <div class="rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-xs font-medium leading-relaxed text-amber-800">
                ما يُختار هنا يسري على ما يُرحَّل بعد الحفظ. القيود المرحَّلة سابقًا لا تتأثر بالحفظ وحده — لنقلها إلى الحسابات الجديدة استعمل زر
                «تعديل القيود السابقة» أسفل الصفحة.
            </div>

            <form @submit.prevent="submit" class="space-y-5">
                <div v-for="(section, index) in form.sections" :key="section.key" class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                    <h2 class="mb-1 flex items-center gap-1.5 text-sm font-extrabold text-slate-800">
                        <TrendingUp class="h-4 w-4 text-emerald-500" /> {{ metaOf(section.key)?.label }}
                    </h2>
                    <p class="mb-4 text-[11px] font-medium leading-relaxed text-slate-500">{{ metaOf(section.key)?.hint }}</p>

                    <div class="grid gap-3 lg:grid-cols-2">
                        <div class="rounded-xl border border-slate-100 bg-slate-50/60 p-3">
                            <label class="mb-1 block text-[11px] font-bold text-slate-600">حساب الإيراد (دائن)</label>
                            <select
                                v-model="section.account_id"
                                class="w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm focus:border-blue-400 focus:outline-none focus:ring-2 focus:ring-blue-100"
                            >
                                <option :value="null">الافتراضي ({{ metaOf(section.key)?.default_code }})</option>
                                <option v-for="account in accounts" :key="account.id" :value="account.id">
                                    {{ account.code }} — {{ account.name }}
                                </option>
                            </select>
                            <p class="mt-1.5 text-[11px] font-bold text-slate-600">
                                يُرحَّل الآن على:
                                <span dir="ltr" class="rounded bg-white px-1.5 py-0.5 text-slate-700 ring-1 ring-slate-200">
                                    {{ effectiveLabel(section.key, section.account_id) }}
                                </span>
                            </p>
                            <p v-if="errorFor(index, 'account_id')" class="mt-1 text-xs text-red-500">{{ errorFor(index, 'account_id') }}</p>
                        </div>

                        <div class="rounded-xl border border-slate-100 bg-slate-50/60 p-3">
                            <label class="mb-1 flex items-center gap-1 text-[11px] font-bold text-slate-600">
                                <Landmark class="h-3 w-3 text-sky-500" /> {{ depositAccountLabel(section.key) }} (مدين)
                            </label>
                            <select
                                v-model="section.deposit_account_id"
                                class="w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm focus:border-blue-400 focus:outline-none focus:ring-2 focus:ring-blue-100"
                            >
                                <option :value="null">بحسب طريقة الدفع لكل عملية</option>
                                <optgroup v-if="cashAccounts.length" label="النقدية وما في حكمها">
                                    <option v-for="account in cashAccounts" :key="account.id" :value="account.id">
                                        {{ account.code }} — {{ account.name }}
                                    </option>
                                </optgroup>
                                <optgroup v-if="otherAssets.length" label="حسابات أصول أخرى">
                                    <option v-for="account in otherAssets" :key="account.id" :value="account.id">
                                        {{ account.code }} — {{ account.name }}
                                    </option>
                                </optgroup>
                            </select>
                            <p class="mt-1.5 text-[11px] font-bold text-slate-600">
                                يُودع الآن في:
                                <span dir="ltr" class="rounded bg-white px-1.5 py-0.5 text-slate-700 ring-1 ring-slate-200">
                                    {{ depositLabel(section.deposit_account_id) ?? 'بحسب طريقة الدفع لكل عملية' }}
                                </span>
                            </p>
                            <p v-if="errorFor(index, 'deposit_account_id')" class="mt-1 text-xs text-red-500">
                                {{ errorFor(index, 'deposit_account_id') }}
                            </p>
                        </div>
                    </div>

                    <div class="mt-4">
                        <h3 class="mb-2 flex items-center gap-1.5 text-[11px] font-extrabold text-slate-700">
                            <Wallet class="h-3.5 w-3.5 text-indigo-500" /> حساب كل طريقة دفع داخل {{ metaOf(section.key)?.label }}
                        </h3>
                        <p class="mb-3 text-[11px] font-medium leading-relaxed text-slate-500">
                            اختياري لكل طريقة على حدة — فارغةً تتبع {{ depositAccountLabel(section.key).toLowerCase() }} أعلاه، وإن كان فارغًا أيضًا
                            فحسابها الافتراضي.
                        </p>

                        <div class="overflow-hidden rounded-xl border border-slate-100">
                            <table class="w-full text-sm">
                                <thead class="bg-slate-50 text-[11px] font-bold text-slate-500">
                                    <tr>
                                        <th class="px-3 py-2 text-start">طريقة الدفع</th>
                                        <th class="px-3 py-2 text-start">الحساب</th>
                                        <th class="px-3 py-2 text-start">يُودع الآن في</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-slate-100">
                                    <tr v-for="(method, methodIndex) in payment_methods" :key="method.id">
                                        <td class="px-3 py-2 font-bold text-slate-700">{{ method.name }}</td>
                                        <td class="px-3 py-2">
                                            <select
                                                v-model="section.payment_accounts[methodIndex].account_id"
                                                class="w-full max-w-xs rounded-xl border border-slate-200 bg-white px-2.5 py-1.5 text-xs focus:border-blue-400 focus:outline-none focus:ring-2 focus:ring-blue-100"
                                            >
                                                <option :value="null">بحسب حساب إيداع القسم</option>
                                                <optgroup v-if="cashAccounts.length" label="النقدية وما في حكمها">
                                                    <option v-for="account in cashAccounts" :key="account.id" :value="account.id">
                                                        {{ account.code }} — {{ account.name }}
                                                    </option>
                                                </optgroup>
                                                <optgroup v-if="otherAssets.length" label="حسابات أصول أخرى">
                                                    <option v-for="account in otherAssets" :key="account.id" :value="account.id">
                                                        {{ account.code }} — {{ account.name }}
                                                    </option>
                                                </optgroup>
                                            </select>
                                            <p v-if="paymentErrorFor(index, methodIndex)" class="mt-1 text-xs text-red-500">
                                                {{ paymentErrorFor(index, methodIndex) }}
                                            </p>
                                        </td>
                                        <td class="px-3 py-2 text-[11px] font-bold text-slate-600" dir="ltr">
                                            {{ methodEffectiveLabel(section, method) }}
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <div class="flex flex-wrap items-center gap-2">
                    <button
                        type="submit"
                        :disabled="form.processing"
                        class="inline-flex items-center gap-1.5 rounded-md bg-blue-600 px-5 py-2.5 text-sm font-bold text-white shadow-sm transition hover:bg-blue-700 disabled:opacity-60"
                    >
                        <Save class="h-4 w-4" /> حفظ إعدادات المحاسبة
                    </button>
                </div>
            </form>

            <div class="rounded-2xl border border-red-200 bg-red-50 p-5">
                <h2 class="mb-1 flex items-center gap-1.5 text-sm font-extrabold text-red-800">
                    <RefreshCcw class="h-4 w-4" /> تعديل القيود السابقة على الحسابات الجديدة
                </h2>
                <p class="mb-4 text-[11px] font-medium leading-relaxed text-red-700">
                    هذا الزر يعدّل مباشرة حساب كل سطور القيود المرحَّلة من قبل — الإيراد والإيداع معًا — لتصبح على الحسابات المحفوظة أعلاه الآن، في كل
                    قسم من الأقسام الثلاثة. احفظ اختياراتك أولًا، فما يُطبَّق هو آخر ما تم حفظه لا ما هو ظاهر في النموذج دون حفظ.
                </p>
                <button
                    type="button"
                    :disabled="remapping"
                    @click="remap"
                    class="inline-flex items-center gap-1.5 rounded-md bg-red-600 px-5 py-2.5 text-sm font-bold text-white shadow-sm transition hover:bg-red-700 disabled:opacity-60"
                >
                    <RefreshCcw class="h-4 w-4" /> تعديل القيود السابقة
                </button>
            </div>
        </div>
    </AppLayout>
</template>
