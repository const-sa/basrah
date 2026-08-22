<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { jsonHeaders } from '@/lib/csrf';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { AlertTriangle, CheckCircle2, KeyRound, Link2, Megaphone, MessageCircle, QrCode, RefreshCw, Save, Send, Smartphone, XCircle } from 'lucide-vue-next';
import { computed, onBeforeUnmount, ref } from 'vue';

interface WhatsappSettings {
    wa_enabled: boolean;
    wa_instance_id: string | null;
    wa_has_token: boolean;
    wa_number: string | null;
    wa_connected_at: string | null;
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
    wa_number: props.settings.wa_number ?? '',
    wa_welcome_enabled: props.settings.wa_welcome_enabled ?? false,
    wa_welcome_template: props.settings.wa_welcome_template ?? '',
});

const hasToken = ref(props.settings.wa_has_token);
const connectedAt = ref(props.settings.wa_connected_at);

const submit = () => {
    form.post('/admin/settings/whatsapp', {
        preserveScroll: true,
        onSuccess: () => {
            if (form.wa_access_token) hasToken.value = true;
            form.wa_access_token = '';
        },
    });
};

// ==== نداءات الواجهة (fetch مع رمز CSRF) ====
const call = async (url: string, body?: Record<string, unknown>) => {
    const res = await fetch(url, {
        method: body ? 'POST' : 'GET',
        headers: jsonHeaders(),
        credentials: 'same-origin',
        cache: 'no-store',
        body: body ? JSON.stringify(body) : undefined,
    });
    return res.json();
};

// ==== الربط: استعلام متكرر حتى يكتمل المسح ====
type ConnectState = 'idle' | 'qr' | 'pending' | 'problem' | 'connected' | 'unauthorized' | 'missing' | 'error';

interface ConnectResult {
    state: Exclude<ConnectState, 'idle'>;
    qr?: string;
    phone?: string | null;
    avatar_url?: string | null;
    platform?: string | null;
    subscription?: { days_remaining?: number; plan?: string } | null;
    mismatch?: boolean;
    expected_number?: string | null;
    message?: string;
    retrying?: boolean;
}

const linking = ref(false);
const state = ref<ConnectState>('idle');
const result = ref<ConnectResult | null>(null);
let timer: ReturnType<typeof setTimeout> | null = null;

const stopPolling = () => {
    if (timer) clearTimeout(timer);
    timer = null;
    linking.value = false;
};

/**
 * نبضةٌ كل ثلاث ثوانٍ: تُظهر الرمز وتُجدّده وتتوقّف وحدها عند نجاح
 * الربط — فلا يحتاج المستخدم للضغط على شيء بعد المسح.
 */
const poll = async () => {
    try {
        const res: ConnectResult = await call('/admin/settings/whatsapp/connect');
        result.value = res;
        state.value = res.state;

        if (res.state === 'connected') {
            connectedAt.value = new Date().toLocaleString('ar');
            form.wa_enabled = true;
            if (res.phone && !form.wa_number) form.wa_number = res.phone;
            stopPolling();
            return;
        }

        // اعتمادٌ خاطئ أو ناقص: لا فائدة من التكرار حتى تُصحَّح البيانات.
        if (res.state === 'unauthorized' || res.state === 'missing') {
            stopPolling();
            return;
        }

        timer = setTimeout(poll, 3000);
    } catch {
        state.value = 'error';
        result.value = { state: 'error', message: 'تعذّر الاتصال بالخادم — إعادة المحاولة…' };
        timer = setTimeout(poll, 5000);
    }
};

const startLinking = () => {
    stopPolling();
    linking.value = true;
    state.value = 'pending';
    result.value = { state: 'pending', message: 'جارٍ الاستعلام عن حالة الرقم…' };
    poll();
};

onBeforeUnmount(stopPolling);

const stateLabel = computed(() => {
    switch (state.value) {
        case 'connected':
            return 'متصل';
        case 'qr':
            return 'في انتظار المسح';
        case 'pending':
            return 'جارٍ التوليد…';
        case 'problem':
            return 'تعثّر مؤقّت';
        case 'unauthorized':
            return 'اعتماد غير صحيح';
        case 'missing':
            return 'بيانات ناقصة';
        case 'error':
            return 'خطأ';
        default:
            return 'غير مبدوء';
    }
});

const stateTone = computed(() => {
    if (state.value === 'connected') return 'bg-emerald-100 text-emerald-700';
    if (state.value === 'qr' || state.value === 'pending' || state.value === 'problem') return 'bg-amber-100 text-amber-700';
    if (state.value === 'idle') return 'bg-slate-100 text-slate-600';
    return 'bg-red-100 text-red-700';
});

// ==== إرسال تجريبي ====
const testNumber = ref('');
const testing = ref(false);
const testResult = ref<{ ok: boolean; message?: string } | null>(null);

