<script setup lang="ts">
import SiteLayout, { type SiteOrg } from '@/layouts/SiteLayout.vue';
import { addDays } from '@/lib/dates';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { AlertTriangle, ArrowRight, Loader2, ShieldCheck } from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';

interface QuoteLine { label: string; amount: number }

const props = defineProps<{
    org: SiteOrg;
    unit: {
        id: number; name: string; type: 'hall' | 'chalet';
        capacity: number | null; logo_url: string | null;
        allows_whole: boolean; allows_sections: boolean;
        sections: { id: number; name: string }[];
    };
    isStay: boolean;
    periods: { key: string; label: string }[];
    eventTypes: { id: number; name: string }[];
    maxDays: number;
    maxNights: number;
    today: string;
}>();

const form = useForm({
    // الوحدة التي لا تُحجز كاملة تبدأ على الأقسام مباشرةً بدل خيارٍ مرفوض.
    scope: props.unit.allows_whole ? 'whole' : 'sections',
    section_ids: [] as number[],
    booking_date: '',
    check_out_date: '',
    period: props.periods[0]?.key ?? 'full_day',
    days_count: 1,
    event_type_id: null as number | null,
    guests_count: null as number | null,
    client_name: '',
    client_mobile: '',
    notes: '',
    agreed: false,
});

const money = (n: number) => new Intl.NumberFormat('ar-SA-u-nu-latn', { maximumFractionDigits: 2 }).format(n ?? 0);

// ── التسعيرة الحيّة ──────────────────────────────────────────
// السعر يُحسب على الخادم لا في المتصفح: قواعد نهاية الأسبوع وأسعار الأيام
// وأنواع المناسبات تعيش هناك، ونسخُها هنا يعني رقمين يفترقان مع أول تعديل.
const quote = ref<{ total_amount: number; deposit_amount: number; lines: QuoteLine[] } | null>(null);
const quoting = ref(false);
const quoteError = ref<string | null>(null);

const readyToQuote = computed(() => {
    if (!form.booking_date) return false;
    if (props.isStay && !form.check_out_date) return false;
    if (form.scope === 'sections' && !form.section_ids.length) return false;
    return true;
});

let timer: ReturnType<typeof setTimeout> | undefined;

const fetchQuote = async () => {
    if (!readyToQuote.value) {
        quote.value = null;
        return;
    }

    quoting.value = true;
    quoteError.value = null;

    try {
        const response = await fetch(`/book/${props.unit.id}/quote`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-XSRF-TOKEN': decodeURIComponent(
                    document.cookie.match(/XSRF-TOKEN=([^;]+)/)?.[1] ?? '',
                ),
            },
            body: JSON.stringify({
                scope: form.scope,
                section_ids: form.section_ids,
                booking_date: form.booking_date,
                check_out_date: form.check_out_date,
                period: form.period,
                days_count: form.days_count,
                event_type_id: form.event_type_id,
            }),
        });

        if (!response.ok) {
            quote.value = null;
            // 422 يعني بيانات ناقصة أو تاريخًا غير مقبول — لا عطلًا.
            quoteError.value = response.status === 422 ? null : 'تعذّر احتساب السعر الآن.';
            return;
        }

        quote.value = await response.json();
    } catch {
        quote.value = null;
        quoteError.value = 'تعذّر الاتصال بالخادم لاحتساب السعر.';
    } finally {
        quoting.value = false;
    }
};

watch(
    () => [form.scope, form.section_ids, form.booking_date, form.check_out_date, form.period, form.days_count, form.event_type_id],
    () => {
        clearTimeout(timer);
        timer = setTimeout(fetchQuote, 350);
    },
    { deep: true },
);

// تاريخ الخروج يتبع الدخول: إقامةٌ تخرج قبل أن تدخل لا معنى لها، وتركها
// للخادم وحده يجعل الزائر يملأ النموذج ثم يُرفض.
watch(
    () => form.booking_date,
    (value) => {
        if (props.isStay && value && (!form.check_out_date || form.check_out_date <= value)) {
            form.check_out_date = addDays(value, 1);
        }
    },
);

const toggleSection = (id: number) => {
    form.section_ids = form.section_ids.includes(id)
        ? form.section_ids.filter((s) => s !== id)
        : [...form.section_ids, id];
};

const submit = () => form.post(`/book/${props.unit.id}`, { preserveScroll: true });
</script>

