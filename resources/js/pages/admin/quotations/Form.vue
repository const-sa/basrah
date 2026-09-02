<script setup lang="ts">
import AsyncSelect from '@/components/AsyncSelect.vue';
import ItemGroupPicker from '@/components/ItemGroupPicker.vue';
import { useVat } from '@/composables/useVat';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { type GroupInsertion, type ItemGroupOption } from '@/types/item-groups';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { FileText, Trash2 } from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';

// شاشة إدخال، فتتبع المفتاح وحده: لا عمود ضريبة ولا سطرها ما دامت مطفأة.
const { applies: vatApplies } = useVat();

interface ItemOption {
    id: number;
    code: string | null;
    name: string;
    category: string | null;
    price: number;
    tax_rate: number;
}

const props = defineProps<{
    departments: { id: number; name: string }[];
    clients: { id: number; name: string }[];
    items: ItemOption[];
    /** المجموعات المحفوظة — بنود العرض تُملأ بها دفعةً واحدة. */
    groups: ItemGroupOption[];
    quotation?: {
        id: number;
        client_id: number;
        department_id: number;
        discount_amount: number;
        notes: string | null;
        valid_until: string | null;
        items: any[];
    };
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'لوحة التحكم', href: '/admin' },
    { title: 'عروض الأسعار', href: '/admin/quotations' },
    { title: props.quotation ? `تعديل عرض ${props.quotation.id}` : 'عرض سعر جديد', href: '#' },
];

