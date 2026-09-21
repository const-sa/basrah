<script setup lang="ts">
import SettingsTabs from '@/components/SettingsTabs.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { jsonHeaders } from '@/lib/csrf';
import { type BreadcrumbItem } from '@/types';
import { Head, useForm } from '@inertiajs/vue3';
import {
    AlertTriangle,
    CheckCircle2,
    KeyRound,
    Link2,
    Lock,
    Plug,
    QrCode,
    RefreshCw,
    Save,
    Send,
    SlidersHorizontal,
    Smartphone,
    XCircle,
} from 'lucide-vue-next';
import { computed, onBeforeUnmount, ref } from 'vue';

interface WhatsappSettings {
    wa_enabled: boolean;
    wa_number: string | null;
    wa_connected_at: string | null;
}

interface DriverOption {
    key: string;
    label: string;
    /** البوابة تربط بالرقم: يُسأل عن الرقم قبل عرض الرمز. */
    links_by_phone: boolean;
}

interface Credentials {
    base_url: string;
    instance_id: string;
    /** بصمة التوكن المحفوظ، لا التوكن. */
    saved_token: string;
}

interface Gateway {
    driver: string;
    drivers: DriverOption[];
    country_code: string;
    credentials: Record<string, Credentials>;
    lookup_enabled: boolean;
    env_writable: boolean;
    env_path: string;
}

const props = defineProps<{ settings: WhatsappSettings; gateway: Gateway }>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'لوحة التحكم', href: '/admin' },
    { title: 'إعدادات الواتساب', href: '/admin/settings/whatsapp' },
];

// معرّفات كل بوابة على حدة: تبديل المنتقي يُظهر بياناتها هي، وحفظةٌ واحدة
// تكتبها جميعاً، فلا تضيع بيانات بوابةٍ لأن غيرها هو المفعَّل الآن.
const blankCredentials = (): Record<string, { base_url: string; instance_id: string; access_token: string }> =>
    Object.fromEntries(
        props.gateway.drivers.map((driver) => [
            driver.key,
            {
                base_url: props.gateway.credentials[driver.key]?.base_url ?? '',
                instance_id: props.gateway.credentials[driver.key]?.instance_id ?? '',
                // فارغ = إبقاء التوكن المحفوظ.
                access_token: '',
            },
        ]),
    );

const form = useForm({
    wa_enabled: props.settings.wa_enabled ?? false,
    wa_driver: props.gateway.driver,
    wa_country_code: props.gateway.country_code ?? '',
    credentials: blankCredentials(),
    wa_number: props.settings.wa_number ?? '',
});

/** البوابة المنتقاة الآن على الشاشة، لا المحفوظة في .env. */
const driver = computed(() => props.gateway.drivers.find((d) => d.key === form.wa_driver));
const driverLabel = computed(() => driver.value?.label ?? form.wa_driver);
const current = computed(() => form.credentials[form.wa_driver]);
const savedToken = ref<Record<string, string>>(
    Object.fromEntries(props.gateway.drivers.map((d) => [d.key, props.gateway.credentials[d.key]?.saved_token ?? ''])),
);

/** خطأ حقلٍ من حقول البوابة المنتقاة، ومفتاحه متشعّب فلا تطاله أنواع النموذج. */
const fieldError = (option: string): string | undefined =>
    (form.errors as Record<string, string | undefined>)[`credentials.${form.wa_driver}.${option}`];

/** على بوابةٍ تربط بالرقم يكون الرقم هو المدخل، ولا يتكرّر في حقل ثانٍ. */
const byPhone = computed(() => driver.value?.links_by_phone ?? false);

/** والجلب مسألة أخرى: بمفتاحٍ تُجلب المعرّفات بالرقم، وبلا مفتاح تُدخل بيدك. */
const lookupEnabled = computed(() => props.gateway.lookup_enabled);

const connectedAt = ref(props.settings.wa_connected_at);