<template>
    <Head :title="`حجز ${unit.name}`" />

    <SiteLayout :org="org">
        <div class="mx-auto max-w-5xl space-y-4 px-4 py-8">
            <Link :href="`/units/${unit.id}`" class="inline-flex items-center gap-1 text-sm font-bold text-slate-500 hover:text-slate-800">
                <ArrowRight class="h-4 w-4" /> رجوع إلى {{ unit.name }}
            </Link>

            <h1 class="text-2xl font-extrabold text-slate-900">حجز {{ unit.name }}</h1>

            <!-- التعارض يصل من الخادم برسالته المفصّلة -->
            <div v-if="form.errors.availability" class="flex items-start gap-2 rounded-xl border border-red-200 bg-red-50 p-4 text-sm font-bold text-red-700">
                <AlertTriangle class="mt-0.5 h-5 w-5 shrink-0" />
                <span>{{ form.errors.availability }}</span>
            </div>

            <form @submit.prevent="submit" class="grid gap-4 lg:grid-cols-3">
                <!-- تفاصيل الحجز -->
                <div class="space-y-4 lg:col-span-2">
                    <div class="space-y-3 rounded-2xl border border-slate-200 bg-white p-5">
                        <h2 class="text-lg font-extrabold text-slate-900">تفاصيل الحجز</h2>

                        <!-- النطاق -->
                        <div v-if="unit.allows_whole && unit.allows_sections">
                            <label class="mb-1.5 block text-sm font-bold text-slate-700">النطاق</label>
                            <div class="flex gap-2">
                                <button
                                    type="button" @click="form.scope = 'whole'"
                                    class="flex-1 rounded-xl px-3 py-2.5 text-sm font-bold transition"
                                    :class="form.scope === 'whole' ? 'bg-slate-900 text-white' : 'bg-slate-100 text-slate-600'"
                                >الوحدة كاملة</button>
                                <button
                                    type="button" @click="form.scope = 'sections'"
                                    class="flex-1 rounded-xl px-3 py-2.5 text-sm font-bold transition"
                                    :class="form.scope === 'sections' ? 'bg-slate-900 text-white' : 'bg-slate-100 text-slate-600'"
                                >أقسام محددة</button>
                            </div>
                        </div>

                        <div v-if="form.scope === 'sections'">
                            <label class="mb-1.5 block text-sm font-bold text-slate-700">الأقسام المطلوبة</label>
                            <div class="flex flex-wrap gap-2">
                                <button
                                    v-for="s in unit.sections" :key="s.id" type="button" @click="toggleSection(s.id)"
                                    class="rounded-xl border px-3 py-2 text-sm font-bold transition"
                                    :class="form.section_ids.includes(s.id) ? 'border-slate-900 bg-slate-900 text-white' : 'border-slate-200 bg-white text-slate-600 hover:bg-slate-50'"
                                >{{ s.name }}</button>
                            </div>
                            <p v-if="form.errors.section_ids" class="mt-1 text-xs font-bold text-red-600">{{ form.errors.section_ids }}</p>
                        </div>

                        <!-- التواريخ -->
                        <div class="grid gap-3 sm:grid-cols-2">
                            <div>
                                <label class="mb-1.5 block text-sm font-bold text-slate-700">
                                    {{ isStay ? 'تاريخ الدخول' : 'تاريخ المناسبة' }}
                                </label>
                                <input v-model="form.booking_date" type="date" :min="today" class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm" />
                                <p v-if="form.errors.booking_date" class="mt-1 text-xs font-bold text-red-600">{{ form.errors.booking_date }}</p>
                            </div>

                            <div v-if="isStay">
                                <label class="mb-1.5 block text-sm font-bold text-slate-700">تاريخ الخروج</label>
                                <input v-model="form.check_out_date" type="date" :min="form.booking_date || today" class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm" />
                                <p v-if="form.errors.check_out_date" class="mt-1 text-xs font-bold text-red-600">{{ form.errors.check_out_date }}</p>
                                <p class="mt-1 text-[11px] text-slate-500">أقصى مدة إقامة {{ maxNights }} ليلة</p>
                            </div>

                            <div v-else>
                                <label class="mb-1.5 block text-sm font-bold text-slate-700">الفترة</label>
                                <!-- بفترة واحدة تُعرض خبرًا: قائمةٌ بخيار واحد تسأل سؤالًا بلا جواب آخر -->
                                <div v-if="periods.length === 1" class="w-full rounded-xl border border-slate-200 bg-slate-50 px-3 py-2.5 text-sm font-bold text-slate-700">
                                    {{ periods[0].label }}
                                </div>
                                <select v-else v-model="form.period" class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm">
                                    <option v-for="p in periods" :key="p.key" :value="p.key">{{ p.label }}</option>
                                </select>
                            </div>
                        </div>

                        <div v-if="!isStay" class="grid gap-3 sm:grid-cols-2">
                            <div>
                                <label class="mb-1.5 block text-sm font-bold text-slate-700">عدد الأيام</label>
                                <input v-model.number="form.days_count" type="number" min="1" :max="maxDays" class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm" />
                            </div>
                            <div v-if="eventTypes.length">
                                <label class="mb-1.5 block text-sm font-bold text-slate-700">نوع المناسبة</label>
                                <select v-model="form.event_type_id" class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm">
                                    <option :value="null">— غير محدد —</option>
                                    <option v-for="e in eventTypes" :key="e.id" :value="e.id">{{ e.name }}</option>
                                </select>
                            </div>
                        </div>

                        <div>
                            <label class="mb-1.5 block text-sm font-bold text-slate-700">
                                عدد الضيوف <span class="font-medium text-slate-400">(اختياري)</span>
                            </label>
                            <input
                                v-model.number="form.guests_count" type="number" min="1"
                                :placeholder="unit.capacity ? `تتسع الوحدة لـ ${unit.capacity}` : ''"
                                class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm"
                            />
                        </div>
                    </div>

                    <!-- بيانات مقدّم الطلب -->
                    <div class="space-y-3 rounded-2xl border border-slate-200 bg-white p-5">
                        <h2 class="text-lg font-extrabold text-slate-900">بياناتك</h2>
                        <div class="grid gap-3 sm:grid-cols-2">
                            <div>
                                <label class="mb-1.5 block text-sm font-bold text-slate-700">الاسم</label>
                                <input v-model="form.client_name" type="text" class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm" />
                                <p v-if="form.errors.client_name" class="mt-1 text-xs font-bold text-red-600">{{ form.errors.client_name }}</p>
                            </div>
                            <div>
                                <label class="mb-1.5 block text-sm font-bold text-slate-700">رقم الجوال</label>
                                <input v-model="form.client_mobile" type="tel" dir="ltr" placeholder="05XXXXXXXX" class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm" />
                                <p v-if="form.errors.client_mobile" class="mt-1 text-xs font-bold text-red-600">{{ form.errors.client_mobile }}</p>
                            </div>
                        </div>
                        <div>
                            <label class="mb-1.5 block text-sm font-bold text-slate-700">
                                ملاحظات <span class="font-medium text-slate-400">(اختياري)</span>
                            </label>
                            <textarea v-model="form.notes" rows="2" class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm"></textarea>
                        </div>
                    </div>
                </div>

                <!-- الملخص -->
                <aside class="space-y-3 lg:sticky lg:top-20 lg:self-start">
                    <div class="rounded-2xl border border-slate-200 bg-white p-5">
                        <h2 class="mb-3 text-lg font-extrabold text-slate-900">ملخص التكلفة</h2>

                        <div v-if="quoting" class="flex items-center gap-2 py-6 text-sm font-bold text-slate-500">
                            <Loader2 class="h-4 w-4 animate-spin" /> جارٍ احتساب السعر…
                        </div>

                        <template v-else-if="quote">
                            <ul class="space-y-1.5 text-sm">
                                <li v-for="(l, i) in quote.lines" :key="i" class="flex justify-between gap-2">
                                    <span class="text-slate-600">{{ l.label }}</span>
                                    <span class="shrink-0 font-bold text-slate-800" dir="ltr">{{ money(l.amount) }}</span>
                                </li>
                            </ul>
                            <div class="mt-3 border-t border-slate-200 pt-3">
                                <div class="flex justify-between text-base">
                                    <span class="font-extrabold text-slate-900">الإجمالي</span>
                                    <span class="font-extrabold text-slate-900" dir="ltr">{{ money(quote.total_amount) }} ريال</span>
                                </div>
                                <div v-if="quote.deposit_amount > 0" class="mt-1 flex justify-between text-sm">
                                    <span class="font-bold text-slate-600">العربون المطلوب</span>
                                    <span class="font-extrabold text-emerald-700" dir="ltr">{{ money(quote.deposit_amount) }} ريال</span>
                                </div>
                            </div>
                        </template>

                        <p v-else-if="quoteError" class="py-4 text-sm font-bold text-red-600">{{ quoteError }}</p>
                        <p v-else class="py-6 text-sm text-slate-500">اختر التاريخ والنطاق ليظهر السعر.</p>
                    </div>

                    <div class="rounded-2xl border border-slate-200 bg-white p-5">
                        <label class="flex items-start gap-2 text-sm text-slate-700">
                            <input v-model="form.agreed" type="checkbox" class="mt-1 h-4 w-4 rounded border-slate-300" />
                            <span>
                                أوافق على أن هذا <b>طلب حجز</b> يحجز الموعد مؤقتًا بانتظار سداد العربون، وتتواصل معي
                                الإدارة لاستكمال السداد وإصدار العقد.
                            </span>
                        </label>
                        <p v-if="form.errors.agreed" class="mt-1 text-xs font-bold text-red-600">{{ form.errors.agreed }}</p>

                        <button
                            type="submit"
                            :disabled="form.processing"
                            class="mt-3 w-full rounded-xl bg-emerald-600 py-3 text-sm font-extrabold text-white hover:bg-emerald-700 disabled:opacity-60"
                        >
                            {{ form.processing ? 'جارٍ الإرسال…' : 'تأكيد طلب الحجز' }}
                        </button>

                        <p class="mt-2 flex items-start gap-1.5 text-[11px] text-slate-500">
                            <ShieldCheck class="mt-0.5 h-3.5 w-3.5 shrink-0" />
                            السداد يتم بالتحويل البنكي أو في المقر — لا تُطلب بيانات بطاقة في هذه الصفحة.
                        </p>
                    </div>
                </aside>
            </form>
        </div>
    </SiteLayout>
</template>
