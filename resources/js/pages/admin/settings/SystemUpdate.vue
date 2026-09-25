<script setup lang="ts">
import SettingsTabs from '@/components/SettingsTabs.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { jsonHeaders } from '@/lib/csrf';
import { type BreadcrumbItem } from '@/types';
import { Head, useForm } from '@inertiajs/vue3';
import { AlertTriangle, CheckCircle2, CloudDownload, GitBranch, GitCommitHorizontal, Lock, RefreshCw, Save, Search, XCircle } from 'lucide-vue-next';
import { ref } from 'vue';

interface Commit {
    commit: string;
    message: string;
    date: string;
}

interface Run {
    at: string;
    ok: boolean;
    from: string | null;
    to: string | null;
    log: string;
}

const props = defineProps<{
    repository: { owner: string; repo: string; branch: string; saved_token: string };
    configured: boolean;
    is_git: boolean;
    current: Commit | null;
    last_run: Run | null;
    env_writable: boolean;
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'لوحة التحكم', href: '/admin' },
    { title: 'تحديث النظام', href: '/admin/settings/system-update' },
];

const form = useForm({
    owner: props.repository.owner,
    repo: props.repository.repo,
    branch: props.repository.branch,
    token: '',
});

const savedToken = ref(props.repository.saved_token);

const save = () =>
    form.post('/admin/settings/system-update', {
        preserveScroll: true,
        onSuccess: () => {
            if (form.token) savedToken.value = `${form.token.slice(0, 4)}••••••${form.token.slice(-4)}`;
            form.token = '';
        },
    });

const call = async (url: string, method: 'GET' | 'POST' = 'GET') => {
    const res = await fetch(url, { method, headers: jsonHeaders(), credentials: 'same-origin', cache: 'no-store' });
    return res.json();
};

// ==== الفحص ====
const checking = ref(false);
const check = ref<{ ok: boolean; error?: string; behind?: number; commits?: string[]; dirty?: string[] } | null>(null);

const runCheck = async () => {
    checking.value = true;
    check.value = null;
    try {
        check.value = await call('/admin/settings/system-update/check');
    } catch {
        check.value = { ok: false, error: 'تعذّر الاتصال بالخادم.' };
    } finally {
        checking.value = false;
    }
};

// ==== التحديث ====
const updating = ref(false);
const current = ref<Commit | null>(props.current);
const lastRun = ref<Run | null>(props.last_run);

const runUpdate = async () => {
    const warning = check.value?.dirty?.length ? '\n\nتنبيه: التعديلات المباشرة على ملفات الخادم ستُطرح.' : '';
    if (!confirm(`سحب آخر نسخة من ${form.owner}/${form.repo} (${form.branch}) وتطبيقها الآن؟${warning}`)) return;

    updating.value = true;
    try {
        const res = await call('/admin/settings/system-update/run', 'POST');
        lastRun.value = { at: res.at ?? new Date().toLocaleString('en-GB'), ok: res.ok, from: res.from ?? null, to: res.to ?? null, log: res.log ?? '' };
        if (res.current) current.value = res.current;
        if (res.ok) check.value = { ok: true, behind: 0, commits: [], dirty: [] };
    } catch {
        lastRun.value = { at: '', ok: false, from: null, to: null, log: 'انقطع الاتصال أثناء التحديث — قد يكون ما زال يعمل على الخادم. حدّث الصفحة بعد دقيقة.' };
    } finally {
        updating.value = false;
    }
};

const input =
    'w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm focus:border-emerald-400 focus:outline-none focus:ring-2 focus:ring-emerald-100';
</script>

