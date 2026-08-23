<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import AsyncSelect from '@/components/AsyncSelect.vue';
import ItemQuickAdd from '@/components/ItemQuickAdd.vue';
import SupplierQuickAdd from '@/components/SupplierQuickAdd.vue';
import { type BreadcrumbItem, type PaymentMethodOption } from '@/types';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { Plus, Trash2, Save, X } from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';

interface ItemOption {
    id: number;
    code: string | null;
    name: string;
    category: string | null;
    price: number;
    cost: number;
    tax_rate: number;
}

const props = defineProps<{
    departments: { id: number; name: string }[];
    suppliers: { id: number; name: string }[];
    methods: PaymentMethodOption[];
    items: ItemOption[];
    itemTypes: { key: string; label: string }[];
    itemUnits: { key: string; label: string }[];
    defaultTaxRate: number;
    purchase?: {
        id: number;
        supplier_id: number;
        department_id: number;
        payment_method_id: number | null;
        paid_amount: number;
        discount_amount: number;
        notes: string | null;
        items: any[];
    };
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'لوحة التحكم', href: '/admin' },
    { title: 'سجل المشتريات', href: '/admin/purchases' },
    { title: props.purchase ? `تعديل فاتورة ${props.purchase.id}` : 'فاتورة مشتريات جديدة', href: '#' },
];

