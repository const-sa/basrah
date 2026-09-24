<script setup lang="ts">
/**
 * بطاقة رقم قسمٍ واحد: بياناته، وربطه بالرمز، واشتراكه، وآخر رسائله.
 *
 * بلا account هي بطاقة إضافة رقمٍ جديد، ولا ربط فيها قبل الحفظ.
 */
import { jsonHeaders } from '@/lib/csrf';
import { Link, router, useForm } from '@inertiajs/vue3';
import {
    AlertTriangle,
    CheckCircle2,
    History,
    Lock,
    Plug,
    RefreshCw,
    Save,
    Send,
    Smartphone,
    Trash2,
    XCircle,
} from 'lucide-vue-next';
import { computed, onBeforeUnmount, ref, watch } from 'vue';

export interface AccountMessage {
    id: number;
    to_number: string;
    purpose_label: string;
    status: string;
    error: string | null;
    created_at: string;
}

export interface Account {
    id: number;
    name: string;
    section: string;
    unit_id: number | null;
    target_label: string;
    driver: string;
    base_url: string;
    instance_id: string;
    saved_token: string;
    wa_number: string | null;
    connected_at: string | null;
    is_active: boolean;
    linked: boolean;
    stats: { sent: number; failed: number; queued: number; conversations: number; total: number };
    recent: AccountMessage[];
}

export interface Option {
    key: string;
    label: string;
}

export interface UnitOption {
    id: number;
    name: string;
    section: string;
}

const props = defineProps<{
    account?: Account | null;
    sections: Option[];
    units: UnitOption[];
    drivers: Option[];
    lookupEnabled: boolean;
}>();

const emit = defineEmits<{ (e: 'saved'): void; (e: 'cancel'): void }>();

const isNew = computed(() => !props.account);

const form = useForm({
    name: props.account?.name ?? '',
    section: props.account?.section ?? props.sections[0]?.key ?? 'halls',
    unit_id: props.account?.unit_id ?? (null as number | null),
    driver: props.account?.driver ?? 'cwts',
    base_url: props.account?.base_url ?? '',
    instance_id: props.account?.instance_id ?? '',
    access_token: '',
    wa_number: props.account?.wa_number ?? '',
    is_active: props.account?.is_active ?? true,
});

const unitsOfSection = computed(() => props.units.filter((u) => u.section === form.section));

// وحدةٌ من قسمٍ آخر لا تبقى مختارة بعد تبديل القسم.
watch(
    () => form.section,
    () => {
        if (form.unit_id && !unitsOfSection.value.some((u) => u.id === form.unit_id)) form.unit_id = null;
    },
);

// الخادم يمحو المعرّفات حين يتبدّل الرقم ويملؤها عند الجلب، فتتبعه الشاشة
// كي لا تعيد الحفظةُ التالية معرّفاً قديماً.
watch(
    () => props.account?.instance_id,
    (value) => {
        form.instance_id = value ?? '';
        form.defaults('instance_id', value ?? '');
    },
);

/** بالرقم تُجلب المعرّفات، فلا تُعرض حقولها إلا لمن يطلبها. */
const showAdvanced = ref(isNew.value ? !props.lookupEnabled : !props.lookupEnabled && !props.account?.linked);

const submit = () => {
    const options = {
        preserveScroll: true,
        onSuccess: () => {
            form.access_token = '';
            emit('saved');
        },
    };

    if (isNew.value) form.post('/admin/settings/whatsapp/accounts', options);
    else form.put(`/admin/settings/whatsapp/accounts/${props.account!.id}`, options);
};

const remove = () => {
    if (!props.account) return;
    if (!confirm(`حذف رقم «${props.account.name}»؟ رسائل قسمه تعود إلى البوابة العامة.`)) return;
    router.delete(`/admin/settings/whatsapp/accounts/${props.account.id}`, { preserveScroll: true });
};

// ==== نداءات الواجهة ====
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

const base = computed(() => `/admin/settings/whatsapp/accounts/${props.account?.id}`);

// ==== الحالة والاشتراك ====
const checking = ref(false);
const statusInfo = ref<{ ok: boolean; message: string; days?: number | null } | null>(null);

