<script setup lang="ts">
import { useVat } from '@/composables/useVat';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link } from '@inertiajs/vue3';
import { CheckCircle2, ChevronRight, Clock, FileText, Printer, XCircle } from 'lucide-vue-next';
import { computed } from 'vue';

interface PurchaseItem {
    id: number;
    name: string;
    code: string | null;
    quantity: number;
    unit_cost: number;
    tax_amount: number;
    total_cost: number;
}

interface PurchaseData {
    id: number;
    number: string;
    date: string;
    time: string;
    supplier: string | null;
    department: string | null;
    method_label: string;
    subtotal: number;
    discount_amount: number;
    tax_amount: number;
    is_taxable: boolean;
    total: number;
    paid: number;
    remaining: number;
    status: string;
    status_label: string;
    notes: string | null;
    user: string | null;
}

const props = defineProps<{
    purchase: PurchaseData;
    items: PurchaseItem[];
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'لوحة التحكم', href: '/admin' },
    { title: 'المشتريات', href: '/admin/purchases' },
    { title: `فاتورة رقم ${props.purchase.number}`, href: '#' },
];

const money = (n: number) => new Intl.NumberFormat('ar-SA-u-nu-latn', { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(n ?? 0);
const qty = (n: number) => new Intl.NumberFormat('ar-SA-u-nu-latn', { maximumFractionDigits: 3 }).format(n ?? 0);

// الورقة تُقرأ بما حُرِّرت به: فاتورةٌ حُصّلت ضريبتها تبقى سطورها بعد إطفاء
// المفتاح، وإلا أنكر النظام مبلغًا أُخذ فعلًا.
const { shows } = useVat();

// جواب الفاتورة نفسها أوّلًا، ثم أن يكون للضريبة موضعٌ على الشاشة أصلًا.
const showsTax = computed(() => props.purchase.is_taxable && shows(props.purchase.tax_amount));

const print = () => window.print();
</script>

<template>
    <Head :title="`فاتورة مشتريات ${purchase.number}`" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="mx-auto max-w-5xl space-y-6 p-4 sm:p-6 lg:p-8">
            <!-- Header Actions -->
            <div class="flex flex-wrap items-center justify-between gap-4 print:hidden">
                <div class="flex items-center gap-3">
                    <Link
                        href="/admin/purchases"
                        class="inline-flex h-10 w-10 items-center justify-center rounded-xl bg-white text-slate-500 shadow-sm ring-1 ring-inset ring-slate-200 transition-all hover:bg-slate-50 hover:text-slate-700"
                    >
                        <ChevronRight class="h-5 w-5" />
                    </Link>
                    <div>
                        <h1 class="text-2xl font-extrabold tracking-tight text-slate-900">
                            فاتورة مشتريات <span class="text-emerald-700" dir="ltr">#{{ purchase.number }}</span>
                        </h1>
                        <p class="mt-0.5 text-sm font-medium text-slate-500">
                            تم إصدارها في <span dir="ltr">{{ purchase.date }}</span>
                        </p>
                    </div>
                </div>
                <div class="flex items-center gap-2">
                    <button
                        @click="print"
                        class="inline-flex items-center gap-2 rounded-xl bg-white px-4 py-2.5 text-sm font-bold text-slate-700 shadow-sm ring-1 ring-inset ring-slate-300 transition-all hover:bg-slate-50"
                    >
                        <Printer class="h-4 w-4 text-slate-500" />
                        <span>طباعة</span>
                    </button>
                    <Link
                        :href="`/admin/purchases/${purchase.id}/edit`"
                        class="inline-flex items-center gap-2 rounded-xl bg-emerald-700 px-4 py-2.5 text-sm font-bold text-white shadow-sm transition-all hover:bg-emerald-800"
                    >
                        <FileText class="h-4 w-4" />
                        <span>تعديل الفاتورة</span>
                    </Link>
                </div>
            </div>

            <!-- Invoice Paper -->
            <div class="relative overflow-hidden rounded-2xl bg-white shadow-xl ring-1 ring-slate-200 print:shadow-none print:ring-0">
                <div class="absolute left-0 right-0 top-0 h-2 bg-emerald-700 print:hidden"></div>

                <div class="p-8 sm:p-12">
                    <!-- Invoice Header Row -->
                    <div class="mb-10 flex flex-col justify-between gap-8 md:flex-row md:items-start">
                        <div class="space-y-2">
                            <h2 class="text-3xl font-black tracking-tight text-slate-900">فاتورة مشتريات</h2>
                            <p class="text-lg font-bold text-emerald-700" dir="ltr">{{ purchase.number }}</p>
                            <div
                                class="mt-4 inline-flex items-center gap-1.5 rounded-full px-3 py-1 text-sm font-bold"
                                :class="{
                                    'bg-emerald-100 text-emerald-800': purchase.status === 'paid',
                                    'bg-amber-100 text-amber-800': purchase.status === 'partial',
                                    'bg-red-100 text-red-800': purchase.status === 'unpaid',
                                }"
                            >
                                <CheckCircle2 v-if="purchase.status === 'paid'" class="h-4 w-4" />
                                <Clock v-else-if="purchase.status === 'partial'" class="h-4 w-4" />
                                <XCircle v-else class="h-4 w-4" />
                                <span>{{ purchase.status_label }}</span>
                            </div>
                        </div>

                        <div class="grid grid-cols-2 gap-x-8 gap-y-4 text-right text-sm">
                            <div>
                                <dt class="mb-1 font-bold text-slate-500">المورد</dt>
                                <dd class="font-extrabold text-slate-900">{{ purchase.supplier ?? '—' }}</dd>
                            </div>
                            <div>
                                <dt class="mb-1 font-bold text-slate-500">القسم</dt>
                                <dd class="font-extrabold text-slate-900">{{ purchase.department ?? '—' }}</dd>
                            </div>
                            <div>
                                <dt class="mb-1 font-bold text-slate-500">التاريخ</dt>
                                <dd class="font-extrabold text-slate-900" dir="ltr">{{ purchase.date }}</dd>
                            </div>
                            <div>
                                <dt class="mb-1 font-bold text-slate-500">الوقت</dt>
                                <dd class="font-extrabold text-slate-900" dir="ltr">{{ purchase.time }}</dd>
                            </div>
                        </div>
                    </div>

                    <!-- Items Table -->
                    <div class="mt-8 overflow-hidden rounded-xl border border-slate-200">
                        <table class="w-full text-sm">
                            <thead class="border-b border-slate-200 bg-slate-50">
                                <tr>
                                    <th class="px-4 py-3 text-right font-extrabold text-slate-800">#</th>
                                    <th class="px-4 py-3 text-right font-extrabold text-slate-800">الصنف</th>
                                    <th class="px-4 py-3 text-center font-extrabold text-slate-800">الكمية</th>
                                    <th class="px-4 py-3 text-center font-extrabold text-slate-800">تكلفة الوحدة</th>
                                    <th v-if="showsTax" class="px-4 py-3 text-center font-extrabold text-slate-800">الضريبة</th>
                                    <th class="px-4 py-3 text-left font-extrabold text-slate-800">الإجمالي</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-200 bg-white">
                                <tr v-for="(item, idx) in items" :key="item.id" class="hover:bg-slate-50/50">
                                    <td class="px-4 py-3 font-bold text-slate-500" dir="ltr">{{ idx + 1 }}</td>
                                    <td class="px-4 py-3 font-extrabold text-slate-900">{{ item.name }}</td>
                                    <td class="px-4 py-3 text-center font-bold text-slate-700" dir="ltr">{{ qty(item.quantity) }}</td>
                                    <td class="px-4 py-3 text-center font-bold text-slate-700" dir="ltr">{{ money(item.unit_cost) }}</td>
                                    <td
                                        v-if="showsTax"
                                        class="px-4 py-3 text-center font-bold"
                                        :class="purchase.is_taxable ? 'text-slate-700' : 'text-slate-300'"
                                        dir="ltr"
                                    >
                                        {{ purchase.is_taxable ? money(item.tax_amount) : '—' }}
                                    </td>
                                    <td class="px-4 py-3 text-left font-black text-slate-900" dir="ltr">{{ money(item.total_cost) }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <!-- Summary and Notes -->
                    <div class="mt-8 flex flex-col justify-between gap-8 md:flex-row md:items-start">
                        <div class="w-full space-y-6 md:w-1/2">
                            <div v-if="purchase.notes" class="rounded-xl border border-amber-200/50 bg-amber-50 p-4">
                                <h4 class="mb-2 text-xs font-black uppercase tracking-wider text-amber-800">ملاحظات</h4>
                                <p class="text-sm font-bold leading-relaxed text-amber-900">{{ purchase.notes }}</p>
                            </div>

                            <div class="text-sm font-medium text-slate-500">
                                <p>
                                    بواسطة: <span class="font-bold text-slate-700">{{ purchase.user ?? '—' }}</span>
                                </p>
                            </div>
                        </div>

                        <div class="w-full md:w-1/3">
                            <div class="rounded-2xl border border-slate-200 bg-slate-50 p-6">
                                <dl class="space-y-3 text-sm">
                                    <div class="flex justify-between font-bold text-slate-600">
                                        <dt>{{ showsTax ? 'المجموع (قبل الخصم والضريبة)' : 'المجموع (قبل الخصم)' }}</dt>
                                        <dd dir="ltr">{{ money(purchase.subtotal) }}</dd>
                                    </div>
                                    <div v-if="purchase.discount_amount > 0" class="flex justify-between font-bold text-red-600">
                                        <dt>الخصم</dt>
                                        <dd dir="ltr">-{{ money(purchase.discount_amount) }}</dd>
                                    </div>
                                    <div v-if="showsTax" class="flex justify-between font-bold text-slate-600">
                                        <dt>الضريبة</dt>
                                        <dd v-if="purchase.is_taxable" dir="ltr">{{ money(purchase.tax_amount) }}</dd>
                                        <dd v-else class="text-xs text-slate-400">فاتورة بدون ضريبة</dd>
                                    </div>

                                    <div class="my-4 border-t border-slate-200"></div>

                                    <div class="flex items-center justify-between text-lg">
                                        <dt class="font-black text-slate-900">الإجمالي النهائي</dt>
                                        <dd class="font-black text-emerald-700" dir="ltr">{{ money(purchase.total) }}</dd>
                                    </div>

                                    <div class="my-4 border-t border-slate-200"></div>

                                    <div class="flex justify-between font-bold text-emerald-600">
                                        <dt>المدفوع ({{ purchase.method_label }})</dt>
                                        <dd dir="ltr">{{ money(purchase.paid) }}</dd>
                                    </div>
                                    <div class="flex justify-between font-bold" :class="purchase.remaining > 0 ? 'text-red-600' : 'text-slate-400'">
                                        <dt>المتبقي</dt>
                                        <dd dir="ltr">{{ money(purchase.remaining) }}</dd>
                                    </div>
                                </dl>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Footer pattern -->
                <div class="h-4 w-full bg-emerald-900/5 print:hidden"></div>
            </div>
        </div>
    </AppLayout>
</template>

<style>
@media print {
    body {
        background-color: white !important;
    }
    #app-sidebar,
    #app-header {
        display: none !important;
    }
}
</style>