const money = (n: number) => new Intl.NumberFormat('ar-SA-u-nu-latn', { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(n ?? 0);
const qty = (n: number) => new Intl.NumberFormat('ar-SA-u-nu-latn', { maximumFractionDigits: 3 }).format(n ?? 0);

interface FormLine {
    item_id: number;
    item: ItemOption | null;
    quantity: number;
    unit_cost: number;
    tax_amount: number;
}

const form = useForm({
    supplier_id: props.purchase?.supplier_id ?? ('' as string | number),
    department_id: props.purchase?.department_id ?? (props.departments[0]?.id ?? ('' as string | number)),
    payment_method_id: props.purchase?.payment_method_id ?? (props.methods[0]?.id ?? ('' as string | number)),
    paid_amount: props.purchase?.paid_amount ?? 0,
    discount_amount: props.purchase?.discount_amount ?? 0,
    notes: props.purchase?.notes ?? '',
    items: props.purchase ? props.purchase.items.map(i => ({
        item_id: i.item_id,
        item: i.item,
        quantity: i.quantity,
        unit_cost: i.unit_cost,
        tax_amount: i.tax_amount
    })) : [] as FormLine[],
});

interface SupplierOption {
    id: number;
    name: string;
    mobile?: string | null;
}

/** Option shown in the supplier field — refreshed after a quick add so the name appears without a reload. */
const selectedSupplier = ref<SupplierOption | null>(
    props.purchase?.supplier_id
        ? { id: props.purchase.supplier_id, name: props.suppliers.find(s => s.id === props.purchase?.supplier_id)?.name ?? '' }
        : null,
);

const onSupplierCreated = (supplier: SupplierOption) => {
    selectedSupplier.value = supplier;
    form.supplier_id = supplier.id;
};

const selectedItemId = ref<number | string | null>(null);

const handleItemSelect = (item: any) => {
    if (item) {
        addItem(item);
        selectedItemId.value = null;
    }
};

/** A quick-added item lands on the invoice directly — that is the point of the shortcut. */
const addItem = (item: ItemOption) => {
    form.items.push({
        item_id: item.id,
        item: item,
        quantity: 1,
        unit_cost: item.cost,
        tax_amount: item.cost * (item.tax_rate / 100),
    });
};

const removeItem = (index: number) => {
    form.items.splice(index, 1);
};

// Calculations
watch([() => form.items, () => form.discount_amount], ([lines, discount]) => {
    const currentSubtotal = lines.reduce((sum, line) => sum + (line.quantity * line.unit_cost), 0);
    const discountRatio = currentSubtotal > 0 ? (discount / currentSubtotal) : 0;
    
    lines.forEach(line => {
        if (line.item) {
            const lineTotal = line.quantity * line.unit_cost;
            const discountedLineTotal = lineTotal - (lineTotal * discountRatio);
            line.tax_amount = discountedLineTotal * (line.item.tax_rate / 100);
        }
    });
}, { deep: true });

const subtotal = computed(() => form.items.reduce((sum, line) => sum + (line.quantity * line.unit_cost), 0));
const totalTax = computed(() => form.items.reduce((sum, line) => sum + line.tax_amount, 0));
const grandTotal = computed(() => subtotal.value - form.discount_amount + totalTax.value);

watch(grandTotal, (val) => {
    if (form.paid_amount > val) form.paid_amount = val;
});

const submit = () => {
    if (props.purchase) {
        form.put(`/admin/purchases/${props.purchase.id}`, { preserveScroll: true });
    } else {
        form.post('/admin/purchases', { preserveScroll: true });
    }
};
</script>

<template>
    <Head :title="props.purchase ? 'تعديل فاتورة مشتريات' : 'فاتورة مشتريات جديدة'" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="mx-auto w-full space-y-6 p-4 sm:p-6 lg:p-8">
            <div class="flex items-center justify-between border-b border-slate-200 pb-4">
                <div>
                    <h1 class="text-2xl font-extrabold text-slate-900 tracking-tight">
                        {{ props.purchase ? 'تعديل فاتورة المشتريات' : 'إصدار فاتورة مشتريات' }}
                    </h1>
                    <p class="mt-1 text-sm font-medium text-slate-500">
                        {{ props.purchase ? 'قم بتحديث بيانات الفاتورة والأصناف المشتراة' : 'قم بإدخال بيانات الفاتورة والأصناف المشتراة' }}
                    </p>
                </div>
                <Link href="/admin/purchases" class="inline-flex items-center justify-center rounded-xl bg-white px-4 py-2 text-sm font-bold text-slate-700 shadow-sm ring-1 ring-inset ring-slate-300 hover:bg-slate-50 transition-all">
                    العودة للسجل
                </Link>
            </div>

            <form @submit.prevent="submit" class="space-y-4">
                <!-- Basic Invoice Information -->
                <div class="rounded-2xl border-2 border-slate-300 bg-white shadow-sm overflow-hidden transition-all duration-200 hover:shadow-md">
                    <div class="bg-slate-50 px-5 py-3 border-b border-slate-200">
                        <h2 class="text-sm font-bold text-slate-800 flex items-center gap-2">
                            <span class="w-1.5 h-1.5 rounded-full bg-emerald-700"></span>
                            البيانات الأساسية
                        </h2>
                    </div>
                    <div class="grid gap-5 p-5 sm:grid-cols-2 lg:grid-cols-3">
                        <div>
                            <div class="mb-1.5 flex min-h-[24px] items-center justify-between gap-2">
                                <label class="block text-sm font-bold text-slate-700">المورد <span class="text-red-500">*</span></label>
                                <SupplierQuickAdd @created="onSupplierCreated" />
                            </div>
                            <AsyncSelect
                                v-model="form.supplier_id"
                                api-url="/admin/api/search?type=suppliers"
                                placeholder="ابحث عن مورد..."
                                :initial-option="selectedSupplier"
                                required
                            />
                            <p v-if="form.errors.supplier_id" class="mt-1.5 text-xs text-red-500 font-medium">{{ form.errors.supplier_id }}</p>
                        </div>

                        <div>
                            <label class="mb-1.5 flex min-h-[24px] items-center text-sm font-bold text-slate-700">القسم الوجهة (المستودع) <span class="text-red-500">&nbsp;*</span></label>
                            <select v-model="form.department_id" class="w-full rounded-xl border-slate-200 px-4 py-2.5 text-sm shadow-sm focus:border-emerald-600 focus:ring-emerald-600 transition-colors" required>
                                <option v-for="d in departments" :key="d.id" :value="d.id">{{ d.name }}</option>
                            </select>
                            <p v-if="form.errors.department_id" class="mt-1.5 text-xs text-red-500 font-medium">{{ form.errors.department_id }}</p>
                        </div>

                        <div>
                            <label class="mb-1.5 flex min-h-[24px] items-center text-sm font-bold text-slate-700">طريقة الدفع</label>
                            <select v-model="form.payment_method_id" class="w-full rounded-xl border-slate-200 px-4 py-2.5 text-sm shadow-sm focus:border-emerald-600 focus:ring-emerald-600 transition-colors">
                                <option v-for="m in methods" :key="m.id" :value="m.id">{{ m.label }}</option>
                            </select>
                            <p v-if="form.errors.payment_method_id" class="mt-1.5 text-xs text-red-500 font-medium">{{ form.errors.payment_method_id }}</p>
                        </div>
                    </div>
                </div>

                <!-- Items -->
                <div class="relative z-50 rounded-2xl border-2 border-slate-300 bg-white shadow-sm transition-all duration-200 hover:shadow-md">
                    <div class="flex items-center justify-between border-b border-slate-200 bg-slate-50 px-5 py-3 rounded-t-2xl">
                        <h2 class="text-sm font-bold text-slate-800 flex items-center gap-2">
                            <span class="w-1.5 h-1.5 rounded-full bg-emerald-500"></span>
                            الأصناف المشتراة
                        </h2>
                        <div class="flex items-center gap-2">
                            <div class="relative w-72">
                                <AsyncSelect
                                    v-model="selectedItemId"
                                    api-url="/admin/api/search?type=items"
                                    placeholder="إضافة صنف بالبحث..."
                                    @change="handleItemSelect"
                                    :clearable="false"
                                />
                            </div>
                            <ItemQuickAdd
                                :types="itemTypes"
                                :units="itemUnits"
                                :default-tax-rate="defaultTaxRate"
                                :department-id="form.department_id"
                                @created="addItem"
                            />
                        </div>
                    </div>

                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead class="bg-slate-50">
                                <tr>
                                    <th class="px-4 py-3 text-right font-extrabold text-[#064e3b] bg-emerald-100/50">الصنف</th>
                                    <th class="px-4 py-3 text-center font-extrabold text-[#064e3b] bg-emerald-100/50 w-32">الكمية</th>
                                    <th class="px-4 py-3 text-center font-extrabold text-[#064e3b] bg-emerald-100/50 w-36">تكلفة الوحدة</th>
                                    <th class="px-4 py-3 text-center font-extrabold text-[#064e3b] bg-emerald-100/50 w-32">الضريبة</th>
                                    <th class="px-4 py-3 text-left font-extrabold text-[#064e3b] bg-emerald-100/50 w-36">الإجمالي</th>
                                    <th class="px-4 py-3 text-center font-extrabold text-[#064e3b] bg-emerald-100/50 w-12"></th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="(line, index) in form.items" :key="index" class="border-t border-slate-100 transition-colors hover:bg-slate-50/50">
                                    <td class="px-4 py-3">
                                        <div class="font-bold text-slate-800">{{ line.item?.name }}</div>
                                        <div class="text-[10px] text-slate-500" dir="ltr" v-if="line.item?.code">{{ line.item.code }}</div>
                                    </td>
                                    <td class="px-4 py-3">
                                        <input v-model.number="line.quantity" type="number" min="0.001" step="any" class="w-full rounded-lg border-slate-200 px-3 py-1.5 text-center text-sm shadow-sm focus:border-emerald-600 focus:ring-emerald-600" required />
                                    </td>
                                    <td class="px-4 py-3">
                                        <input v-model.number="line.unit_cost" type="number" min="0" step="0.01" class="w-full rounded-lg border-slate-200 px-3 py-1.5 text-center text-sm shadow-sm focus:border-emerald-600 focus:ring-emerald-600" required />
                                    </td>
                                    <td class="px-4 py-3 text-center text-xs font-bold text-slate-500" dir="ltr">
                                        {{ money(line.tax_amount) }}
                                    </td>
                                    <td class="px-4 py-3 text-left font-extrabold text-slate-900" dir="ltr">
                                        {{ money((line.quantity * line.unit_cost) + line.tax_amount) }}
                                    </td>
                                    <td class="px-4 py-3 text-center">
                                        <button type="button" @click="removeItem(index)" class="rounded-lg p-1.5 text-slate-400 hover:bg-red-50 hover:text-red-600 transition-colors" title="إزالة الصنف"><Trash2 class="h-4 w-4" /></button>
                                    </td>
                                </tr>
                                <tr v-if="!form.items.length">
                                    <td colspan="6" class="py-12 text-center">
                                        <div class="flex flex-col items-center justify-center text-slate-400">
                                            <svg class="h-12 w-12 mb-3 text-slate-200" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 13V6a2 2 0 00-2-2H6a2 2 0 00-2 2v7m16 0v5a2 2 0 01-2 2H6a2 2 0 01-2-2v-5m16 0h-2.586a1 1 0 00-.707.293l-2.414 2.414a1 1 0 01-.707.293h-3.172a1 1 0 01-.707-.293l-2.414-2.414A1 1 0 006.586 13H4" />
                                            </svg>
                                            <p class="text-sm font-medium">لا يوجد أصناف في الفاتورة.</p>
                                            <p class="text-xs mt-1">انقر "إضافة صنف" للبدء بإدراج المشتريات.</p>
                                        </div>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <p v-if="form.errors.items" class="mt-2 text-xs font-bold text-red-500">{{ form.errors.items }}</p>
                    <p v-for="(err, key) in form.errors" :key="key" class="text-xs text-red-500 text-left" dir="ltr">
                        <span v-if="key.startsWith('items.')">{{ key }}: {{ err }}</span>
                    </p>
                </div>

                <!-- Totals and Notes -->
                <div class="grid gap-6 sm:grid-cols-12">
                    <div class="sm:col-span-7">
                        <div class="rounded-2xl border-2 border-slate-300 bg-white shadow-sm overflow-hidden h-full">
                            <div class="bg-slate-50 px-5 py-3 border-b border-slate-200">
                                <h2 class="text-sm font-bold text-slate-800 flex items-center gap-2">
                                    <span class="w-1.5 h-1.5 rounded-full bg-amber-500"></span>
                                    ملاحظات الفاتورة
                                </h2>
                            </div>
                            <div class="p-5">
                                <textarea v-model="form.notes" rows="6" class="w-full rounded-xl border-slate-200 px-4 py-3 text-sm shadow-sm focus:border-emerald-600 focus:ring-emerald-600 transition-colors" placeholder="أي تفاصيل أو شروط إضافية تخص هذه المشتريات..."></textarea>
                            </div>
                        </div>
                    </div>

                    <div class="sm:col-span-5">
                        <div class="rounded-2xl border border-emerald-900 bg-emerald-950 text-white shadow-lg overflow-hidden relative">
                            <!-- Background decoration -->
                            <div class="absolute top-0 left-0 w-32 h-32 bg-emerald-600/10 rounded-br-full pointer-events-none"></div>
                            
                            <div class="px-6 py-4 border-b border-emerald-900">
                                <h2 class="text-sm font-bold text-slate-300">ملخص التكاليف</h2>
                            </div>
                            
                            <div class="p-6">
                                <dl class="space-y-4 text-sm relative z-10">
                                    <div class="flex justify-between items-center text-slate-300">
                                        <dt class="font-medium">الإجمالي قبل الضريبة</dt>
                                        <dd class="font-bold text-white tracking-wide" dir="ltr">{{ money(subtotal) }}</dd>
                                    </div>
                                    <div class="flex items-center justify-between border-t border-emerald-900 pt-4">
                                        <dt class="font-medium text-slate-300">الخصم المكتسب</dt>
                                        <dd class="w-32">
                                            <input v-model.number="form.discount_amount" type="number" min="0" :max="subtotal" step="0.01" class="w-full rounded-lg border-slate-700 bg-slate-800/50 px-3 py-2 text-center text-sm font-bold text-red-400 placeholder-slate-600 focus:border-red-500 focus:ring-red-500 transition-colors" />
                                        </dd>
                                    </div>
                                    <div class="flex justify-between items-center border-t border-emerald-900 pt-4 text-slate-300">
                                        <dt class="font-medium">الضريبة</dt>
                                        <dd class="font-bold text-white tracking-wide" dir="ltr">{{ money(totalTax) }}</dd>
                                    </div>
                                    <div class="flex justify-between items-center rounded-xl bg-emerald-700/20 px-4 py-3 border border-emerald-600/30 text-emerald-200">
                                        <dt class="font-bold">الإجمالي المستحق</dt>
                                        <dd class="font-extrabold text-base tracking-wide" dir="ltr">{{ money(grandTotal) }}</dd>
                                    </div>
                                    <div class="flex items-center justify-between border-t border-emerald-900 pt-4">
                                        <dt class="font-bold text-emerald-400">المبلغ المدفوع</dt>
                                        <dd class="w-32">
                                            <input v-model.number="form.paid_amount" type="number" min="0" :max="grandTotal" step="0.01" class="w-full rounded-lg border-slate-700 bg-slate-800/50 px-3 py-2 text-center text-sm font-bold text-emerald-400 placeholder-slate-600 focus:border-emerald-500 focus:ring-emerald-500 transition-colors" />
                                        </dd>
                                    </div>
                                    <div class="flex justify-between items-center pt-2">
                                        <dt class="font-medium text-slate-400">المتبقي</dt>
                                        <dd class="font-extrabold tracking-wide" :class="grandTotal - form.paid_amount > 0 ? 'text-red-400' : 'text-slate-400'" dir="ltr">
                                            {{ money(grandTotal - form.paid_amount) }}
                                        </dd>
                                    </div>
                                </dl>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="flex items-center justify-end gap-4 pt-4 border-t border-slate-200">
                    <button type="submit" :disabled="form.processing || !form.items.length" class="inline-flex items-center gap-2 rounded-xl bg-emerald-700 px-8 py-3 text-sm font-bold text-white shadow-sm hover:bg-emerald-800 focus:ring-4 focus:ring-emerald-200 disabled:opacity-60 transition-all">
                        <Save class="h-4 w-4" /> {{ props.purchase ? 'حفظ التعديلات' : 'حفظ الفاتورة وتحديث المخزون' }}
                    </button>
                </div>
            </form>
        </div>
    </AppLayout>
</template>