const checkStatus = async () => {
    checking.value = true;
    statusInfo.value = null;
    try {
        const res = await call(`${base.value}/status`);
        const days = res.subscription?.days_remaining ?? null;
        if (!res.configured) statusInfo.value = { ok: false, message: res.message };
        else if (res.ok && res.connected) statusInfo.value = { ok: true, message: `متصل${res.phone ? ` (${res.phone})` : ''}`, days };
        else if (res.ok) statusInfo.value = { ok: false, message: 'البوابة تستجيب لكن الجوال غير مرتبط — اضغط «ربط».', days };
        else statusInfo.value = { ok: false, message: `فشل الاتصال: ${res.message ?? 'سبب غير معروف'}` };
    } catch {
        statusInfo.value = { ok: false, message: 'تعذّر الاتصال بالخادم.' };
    } finally {
        checking.value = false;
    }
};

// ==== الربط ====
interface ConnectResult {
    state: string;
    qr?: string;
    phone?: string | null;
    subscription?: { days_remaining?: number } | null;
    fetched_for?: string | null;
    connected_at?: string;
    mismatch?: boolean;
    message?: string;
}

const linking = ref(false);
const connect = ref<ConnectResult | null>(null);
const connectedAt = ref(props.account?.connected_at ?? null);
let timer: ReturnType<typeof setTimeout> | null = null;

const stopLinking = () => {
    if (timer) clearTimeout(timer);
    timer = null;
    linking.value = false;
};

const poll = async (first = false) => {
    try {
        const res: ConnectResult = await call(`${base.value}/connect${first ? '?check=1' : ''}`);
        connect.value = res;

        if (res.state === 'connected') {
            connectedAt.value = res.connected_at ?? connectedAt.value;
            stopLinking();
            // المعرّفات المجلوبة وأثر الربط صارا في الخادم.
            router.reload({ only: ['accounts'] });
            return;
        }

        if (['unauthorized', 'missing', 'missing_number', 'not_registered'].includes(res.state)) {
            stopLinking();
            return;
        }

        timer = setTimeout(poll, 3000);
    } catch {
        connect.value = { state: 'error', message: 'تعذّر الاتصال بالخادم — إعادة المحاولة…' };
        timer = setTimeout(poll, 5000);
    }
};

const startLinking = () => {
    stopLinking();
    linking.value = true;
    connect.value = { state: 'pending', message: 'جارٍ الاستعلام عن حالة الرقم…' };
    poll(true);
};

onBeforeUnmount(stopLinking);

// ==== إرسال تجريبي ====
const testNumber = ref('');
const testing = ref(false);
const testResult = ref<{ ok: boolean; message?: string } | null>(null);

const sendTest = async () => {
    if (!testNumber.value.trim()) return;
    testing.value = true;
    testResult.value = null;
    try {
        testResult.value = await call(`${base.value}/test`, { number: testNumber.value });
    } catch {
        testResult.value = { ok: false, message: 'تعذّر الاتصال بالخادم.' };
    } finally {
        testing.value = false;
    }
};

const badge = computed(() => {
    if (!props.account) return null;
    if (!props.account.is_active) return { label: 'موقوف', tone: 'bg-slate-200 text-slate-600' };
    if (props.account.linked && connectedAt.value) return { label: 'مربوط', tone: 'bg-emerald-100 text-emerald-700' };
    if (props.account.linked) return { label: 'معرّفات محفوظة', tone: 'bg-sky-100 text-sky-700' };
    return { label: 'غير مربوط', tone: 'bg-amber-100 text-amber-700' };
});

const statusLabel = (s: string) => (s === 'sent' ? 'أُرسلت' : s === 'queued' ? 'بالطابور' : 'فشلت');
const statusTone = (s: string) =>
    s === 'sent' ? 'bg-emerald-100 text-emerald-700' : s === 'queued' ? 'bg-amber-100 text-amber-700' : 'bg-red-100 text-red-700';

const err = (key: string) => (form.errors as Record<string, string | undefined>)[key];
const input =
    'w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm focus:border-emerald-400 focus:outline-none focus:ring-2 focus:ring-emerald-100';
</script>

