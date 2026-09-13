<script setup lang="ts">
/**
 * إضافة عميل سريعة من داخل نموذج الحجز أو شاشة الفواتير.
 *
 * الموظف على الهاتف مع النزيل: مغادرة النموذج إلى شاشة العملاء تُفقده ما عبّأه،
 * فتُفتح نافذة تحمل حقول شاشة العملاء نفسها — الاسم والجوال والمدينة والبريد
 * والهوية والبيانات الضريبية — ويعود العميل عبر JSON ليُحدَّد فورًا، فلا يعود
 * الموظف إلى شاشة العملاء ليستكمل ما كان بين يديه.
 * والنشاط لا يُختار هنا: العميل يُقيَّد في سجل الشاشة التي أُضيف منها.
 */
import { usePermissions } from '@/composables/usePermissions';
import { jsonHeaders } from '@/lib/csrf';
import { type ClientTypeKey } from '@/types';
import { Loader2, UserPlus, X } from 'lucide-vue-next';
import { computed, nextTick, ref } from 'vue';

export interface QuickClient {
    id: number;
    name: string;
    mobile: string | null;
}

const emit = defineEmits<{ created: [client: QuickClient] }>();

/** نشاط الشاشة: العميل يُقيَّد في سجلّه، وبه يُختار قالب الترحيب. */
const props = withDefaults(
    defineProps<{ type?: ClientTypeKey | null }>(),
    { type: undefined },
);

const { canActivity } = usePermissions();

// The client is filed in this screen's register, so the key is that register's.
const ACTIVITY_OF: Record<string, 'halls' | 'chalets' | 'pools'> = { hall: 'halls', chalet: 'chalets', pool: 'pools' };
const mayCreate = computed(() => canActivity('clients', 'create', ACTIVITY_OF[props.type ?? 'pool'] ?? 'pools'));

/** اسم النشاط كما يظهر في شاشة العملاء — يُعرض ليعرف الموظف أين يُقيَّد العميل. */
const TYPE_LABEL: Record<string, string> = { hall: 'القاعات', chalet: 'الشاليهات', pool: 'المسابح' };
const typeLabel = computed(() => TYPE_LABEL[props.type ?? 'pool'] ?? TYPE_LABEL.pool);

const open = ref(false);
const saving = ref(false);
const errors = ref<Record<string, string>>({});
const message = ref('');
const nameInput = ref<HTMLInputElement | null>(null);

/** حقول العميل — هي نفسها حقول نموذج شاشة العملاء. */
const blank = () => ({
    name: '',
    mobile: '',
    email: '',
    city: '',
    national_id: '',
    is_taxable: false,
    tax_number: '',
    tax_address: '',
    is_active: true,
});

const form = ref(blank());

// قائمة المدن المفعّلة — تُجلب مرةً واحدة عند أول فتح، فلا تُثقل كل شاشة تحمل الزر.
const cities = ref<string[]>([]);
const citiesLoaded = ref(false);

const loadCities = async () => {
    if (citiesLoaded.value) return;

    try {
        const response = await fetch('/admin/clients/cities', { headers: { Accept: 'application/json' } });

        if (response.ok) {
            const body = (await response.json()) as { cities: string[] };
            cities.value = body.cities ?? [];
            citiesLoaded.value = true;
        }
    } catch {
        // تعذّر جلب المدن لا يمنع إضافة العميل — تبقى القائمة فارغة وحدها.
    }
};

const openModal = async () => {
    form.value = blank();
    errors.value = {};
    message.value = '';
    open.value = true;
    void loadCities();
    await nextTick();
    nameInput.value?.focus();
};

const close = () => {
    if (saving.value) return;
    open.value = false;
};

const failureMessage = (status: number) =>
    ({
        419: 'انتهت صلاحية الجلسة — حدّث الصفحة ثم أعد المحاولة.',
        403: 'لا صلاحية لديك لإضافة عميل.',
    })[status] ?? `تعذّرت إضافة العميل (${status}).`;