const sendTest = async () => {
    if (!testNumber.value.trim()) return;
    testing.value = true;
    testResult.value = null;
    try {
        testResult.value = await call('/admin/settings/whatsapp/test', { number: testNumber.value });
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

                <div class="grid gap-4 sm:grid-cols-3">
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
                    <div>
                        <label class="mb-1 block text-sm font-bold text-slate-700">رقم الواتساب المرتبط</label>
                        <input v-model="form.wa_number" type="text" dir="ltr" placeholder="9665xxxxxxxx" class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm focus:border-emerald-400 focus:outline-none focus:ring-2 focus:ring-emerald-100" />
                        <p class="mt-1 text-[11px] font-medium text-slate-500">يُستخدم للاستعلام والتأكّد أن الجهاز الممسوح هو رقم النشاط.</p>
                        <p v-if="form.errors.wa_number" class="mt-1 text-xs text-red-500">{{ form.errors.wa_number }}</p>
                    </div>
                </div>
                <p class="mt-3 text-xs font-medium text-slate-500">احفظ الإعدادات أولاً — البوابة تقرأ المعرّفات من الخادم لا من الشاشة.</p>
            </div>

            <!-- الربط بالكيو آر -->
            <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
                    <div class="flex items-center gap-2">
                        <Link2 class="h-5 w-5 text-emerald-600" />
                        <h2 class="text-lg font-bold text-slate-800">ربط الجهاز</h2>
                        <span :class="['rounded-full px-3 py-1 text-xs font-extrabold', stateTone]">{{ stateLabel }}</span>
                    </div>
                    <div class="flex items-center gap-2">
                        <button v-if="linking" type="button" @click="stopPolling" class="rounded-xl border border-slate-200 bg-white px-4 py-2 text-sm font-bold text-slate-600 transition hover:bg-slate-50">
                            إيقاف
                        </button>
                        <button type="button" @click="startLinking" :disabled="linking" class="inline-flex items-center gap-1.5 rounded-xl brand-gradient px-4 py-2 text-sm font-bold text-white shadow-md transition hover:brightness-110 disabled:opacity-60">
                            <RefreshCw :class="['h-4 w-4', linking && 'animate-spin']" />
                            {{ linking ? 'جارٍ الاستعلام…' : state === 'connected' ? 'إعادة الاستعلام' : 'استعلام وربط' }}
                        </button>
                    </div>
                </div>

                <p class="mb-4 text-sm font-medium text-slate-600">
                    اضغط «استعلام وربط»: يُستعلَم عن حالة الرقم، فإن كان مرتبطاً ظهرت بياناته، وإلا ظهر رمز QR ويتجدّد تلقائياً — وبمجرد مسحه من الجوال يكتمل الربط ويُفعَّل التكامل دون أي خطوة إضافية.
                </p>

                <!-- تم الربط -->
                <div v-if="state === 'connected'" class="space-y-3">
                    <div class="flex flex-wrap items-center gap-4 rounded-2xl border border-emerald-200 bg-emerald-50 p-5">
                        <img v-if="result?.avatar_url" :src="result.avatar_url" alt="" class="h-16 w-16 rounded-full border-2 border-white object-cover shadow" />
                        <span v-else class="flex h-16 w-16 items-center justify-center rounded-full bg-emerald-600 text-white shadow"><Smartphone class="h-7 w-7" /></span>
                        <div class="flex-1">
                            <p class="flex items-center gap-1.5 text-base font-extrabold text-emerald-800"><CheckCircle2 class="h-5 w-5" /> تم الربط بنجاح</p>
                            <p v-if="result?.phone" dir="ltr" class="mt-0.5 text-lg font-extrabold text-slate-800">+{{ result.phone }}</p>
                            <p class="mt-1 flex flex-wrap gap-x-3 text-xs font-bold text-slate-600">
                                <span v-if="result?.platform">المنصّة: {{ result.platform }}</span>
                                <span v-if="result?.subscription?.days_remaining !== undefined">متبقٍ من الاشتراك: {{ result.subscription.days_remaining }} يوم</span>
                                <span v-if="connectedAt" dir="ltr">{{ connectedAt }}</span>
                            </p>
                        </div>
                    </div>

                    <!-- الرقم الممسوح يخالف رقم النشاط المُدخل -->
                    <div v-if="result?.mismatch" class="flex items-start gap-2 rounded-2xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm font-bold text-amber-800">
                        <AlertTriangle class="mt-0.5 h-5 w-5 shrink-0" />
                        <span>الجهاز المرتبط رقمه <span dir="ltr">{{ result.phone }}</span> بينما الرقم المُدخل <span dir="ltr">{{ result.expected_number }}</span>. عدّل الرقم أو أعد الربط من جوال النشاط.</span>
                    </div>
                </div>

                <!-- رمز QR -->
                <div v-else-if="state === 'qr' && result?.qr" class="grid gap-5 sm:grid-cols-[auto_1fr] sm:items-center">
                    <div class="mx-auto rounded-2xl border border-slate-200 bg-white p-3 shadow-sm">
                        <img :src="result.qr" alt="QR" class="h-56 w-56" />
                    </div>
                    <ol class="space-y-2 rounded-2xl bg-slate-50 p-4 text-sm font-medium leading-7 text-slate-700">
                        <li>١. افتح <strong>واتساب</strong> على جوال النشاط.</li>
                        <li>٢. اذهب إلى <strong>الإعدادات ← الأجهزة المرتبطة</strong>.</li>
                        <li>٣. اضغط <strong>ربط جهاز</strong> ووجّه الكاميرا للرمز.</li>
                        <li class="text-xs font-bold text-emerald-700">يتجدّد الرمز تلقائياً، ولا حاجة لتحديث الصفحة بعد المسح.</li>
                    </ol>
                </div>

                <!-- انتظار / تعثّر / أخطاء -->
                <div v-else-if="state === 'pending' || state === 'problem'" class="flex items-center gap-3 rounded-2xl border border-amber-200 bg-amber-50 px-4 py-6 text-sm font-bold text-amber-800">
                    <RefreshCw class="h-5 w-5 shrink-0 animate-spin" />
                    <span>{{ result?.message }}</span>
                </div>

                <div v-else-if="state === 'unauthorized' || state === 'missing' || state === 'error'" class="flex items-start gap-2 rounded-2xl border border-red-200 bg-red-50 px-4 py-4 text-sm font-bold text-red-700">
                    <XCircle class="mt-0.5 h-5 w-5 shrink-0" />
                    <span>{{ result?.message }}</span>
                </div>

                <div v-else class="flex flex-col items-center gap-2 rounded-2xl border-2 border-dashed border-slate-300 py-10 text-slate-400">
                    <QrCode class="h-10 w-10" />
                    <span class="text-sm font-bold">اضغط «استعلام وربط» لعرض رمز الاتصال</span>
                </div>
            </div>

            <!-- قالب الترحيب -->
            <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                <div class="mb-4 flex items-center justify-between">
                    <div class="flex items-center gap-2">
                        <MessageCircle class="h-5 w-5 text-emerald-600" />
                        <h2 class="text-lg font-bold text-slate-800">الترحيب بالعميل</h2>
                    </div>
                    <button type="button" role="switch" :aria-checked="form.wa_welcome_enabled" @click="form.wa_welcome_enabled = !form.wa_welcome_enabled"
                        :class="['relative inline-flex h-6 w-11 items-center rounded-full transition', form.wa_welcome_enabled ? 'brand-gradient' : 'bg-slate-300']">
                        <span :class="['inline-block h-4 w-4 transform rounded-full bg-white transition', form.wa_welcome_enabled ? '-translate-x-1' : '-translate-x-6']"></span>
                    </button>
                </div>

                <p class="mb-3 text-sm font-medium text-slate-600">
                    تُرسَل رسالة الترحيب تلقائياً عند إضافة عميل جديد لديه رقم جوال. النصّ المستخدَم هو قالب «ترحيب بالعميل» من
                    <Link href="/admin/notifications/library" class="font-bold text-emerald-700 underline underline-offset-2">مكتبة الإشعارات</Link>
                    إن وُجد، وإلا استُخدم النصّ الاحتياطي أدناه.
                </p>

                <div class="mb-2 flex flex-wrap items-center gap-2">
                    <span class="text-xs font-bold text-slate-600">إدراج متغيّر:</span>
                    <button type="button" @click="insertVar('name')" class="rounded-lg bg-slate-200 px-2 py-1 text-xs font-bold text-slate-700 transition hover:bg-slate-300">{name} اسم العميل</button>
                    <button type="button" @click="insertVar('business_name')" class="rounded-lg bg-slate-200 px-2 py-1 text-xs font-bold text-slate-700 transition hover:bg-slate-300">{business_name} اسم النشاط</button>
                </div>

                <textarea v-model="form.wa_welcome_template" rows="5" class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm leading-7 focus:border-emerald-400 focus:outline-none focus:ring-2 focus:ring-emerald-100" placeholder="مرحباً {name} 👋 …"></textarea>
                <p v-if="form.errors.wa_welcome_template" class="mt-1 text-xs text-red-500">{{ form.errors.wa_welcome_template }}</p>

                <Link href="/admin/notifications/library" class="mt-3 inline-flex items-center gap-1.5 rounded-xl border border-slate-200 bg-white px-3.5 py-2 text-sm font-bold text-slate-700 shadow-sm transition hover:bg-slate-50">
                    <Megaphone class="h-4 w-4" /> إدارة قوالب الشاليهات والقاعات والمسابح
                </Link>
            </div>

            <!-- إرسال تجريبي -->
            <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                <div class="mb-4 flex items-center gap-2">
                    <Send class="h-5 w-5 text-emerald-600" />
                    <h2 class="text-lg font-bold text-slate-800">إرسال رسالة تجريبية</h2>
                </div>
                <div class="flex flex-wrap items-end gap-3">
                    <div class="min-w-[220px] flex-1">
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
