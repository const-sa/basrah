<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { jsonHeaders } from '@/lib/csrf';
import { type BreadcrumbItem } from '@/types';
import { Head, useForm } from '@inertiajs/vue3';
import { CheckCircle2, KeyRound, MessageCircle, Save, Send, XCircle } from 'lucide-vue-next';
import { computed, ref } from 'vue';

interface WhatsappSettings {
    wa_enabled: boolean;
    wa_instance_id: string | null;
    wa_has_token: boolean;
    wa_welcome_enabled: boolean;
    wa_welcome_template: string;
}

const props = defineProps<{ settings: WhatsappSettings }>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'لوحة التحكم', href: '/admin' },
    { title: 'إعدادات الواتساب', href: '/admin/settings/whatsapp' },
];

const form = useForm({
    wa_enabled: props.settings.wa_enabled ?? false,
    wa_instance_id: props.settings.wa_instance_id ?? '',
    // فارغ = عدم تغيير التوكن المحفوظ.
    wa_access_token: '',
    wa_welcome_enabled: props.settings.wa_welcome_enabled ?? false,
    wa_welcome_template: props.settings.wa_welcome_template ?? '',
});

const hasToken = ref(props.settings.wa_has_token);

const submit = () => {
    form.post('/admin/settings/whatsapp', {
        preserveScroll: true,
        onSuccess: () => {
            if (form.wa_access_token) hasToken.value = true;
            form.wa_access_token = '';
        },
    });
};

// ==== التحقق من الاتصال / الإرسال التجريبي (عبر fetch مع رمز CSRF) ====
const post = async (url: string, body?: Record<string, unknown>) => {
    const res = await fetch(url, {
        method: body ? 'POST' : 'GET',
        headers: jsonHeaders(),
        credentials: 'same-origin',
        body: body ? JSON.stringify(body) : undefined,
    });
    return res.json();
};

type StatusResult = {
    ok: boolean;
    configured?: boolean;
    status?: string | null;
    phone?: string | null;
    subscription?: { days_remaining?: number } | null;
    message?: string | null;
};

const checking = ref(false);
const statusResult = ref<StatusResult | null>(null);

const checkStatus = async () => {
    checking.value = true;
    statusResult.value = null;
    try {
        statusResult.value = await post('/admin/settings/whatsapp/status');
    } catch {
        statusResult.value = { ok: false, message: 'تعذّر الاتصال بالخادم.' };
    } finally {
        checking.value = false;
    }
};

const statusLabel = computed(() => {
    const s = statusResult.value;
    if (!s) return '';
    if (s.ok && s.status === 'connected') return 'متصل';
    if (s.configured === false) return 'غير مُهيّأ';
    return s.message || 'غير متصل';
});

// إرسال تجريبي
const testNumber = ref('');
const testing = ref(false);
const testResult = ref<{ ok: boolean; message?: string } | null>(null);

const sendTest = async () => {
    if (!testNumber.value.trim()) return;
    testing.value = true;
    testResult.value = null;
    try {
        testResult.value = await post('/admin/settings/whatsapp/test', { number: testNumber.value });
    } catch {
        testResult.value = { ok: false, message: 'تعذّر الاتصال بالخادم.' };
    } finally {
        testing.value = false;
    }
};

const insertVar = (v: string) => {
    form.wa_welcome_template = (form.wa_welcome_template ?? '') + `{${v}}`;
};
</script>