const submit = async () => {
    if (saving.value) return;

    saving.value = true;
    errors.value = {};
    message.value = '';

    const f = form.value;
    const trimmed = (value: string) => value.trim() || null;

    try {
        const response = await fetch('/admin/clients/quick', {
            method: 'POST',
            headers: jsonHeaders(),
            body: JSON.stringify({
                name: f.name.trim(),
                mobile: trimmed(f.mobile),
                email: trimmed(f.email),
                city: trimmed(f.city),
                national_id: trimmed(f.national_id),
                is_taxable: f.is_taxable,
                // غير الضريبي لا يرسل رقمًا ولا عنوانًا — الخادم يُفرغهما كذلك.
                tax_number: f.is_taxable ? trimmed(f.tax_number) : null,
                tax_address: f.is_taxable ? trimmed(f.tax_address) : null,
                is_active: f.is_active,
                type: props.type ?? null,
            }),
        });

        if (response.status === 422) {
            const body = await response.json();
            // Laravel يرسل مصفوفة رسائل لكل حقل — تكفي أولاها تحت الحقل.
            errors.value = Object.fromEntries(
                Object.entries(body.errors ?? {}).map(([key, list]) => [key, (list as string[])[0]]),
            );

            return;
        }

        if (!response.ok) {
            message.value = failureMessage(response.status);

            return;
        }

        const { client } = (await response.json()) as { client: QuickClient };
        emit('created', client);
        open.value = false;
    } catch {
        message.value = 'تعذّر الاتصال بالخادم — تحقّق من الشبكة ثم أعد المحاولة.';
    } finally {
        saving.value = false;
    }
};
</script>

