<script setup lang="ts">
import SectionBackLink from '@/components/SectionBackLink.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { FileText, Landmark, Save, TrendingUp } from 'lucide-vue-next';
import { computed } from 'vue';

interface StreamRow {
    key: string;
    label: string;
    hint: string;
    account_id: number | null;
    effective_account_id: number | null;
    default_code: string;
    default_name: string | null;
    deposit_account_id: number | null;
    effective_deposit_account_id: number | null;
}

interface AccountOption {
    id: number;
    code: string;
    name: string;
}

interface DepositAccountOption extends AccountOption {
    is_cash_family: boolean;
}

const props = defineProps<{
    streams: StreamRow[];
    accounts: AccountOption[];
    deposit_accounts: DepositAccountOption[];
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'لوحة التحكم', href: '/admin' },
    { title: 'المحاسبة', href: '/admin/accounting' },
    { title: 'حسابات الإيراد', href: '/admin/accounting/revenue-accounts' },
];

const form = useForm({
    streams: props.streams.map((s) => ({
        key: s.key,
        account_id: s.account_id,
        deposit_account_id: s.deposit_account_id,
    })),
});

const metaOf = (key: string) => props.streams.find((s) => s.key === key);

const accountOf = (id: number | null) => (id ? props.accounts.find((a) => a.id === id) : undefined);

const depositAccountOf = (id: number | null) => (id ? props.deposit_accounts.find((a) => a.id === id) : undefined);

// الخزائن والبنوك أولًا — هي جواب هذا السؤال في كل الحالات تقريبًا — وما عداها
// من الأصول في مجموعة ثانية: مرئيّ لمن يحتاجه، ولا يسبق ما يحتاجه الناس.
const cashAccounts = computed(() => props.deposit_accounts.filter((a) => a.is_cash_family));
const otherAssets = computed(() => props.deposit_accounts.filter((a) => !a.is_cash_family));

/**
 * The account a stream posts to right now: what was chosen, or — when nothing
 * was — the one it has always used. Shown beside the field so an untouched
 * setting still says where the money goes instead of reading as empty.
 */
const effectiveLabel = (key: string, chosen: number | null) => {
    const account = accountOf(chosen);

    if (account) {
        return `${account.code} — ${account.name}`;
    }

    const meta = metaOf(key);

    return meta ? `${meta.default_code} — ${meta.default_name ?? 'الحساب الافتراضي'}` : '—';
};

/**
 * وحيث يُودع المقبوض. وفارغُه ليس نقصًا بل اختيار: تبقى طريقة الدفع هي التي
 * تقرّر — نقدًا في الصندوق وما سواه في البنك — فتُقال العبارة لا تُترك خانة.
 */
const depositLabel = (chosen: number | null) => {
    const account = depositAccountOf(chosen);

    return account ? `${account.code} — ${account.name}` : 'بحسب طريقة الدفع';
};

const usesDefault = (chosen: number | null) => !chosen;

// حسابٌ خارج «النقدية وما في حكمها» اختيارٌ مشروع — محفظة إلكترونية مثلًا —
// لكنه نادر بما يكفي ليُنبَّه عليه: الخطأ هنا يضع المال في غير موضعه.
const oddDeposit = (chosen: number | null) => {
    const account = depositAccountOf(chosen);

    return account ? !account.is_cash_family : false;
};

// Two streams on one account is a legitimate choice (it is today's default),
// so this is a note on the screen rather than a rule in the validator.
const shared = computed(() => {
    const counts = new Map<number, string[]>();

    form.streams.forEach((row) => {
        const meta = metaOf(row.key);
        const id = row.account_id ?? null;

        if (!id || !meta) {
            return;
        }

        counts.set(id, [...(counts.get(id) ?? []), meta.label]);
    });

    return [...counts.entries()].filter(([, labels]) => labels.length > 1).map(([id, labels]) => ({ account: accountOf(id), labels }));
});

const statementHref = (key: string) => `/admin/accounting/revenue-statement?stream=${key}`;

