<script setup lang="ts">
/**
 * Quick item creation from inside the purchase invoice.
 *
 * A supplier delivers something not yet on file: leaving the invoice for the
 * items screen loses every line already entered, so a small modal collects
 * only what a line needs — name, cost, price, tax — and the item comes back
 * as JSON to be appended to the invoice straight away.
 */
import { usePermissions } from '@/composables/usePermissions';
import { useVat } from '@/composables/useVat';
import { jsonHeaders } from '@/lib/csrf';
import { Loader2, PackagePlus, X } from 'lucide-vue-next';
import { nextTick, ref, watch } from 'vue';

export interface QuickItem {
    id: number;
    code: string | null;
    name: string;
    category: string | null;
    price: number;
    cost: number;
    tax_rate: number;
}

const props = withDefaults(
    defineProps<{
        types: { key: string; label: string }[];
        units: { key: string; label: string }[];
        /** Default tax rate from the business settings. */
        defaultTaxRate?: number;
        /** The invoice's department, so the new item lands in the right warehouse. */
        departmentId?: number | string | null;
    }>(),
    { defaultTaxRate: 0, departmentId: null },
);

const emit = defineEmits<{ created: [item: QuickItem] }>();

const { can } = usePermissions();

// نسبة الصنف لا تُعرض ولا تُحرَّر ما دام مفتاح الضريبة مطفأً.
const { applies: vatApplies } = useVat();

const open = ref(false);
const saving = ref(false);
const errors = ref<Record<string, string>>({});
const message = ref('');
const nameInput = ref<HTMLInputElement | null>(null);

const name = ref('');
const code = ref('');
const type = ref('stock');
const unit = ref('piece');
const cost = ref<number>(0);
const price = ref<number>(0);
const taxRate = ref<number>(0);

/**
 * Keep the numbers inside their real bounds as they are typed.
 *
 * The min/max attributes only gate a native form submit, and this modal saves
 * on a button click, so nothing stopped a tax rate of 100000 from reaching the
 * server and coming back as a 422 after the fact.
 */
const clamp = (value: number, min: number, max: number) => Math.min(Math.max(value, min), max);

watch(taxRate, (val) => {
    if (typeof val === 'number' && !Number.isNaN(val) && (val < 0 || val > 100)) {
        taxRate.value = clamp(val, 0, 100);
    }
});

watch([cost, price], ([nextCost, nextPrice]) => {
    if (typeof nextCost === 'number' && nextCost < 0) cost.value = 0;
    if (typeof nextPrice === 'number' && nextPrice < 0) price.value = 0;
});

const openModal = async () => {
    name.value = '';
    code.value = '';
    type.value = props.types[0]?.key ?? 'stock';
    unit.value = props.units[0]?.key ?? 'piece';
    cost.value = 0;
    price.value = 0;
    taxRate.value = props.defaultTaxRate;
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
        403: 'لا صلاحية لديك لإضافة صنف.',
    })[status] ?? `تعذّرت إضافة الصنف (${status}).`;