<template>
    <Head title="تحديث النظام" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="min-h-full space-y-5 bg-slate-100 p-5">
            <SettingsTabs />

            <div>
                <h1 class="text-2xl font-extrabold text-slate-900">تحديث النظام</h1>
                <p class="mt-1 text-sm font-medium text-slate-600">سحب آخر نسخة من GitHub وتطبيقها على هذا الخادم: الترحيلات، والمكتبات، وبناء الواجهة.</p>
            </div>

            <div v-if="!is_git" class="flex items-start gap-2 rounded-2xl border border-red-200 bg-red-50 px-4 py-3 text-sm font-bold text-red-700">
                <XCircle class="mt-0.5 h-5 w-5 shrink-0" />
                <span>مجلد المشروع على هذا الخادم ليس مستودع git — التحديث من هنا يحتاج أن يكون المشروع منسوخاً بـ git clone.</span>
            </div>

            <!-- النسخة الحالية -->
            <div class="flex flex-wrap items-center gap-4 rounded-2xl border border-slate-200 bg-white px-5 py-4 shadow-sm">
                <span class="flex h-10 w-10 items-center justify-center rounded-full bg-slate-800 text-white"><GitCommitHorizontal class="h-5 w-5" /></span>
                <div class="flex-1">
                    <p class="text-xs font-bold text-slate-500">النسخة العاملة الآن</p>
                    <p v-if="current" class="font-extrabold text-slate-800">
                        <span dir="ltr" class="me-2 rounded bg-slate-100 px-1.5 font-mono text-sm">{{ current.commit }}</span>{{ current.message }}
                    </p>
                    <p v-else class="font-bold text-slate-400">غير معروفة</p>
                    <p v-if="current" dir="ltr" class="text-right text-xs text-slate-500">{{ current.date }}</p>
                </div>
                <button type="button" @click="runCheck" :disabled="checking || !configured" class="inline-flex items-center gap-1.5 rounded-xl border border-slate-200 bg-white px-4 py-2 text-sm font-bold text-slate-700 hover:bg-slate-50 disabled:opacity-60">
                    <Search :class="['h-4 w-4', checking && 'animate-pulse']" /> {{ checking ? 'جارٍ الفحص…' : 'فحص التحديثات' }}
                </button>
                <button type="button" @click="runUpdate" :disabled="updating || !configured || !is_git" class="inline-flex items-center gap-1.5 rounded-xl brand-gradient px-4 py-2 text-sm font-bold text-white shadow-md hover:brightness-110 disabled:opacity-60">
                    <RefreshCw :class="['h-4 w-4', updating && 'animate-spin']" /> {{ updating ? 'جارٍ التحديث… لا تغلق الصفحة' : 'تحديث الآن' }}
                </button>
            </div>

            <!-- نتيجة الفحص -->
            <div v-if="check" class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                <p v-if="!check.ok" class="flex items-start gap-2 text-sm font-bold text-red-700"><XCircle class="mt-0.5 h-5 w-5 shrink-0" /> {{ check.error }}</p>
                <template v-else>
                    <p v-if="check.behind" class="flex items-center gap-2 text-sm font-extrabold text-blue-700">
                        <CloudDownload class="h-5 w-5" /> يوجد {{ check.behind }} تحديث جديد لم يُطبَّق بعد
                    </p>
                    <p v-else class="flex items-center gap-2 text-sm font-extrabold text-emerald-700"><CheckCircle2 class="h-5 w-5" /> النظام على آخر نسخة</p>
                    <ul v-if="check.commits?.length" class="mt-3 space-y-1 rounded-xl bg-slate-50 p-3 text-xs">
                        <li v-for="c in check.commits" :key="c" dir="ltr" class="text-left font-mono text-slate-700">{{ c }}</li>
                    </ul>
                    <div v-if="check.dirty?.length" class="mt-3 rounded-xl border border-amber-200 bg-amber-50 p-3 text-xs font-bold text-amber-800">
                        <p class="mb-1 flex items-center gap-1.5"><AlertTriangle class="h-4 w-4" /> ملفات عُدِّلت مباشرةً على الخادم وستُطرح عند التحديث:</p>
                        <p v-for="f in check.dirty" :key="f" dir="ltr" class="text-left font-mono">{{ f }}</p>
                    </div>
                </template>
            </div>

            <!-- آخر تحديث -->
            <div v-if="lastRun" class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                <div class="mb-3 flex flex-wrap items-center gap-2">
                    <CheckCircle2 v-if="lastRun.ok" class="h-5 w-5 text-emerald-600" />
                    <XCircle v-else class="h-5 w-5 text-red-600" />
                    <h2 class="text-lg font-bold text-slate-800">{{ lastRun.ok ? 'آخر تحديث نجح' : 'آخر تحديث فشل' }}</h2>
                    <span v-if="lastRun.from || lastRun.to" dir="ltr" class="rounded bg-slate-100 px-2 py-0.5 font-mono text-xs text-slate-600">{{ lastRun.from ?? '?' }} → {{ lastRun.to ?? '?' }}</span>
                    <span dir="ltr" class="ms-auto text-xs text-slate-500">{{ lastRun.at }}</span>
                </div>
                <pre dir="ltr" class="max-h-96 overflow-auto whitespace-pre-wrap rounded-xl bg-slate-900 p-4 text-left text-xs leading-5 text-slate-100">{{ lastRun.log }}</pre>
            </div>

            <!-- بيانات المستودع -->
            <form @submit.prevent="save" class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                <div class="mb-1 flex items-center gap-2">
                    <GitBranch class="h-5 w-5 text-emerald-600" />
                    <h2 class="text-lg font-bold text-slate-800">بيانات GitHub</h2>
                    <span :class="['rounded-full px-3 py-1 text-xs font-extrabold', configured ? 'bg-emerald-100 text-emerald-700' : 'bg-amber-100 text-amber-700']">{{ configured ? 'مكتملة' : 'ناقصة' }}</span>
                </div>
                <p class="mb-4 text-sm font-medium text-slate-600">
                    تُحفظ في ملف <code dir="ltr" class="rounded bg-slate-100 px-1 text-xs">.env</code> ولا تُخزَّن في قاعدة البيانات.
                </p>

                <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <div>
                        <label class="mb-1 block text-sm font-bold text-slate-700">المالك (Owner)</label>
                        <input v-model="form.owner" type="text" dir="ltr" placeholder="const-sa" :class="input" />
                        <p v-if="form.errors.owner" class="mt-1 text-xs text-red-500">{{ form.errors.owner }}</p>
                    </div>
                    <div>
                        <label class="mb-1 block text-sm font-bold text-slate-700">المستودع (Repository)</label>
                        <input v-model="form.repo" type="text" dir="ltr" placeholder="basrah" :class="input" />
                        <p v-if="form.errors.repo" class="mt-1 text-xs text-red-500">{{ form.errors.repo }}</p>
                    </div>
                    <div>
                        <label class="mb-1 block text-sm font-bold text-slate-700">الفرع (Branch)</label>
                        <input v-model="form.branch" type="text" dir="ltr" placeholder="main" :class="input" />
                        <p v-if="form.errors.branch" class="mt-1 text-xs text-red-500">{{ form.errors.branch }}</p>
                    </div>
                    <div>
                        <label class="mb-1 block text-sm font-bold text-slate-700">رمز الوصول (Token)</label>
                        <input v-model="form.token" type="password" dir="ltr" autocomplete="new-password" :placeholder="savedToken || 'ghp_…'" :class="input" />
                        <p v-if="form.errors.token" class="mt-1 text-xs text-red-500">{{ form.errors.token }}</p>
                        <p class="mt-1 flex items-start gap-1 text-[11px] font-medium text-slate-500">
                            <Lock class="mt-0.5 h-3 w-3 shrink-0" />
                            <span>{{ savedToken ? 'محفوظ. اتركه فارغاً للإبقاء عليه.' : 'رمز GitHub بصلاحية قراءة المستودع (Contents: Read).' }}</span>
                        </p>
                    </div>
                </div>

                <div class="mt-4 flex items-center gap-3">
                    <button type="submit" :disabled="form.processing" class="inline-flex items-center gap-1.5 rounded-xl brand-gradient px-4 py-2 text-sm font-bold text-white shadow-md hover:brightness-110 disabled:opacity-60">
                        <Save class="h-4 w-4" /> {{ form.processing ? 'جارٍ الحفظ…' : 'حفظ' }}
                    </button>
                    <p v-if="!env_writable" class="text-xs font-bold text-amber-700">ملف .env غير قابل للكتابة على الخادم — لن تُحفظ البيانات.</p>
                </div>
            </form>
        </div>
    </AppLayout>
</template>