const money = (n: number) => new Intl.NumberFormat('ar-SA-u-nu-latn', { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(n ?? 0);
const qty = (n: number) => new Intl.NumberFormat('ar-SA-u-nu-latn', { maximumFractionDigits: 3 }).format(n ?? 0);

interface FormLine {
    item_id: number;
    item: ItemOption | null;
    quantity: number;
    unit_price: number;
    tax_amount: number;
}

const form = useForm({
    client_id: props.quotation?.client_id ?? ('' as string | number),
    department_id: props.quotation?.department_id ?? props.departments[0]?.id ?? ('' as string | number),
    discount_amount: props.quotation?.discount_amount ?? 0,
    notes: props.quotation?.notes ?? 'نأمل أن يحوز عرضنا على رضاكم. هذا العرض صالح لمدة 30 يوم من تاريخ إصداره.',
    valid_until: props.quotation?.valid_until ?? new Date(Date.now() + 15 * 24 * 60 * 60 * 1000).toISOString().split('T')[0],
    items: props.quotation
        ? props.quotation.items.map((i) => ({
              item_id: i.item_id,
              item: i.item,
              quantity: i.quantity,
              unit_price: i.unit_price,
              tax_amount: i.tax_amount ?? i.quantity * i.unit_price * ((i.item?.tax_rate ?? 0) / 100),
          }))
        : ([] as FormLine[]),
});

const selectedItemId = ref<number | string | null>(null);

const handleItemSelect = (item: any) => {
    if (item) {
        addItem(item);
        selectedItemId.value = null;
    }
};

const addItem = (item: ItemOption) => {
    form.items.push({
        item_id: item.id,
        item: item,
        quantity: 1,
        unit_price: item.price,
        tax_amount: item.price * (item.tax_rate / 100),
    });
};

const removeItem = (index: number) => {
    form.items.splice(index, 1);
};

// ── المجموعات المحفوظة ──────────────────────────────────────
/** آخر مجموعة أُضيفت — يُطمئن أن الاختيار وقع. */
const lastGroup = ref<GroupInsertion | null>(null);

/**
 * إضافة أصناف مجموعة دفعةً واحدة.
 *
 * الصنف الموجود في العرض تزيد كميته ولا يُكرَّر بندًا: بندان بالصنف نفسه
 * يقرؤهما العميل سطرين متطابقين بلا سبب، والجمع أوضح.
 */
const addGroup = (group: ItemGroupOption) => {
    let added = 0;
    let merged = 0;

    for (const member of group.items) {
        const existing = form.items.find((l) => l.item_id === member.id);

        if (existing) {
            existing.quantity += 1;
            merged++;

            continue;
        }

        addItem(member);
        added++;
    }

    lastGroup.value = { name: group.name, added, merged };
};

// Calculations
watch(
    [() => form.items, () => form.discount_amount],
    ([lines, discount]) => {
        const currentSubtotal = lines.reduce((sum, line) => sum + line.quantity * line.unit_price, 0);
        const discountRatio = currentSubtotal > 0 ? discount / currentSubtotal : 0;

        lines.forEach((line) => {
            if (line.item) {
                const lineTotal = line.quantity * line.unit_price;
                const discountedLineTotal = lineTotal - lineTotal * discountRatio;
                line.tax_amount = discountedLineTotal * (line.item.tax_rate / 100);
            }
        });
    },
    { deep: true },
);

const subtotal = computed(() => form.items.reduce((sum, line) => sum + line.quantity * line.unit_price, 0));
const totalTax = computed(() => form.items.reduce((sum, line) => sum + line.tax_amount, 0));
const grandTotal = computed(() => subtotal.value - form.discount_amount + totalTax.value);

const submit = () => {
    if (props.quotation) {
        form.put(`/admin/quotations/${props.quotation.id}`, { preserveScroll: true });
    } else {
        form.post('/admin/quotations', {
            preserveScroll: true,
        });
    }
};
</script>

<template>
    <Head :title="props.quotation ? 'تعديل عرض سعر' : 'عرض سعر جديد'" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="mx-auto w-full space-y-4 p-4 sm:p-6">
            <div class="flex items-center justify-between">
                <div>
                    <h1 class="text-2xl font-extrabold tracking-tight text-slate-900">
                        {{ props.quotation ? 'تعديل عرض السعر' : 'إصدار عرض سعر' }}
                    </h1>
                    <p class="mt-1 text-sm font-medium text-slate-500">
                        {{ props.quotation ? 'تحديث الأصناف والأسعار في العرض المختار' : 'إضافة الأصناف والأسعار لتقديمها للعميل' }}
                    </p>
                </div>
                <Link href="/admin/quotations" class="text-sm font-bold text-slate-500 hover:text-slate-800"> العودة للسجل </Link>
            </div>

            <form @submit.prevent="submit" class="space-y-4">
                <div class="grid gap-6 rounded-2xl border-2 border-slate-300 bg-white p-5 shadow-sm sm:grid-cols-2 lg:grid-cols-3">
                    <div>
                        <label class="mb-2 block text-sm font-extrabold text-emerald-950">العميل</label>
                        <AsyncSelect
                            v-model="form.client_id"
                            api-url="/admin/api/search?type=clients"
                            placeholder="ابحث عن عميل..."
                            :initial-option="
                                quotation?.client_id
                                    ? { id: quotation.client_id, name: clients.find((c) => c.id === quotation?.client_id)?.name }
                                    : null
                            "
                            required
                        />
                        <p v-if="form.errors.client_id" class="mt-1 text-xs text-red-500">{{ form.errors.client_id }}</p>
                    </div>

                    <div>
                        <label class="mb-2 block text-sm font-extrabold text-emerald-950">القسم / الفرع</label>
                        <select
                            v-model="form.department_id"
                            required
                            class="w-full rounded-xl border-2 border-slate-300 px-4 py-3 text-sm font-bold shadow-sm transition-all focus:border-emerald-700 focus:ring-emerald-200"
                        >
                            <option value="" disabled>— اختر القسم —</option>
                            <option v-for="d in departments" :key="d.id" :value="d.id">{{ d.name }}</option>
                        </select>
                    </div>

                    <div>
                        <label class="mb-2 block text-sm font-extrabold text-emerald-950">تاريخ انتهاء الصلاحية</label>
                        <input
                            v-model="form.valid_until"
                            type="date"
                            class="w-full rounded-xl border-2 border-slate-300 px-4 py-3 text-sm font-bold shadow-sm focus:border-emerald-700"
                        />
                        <p v-if="form.errors.valid_until" class="mt-1 text-xs text-red-500">{{ form.errors.valid_until }}</p>
                    </div>
                </div>

                <!-- Items -->
                <div class="relative z-50 rounded-2xl border-2 border-slate-300 bg-white p-5 shadow-sm">
                    <div class="mb-4 flex items-center justify-between">
                        <h2 class="text-lg font-bold text-slate-800">بنود العرض</h2>
                        <div class="flex flex-wrap items-center gap-3">
                            <!-- المجموعات المحفوظة: أصناف تتكرّر معًا تُضاف دفعةً واحدة -->
                            <ItemGroupPicker :groups="groups" @select="addGroup" />

                            <div class="relative w-72">
                                <AsyncSelect
                                    v-model="selectedItemId"
                                    api-url="/admin/api/search?type=items"
                                    placeholder="إضافة صنف بالبحث..."
                                    @change="handleItemSelect"
                                    :clearable="false"
                                />
                            </div>
                        </div>
                    </div>

                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead class="bg-slate-50">
                                <tr>
                                    <th class="px-3 py-2 text-right font-extrabold text-[#064e3b]">البند</th>
                                    <th class="w-32 px-3 py-2 text-center font-extrabold text-[#064e3b]">الكمية</th>
                                    <th class="w-32 px-3 py-2 text-center font-extrabold text-[#064e3b]">السعر</th>
                                    <th v-if="vatApplies" class="w-32 px-3 py-2 text-center font-extrabold text-[#064e3b]">الضريبة</th>
                                    <th class="w-32 px-3 py-2 text-left font-extrabold text-[#064e3b]">الإجمالي</th>
                                    <th class="w-12 px-3 py-2 text-center font-extrabold text-[#064e3b]"></th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="(line, index) in form.items" :key="index" class="border-t border-slate-100">
                                    <td class="px-3 py-2 font-bold text-slate-700">{{ line.item?.name }}</td>
                                    <td class="px-3 py-2">
                                        <input
                                            v-model.number="line.quantity"
                                            type="number"
                                            min="0.001"
                                            step="any"
                                            class="w-full rounded-lg border-2 border-slate-300 px-2 py-1.5 text-center text-sm"
                                            required
                                        />
                                    </td>
                                    <td class="px-3 py-2">
                                        <input
                                            v-model.number="line.unit_price"
                                            type="number"
                                            min="0"
                                            step="0.01"
                                            class="w-full rounded-lg border-2 border-slate-300 px-2 py-1.5 text-center text-sm"
                                            required
                                        />
                                    </td>
                                    <td v-if="vatApplies" class="px-3 py-2 text-center text-xs font-bold text-slate-500" dir="ltr">
                                        {{ money(line.tax_amount) }}
                                    </td>
                                    <td class="px-3 py-2 text-left font-extrabold text-slate-900" dir="ltr">
                                        {{ money(line.quantity * line.unit_price + line.tax_amount) }}
                                    </td>
                                    <td class="px-3 py-2 text-center">
                                        <button type="button" @click="removeItem(index)" class="text-slate-400 hover:text-red-600">
                                            <Trash2 class="h-4 w-4" />
                                        </button>
                                    </td>
                                </tr>
                                <tr v-if="!form.items.length">
                                    <td :colspan="vatApplies ? 6 : 5" class="py-8 text-center text-sm text-slate-500">
                                        لا يوجد بنود في العرض. انقر "إضافة صنف" للبدء.
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <p v-if="lastGroup" class="mt-2 inline-block rounded-lg bg-emerald-100 px-2.5 py-1 text-xs font-bold text-emerald-900">
                        «{{ lastGroup.name }}»:
                        <template v-if="lastGroup.added">أُضيف {{ lastGroup.added }} صنف</template>
                        <template v-if="lastGroup.added && lastGroup.merged"> و</template>
                        <template v-if="lastGroup.merged">زادت كمية {{ lastGroup.merged }} صنف مضاف سلفًا</template>
                    </p>
                    <p v-if="form.errors.items" class="mt-2 text-xs font-bold text-red-500">{{ form.errors.items }}</p>
                </div>

                <!-- Totals and Notes -->
                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="rounded-2xl border-2 border-slate-300 bg-white p-5 shadow-sm">
                        <label class="mb-1.5 block text-sm font-bold text-slate-700">شروط العرض وملاحظات</label>
                        <textarea v-model="form.notes" rows="6" class="w-full rounded-xl border-2 border-slate-300 px-3 py-2.5 text-sm"></textarea>
                    </div>

                    <div class="rounded-2xl border-2 border-slate-300 bg-slate-50 p-5 shadow-sm">
                        <dl class="space-y-3 text-sm">
                            <div class="flex justify-between">
                                <dt class="font-bold text-slate-600">{{ vatApplies ? 'الإجمالي قبل الضريبة' : 'الإجمالي' }}</dt>
                                <dd class="font-extrabold text-slate-900" dir="ltr">{{ money(subtotal) }}</dd>
                            </div>
                            <div class="flex items-center justify-between border-t border-slate-200 pt-3">
                                <dt class="font-bold text-slate-600">الخصم الممنوح</dt>
                                <dd class="w-32">
                                    <input
                                        v-model.number="form.discount_amount"
                                        type="number"
                                        min="0"
                                        :max="subtotal"
                                        step="0.01"
                                        class="w-full rounded-lg border border-slate-300 px-2 py-1.5 text-center text-sm font-bold text-red-600"
                                    />
                                </dd>
                            </div>
                            <div v-if="vatApplies" class="flex justify-between border-t border-slate-200 pt-3">
                                <dt class="font-bold text-slate-600">ضريبة القيمة المضافة</dt>
                                <dd class="font-bold text-slate-600" dir="ltr">{{ money(totalTax) }}</dd>
                            </div>
                            <div class="flex justify-between rounded-xl bg-slate-800 px-3 py-2 text-white">
                                <dt class="font-extrabold">الإجمالي</dt>
                                <dd class="font-extrabold" dir="ltr">{{ money(grandTotal) }}</dd>
                            </div>
                        </dl>
                    </div>
                </div>

                <div class="flex items-center justify-end gap-4 border-t border-slate-200 pt-4">
                    <button
                        type="submit"
                        :disabled="form.processing || !form.items.length"
                        class="inline-flex items-center gap-2 rounded-xl bg-emerald-700 px-8 py-3 text-sm font-bold text-white shadow-sm transition-all hover:bg-emerald-800 focus:ring-4 focus:ring-emerald-200 disabled:opacity-60"
                    >
                        <FileText class="h-4 w-4" /> {{ props.quotation ? 'حفظ التعديلات' : 'حفظ عرض السعر' }}
                    </button>
                </div>
            </form>
        </div>
    </AppLayout>
</template>