// Inertia types the error bag by top-level field, so a nested key is read
// through a plain record rather than left as an implicit any.
const errorFor = (index: number, field: 'account_id' | 'deposit_account_id' = 'account_id') =>
    (form.errors as Record<string, string | undefined>)[`streams.${index}.${field}`];

const submit = () => form.post('/admin/accounting/revenue-accounts', { preserveScroll: true });
</script>

<template>
    <Head title="حسابات الإيراد" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="min-h-full space-y-5 bg-slate-100 p-5">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <SectionBackLink />
                <div class="text-start">
                    <h1 class="text-2xl font-extrabold text-slate-900">حسابات الإيراد</h1>
                    <p class="mt-1 text-sm font-medium text-slate-600">أين يُسجَّل دخل كل نشاط في شجرة الحسابات، وأين يُودع ماله</p>
                </div>
            </div>

            <div class="rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-xs font-medium leading-relaxed text-amber-800">
                ما يُختار هنا يسري على ما يُرحَّل بعد الحفظ. القيود المرحَّلة سابقًا تبقى على حساباتها — القيد المرحَّل لا يُعاد كتابته، وإلا تغيّرت
                أرقام شهرٍ أُقفل وقُدِّم إقراره.
            </div>

            <div v-if="!accounts.length" class="rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-xs font-bold text-red-700">
                لا توجد حسابات إيرادية قابلة للترحيل في شجرة الحسابات.
                <Link href="/admin/accounting/accounts" class="underline">أضِف حسابًا إيراديًا أولًا</Link>.
            </div>

            <form v-else @submit.prevent="submit" class="space-y-5">
                <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                    <h2 class="mb-1 flex items-center gap-1.5 text-sm font-extrabold text-slate-800">
                        <TrendingUp class="h-4 w-4 text-emerald-500" /> مصادر الدخل وحساباتها
                    </h2>
                    <p class="mb-4 text-[11px] font-medium leading-relaxed text-slate-500">
                        لكل مصدر طرفان: <span class="font-bold text-slate-600">حساب الإيراد</span> الذي يُقيَّد دائنًا، و<span
                            class="font-bold text-slate-600"
                            >حساب الإيداع</span
                        >
                        الذي يهبط عليه المقبوض مدينًا. اترك الأول فارغًا ليبقى المصدر على حسابه الافتراضي، واترك الثاني فارغًا ليتبع المقبوض طريقة
                        الدفع كما كان. وتوزيع الإيراد على القاعات والشاليهات بمراكز التكلفة لا يتغيّر بشيء من هذا.
                    </p>

                    <div class="space-y-3">
                        <div
                            v-for="(row, index) in form.streams"
                            :key="row.key"
                            class="grid items-start gap-3 rounded-xl border border-slate-100 bg-slate-50/60 p-3 lg:grid-cols-[1fr_17rem_17rem]"
                        >
                            <div>
                                <div class="flex flex-wrap items-center gap-2">
                                    <span class="text-sm font-extrabold text-slate-800">{{ metaOf(row.key)?.label }}</span>
                                    <span
                                        v-if="usesDefault(row.account_id)"
                                        class="rounded bg-slate-200 px-1.5 py-0.5 text-[10px] font-bold text-slate-600"
                                    >
                                        الافتراضي
                                    </span>
                                </div>
                                <p class="mt-1 text-[11px] font-medium leading-relaxed text-slate-500">{{ metaOf(row.key)?.hint }}</p>
                                <p class="mt-1.5 text-[11px] font-bold text-slate-600">
                                    يُرحَّل الآن على:
                                    <span dir="ltr" class="rounded bg-white px-1.5 py-0.5 text-slate-700 ring-1 ring-slate-200">
                                        {{ effectiveLabel(row.key, row.account_id) }}
                                    </span>
                                    <Link :href="statementHref(row.key)" class="mr-2 inline-flex items-center gap-1 text-blue-600 underline">
                                        <FileText class="h-3 w-3" /> كشف الحساب
                                    </Link>
                                </p>
                                <p class="mt-1 text-[11px] font-bold text-slate-600">
                                    ويُودع في:
                                    <span dir="ltr" class="rounded bg-white px-1.5 py-0.5 text-slate-700 ring-1 ring-slate-200">
                                        {{ depositLabel(row.deposit_account_id) }}
                                    </span>
                                </p>
                            </div>

                            <div>
                                <label class="mb-1 block text-[11px] font-bold text-slate-600">حساب الإيراد (دائن)</label>
                                <select
                                    v-model="row.account_id"
                                    class="w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm focus:border-blue-400 focus:outline-none focus:ring-2 focus:ring-blue-100"
                                >
                                    <option :value="null">الافتراضي ({{ metaOf(row.key)?.default_code }})</option>
                                    <option v-for="account in accounts" :key="account.id" :value="account.id">
                                        {{ account.code }} — {{ account.name }}
                                    </option>
                                </select>
                                <p v-if="errorFor(index)" class="mt-1 text-xs text-red-500">{{ errorFor(index) }}</p>
                            </div>

                            <div>
                                <label class="mb-1 flex items-center gap-1 text-[11px] font-bold text-slate-600">
                                    <Landmark class="h-3 w-3 text-sky-500" /> يُودع في (مدين)
                                </label>
                                <select
                                    v-model="row.deposit_account_id"
                                    class="w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm focus:border-blue-400 focus:outline-none focus:ring-2 focus:ring-blue-100"
                                >
                                    <option :value="null">بحسب طريقة الدفع</option>
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
                                <p v-if="errorFor(index, 'deposit_account_id')" class="mt-1 text-xs text-red-500">
                                    {{ errorFor(index, 'deposit_account_id') }}
                                </p>
                                <p v-else-if="oddDeposit(row.deposit_account_id)" class="mt-1 text-[11px] font-bold text-amber-600">
                                    حساب من خارج النقدية وما في حكمها — تأكّد أنه الذي يستقبل المال فعلًا.
                                </p>
                            </div>
                        </div>
                    </div>

                    <p class="mt-4 rounded-xl border border-sky-100 bg-sky-50 px-3 py-2 text-[11px] font-medium leading-relaxed text-sky-800">
                        متى حدّدت حساب إيداع لمصدر، هبط عليه كل ما يُقبض منه مهما كانت طريقة الدفع — نقدًا كان أو شبكةً أو تحويلًا. فإن كان قبضك
                        يتوزّع بين الصندوق والبنك بحسب الطريقة، اتركه «بحسب طريقة الدفع» واضبط حساب كل طريقة من
                        <Link href="/admin/accounting/payment-methods" class="underline">طرق الدفع</Link>. ولا يظهر هنا إلا حساب أصول فعّال غير
                        تجميعي؛ حساب بنك جديد يُفتح من <Link href="/admin/accounting/accounts" class="underline">شجرة الحسابات</Link>.
                    </p>
                </div>

                <div
                    v-if="shared.length"
                    class="rounded-xl border border-slate-200 bg-white px-4 py-3 text-[11px] font-medium leading-relaxed text-slate-600"
                >
                    مصادر تشترك في حسابٍ واحد — يظهر دخلها مجموعًا في كشفه، وتفصيله يبقى بمركز التكلفة:
                    <span v-for="group in shared" :key="group.account?.id" class="mr-1 font-bold text-slate-800">
                        {{ group.labels.join(' و') }} على {{ group.account?.name }}.
                    </span>
                </div>

                <div class="flex flex-wrap items-center gap-2">
                    <button
                        type="submit"
                        :disabled="form.processing"
                        class="inline-flex items-center gap-1.5 rounded-md bg-blue-600 px-5 py-2.5 text-sm font-bold text-white shadow-sm transition hover:bg-blue-700 disabled:opacity-60"
                    >
                        <Save class="h-4 w-4" /> حفظ حسابات الإيراد
                    </button>
                    <Link
                        href="/admin/accounting/revenue-statement"
                        class="inline-flex items-center gap-1.5 rounded-xl border border-slate-200 bg-white px-4 py-2.5 text-sm font-bold text-slate-600 transition hover:bg-slate-50"
                    >
                        <FileText class="h-4 w-4" /> كشف حساب الإيراد
                    </Link>
                </div>
            </form>
        </div>
    </AppLayout>
</template>
