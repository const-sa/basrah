<script setup lang="ts">
/**
 * إضافة عميل سريعة من داخل نموذج الحجز أو شاشة الفواتير.
 *
 * الموظف على الهاتف مع النزيل: مغادرة النموذج إلى شاشة العملاء تُفقده ما عبّأه،
 * فتُفتح نافذة صغيرة بالاسم والجوال فقط، ويعود العميل عبر JSON ليُحدَّد فورًا.
 * بقية بيانات العميل تُستكمل لاحقًا من شاشة العملاء.
 */
import { usePermissions } from '@/composables/usePermissions';
import { jsonHeaders } from '@/lib/csrf';
import { type ClientTypeKey } from '@/types';
import { Loader2, UserPlus, X } from 'lucide-vue-next';
import { nextTick, ref } from 'vue';

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

const { can } = usePermissions();

const open = ref(false);
const saving = ref(false);
const name = ref('');
const mobile = ref('');
const errors = ref<Record<string, string>>({});
const message = ref('');
const nameInput = ref<HTMLInputElement | null>(null);

const openModal = async () => {
    name.value = '';
    mobile.value = '';
    errors.value = {};
    message.value = '';
    open.value = true;
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

    try {
        const response = await fetch('/admin/clients/quick', {
            method: 'POST',
            headers: jsonHeaders(),
            body: JSON.stringify({
                name: name.value.trim(),
                mobile: mobile.value.trim() || null,
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
        v-if="can('clients.create')"
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
            <div class="w-full max-w-sm rounded-2xl bg-white p-5 shadow-2xl">
                <div class="mb-4 flex items-center justify-between">
                    <h2 class="text-base font-extrabold text-slate-900">عميل جديد</h2>
                    <button type="button" @click="close" class="rounded-lg p-1 text-slate-400 transition hover:bg-slate-100 hover:text-slate-600">
                        <X class="h-4 w-4" />
                    </button>
                </div>

                <!-- النموذج داخل <form> منفصل ممنوع: نموذج الحجز يحيط بالمكوّن،
                     و@keyup.enter تكفي للحفظ بالمفتاح بلا تعشيش نماذج غير صالح. -->
                <div class="space-y-3">
                    <div>
                        <label class="mb-1 block text-sm font-bold text-slate-700">الاسم</label>
                        <input
                            ref="nameInput"
                            v-model="name"
                            type="text"
                            @keyup.enter="submit"
                            class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm focus:border-emerald-400 focus:outline-none focus:ring-2 focus:ring-emerald-100"
                        />
                        <p v-if="errors.name" class="mt-1 text-xs text-red-500">{{ errors.name }}</p>
                    </div>

                    <div>
                        <label class="mb-1 block text-sm font-bold text-slate-700">
                            الجوال <span class="font-medium text-slate-400">(اختياري)</span>
                        </label>
                        <input
                            v-model="mobile"
                            type="text"
                            dir="ltr"
                            @keyup.enter="submit"
                            class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm focus:border-emerald-400 focus:outline-none focus:ring-2 focus:ring-emerald-100"
                        />
                        <p v-if="errors.mobile" class="mt-1 text-xs text-red-500">{{ errors.mobile }}</p>
                    </div>

                    <p v-if="message" class="rounded-lg bg-red-50 px-3 py-2 text-xs font-bold text-red-600">{{ message }}</p>

                    <p class="text-[11px] font-medium text-slate-500">
                        تُستكمل بقية البيانات (المدينة والهوية والبيانات الضريبية) من شاشة العملاء.
                    </p>

                    <div class="flex gap-2 pt-1">
                        <button
                            type="button"
                            @click="submit"
                            :disabled="saving || !name.trim()"
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
