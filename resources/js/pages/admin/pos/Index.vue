<script setup lang="ts">
import SearchableSelect from '@/components/SearchableSelect.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, router, useForm } from '@inertiajs/vue3';
import { AlertTriangle, Plus, Receipt, Trash2, X } from 'lucide-vue-next';
import { computed, ref } from 'vue';

interface PosItem {
    id: number; code: string; barcode: string | null; name: string;
    category: string | null; type: string; type_label: string;
    unit: string; unit_label: string;
    price: number; tax_rate: number; stock_qty: number;
    tracks_stock: boolean; fractional: boolean; low_stock: boolean;
}

interface Line {
    /** مفتاح ثابت للسطر — الفهرس لا يصلح مفتاحًا مع الحذف والدمج. */
    uid: number;
    item_id: number | null;
    quantity: number;
    unit_price: number;
    discount_amount: number;
}

const props = defineProps<{
    departments: { id: number; name: string; code: string | null }[];
    departmentId: number | null;
    items: PosItem[];
    clients: { id: number; name: string; mobile: string | null }[];
    /** العميل النقدي — المختار تلقائيًا ما لم يُحدَّد غيره. */
    defaultClientId: number;
    methods: { key: string; label: string }[];
    recentSales: { id: number; number: string; type: string; total: number; method_label: string; time: string }[];
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'لوحة التحكم', href: '/admin' },
    { title: 'الفواتير', href: '/admin/pos' },
];