<template>
    <button
        v-if="mayCreate"
        type="button"
        @click="openModal"
        title="إضافة عميل جديد"
        class="inline-flex items-center gap-1 rounded-lg border border-emerald-200 bg-emerald-50 px-2 py-0.5 text-xs font-bold text-emerald-700 transition hover:bg-emerald-100"
    >
        <UserPlus class="h-3.5 w-3.5" /> عميل جديد
    </button>

    <Teleport to="body">
        <div
            v-if="open"
            dir="rtl"
            class="fixed inset-0 z-[110] flex items-center justify-center bg-black/40 p-4"
            @click.self="close"
        >
            <div class="max-h-[90vh] w-full max-w-md overflow-y-auto rounded-2xl bg-white p-5 shadow-2xl">
                <div class="mb-4 flex items-center justify-between">
                    <div>
                        <h2 class="text-base font-extrabold text-slate-900">عميل جديد</h2>
                        <p class="text-[11px] font-bold text-slate-500">يُقيَّد في سجل عملاء {{ typeLabel }}</p>
                    </div>
                    <button type="button" @click="close" class="rounded-lg p-1 text-slate-400 transition hover:bg-slate-100 hover:text-slate-600">
                        <X class="h-4 w-4" />
                    </button>
                </div>

                <!-- النموذج داخل <form> منفصل ممنوع: نموذج الحجز يحيط بالمكوّن،
                     و@keyup.enter تكفي للحفظ بالمفتاح بلا تعشيش نماذج غير صالح. -->
                <div class="space-y-3">
                    <div>
                        <label class="mb-1 block text-sm font-bold text-slate-700">اسم العميل</label>
                        <input
                            ref="nameInput"
                            v-model="form.name"
                            type="text"
                            @keyup.enter="submit"
                            class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm focus:border-emerald-400 focus:outline-none focus:ring-2 focus:ring-emerald-100"
                        />
                        <p v-if="errors.name" class="mt-1 text-xs text-red-500">{{ errors.name }}</p>
                    </div>

                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="mb-1 block text-sm font-bold text-slate-700">الجوال</label>
                            <input
                                v-model="form.mobile"
                                type="text"
                                dir="ltr"
                                @keyup.enter="submit"
                                class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm focus:border-emerald-400 focus:outline-none focus:ring-2 focus:ring-emerald-100"
                            />
                            <p v-if="errors.mobile" class="mt-1 text-xs text-red-500">{{ errors.mobile }}</p>
                        </div>

                        <div>
                            <label class="mb-1 block text-sm font-bold text-slate-700">المدينة</label>
                            <select
                                v-model="form.city"
                                class="w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm focus:border-emerald-400 focus:outline-none focus:ring-2 focus:ring-emerald-100"
                            >
                                <option value="">— اختر المدينة —</option>
                                <option v-for="city in cities" :key="city" :value="city">{{ city }}</option>
                            </select>
                            <p v-if="errors.city" class="mt-1 text-xs text-red-500">{{ errors.city }}</p>
                        </div>
                    </div>

                    <div>
                        <label class="mb-1 block text-sm font-bold text-slate-700">البريد الإلكتروني</label>
                        <input
                            v-model="form.email"
                            type="email"
                            dir="ltr"
                            @keyup.enter="submit"
                            class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm focus:border-emerald-400 focus:outline-none focus:ring-2 focus:ring-emerald-100"
                        />
                        <p v-if="errors.email" class="mt-1 text-xs text-red-500">{{ errors.email }}</p>
                    </div>

                    <div>
                        <label class="mb-1 block text-sm font-bold text-slate-700">
                            رقم الهوية <span class="font-medium text-slate-400">(اختياري)</span>
                        </label>
                        <input
                            v-model="form.national_id"
                            type="text"
                            dir="ltr"
                            @keyup.enter="submit"
                            class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm focus:border-emerald-400 focus:outline-none focus:ring-2 focus:ring-emerald-100"
                        />
                        <p v-if="errors.national_id" class="mt-1 text-xs text-red-500">{{ errors.national_id }}</p>
                    </div>

                    <!-- سويتش العميل الضريبي -->
                    <div class="flex items-center justify-between rounded-xl border border-slate-200 bg-slate-50 px-3 py-2.5">
                        <span class="text-sm font-bold text-slate-700">عميل ضريبي</span>
                        <button
                            type="button"
                            role="switch"
                            :aria-checked="form.is_taxable"
                            @click="form.is_taxable = !form.is_taxable"
                            :class="[
                                'relative inline-flex h-6 w-11 items-center rounded-full transition',
                                form.is_taxable ? 'brand-gradient' : 'bg-slate-300',
                            ]"
                        >
                            <span
                                :class="[
                                    'inline-block h-4 w-4 transform rounded-full bg-white transition',
                                    form.is_taxable ? '-translate-x-1' : '-translate-x-6',
                                ]"
                            ></span>
                        </button>
                    </div>

                    <!-- بيانات الضريبة -->
                    <template v-if="form.is_taxable">
                        <div>
                            <label class="mb-1 block text-sm font-bold text-slate-700">الرقم الضريبي</label>
                            <input
                                v-model="form.tax_number"
                                type="text"
                                dir="ltr"
                                @keyup.enter="submit"
                                class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm focus:border-emerald-400 focus:outline-none focus:ring-2 focus:ring-emerald-100"
                            />
                            <p v-if="errors.tax_number" class="mt-1 text-xs text-red-500">{{ errors.tax_number }}</p>
                        </div>
                        <div>
                            <label class="mb-1 block text-sm font-bold text-slate-700">العنوان الضريبي</label>
                            <textarea
                                v-model="form.tax_address"
                                rows="2"
                                class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm focus:border-emerald-400 focus:outline-none focus:ring-2 focus:ring-emerald-100"
                            ></textarea>
                            <p v-if="errors.tax_address" class="mt-1 text-xs text-red-500">{{ errors.tax_address }}</p>
                        </div>
                    </template>

                    <label class="flex items-center gap-2 text-sm font-bold text-slate-700">
                        <input
                            v-model="form.is_active"
                            type="checkbox"
                            class="h-4 w-4 rounded border-slate-300 text-emerald-600 focus:ring-emerald-200"
                        />
                        عميل نشط
                    </label>

                    <p v-if="message" class="rounded-lg bg-red-50 px-3 py-2 text-xs font-bold text-red-600">{{ message }}</p>

                    <p class="text-[11px] font-medium text-slate-500">الملاحظات تُكتب من ملف العميل بعد حفظه.</p>

                    <div class="flex gap-2 pt-1">
                        <button
                            type="button"
                            @click="submit"
                            :disabled="saving || !form.name.trim()"
                            class="flex flex-1 items-center justify-center gap-1.5 rounded-xl bg-emerald-600 px-4 py-2.5 text-sm font-bold text-white transition hover:bg-emerald-700 disabled:opacity-50"
                        >
                            <Loader2 v-if="saving" class="h-4 w-4 animate-spin" />
                            حفظ وتحديد
                        </button>
                        <button
                            type="button"
                            @click="close"
                            :disabled="saving"
                            class="rounded-xl border border-slate-200 bg-white px-4 py-2.5 text-sm font-bold text-slate-600 transition hover:bg-slate-50 disabled:opacity-50"
                        >
                            إلغاء
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </Teleport>
</template>