const submit = async () => {
    if (saving.value || !name.value.trim()) return;

    saving.value = true;
    errors.value = {};
    message.value = '';

    try {
        const response = await fetch('/admin/items/quick', {
            method: 'POST',
            headers: jsonHeaders(),
            body: JSON.stringify({
                name: name.value.trim(),
                // An empty code is generated server-side; sending "" instead of null
                // would collide with the unique index the moment a second one is saved.
                code: code.value.trim() || null,
                type: type.value,
                unit: unit.value,
                cost: Math.max(Number(cost.value) || 0, 0),
                price: Math.max(Number(price.value) || 0, 0),
                tax_rate: clamp(Number(taxRate.value) || 0, 0, 100),
                department_id: props.departmentId || null,
            }),
        });

        if (response.status === 422) {
            const body = await response.json();
            errors.value = Object.fromEntries(Object.entries(body.errors ?? {}).map(([key, list]) => [key, (list as string[])[0]]));

            return;
        }

        if (!response.ok) {
            message.value = failureMessage(response.status);

            return;
        }

        const { item } = (await response.json()) as { item: QuickItem };
        emit('created', item);
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
        v-if="can('items.create')"
        type="button"
        @click="openModal"
        title="إضافة صنف جديد"
        class="inline-flex shrink-0 items-center gap-1 rounded-lg border border-emerald-200 bg-emerald-50 px-2.5 py-1.5 text-xs font-bold text-emerald-700 transition hover:bg-emerald-100"
    >
        <PackagePlus class="h-3.5 w-3.5" /> صنف جديد
    </button>

    <Teleport to="body">
        <div v-if="open" dir="rtl" class="fixed inset-0 z-[110] flex items-center justify-center bg-black/40 p-4" @click.self="close">
            <div class="w-full max-w-md rounded-2xl bg-white p-5 shadow-2xl">
                <div class="mb-4 flex items-center justify-between">
                    <h2 class="text-base font-extrabold text-slate-900">صنف جديد</h2>
                    <button type="button" @click="close" class="rounded-lg p-1 text-slate-400 transition hover:bg-slate-100 hover:text-slate-600">
                        <X class="h-4 w-4" />
                    </button>
                </div>

                <!-- No nested <form>: the invoice form wraps this component, and
                     @keyup.enter covers saving by keyboard without invalid nesting. -->
                <div class="space-y-3">
                    <div>
                        <label class="mb-1 block text-sm font-bold text-slate-700">اسم الصنف</label>
                        <input
                            ref="nameInput"
                            v-model="name"
                            type="text"
                            @keyup.enter="submit"
                            class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm focus:border-emerald-400 focus:outline-none focus:ring-2 focus:ring-emerald-100"
                        />
                        <p v-if="errors.name" class="mt-1 text-xs text-red-500">{{ errors.name }}</p>
                    </div>

                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="mb-1 block text-sm font-bold text-slate-700">
                                الرمز <span class="font-medium text-slate-400">(يُولَّد تلقائيًا)</span>
                            </label>
                            <input
                                v-model="code"
                                type="text"
                                dir="ltr"
                                @keyup.enter="submit"
                                class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm focus:border-emerald-400 focus:outline-none focus:ring-2 focus:ring-emerald-100"
                            />
                            <p v-if="errors.code" class="mt-1 text-xs text-red-500">{{ errors.code }}</p>
                        </div>

                        <div>
                            <label class="mb-1 block text-sm font-bold text-slate-700">النوع</label>
                            <select
                                v-model="type"
                                class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm focus:border-emerald-400 focus:outline-none focus:ring-2 focus:ring-emerald-100"
                            >
                                <option v-for="t in types" :key="t.key" :value="t.key">{{ t.label }}</option>
                            </select>
                            <p v-if="errors.type" class="mt-1 text-xs text-red-500">{{ errors.type }}</p>
                        </div>
                    </div>

                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="mb-1 block text-sm font-bold text-slate-700">الوحدة</label>
                            <select
                                v-model="unit"
                                class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm focus:border-emerald-400 focus:outline-none focus:ring-2 focus:ring-emerald-100"
                            >
                                <option v-for="u in units" :key="u.key" :value="u.key">{{ u.label }}</option>
                            </select>
                            <p v-if="errors.unit" class="mt-1 text-xs text-red-500">{{ errors.unit }}</p>
                        </div>

                        <!-- تُخفى وتبقى قيمتها الافتراضية ما دامت الضريبة مطفأة -->
                        <div v-if="vatApplies">
                            <label class="mb-1 block text-sm font-bold text-slate-700">الضريبة %</label>
                            <input
                                v-model.number="taxRate"
                                type="number"
                                min="0"
                                max="100"
                                step="0.01"
                                dir="ltr"
                                @keyup.enter="submit"
                                class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-center text-sm focus:border-emerald-400 focus:outline-none focus:ring-2 focus:ring-emerald-100"
                            />
                            <p v-if="errors.tax_rate" class="mt-1 text-xs text-red-500">{{ errors.tax_rate }}</p>
                        </div>
                    </div>

                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="mb-1 block text-sm font-bold text-slate-700">تكلفة الشراء</label>
                            <input
                                v-model.number="cost"
                                type="number"
                                min="0"
                                step="0.01"
                                dir="ltr"
                                @keyup.enter="submit"
                                class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-center text-sm focus:border-emerald-400 focus:outline-none focus:ring-2 focus:ring-emerald-100"
                            />
                            <p v-if="errors.cost" class="mt-1 text-xs text-red-500">{{ errors.cost }}</p>
                        </div>

                        <div>
                            <label class="mb-1 block text-sm font-bold text-slate-700">سعر البيع</label>
                            <input
                                v-model.number="price"
                                type="number"
                                min="0"
                                step="0.01"
                                dir="ltr"
                                @keyup.enter="submit"
                                class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-center text-sm focus:border-emerald-400 focus:outline-none focus:ring-2 focus:ring-emerald-100"
                            />
                            <p v-if="errors.price" class="mt-1 text-xs text-red-500">{{ errors.price }}</p>
                        </div>
                    </div>

                    <p v-if="message" class="rounded-lg bg-red-50 px-3 py-2 text-xs font-bold text-red-600">{{ message }}</p>

                    <p class="text-[11px] font-medium text-slate-500">
                        يُنشأ الصنف برصيد صفر ثم تزيده هذه الفاتورة عند الحفظ. التصنيف والباركود وحد إعادة الطلب تُستكمل من شاشة الأصناف.
                    </p>

                    <div class="flex gap-2 pt-1">
                        <button
                            type="button"
                            @click="submit"
                            :disabled="saving || !name.trim()"
                            class="flex flex-1 items-center justify-center gap-1.5 rounded-xl bg-emerald-600 px-4 py-2.5 text-sm font-bold text-white transition hover:bg-emerald-700 disabled:opacity-50"
                        >
                            <Loader2 v-if="saving" class="h-4 w-4 animate-spin" />
                            حفظ وإضافة للفاتورة
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