const money = (n: number) => new Intl.NumberFormat('ar-SA-u-nu-latn', { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(n ?? 0);

const itemOf = (id: number | null): PosItem | null => props.items.find((i) => i.id === id) ?? null;

// ── سطور الفاتورة ───────────────────────────────────────────
const form = useForm({
    lines: [] as unknown[],
    client_id: props.defaultClientId as number | null,
    department_id: props.departmentId,
    method: 'cash',
    discount_amount: 0,
    paid_amount: 0,
    notes: '',
});

/** تبديل القسم يعيد تحميل أصنافه — مستودع كل نشاط مستقل. */
const changeDepartment = (id: number | string | null) => {
    router.get('/admin/pos', { department_id: id }, { preserveState: false });
};

let uid = 0;
const emptyLine = (): Line => ({ uid: ++uid, item_id: null, quantity: 1, unit_price: 0, discount_amount: 0 });

const lines = ref<Line[]>([emptyLine()]);

const addLine = () => lines.value.push(emptyLine());

const removeLine = (i: number) => {
    lines.value.splice(i, 1);
    if (!lines.value.length) lines.value.push(emptyLine());
};

const chosenIds = computed(() => new Set(lines.value.map((l) => l.item_id).filter((id): id is number => id !== null)));

/**
 * قائمة السطر تُخفي ما اختير في السطور الأخرى — التكرار ممنوع من أصله،
 * وصنف السطر نفسه يبقى فيها وإلا اختفى اسمه من الزر.
 */
const optionsFor = (line: Line) =>
    props.items.filter((i) => i.id === line.item_id || !chosenIds.value.has(i.id));

/** عند اختيار الصنف يُملأ سعره ووحدته تلقائيًا. */
const onItemChange = (line: Line, item: PosItem | null) => {
    line.unit_price = item?.price ?? 0;
    line.quantity = 1;
};

/** كل الأصناف مضافة: لا معنى لسطر فارغ لا يجد ما يُختار فيه. */
const canAddLine = computed(() => chosenIds.value.size < props.items.length);

/** الصنف غير المقاس لا يقبل الكسور — نفس القاعدة المطبَّقة على الخادم. */
const normalizeQuantity = (line: Line) => {
    const item = itemOf(line.item_id);
    const min = item?.fractional ? 0.001 : 1;

    if (!item?.fractional) line.quantity = Math.round(line.quantity);
    if (!line.quantity || line.quantity < min) line.quantity = min;
};

const lineTotal = (line: Line) => Math.max(0, line.unit_price * line.quantity - line.discount_amount);

const lineTax = (line: Line) => {
    const item = itemOf(line.item_id);
    return lineTotal(line) * ((item?.tax_rate ?? 0) / 100);
};

const stockWarning = (line: Line): string | null => {
    const item = itemOf(line.item_id);
    if (!item || !item.tracks_stock) return null;

    return line.quantity > item.stock_qty
        ? `الرصيد المتاح ${item.stock_qty} ${item.unit_label} فقط`
        : null;
};

const filledLines = computed(() => lines.value.filter((l) => l.item_id && l.quantity > 0));
const hasStockIssue = computed(() => lines.value.some((l) => stockWarning(l) !== null));

// ── الحساب ──────────────────────────────────────────────────
const subtotal = computed(() => filledLines.value.reduce((s, l) => s + lineTotal(l), 0));
const tax = computed(() => filledLines.value.reduce((s, l) => s + lineTax(l), 0));
const total = computed(() => Math.max(0, subtotal.value + tax.value - (form.discount_amount || 0)));

// ── المدفوع والمتبقي ────────────────────────────────────────
/** null = اتركه تلقائيًا تبعًا لطريقة الدفع؛ ورقمٌ = مبلغ حدّده الكاشير. */
const paidInput = ref<number | null>(null);

/** الآجل يبدأ بلا سداد، وسواه مسدَّد كاملًا — ما لم يُحدَّد غير ذلك. */
const autoPaid = computed(() => (form.method === 'account' ? 0 : total.value));

const paidAmount = computed(() =>
    paidInput.value === null ? autoPaid.value : Math.min(total.value, Math.max(0, paidInput.value)),
);

/** الحقل يقرأ المحسوب ويكتب في التحديد اليدوي. */
const paidField = computed({
    get: () => Number(paidAmount.value.toFixed(2)),
    set: (v: number) => (paidInput.value = Number.isFinite(v) ? v : 0),
});

const remaining = computed(() => Math.max(0, total.value - paidAmount.value));

/** نفس قاعدة الخادم: أقل من نصف هللة ليست دَينًا. */
const paymentStatus = computed(() => {
    if (remaining.value <= 0.005) return { key: 'paid', label: 'مسدّدة', class: 'bg-emerald-100 text-emerald-800' };
    if (paidAmount.value > 0.005) return { key: 'partial', label: 'مسدّدة جزئيًا', class: 'bg-amber-100 text-amber-800' };
    return { key: 'unpaid', label: 'غير مسدّدة', class: 'bg-red-100 text-red-800' };
});

/** تبديل طريقة الدفع يعيد المدفوع إلى تلقائيّ القاعدة الجديدة. */
const changeMethod = (key: string) => {
    form.method = key;
    paidInput.value = null;
};

/** الدَّين على العميل النقدي لا يُتابَع باسم — ننبّه دون منع. */
const debtOnWalkIn = computed(
    () => remaining.value > 0.005 && (form.client_id === null || form.client_id === props.defaultClientId),
);

const submit = () => {
    if (!filledLines.value.length || hasStockIssue.value) return;

    form.lines = filledLines.value.map((l) => ({
        item_id: l.item_id,
        quantity: l.quantity,
        unit_price: l.unit_price,
        discount_amount: l.discount_amount,
    }));

    form.paid_amount = paidAmount.value;

    form.post('/admin/pos/checkout', {
        preserveScroll: true,
        onSuccess: () => {
            lines.value = [emptyLine()];
            paidInput.value = null;
            form.reset('discount_amount', 'notes');
            form.client_id = props.defaultClientId;
        },
    });
};

const typeClass = (t: string) =>
    ({
        stock: 'bg-sky-100 text-sky-800',
        service: 'bg-violet-100 text-violet-800',
        bundle: 'bg-amber-100 text-amber-800',
        measured: 'bg-teal-100 text-teal-800',
    })[t] ?? 'bg-slate-200 text-slate-800';

/** حقول الأرقام: حدّ واضح ونص داكن — والمعطَّل رمادي مقروء لا باهت. */
const numField =
    'w-full rounded-lg border-2 border-slate-400 bg-white px-2 py-2 text-center text-base font-extrabold text-slate-950 focus:border-emerald-700 focus:outline-none focus:ring-2 focus:ring-emerald-200 disabled:border-slate-300 disabled:bg-slate-100 disabled:text-slate-600';
</script>

<template>
    <Head title="الفواتير" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="min-h-full space-y-4 bg-slate-100 p-4">
            <form @submit.prevent="submit" class="space-y-4">
                    <!-- ترويسة الفاتورة -->
                    <div class="rounded-2xl border-2 border-slate-300 bg-white p-4 shadow-sm">
                        <div class="mb-3 flex flex-wrap items-center justify-between gap-3">
                            <h2 class="flex items-center gap-1.5 text-base font-extrabold text-slate-950">
                                <Receipt class="h-5 w-5" /> فاتورة جديدة
                            </h2>

                            <!-- القسم: لكل نشاط مستودعه وأصنافه -->
                            <div v-if="departments.length > 1" class="flex items-center gap-1.5">
                                <span class="text-xs font-extrabold text-slate-700">القسم</span>
                                <button
                                    v-for="d in departments" :key="d.id" type="button" @click="changeDepartment(d.id)"
                                    class="rounded-lg px-3 py-1.5 text-xs font-extrabold transition"
                                    :class="departmentId === d.id ? 'bg-emerald-800 text-white shadow' : 'border-2 border-slate-400 bg-white text-slate-900 hover:bg-slate-200'"
                                >{{ d.name }}</button>
                            </div>
                            <span v-else-if="departments.length === 1" class="rounded-lg bg-emerald-800 px-3 py-1.5 text-xs font-extrabold text-white">
                                قسم {{ departments[0].name }}
                            </span>
                        </div>

                        <div class="grid gap-3 sm:grid-cols-2">
                            <div>
                                <label class="mb-1 block text-sm font-extrabold text-slate-950">العميل</label>
                                <SearchableSelect
                                    v-model="form.client_id"
                                    :options="clients"
                                    :search-keys="['mobile']"
                                    placeholder="— عميل نقدي —"
                                >
                                    <template #option="{ option }">
                                        <span class="font-bold">{{ option.name }}</span>
                                        <span v-if="option.mobile" class="text-[11px] text-slate-400" dir="ltr"> · {{ option.mobile }}</span>
                                    </template>
                                </SearchableSelect>
                            </div>
                            <div>
                                <label class="mb-1 block text-sm font-extrabold text-slate-950">طريقة الدفع</label>
                                <div class="flex gap-1">
                                    <button
                                        v-for="m in methods" :key="m.key" type="button" @click="changeMethod(m.key)"
                                        class="flex-1 rounded-lg py-2 text-xs font-extrabold transition"
                                        :class="form.method === m.key ? 'bg-emerald-800 text-white shadow' : 'border-2 border-slate-400 bg-white text-slate-900 hover:bg-slate-200'"
                                    >{{ m.label }}</button>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- سطور المنتجات -->
                    <div class="overflow-hidden rounded-2xl border-2 border-slate-300 bg-white shadow-sm">
                        <div class="overflow-x-auto">
                            <table class="w-full min-w-[860px] text-sm">
                                <thead class="bg-emerald-900">
                                    <tr>
                                        <th class="w-10 px-2 py-3 text-center text-sm font-extrabold text-white">#</th>
                                        <th class="px-3 py-3 text-right text-sm font-extrabold text-white">الصنف</th>
                                        <th class="w-32 px-3 py-3 text-center text-sm font-extrabold text-white">الكمية</th>
                                        <th class="w-24 px-3 py-3 text-center text-sm font-extrabold text-white">الوحدة</th>
                                        <th class="w-32 px-3 py-3 text-center text-sm font-extrabold text-white">السعر</th>
                                        <th class="w-28 px-3 py-3 text-center text-sm font-extrabold text-white">خصم</th>
                                        <th class="w-32 px-3 py-3 text-left text-sm font-extrabold text-white">الإجمالي</th>
                                        <th class="w-12 px-2 py-3"></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr
                                        v-for="(line, i) in lines" :key="line.uid"
                                        class="border-t border-slate-300 align-top"
                                    >
                                        <td class="px-2 py-2 text-center text-sm font-extrabold text-slate-800">{{ i + 1 }}</td>

                                        <td class="px-3 py-2">
                                            <SearchableSelect
                                                v-model="line.item_id"
                                                :options="optionsFor(line)"
                                                :search-keys="['code', 'barcode', 'category']"
                                                placeholder="ابحث بالاسم أو الكود أو الباركود"
                                                @change="(item) => onItemChange(line, item as PosItem | null)"
                                            >
                                                <template #option="{ option }">
                                                    <span class="flex items-center justify-between gap-2">
                                                        <span class="min-w-0">
                                                            <span class="font-bold">{{ option.name }}</span>
                                                            <span class="block text-[11px] font-bold text-slate-600" dir="ltr">{{ option.code }}</span>
                                                        </span>
                                                        <span class="flex shrink-0 items-center gap-1">
                                                            <span class="rounded px-1.5 py-0.5 text-[9px] font-bold" :class="typeClass(option.type)">{{ option.type_label }}</span>
                                                            <span class="text-xs font-extrabold text-emerald-800">{{ money(option.price) }}</span>
                                                        </span>
                                                    </span>
                                                </template>
                                            </SearchableSelect>

                                            <p v-if="stockWarning(line)" class="mt-1 flex items-center gap-1 text-[11px] font-bold text-red-600">
                                                <AlertTriangle class="h-3 w-3 shrink-0" /> {{ stockWarning(line) }}
                                            </p>
                                        </td>

                                        <td class="px-3 py-2">
                                            <input
                                                v-model.number="line.quantity"
                                                @change="normalizeQuantity(line)"
                                                type="number"
                                                :step="itemOf(line.item_id)?.fractional ? 0.001 : 1"
                                                :min="itemOf(line.item_id)?.fractional ? 0.001 : 1"
                                                :disabled="!line.item_id"
                                                :class="numField"
                                            />
                                        </td>

                                        <td class="px-3 py-2 text-center">
                                            <span class="inline-block rounded-lg bg-slate-300 px-2.5 py-2 text-sm font-extrabold text-slate-950">
                                                {{ itemOf(line.item_id)?.unit_label ?? '—' }}
                                            </span>
                                        </td>

                                        <td class="px-3 py-2">
                                            <input
                                                v-model.number="line.unit_price"
                                                type="number" min="0" step="0.01"
                                                :disabled="!line.item_id"
                                                :class="numField"
                                            />
                                        </td>

                                        <td class="px-3 py-2">
                                            <input
                                                v-model.number="line.discount_amount"
                                                type="number" min="0" step="0.01"
                                                :disabled="!line.item_id"
                                                :class="numField"
                                            />
                                        </td>

                                        <td class="px-3 py-2 text-left">
                                            <span class="text-base font-extrabold text-slate-950" dir="ltr">{{ money(lineTotal(line)) }}</span>
                                            <span v-if="itemOf(line.item_id)?.tax_rate" class="block text-[11px] font-bold text-slate-700" dir="ltr">
                                                +{{ money(lineTax(line)) }} ضريبة
                                            </span>
                                        </td>

                                        <td class="px-2 py-2 text-center">
                                            <button type="button" @click="removeLine(i)" title="حذف السطر" class="rounded-lg p-1.5 text-red-600 transition hover:bg-red-100 hover:text-red-700">
                                                <Trash2 class="h-4 w-4" />
                                            </button>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>

                        <div class="flex items-center gap-3 border-t border-slate-100 p-3">
                            <button
                                type="button" @click="addLine" :disabled="!canAddLine"
                                class="inline-flex items-center gap-1.5 rounded-lg border-2 border-dashed border-slate-500 px-4 py-2 text-sm font-extrabold text-slate-900 transition hover:border-emerald-700 hover:bg-emerald-100 hover:text-emerald-900 disabled:cursor-not-allowed disabled:border-slate-300 disabled:text-slate-500 disabled:hover:bg-transparent"
                            >
                                <Plus class="h-3.5 w-3.5" /> إضافة سطر
                            </button>
                            <span v-if="!canAddLine" class="text-xs font-bold text-slate-600">كل الأصناف مضافة — زِد الكمية في سطر الصنف.</span>
                        </div>
                    </div>

                    <!-- الإجماليات -->
                    <div class="grid gap-4 lg:grid-cols-[1fr_340px]">
                        <div class="rounded-2xl border-2 border-slate-300 bg-white p-4 shadow-sm">
                            <label class="mb-1 block text-xs font-extrabold text-slate-800">ملاحظات الفاتورة</label>
                            <textarea v-model="form.notes" rows="3" class="w-full rounded-xl border border-slate-300 px-3 py-2.5 text-sm text-slate-900 placeholder:text-slate-500 focus:border-emerald-500 focus:outline-none focus:ring-2 focus:ring-emerald-100"></textarea>
                            <p v-if="form.errors.lines" class="mt-2 rounded-lg border border-red-200 bg-red-100 px-3 py-2 text-xs font-bold text-red-800">{{ form.errors.lines }}</p>
                        </div>

                        <div class="rounded-2xl border-2 border-slate-300 bg-white p-4 shadow-sm">
                            <div class="space-y-2 text-sm">
                                <div class="flex justify-between">
                                    <span class="font-extrabold text-slate-800">الإجمالي ({{ filledLines.length }} صنف)</span>
                                    <span class="text-base font-extrabold text-slate-950" dir="ltr">{{ money(subtotal) }}</span>
                                </div>
                                <div class="flex justify-between">
                                    <span class="font-extrabold text-slate-800">الضريبة</span>
                                    <span class="text-base font-extrabold text-slate-950" dir="ltr">{{ money(tax) }}</span>
                                </div>
                                <div class="flex items-center justify-between">
                                    <span class="font-extrabold text-slate-800">خصم على الفاتورة</span>
                                    <input v-model.number="form.discount_amount" type="number" min="0" step="0.01" class="w-28 rounded-lg border-2 border-slate-400 px-2 py-1.5 text-left text-sm font-extrabold text-slate-950 focus:border-emerald-700 focus:outline-none focus:ring-2 focus:ring-emerald-200" />
                                </div>
                                <div class="mt-1 flex justify-between rounded-xl bg-emerald-900 px-3 py-2.5 text-xl">
                                    <span class="font-extrabold text-white">الصافي</span>
                                    <span class="font-extrabold text-white" dir="ltr">{{ money(total) }}</span>
                                </div>

                                <!-- المقبوض فعلًا: تعديله يُظهر المتبقي ويغيّر حالة الفاتورة -->
                                <div class="mt-2 flex items-center justify-between border-t-2 border-slate-200 pt-2">
                                    <span class="font-extrabold text-slate-800">المدفوع</span>
                                    <div class="flex items-center gap-1">
                                        <button
                                            v-if="paidInput !== null" type="button" @click="paidInput = null"
                                            title="إعادة المدفوع إلى تلقائي طريقة الدفع"
                                            class="rounded-lg border-2 border-slate-400 px-2 py-1.5 text-[11px] font-extrabold text-slate-800 transition hover:bg-slate-200"
                                        >تلقائي</button>
                                        <input
                                            v-model.number="paidField" type="number" min="0" :max="total" step="0.01"
                                            class="w-28 rounded-lg border-2 border-slate-400 px-2 py-1.5 text-left text-sm font-extrabold text-slate-950 focus:border-emerald-700 focus:outline-none focus:ring-2 focus:ring-emerald-200"
                                        />
                                    </div>
                                </div>

                                <div class="flex items-center justify-between">
                                    <span class="font-extrabold text-slate-800">المتبقي</span>
                                    <span
                                        class="text-base font-extrabold" dir="ltr"
                                        :class="remaining > 0.005 ? 'text-red-700' : 'text-slate-950'"
                                    >{{ money(remaining) }}</span>
                                </div>

                                <div class="flex items-center justify-between">
                                    <span class="font-extrabold text-slate-800">حالة السداد</span>
                                    <span class="rounded-lg px-2.5 py-1 text-xs font-extrabold" :class="paymentStatus.class">
                                        {{ paymentStatus.label }}
                                    </span>
                                </div>
                            </div>

                            <p v-if="debtOnWalkIn" class="mt-3 rounded-lg border border-amber-300 bg-amber-100 px-3 py-2 text-[11px] font-bold text-amber-900">
                                المتبقي سيُقيَّد على العميل النقدي — اختر عميلًا محددًا لتتمكّن من متابعة الدَّين وتحصيله.
                            </p>

                            <p v-if="hasStockIssue" class="mt-3 rounded-lg border border-red-200 bg-red-100 px-3 py-2 text-[11px] font-bold text-red-800">
                                لا يمكن الحفظ: أحد الأصناف يتجاوز رصيده المتاح.
                            </p>

                            <button
                                type="submit"
                                :disabled="form.processing || !filledLines.length || hasStockIssue"
                                class="mt-3 w-full rounded-md bg-blue-700 py-3 text-base font-extrabold text-white shadow transition hover:bg-blue-800 disabled:cursor-not-allowed disabled:bg-slate-400 disabled:text-slate-100 disabled:shadow-none"
                            >
                                حفظ الفاتورة
                            </button>
                        </div>
                    </div>
                </form>

            <!-- آخر الفواتير -->
            <div v-if="recentSales.length" class="rounded-2xl border-2 border-slate-300 bg-white p-3 shadow-sm">
                <h3 class="mb-2 flex items-center gap-1.5 text-sm font-extrabold text-slate-800">
                    <Receipt class="h-4 w-4" /> آخر الفواتير
                </h3>
                <div class="flex flex-wrap gap-2">
                    <span
                        v-for="s in recentSales" :key="s.id"
                        class="rounded-lg px-2.5 py-1 text-xs font-extrabold"
                        :class="s.type === 'return' ? 'bg-red-200 text-red-900' : 'bg-slate-200 text-slate-900'"
                    >
                        <span dir="ltr">{{ s.number }}</span> · {{ money(s.total) }} · {{ s.method_label }} · {{ s.time }}
                    </span>
                </div>
            </div>
        </div>

    </AppLayout>
</template>