const submit = () => {
    form.post('/admin/settings/whatsapp', {
        preserveScroll: true,
        onSuccess: () => {
            // التوكن المكتوب صار محفوظاً: يُمسح من الشاشة وتبقى بصمته.
            const typed = current.value?.access_token;

            if (typed) {
                savedToken.value[form.wa_driver] = `${typed.slice(0, 4)}••••••${typed.slice(-4)}`;
                current.value.access_token = '';
            }

            // البوابة تبدّلت، فالرمز المعروض يخصّ بوابةً أخرى.
            stopPolling();
            state.value = 'idle';
            result.value = null;
            fetchedFor.value = null;
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

// ==== اختبار الاتصال: سؤال البوابة عن جلستها دون إرسال شيء لأحد ====
interface StatusResult {
    ok: boolean;
    configured: boolean;
    connected?: boolean;
    phone?: string | null;
    message?: string | null;
}

const checking = ref(false);
const checkResult = ref<{ ok: boolean; message: string } | null>(null);

const testConnection = async () => {
    checking.value = true;
    checkResult.value = null;

    try {
        const res: StatusResult = await call('/admin/settings/whatsapp/status');

        if (!res.configured) {
            checkResult.value = { ok: false, message: res.message ?? 'احفظ المعرّفات أولاً.' };
        } else if (res.ok && res.connected) {
            checkResult.value = { ok: true, message: `الاتصال سليم والحساب مرتبط${res.phone ? ` (${res.phone})` : ''}.` };
        } else if (res.ok) {
            checkResult.value = { ok: false, message: 'البوابة تستجيب لكن الحساب غير مرتبط — اربط الجهاز بالأسفل.' };
        } else {
            checkResult.value = { ok: false, message: `فشل الاتصال بالبوابة: ${res.message ?? 'سبب غير معروف'}.` };
        }
    } catch {
        checkResult.value = { ok: false, message: 'تعذّر الاتصال بالخادم.' };
    } finally {
        checking.value = false;
    }
};

// ==== الربط: استعلام متكرر حتى يكتمل المسح ====
type ConnectState =
    | 'idle'
    | 'qr'
    | 'pending'
    | 'problem'
    | 'connected'
    | 'unauthorized'
    | 'missing'
    | 'missing_number'
    | 'not_registered'
    | 'error';

interface ConnectResult {
    state: Exclude<ConnectState, 'idle'>;
    qr?: string;
    phone?: string | null;
    avatar_url?: string | null;
    platform?: string | null;
    subscription?: { days_remaining?: number; plan?: string } | null;
    mismatch?: boolean;
    expected_number?: string | null;
    /** اسم العميل الذي جُلبت معرّفاته من المنصّة بالرقم، إن جرى الجلب الآن. */
    fetched_for?: string | null;
    notice?: string | null;
    message?: string;
    retrying?: boolean;
}

const linking = ref(false);
const state = ref<ConnectState>('idle');
const fetchedFor = ref<string | null>(null);
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
const poll = async (first = false) => {
    try {
        // فحص الرقم في أوّل نبضة وحدها: لكل فحصٍ حصّةٌ عند البوابة.
        const res: ConnectResult = await call(`/admin/settings/whatsapp/connect${first ? '?check=1' : ''}`);
        result.value = res;
        state.value = res.state;
        if (res.fetched_for) fetchedFor.value = res.fetched_for;

        if (res.state === 'connected') {
            connectedAt.value = new Date().toLocaleString('ar');
            form.wa_enabled = true;
            if (res.phone && !form.wa_number) form.wa_number = res.phone;
            stopPolling();
            return;
        }

        // بياناتٌ خاطئة أو ناقصة: لا فائدة من التكرار حتى تُصحَّح.
        if (['unauthorized', 'missing', 'missing_number', 'not_registered'].includes(res.state)) {
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
    fetchedFor.value = null;
    result.value = { state: 'pending', message: 'جارٍ الاستعلام عن حالة الرقم…' };
    poll(true);
};

onBeforeUnmount(stopPolling);

/** بوابةٌ تربط بالرقم يُتحقّق منها، وغيرها يُطلب رمزها لا أكثر. */
const linkButtonLabel = computed(() => {
    if (linking.value) return byPhone.value ? 'جارٍ التحقق…' : 'جارٍ التحديث…';
    if (!byPhone.value) return 'تحديث QR';

    return state.value === 'idle' ? 'تحقق واعرض QR' : 'أعد التحقق';
});

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
        case 'missing_number':
            return 'الرقم غير مُدخل';
        case 'not_registered':
            return 'رقم خارج واتساب';
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

</script>

<template>
    <Head title="إعدادات الواتساب" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <form @submit.prevent="submit" class="min-h-full space-y-5 bg-slate-100 p-5">
            <SettingsTabs />

            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h1 class="text-2xl font-extrabold text-slate-900">إعدادات الواتساب</h1>
                    <p class="mt-1 text-sm font-medium text-slate-600">ربط بوابة c-wts.com للتراسل الآلي مع العملاء</p>
                </div>
                <button type="submit" :disabled="form.processing" class="inline-flex items-center gap-1.5 rounded-md bg-blue-600 px-5 py-2 text-sm font-bold text-white shadow-sm transition hover:bg-blue-700 disabled:opacity-60">
                    <Save class="h-4 w-4" /> حفظ التغييرات
                </button>
            </div>

            <!-- إعدادات البوابة: ما يُكتب في .env -->
            <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                <div class="mb-1 flex items-center justify-between">
                    <div class="flex items-center gap-2">
                        <SlidersHorizontal class="h-5 w-5 text-emerald-600" />
                        <h2 class="text-lg font-bold text-slate-800">إعدادات بوابة الواتساب</h2>
                    </div>
                    <button type="button" role="switch" :aria-checked="form.wa_enabled" @click="form.wa_enabled = !form.wa_enabled"
                        :class="['relative inline-flex h-6 w-11 items-center rounded-full transition', form.wa_enabled ? 'brand-gradient' : 'bg-slate-300']">
                        <span :class="['inline-block h-4 w-4 transform rounded-full bg-white transition', form.wa_enabled ? '-translate-x-1' : '-translate-x-6']"></span>
                    </button>
                </div>

                <p class="mb-4 text-sm font-medium text-slate-600">
                    تُحفظ هذه القيم في ملف <code class="rounded bg-slate-100 px-1 text-xs" dir="ltr">.env</code> مباشرةً ولا تُخزَّن في قاعدة البيانات.
                </p>

                <div v-if="checkResult" :class="['mb-4 flex items-start gap-2 rounded-2xl border px-4 py-3 text-sm font-bold', checkResult.ok ? 'border-emerald-200 bg-emerald-50 text-emerald-800' : 'border-amber-200 bg-amber-50 text-amber-800']">
                    <Plug class="mt-0.5 h-5 w-5 shrink-0" />
                    <span>{{ checkResult.message }}</span>
                </div>

                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label class="mb-1 block text-sm font-bold text-slate-700">البوابة المستخدمة</label>
                        <select v-model="form.wa_driver" class="w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm focus:border-emerald-400 focus:outline-none focus:ring-2 focus:ring-emerald-100">
                            <option v-for="option in props.gateway.drivers" :key="option.key" :value="option.key">{{ option.label }}</option>
                        </select>
                        <p v-if="form.errors.wa_driver" class="mt-1 text-xs text-red-500">{{ form.errors.wa_driver }}</p>
                    </div>

                    <div>
                        <label class="mb-1 block text-sm font-bold text-slate-700">مفتاح الدولة</label>
                        <input v-model="form.wa_country_code" type="text" dir="ltr" placeholder="966" class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm focus:border-emerald-400 focus:outline-none focus:ring-2 focus:ring-emerald-100" />
                        <p v-if="form.errors.wa_country_code" class="mt-1 text-xs text-red-500">{{ form.errors.wa_country_code }}</p>
                        <p class="mt-1 text-[11px] font-medium leading-6 text-slate-500">
                            يُضاف تلقائياً للأرقام المحلية قبل الإرسال، لأن الواتساب لا يقبل إلا الصيغة الدولية:
                            <span dir="ltr">0512345678</span> ← <span dir="ltr">{{ form.wa_country_code || '966' }}512345678</span>.
                            غيّره لأي دولة (<span dir="ltr">20</span> مصر، <span dir="ltr">971</span> الإمارات، <span dir="ltr">965</span> الكويت).
                            الأرقام التي تحمل مفتاح دولة أصلاً تُرسَل كما هي، فلا يمنع المراسلة خارج هذه الدولة.
                            اتركه فارغاً فقط إن كانت كل أرقامك مخزّنة بالصيغة الدولية.
                        </p>
                    </div>
                </div>

                <div class="my-4 border-t border-slate-100 pt-3 text-xs font-bold text-slate-500">بيانات {{ driverLabel }}</div>

                <div v-if="current" class="grid gap-4 sm:grid-cols-3">
                    <div>
                        <label class="mb-1 block text-sm font-bold text-slate-700">رابط الـ API</label>
                        <input v-model="current.base_url" type="text" dir="ltr" class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm focus:border-emerald-400 focus:outline-none focus:ring-2 focus:ring-emerald-100" />
                        <p v-if="fieldError('base_url')" class="mt-1 text-xs text-red-500">{{ fieldError('base_url') }}</p>
                    </div>

                    <div>
                        <label class="mb-1 block text-sm font-bold text-slate-700">Instance ID</label>
                        <input v-model="current.instance_id" type="text" dir="ltr" autocomplete="off" class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm focus:border-emerald-400 focus:outline-none focus:ring-2 focus:ring-emerald-100" />
                        <p v-if="byPhone && lookupEnabled" class="mt-1 text-[11px] font-medium text-slate-500">يُجلب من المنصّة بالرقم عند الربط، ولا حاجة لكتابته.</p>
                        <p v-if="fieldError('instance_id')" class="mt-1 text-xs text-red-500">{{ fieldError('instance_id') }}</p>
                    </div>

                    <div>
                        <label class="mb-1 block text-sm font-bold text-slate-700">Access Token</label>
                        <input v-model="current.access_token" type="password" dir="ltr" autocomplete="new-password" :placeholder="savedToken[form.wa_driver] || 'أدخل التوكن'" class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm focus:border-emerald-400 focus:outline-none focus:ring-2 focus:ring-emerald-100" />
                        <p v-if="fieldError('access_token')" class="mt-1 text-xs text-red-500">{{ fieldError('access_token') }}</p>
                        <p class="mt-1 flex items-start gap-1 text-[11px] font-medium text-slate-500">
                            <template v-if="savedToken[form.wa_driver]">
                                <Lock class="mt-0.5 h-3 w-3 shrink-0" />
                                <span>محفوظ في <span dir="ltr">.env</span> ولا يُعرض هنا. اتركه فارغاً للإبقاء عليه، أو اكتب توكناً جديداً لاستبداله.</span>
                            </template>
                            <span v-else>لا يوجد توكن محفوظ لهذه البوابة بعد.</span>
                        </p>
                    </div>
                </div>

                <div class="mt-4 flex flex-wrap items-center gap-2">
                    <button type="submit" :disabled="form.processing" class="inline-flex items-center gap-1.5 rounded-xl brand-gradient px-4 py-2 text-sm font-bold text-white shadow-md transition hover:brightness-110 disabled:opacity-60">
                        <Save class="h-4 w-4" /> {{ form.processing ? 'جارٍ الحفظ…' : 'حفظ في .env' }}
                    </button>

                    <button type="button" @click="testConnection" :disabled="checking" class="inline-flex items-center gap-1.5 rounded-xl border border-slate-200 bg-white px-4 py-2 text-sm font-bold text-slate-700 shadow-sm transition hover:bg-slate-50 disabled:opacity-60">
                        <Plug class="h-4 w-4" /> {{ checking ? 'جارٍ الاختبار…' : 'اختبار الاتصال' }}
                    </button>

                    <span class="text-[11px] font-medium text-slate-400 ltr:ml-auto rtl:mr-auto" dir="ltr">{{ props.gateway.env_path }}</span>
                </div>

                <p v-if="!props.gateway.env_writable" class="mt-2 text-xs font-bold text-amber-700">ملف .env غير قابل للكتابة على الخادم — لن تُحفظ المعرّفات.</p>
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
                            {{ linkButtonLabel }}
                        </button>
                    </div>
                </div>

                <p class="mb-4 text-sm font-medium text-slate-600">
                    <template v-if="byPhone && lookupEnabled">
                        اضغط «تحقق واعرض QR»: تُجلب معرّفات البوابة من المنصّة بالرقم المحفوظ، ويُتحقّق أنه رقم واتساب، ثم
                    </template>
                    <template v-else-if="byPhone">
                        اضغط «تحقق واعرض QR»: يُتحقّق أن الرقم المحفوظ رقم واتساب، ثم
                    </template>
                    <template v-else>
                        هذه البوابة تقترن بأي جوال يمسح الرمز، فلا رقم يُسأل عنه. اضغط «تحديث QR»:
                    </template>
                    يُستعلَم عن حالة الجلسة، فإن كانت مرتبطة ظهرت بياناتها، وإلا ظهر رمز QR ويتجدّد تلقائياً — وبمجرد مسحه من الجوال يكتمل الربط ويُفعَّل التكامل دون أي خطوة إضافية.
                </p>

                <!--
                    البوابة التي تربط بالرقم تسأل عنه أولاً، ولا يُعرض رمزٌ قبل معرفته.
                    وغيرها يقترن بأي جوال يمسح الرمز، فلا رقم يُسأل عنه أصلاً.
                -->
                <div v-if="byPhone" class="mb-4 max-w-md">
                    <label class="mb-1 block text-sm font-bold text-slate-700">رقم الواتساب المراد ربطه</label>
                    <input v-model="form.wa_number" type="text" dir="ltr" placeholder="0512345678" class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm focus:border-emerald-400 focus:outline-none focus:ring-2 focus:ring-emerald-100" />
                    <p v-if="form.errors.wa_number" class="mt-1 text-xs text-red-500">{{ form.errors.wa_number }}</p>
                    <p class="mt-1 text-[11px] font-medium text-slate-500">
                        احفظ الرقم أولاً من زرّ «حفظ في .env» بالأعلى — البوابة تقرأ الرقم من الخادم لا من الشاشة.
                    </p>
                </div>

                <!-- ما جلبته المنصّة بالرقم -->
                <div v-if="fetchedFor" class="mb-4 flex items-start gap-2 rounded-2xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-bold text-emerald-800">
                    <KeyRound class="mt-0.5 h-5 w-5 shrink-0" />
                    <span>تم جلب معرّفات البوابة من المنصّة للعميل: {{ fetchedFor }}.</span>
                </div>
                <div v-if="result?.notice" class="mb-4 flex items-start gap-2 rounded-2xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm font-bold text-amber-800">
                    <AlertTriangle class="mt-0.5 h-5 w-5 shrink-0" />
                    <span>{{ result.notice }}</span>
                </div>

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

                <div v-else-if="['unauthorized', 'missing', 'missing_number', 'not_registered', 'error'].includes(state)" class="flex items-start gap-2 rounded-2xl border border-red-200 bg-red-50 px-4 py-4 text-sm font-bold text-red-700">
                    <XCircle class="mt-0.5 h-5 w-5 shrink-0" />
                    <span>{{ result?.message }}</span>
                </div>

                <div v-else class="flex flex-col items-center gap-2 rounded-2xl border-2 border-dashed border-slate-300 py-10 text-slate-400">
                    <QrCode class="h-10 w-10" />
                    <span class="text-sm font-bold">اضغط «استعلام وربط» لعرض رمز الاتصال</span>
                </div>
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