<template>
    <Head title="إعدادات الواتساب" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <form @submit.prevent="submit" class="min-h-full space-y-5 bg-slate-100 p-5">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h1 class="text-2xl font-extrabold text-slate-900">إعدادات الواتساب</h1>
                    <p class="mt-1 text-sm font-medium text-slate-600">ربط بوابة c-wts.com للتراسل الآلي مع العملاء</p>
                </div>
                <button type="submit" :disabled="form.processing" class="inline-flex items-center gap-1.5 rounded-md bg-blue-600 px-5 py-2 text-sm font-bold text-white shadow-sm transition hover:bg-blue-700 disabled:opacity-60">
                    <Save class="h-4 w-4" /> حفظ التغييرات
                </button>
            </div>

            <!-- التفعيل والمعرّفات -->
            <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                <div class="mb-4 flex items-center justify-between">
                    <div class="flex items-center gap-2">
                        <KeyRound class="h-5 w-5 text-emerald-600" />
                        <h2 class="text-lg font-bold text-slate-800">التفعيل ومعرّفات الاتصال</h2>
                    </div>
                    <button type="button" role="switch" :aria-checked="form.wa_enabled" @click="form.wa_enabled = !form.wa_enabled"
                        :class="['relative inline-flex h-6 w-11 items-center rounded-full transition', form.wa_enabled ? 'brand-gradient' : 'bg-slate-300']">
                        <span :class="['inline-block h-4 w-4 transform rounded-full bg-white transition', form.wa_enabled ? '-translate-x-1' : '-translate-x-6']"></span>
                    </button>
                </div>

                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label class="mb-1 block text-sm font-bold text-slate-700">معرّف الجهاز (Instance ID)</label>
                        <input v-model="form.wa_instance_id" type="text" dir="ltr" placeholder="0001" class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm focus:border-emerald-400 focus:outline-none focus:ring-2 focus:ring-emerald-100" />
                        <p v-if="form.errors.wa_instance_id" class="mt-1 text-xs text-red-500">{{ form.errors.wa_instance_id }}</p>
                    </div>
                    <div>
                        <label class="mb-1 block text-sm font-bold text-slate-700">
                            رمز الوصول (Access Token)
                            <span v-if="hasToken" class="text-xs font-bold text-emerald-700">— محفوظ</span>
                        </label>
                        <input v-model="form.wa_access_token" type="password" dir="ltr" :placeholder="hasToken ? '•••••••• (اتركه فارغاً للإبقاء عليه)' : 'const0001'" class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm focus:border-emerald-400 focus:outline-none focus:ring-2 focus:ring-emerald-100" />
                        <p v-if="form.errors.wa_access_token" class="mt-1 text-xs text-red-500">{{ form.errors.wa_access_token }}</p>
                    </div>
                </div>

                <!-- التحقق من الاتصال -->
                <div class="mt-4 flex flex-wrap items-center gap-3 border-t border-slate-100 pt-4">
                    <button type="button" @click="checkStatus" :disabled="checking" class="inline-flex items-center gap-1.5 rounded-xl brand-gradient px-4 py-2 text-sm font-bold text-white shadow-md transition hover:brightness-110 disabled:opacity-60">
                        {{ checking ? 'جارٍ التحقق…' : 'التحقق من الاتصال' }}
                    </button>
                    <div v-if="statusResult" class="flex items-center gap-1.5 text-sm font-bold">
                        <CheckCircle2 v-if="statusResult.ok && statusResult.status === 'connected'" class="h-4 w-4 text-emerald-600" />
                        <XCircle v-else class="h-4 w-4 text-red-500" />
                        <span :class="statusResult.ok && statusResult.status === 'connected' ? 'text-emerald-700' : 'text-red-600'">{{ statusLabel }}</span>
                        <span v-if="statusResult.phone" dir="ltr" class="text-slate-600">({{ statusResult.phone }})</span>
                        <span v-if="statusResult.subscription?.days_remaining !== undefined" class="text-slate-500">— متبقٍ {{ statusResult.subscription.days_remaining }} يوم</span>
                    </div>
                    <p class="w-full text-xs font-medium text-slate-500">احفظ الإعدادات أولاً ثم اضغط للتحقق من ربط جلسة الواتساب.</p>
                </div>
            </div>

            <!-- قالب الترحيب -->
            <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                <div class="mb-4 flex items-center justify-between">
                    <div class="flex items-center gap-2">
                        <MessageCircle class="h-5 w-5 text-emerald-600" />
                        <h2 class="text-lg font-bold text-slate-800">قالب الترحيب بالعميل</h2>
                    </div>
                    <button type="button" role="switch" :aria-checked="form.wa_welcome_enabled" @click="form.wa_welcome_enabled = !form.wa_welcome_enabled"
                        :class="['relative inline-flex h-6 w-11 items-center rounded-full transition', form.wa_welcome_enabled ? 'brand-gradient' : 'bg-slate-300']">
                        <span :class="['inline-block h-4 w-4 transform rounded-full bg-white transition', form.wa_welcome_enabled ? '-translate-x-1' : '-translate-x-6']"></span>
                    </button>
                </div>

                <p class="mb-3 text-sm font-medium text-slate-600">تُرسَل هذه الرسالة تلقائياً عبر الواتساب عند إضافة عميل جديد لديه رقم جوال (عند تفعيل السويتش).</p>

                <div class="mb-2 flex flex-wrap items-center gap-2">
                    <span class="text-xs font-bold text-slate-600">إدراج متغيّر:</span>
                    <button type="button" @click="insertVar('name')" class="rounded-lg bg-slate-200 px-2 py-1 text-xs font-bold text-slate-700 transition hover:bg-slate-300">{name} اسم العميل</button>
                    <button type="button" @click="insertVar('business_name')" class="rounded-lg bg-slate-200 px-2 py-1 text-xs font-bold text-slate-700 transition hover:bg-slate-300">{business_name} اسم النشاط</button>
                </div>

                <textarea v-model="form.wa_welcome_template" rows="5" class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm leading-7 focus:border-emerald-400 focus:outline-none focus:ring-2 focus:ring-emerald-100" placeholder="مرحباً {name} 👋 …"></textarea>
                <p v-if="form.errors.wa_welcome_template" class="mt-1 text-xs text-red-500">{{ form.errors.wa_welcome_template }}</p>
            </div>

            <!-- إرسال تجريبي -->
            <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                <div class="mb-4 flex items-center gap-2">
                    <Send class="h-5 w-5 text-emerald-600" />
                    <h2 class="text-lg font-bold text-slate-800">إرسال رسالة تجريبية</h2>
                </div>
                <div class="flex flex-wrap items-end gap-3">
                    <div class="flex-1 min-w-[220px]">
                        <label class="mb-1 block text-sm font-bold text-slate-700">رقم الجوال</label>
                        <input v-model="testNumber" type="text" dir="ltr" placeholder="05xxxxxxxx" class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm focus:border-emerald-400 focus:outline-none focus:ring-2 focus:ring-emerald-100" />
                    </div>
                    <button type="button" @click="sendTest" :disabled="testing || !testNumber.trim()" class="inline-flex items-center gap-1.5 rounded-xl brand-gradient px-4 py-2.5 text-sm font-bold text-white shadow-md transition hover:brightness-110 disabled:opacity-60">
                        <Send class="h-4 w-4" /> {{ testing ? 'جارٍ الإرسال…' : 'إرسال' }}
                    </button>
                </div>
                <div v-if="testResult" class="mt-3 flex items-center gap-1.5 text-sm font-bold">
                    <CheckCircle2 v-if="testResult.ok" class="h-4 w-4 text-emerald-600" />
                    <XCircle v-else class="h-4 w-4 text-red-500" />
                    <span :class="testResult.ok ? 'text-emerald-700' : 'text-red-600'">{{ testResult.message }}</span>
                </div>
            </div>
        </form>
    </AppLayout>
</template>