<template>
    <div class="rounded-2xl border border-slate-200 bg-white shadow-sm">
        <!-- الرأس -->
        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-100 px-5 py-4">
            <div class="flex items-center gap-3">
                <span class="flex h-11 w-11 items-center justify-center rounded-full bg-emerald-600 text-white shadow"><Smartphone class="h-5 w-5" /></span>
                <div>
                    <h2 class="text-lg font-extrabold text-slate-800">{{ isNew ? 'رقم قسمٍ جديد' : account!.name }}</h2>
                    <p v-if="account" class="text-xs font-bold text-slate-500">
                        {{ account.target_label }}
                        <span v-if="account.wa_number" dir="ltr" class="ms-2 text-slate-700">+{{ account.wa_number }}</span>
                    </p>
                </div>
            </div>
            <div class="flex items-center gap-2">
                <span v-if="badge" :class="['rounded-full px-3 py-1 text-xs font-extrabold', badge.tone]">{{ badge.label }}</span>
                <button type="button" role="switch" :aria-checked="form.is_active" title="تفعيل الإرسال من هذا الرقم" @click="form.is_active = !form.is_active"
                    :class="['relative inline-flex h-6 w-11 items-center rounded-full transition', form.is_active ? 'brand-gradient' : 'bg-slate-300']">
                    <span :class="['inline-block h-4 w-4 transform rounded-full bg-white transition', form.is_active ? '-translate-x-1' : '-translate-x-6']"></span>
                </button>
            </div>
        </div>

        <div class="grid gap-5 p-5 lg:grid-cols-[1.2fr_1fr]">
            <!-- البيانات -->
            <form @submit.prevent="submit" class="space-y-4">
                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="sm:col-span-2">
                        <label class="mb-1 block text-sm font-bold text-slate-700">الاسم المُرسِل</label>
                        <input v-model="form.name" type="text" placeholder="مثال: قاعة ديوان المسره" :class="input" />
                        <p class="mt-1 text-[11px] font-medium text-slate-500">تُوقَّع به رسائل هذا الرقم مكان <code dir="ltr">{business_name}</code> في القوالب.</p>
                        <p v-if="err('name')" class="mt-1 text-xs text-red-500">{{ err('name') }}</p>
                    </div>

                    <div>
                        <label class="mb-1 block text-sm font-bold text-slate-700">القسم</label>
                        <select v-model="form.section" :class="input">
                            <option v-for="s in sections" :key="s.key" :value="s.key">{{ s.label }}</option>
                        </select>
                        <p v-if="err('section')" class="mt-1 text-xs text-red-500">{{ err('section') }}</p>
                    </div>

                    <div>
                        <label class="mb-1 block text-sm font-bold text-slate-700">الوحدة</label>
                        <select v-model="form.unit_id" :class="input">
                            <option :value="null">القسم كله</option>
                            <option v-for="u in unitsOfSection" :key="u.id" :value="u.id">{{ u.name }}</option>
                        </select>
                        <p v-if="err('unit_id')" class="mt-1 text-xs text-red-500">{{ err('unit_id') }}</p>
                    </div>

                    <div class="sm:col-span-2">
                        <label class="mb-1 block text-sm font-bold text-slate-700">رقم الواتساب</label>
                        <input v-model="form.wa_number" type="text" dir="ltr" placeholder="0512345678" :class="input" />
                        <p class="mt-1 text-[11px] font-medium text-slate-500">
                            الرقم صاحب الاشتراك في المنصّة<template v-if="lookupEnabled">، ومنه تُجلب معرّفات البوابة تلقائياً عند الربط</template>.
                        </p>
                        <p v-if="err('wa_number')" class="mt-1 text-xs text-red-500">{{ err('wa_number') }}</p>
                    </div>
                </div>

                <button type="button" @click="showAdvanced = !showAdvanced" class="text-xs font-bold text-blue-600 hover:underline">
                    {{ showAdvanced ? 'إخفاء بيانات البوابة' : 'بيانات البوابة (يدوياً)' }}
                </button>

                <div v-if="showAdvanced" class="grid gap-4 rounded-xl bg-slate-50 p-4 sm:grid-cols-2">
                    <div>
                        <label class="mb-1 block text-xs font-bold text-slate-700">البوابة</label>
                        <select v-model="form.driver" :class="input">
                            <option v-for="d in drivers" :key="d.key" :value="d.key">{{ d.label }}</option>
                        </select>
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-bold text-slate-700">رابط الـ API (اختياري)</label>
                        <input v-model="form.base_url" type="text" dir="ltr" placeholder="كالبوابة العامة" :class="input" />
                        <p v-if="err('base_url')" class="mt-1 text-xs text-red-500">{{ err('base_url') }}</p>
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-bold text-slate-700">Instance ID</label>
                        <input v-model="form.instance_id" type="text" dir="ltr" autocomplete="off" :class="input" />
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-bold text-slate-700">Access Token</label>
                        <input v-model="form.access_token" type="password" dir="ltr" autocomplete="new-password" :placeholder="account?.saved_token || 'أدخل التوكن'" :class="input" />
                        <p class="mt-1 flex items-start gap-1 text-[11px] font-medium text-slate-500">
                            <Lock class="mt-0.5 h-3 w-3 shrink-0" />
                            <span>{{ account?.saved_token ? 'محفوظ مشفّراً. اتركه فارغاً للإبقاء عليه.' : 'يُحفظ مشفّراً في قاعدة البيانات.' }}</span>
                        </p>
                    </div>
                </div>

                <div class="flex flex-wrap items-center gap-2">
                    <button type="submit" :disabled="form.processing" class="inline-flex items-center gap-1.5 rounded-xl brand-gradient px-4 py-2 text-sm font-bold text-white shadow-md transition hover:brightness-110 disabled:opacity-60">
                        <Save class="h-4 w-4" /> {{ form.processing ? 'جارٍ الحفظ…' : isNew ? 'إضافة الرقم' : 'حفظ' }}
                    </button>
                    <button v-if="isNew" type="button" @click="emit('cancel')" class="rounded-xl border border-slate-200 bg-white px-4 py-2 text-sm font-bold text-slate-600 hover:bg-slate-50">إلغاء</button>
                    <template v-else>
                        <button type="button" @click="startLinking" :disabled="linking" class="inline-flex items-center gap-1.5 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-2 text-sm font-bold text-emerald-700 transition hover:bg-emerald-100 disabled:opacity-60">
                            <RefreshCw :class="['h-4 w-4', linking && 'animate-spin']" /> {{ linking ? 'جارٍ الربط…' : 'ربط / QR' }}
                        </button>
                        <button v-if="linking" type="button" @click="stopLinking" class="rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm font-bold text-slate-600 hover:bg-slate-50">إيقاف</button>
                        <button type="button" @click="checkStatus" :disabled="checking" class="inline-flex items-center gap-1.5 rounded-xl border border-slate-200 bg-white px-4 py-2 text-sm font-bold text-slate-700 hover:bg-slate-50 disabled:opacity-60">
                            <Plug class="h-4 w-4" /> {{ checking ? 'جارٍ الفحص…' : 'الحالة والاشتراك' }}
                        </button>
                        <button type="button" @click="remove" class="inline-flex items-center gap-1.5 rounded-xl px-3 py-2 text-sm font-bold text-red-600 hover:bg-red-50 ms-auto">
                            <Trash2 class="h-4 w-4" /> حذف
                        </button>
                    </template>
                </div>
                <p v-if="!isNew" class="text-[11px] font-medium text-slate-500">احفظ الرقم قبل الربط — البوابة تقرأ الرقم المحفوظ لا ما على الشاشة.</p>

                <!-- الحالة -->
                <div v-if="statusInfo" :class="['flex flex-wrap items-center gap-2 rounded-xl border px-4 py-3 text-sm font-bold', statusInfo.ok ? 'border-emerald-200 bg-emerald-50 text-emerald-800' : 'border-amber-200 bg-amber-50 text-amber-800']">
                    <Plug class="h-4 w-4 shrink-0" />
                    <span>{{ statusInfo.message }}</span>
                    <span v-if="statusInfo.days !== null && statusInfo.days !== undefined" class="ms-auto rounded-full bg-white px-3 py-0.5 text-xs">متبقٍ من الاشتراك: {{ statusInfo.days }} يوم</span>
                </div>

                <!-- الربط -->
                <template v-if="connect">
                    <div v-if="connect.state === 'connected'" class="space-y-2 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-bold text-emerald-800">
                        <p class="flex items-center gap-1.5"><CheckCircle2 class="h-4 w-4" /> {{ connect.message }}</p>
                        <p class="flex flex-wrap gap-x-3 text-xs text-slate-600">
                            <span v-if="connect.phone" dir="ltr">+{{ connect.phone }}</span>
                            <span v-if="connect.subscription?.days_remaining !== undefined">متبقٍ من الاشتراك: {{ connect.subscription.days_remaining }} يوم</span>
                            <span v-if="connect.fetched_for">العميل في المنصّة: {{ connect.fetched_for }}</span>
                        </p>
                        <p v-if="connect.mismatch" class="flex items-start gap-1.5 text-amber-700">
                            <AlertTriangle class="mt-0.5 h-4 w-4 shrink-0" /> الجوال الذي مسح الرمز رقمه يخالف الرقم المحفوظ لهذا القسم.
                        </p>
                    </div>
                    <div v-else-if="connect.state === 'qr' && connect.qr" class="grid gap-4 sm:grid-cols-[auto_1fr] sm:items-center">
                        <div class="mx-auto rounded-2xl border border-slate-200 bg-white p-2 shadow-sm"><img :src="connect.qr" alt="QR" class="h-48 w-48" /></div>
                        <p class="rounded-xl bg-slate-50 p-3 text-sm font-medium leading-7 text-slate-700">{{ connect.message }} يتجدّد الرمز تلقائياً، ويكتمل الربط فور المسح.</p>
                    </div>
                    <div v-else-if="connect.state === 'pending'" class="flex items-center gap-2 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm font-bold text-amber-800">
                        <RefreshCw class="h-4 w-4 animate-spin" /> {{ connect.message }}
                    </div>
                    <div v-else class="flex items-start gap-2 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm font-bold text-red-700">
                        <XCircle class="mt-0.5 h-4 w-4 shrink-0" /> {{ connect.message }}
                    </div>
                </template>
            </form>

            <!-- الاستهلاك والسجل -->
            <div v-if="account" class="space-y-4">
                <div>
                    <p class="mb-2 text-xs font-extrabold text-slate-500">هذا الشهر</p>
                    <div class="grid grid-cols-4 gap-2 text-center">
                        <div class="rounded-xl bg-emerald-50 py-2"><p class="text-lg font-extrabold text-emerald-700">{{ account.stats.sent }}</p><p class="text-[11px] font-bold text-emerald-700">أُرسلت</p></div>
                        <div class="rounded-xl bg-red-50 py-2"><p class="text-lg font-extrabold text-red-700">{{ account.stats.failed }}</p><p class="text-[11px] font-bold text-red-700">فشلت</p></div>
                        <div class="rounded-xl bg-amber-50 py-2"><p class="text-lg font-extrabold text-amber-700">{{ account.stats.queued }}</p><p class="text-[11px] font-bold text-amber-700">بالطابور</p></div>
                        <div class="rounded-xl bg-slate-100 py-2"><p class="text-lg font-extrabold text-slate-700">{{ account.stats.conversations }}</p><p class="text-[11px] font-bold text-slate-600">محادثات</p></div>
                    </div>
                </div>

                <div>
                    <div class="mb-2 flex items-center justify-between">
                        <p class="flex items-center gap-1 text-xs font-extrabold text-slate-500"><History class="h-3.5 w-3.5" /> آخر الرسائل</p>
                        <Link :href="`/admin/whatsapp-log?account=${account.id}`" class="text-xs font-bold text-blue-600 hover:underline">السجل كاملاً ({{ account.stats.total }})</Link>
                    </div>
                    <ul v-if="account.recent.length" class="divide-y divide-slate-100 rounded-xl border border-slate-100">
                        <li v-for="m in account.recent" :key="m.id" class="flex items-center gap-2 px-3 py-2 text-xs">
                            <span :class="['rounded-md px-1.5 py-0.5 text-[10px] font-bold', statusTone(m.status)]" :title="m.error ?? ''">{{ statusLabel(m.status) }}</span>
                            <span class="font-bold text-slate-700">{{ m.purpose_label }}</span>
                            <span dir="ltr" class="text-slate-500">{{ m.to_number }}</span>
                            <span dir="ltr" class="ms-auto text-slate-400">{{ m.created_at }}</span>
                        </li>
                    </ul>
                    <p v-else class="rounded-xl border border-dashed border-slate-200 py-4 text-center text-xs font-bold text-slate-400">لم يُرسَل من هذا الرقم شيء بعد</p>
                </div>

                <div>
                    <p class="mb-2 text-xs font-extrabold text-slate-500">رسالة تجريبية من هذا الرقم</p>
                    <div class="flex gap-2">
                        <input v-model="testNumber" type="text" dir="ltr" placeholder="05xxxxxxxx" :class="input" />
                        <button type="button" @click="sendTest" :disabled="testing || !testNumber.trim()" class="inline-flex shrink-0 items-center gap-1.5 rounded-xl brand-gradient px-3 py-2 text-sm font-bold text-white disabled:opacity-60">
                            <Send class="h-4 w-4" /> {{ testing ? '…' : 'إرسال' }}
                        </button>
                    </div>
                    <p v-if="testResult" :class="['mt-2 text-xs font-bold', testResult.ok ? 'text-emerald-700' : 'text-red-600']">{{ testResult.message }}</p>
                </div>
            </div>
        </div>
    </div>
</template>
