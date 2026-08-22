<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link } from '@inertiajs/vue3';
import { Printer, ChevronRight, FileText, CheckCircle2, Clock, XCircle } from 'lucide-vue-next';

interface QuotationItem {
    id: number;
    name: string;
    code: string | null;
    quantity: number;
    unit_price: number;
    tax_amount: number;
    total_price: number;
}

interface QuotationData {
    id: number;
    number: string;
    date: string;
    time: string;
    valid_until: string | null;
    client: string | null;
    client_mobile: string | null;
    user: string | null;
    subtotal: number;
    discount_amount: number;
    tax_amount: number;
    total: number;
    status: string;
    status_label: string;
    notes: string | null;
}

const props = defineProps<{
    quotation: QuotationData;
    items: QuotationItem[];
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'لوحة التحكم', href: '/admin' },
    { title: 'عروض الأسعار', href: '/admin/quotations' },
    { title: `عرض سعر رقم ${props.quotation.number}`, href: '#' },
];

const money = (n: number) => new Intl.NumberFormat('ar-SA-u-nu-latn', { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(n ?? 0);
const qty = (n: number) => new Intl.NumberFormat('ar-SA-u-nu-latn', { maximumFractionDigits: 3 }).format(n ?? 0);

const print = () => window.print();
</script>

<template>
    <Head :title="`عرض سعر ${quotation.number}`" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="mx-auto max-w-5xl space-y-6 p-4 sm:p-6 lg:p-8">
            <!-- Header Actions -->
            <div class="flex flex-wrap items-center justify-between gap-4 print:hidden">
                <div class="flex items-center gap-3">
                    <Link href="/admin/quotations" class="inline-flex h-10 w-10 items-center justify-center rounded-xl bg-white text-slate-500 shadow-sm ring-1 ring-inset ring-slate-200 hover:bg-slate-50 hover:text-slate-700 transition-all">
                        <ChevronRight class="h-5 w-5" />
                    </Link>
                    <div>
                        <h1 class="text-2xl font-extrabold text-slate-900 tracking-tight">عرض سعر <span class="text-emerald-700" dir="ltr">#{{ quotation.number }}</span></h1>
                        <p class="mt-0.5 text-sm font-medium text-slate-500">صالح حتى <span dir="ltr">{{ quotation.valid_until ?? 'غير محدد' }}</span></p>
                    </div>
                </div>
                <div class="flex items-center gap-2">
                    <button @click="print" class="inline-flex items-center gap-2 rounded-xl bg-white px-4 py-2.5 text-sm font-bold text-slate-700 shadow-sm ring-1 ring-inset ring-slate-300 hover:bg-slate-50 transition-all">
                        <Printer class="h-4 w-4 text-slate-500" />
                        <span>طباعة</span>
                    </button>
                    <Link :href="`/admin/quotations/${quotation.id}/edit`" class="inline-flex items-center gap-2 rounded-xl bg-emerald-700 px-4 py-2.5 text-sm font-bold text-white shadow-sm hover:bg-emerald-800 transition-all">
                        <FileText class="h-4 w-4" />
                        <span>تعديل العرض</span>
                    </Link>
                </div>
            </div>

            <!-- Invoice Paper -->
            <div class="relative overflow-hidden rounded-2xl bg-white shadow-xl ring-1 ring-slate-200 print:shadow-none print:ring-0">
                <div class="absolute top-0 left-0 right-0 h-2 bg-emerald-700 print:hidden"></div>
                
                <div class="p-8 sm:p-12">
                    <!-- Invoice Header Row -->
                    <div class="flex flex-col md:flex-row md:items-start justify-between gap-8 mb-10">
                        <div class="space-y-2">
                            <h2 class="text-3xl font-black text-slate-900 tracking-tight">عرض سعر</h2>
                            <p class="text-lg font-bold text-emerald-700" dir="ltr">{{ quotation.number }}</p>
                            <div class="mt-4 inline-flex items-center gap-1.5 rounded-full px-3 py-1 text-sm font-bold" :class="{
                                'bg-emerald-100 text-emerald-800': quotation.status === 'accepted',
                                'bg-amber-100 text-amber-800': quotation.status === 'pending',
                                'bg-red-100 text-red-800': quotation.status === 'rejected',
                            }">
                                <CheckCircle2 v-if="quotation.status === 'accepted'" class="h-4 w-4" />
                                <Clock v-else-if="quotation.status === 'pending'" class="h-4 w-4" />
                                <XCircle v-else class="h-4 w-4" />
                                <span>{{ quotation.status_label }}</span>
                            </div>
                        </div>

                        <div class="grid grid-cols-2 gap-x-8 gap-y-4 text-sm text-right">
                            <div>
                                <dt class="font-bold text-slate-500 mb-1">العميل</dt>
                                <dd class="font-extrabold text-slate-900">{{ quotation.client ?? '—' }}</dd>
                            </div>
                            <div>
                                <dt class="font-bold text-slate-500 mb-1">رقم التواصل</dt>
                                <dd class="font-extrabold text-slate-900" dir="ltr">{{ quotation.client_mobile ?? '—' }}</dd>
                            </div>
                            <div>
                                <dt class="font-bold text-slate-500 mb-1">تاريخ الإصدار</dt>
                                <dd class="font-extrabold text-slate-900" dir="ltr">{{ quotation.date }}</dd>
                            </div>
                            <div>
                                <dt class="font-bold text-slate-500 mb-1">صالح حتى</dt>
                                <dd class="font-extrabold text-slate-900" dir="ltr">{{ quotation.valid_until ?? '—' }}</dd>
                            </div>
                        </div>
                    </div>

                    <!-- Items Table -->
                    <div class="mt-8 overflow-hidden rounded-xl border border-slate-200">
                        <table class="w-full text-sm">
                            <thead class="bg-slate-50 border-b border-slate-200">
                                <tr>
                                    <th class="px-4 py-3 text-right font-extrabold text-slate-800">#</th>
                                    <th class="px-4 py-3 text-right font-extrabold text-slate-800">الصنف</th>
                                    <th class="px-4 py-3 text-center font-extrabold text-slate-800">الكمية</th>
                                    <th class="px-4 py-3 text-center font-extrabold text-slate-800">سعر الوحدة</th>
                                    <th class="px-4 py-3 text-center font-extrabold text-slate-800">الضريبة</th>
                                    <th class="px-4 py-3 text-left font-extrabold text-slate-800">الإجمالي</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-200 bg-white">
                                <tr v-for="(item, idx) in items" :key="item.id" class="hover:bg-slate-50/50">
                                    <td class="px-4 py-3 font-bold text-slate-500" dir="ltr">{{ idx + 1 }}</td>
                                    <td class="px-4 py-3 font-extrabold text-slate-900">{{ item.name }}</td>
                                    <td class="px-4 py-3 text-center font-bold text-slate-700" dir="ltr">{{ qty(item.quantity) }}</td>
                                    <td class="px-4 py-3 text-center font-bold text-slate-700" dir="ltr">{{ money(item.unit_price) }}</td>
                                    <td class="px-4 py-3 text-center font-bold text-slate-700" dir="ltr">{{ money(item.tax_amount) }}</td>
                                    <td class="px-4 py-3 text-left font-black text-slate-900" dir="ltr">{{ money(item.total_price) }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <!-- Summary and Notes -->
                    <div class="mt-8 flex flex-col md:flex-row md:items-start justify-between gap-8">
                        <div class="w-full md:w-1/2 space-y-6">
                            <div v-if="quotation.notes" class="rounded-xl bg-amber-50 p-4 border border-amber-200/50">
                                <h4 class="text-xs font-black uppercase tracking-wider text-amber-800 mb-2">ملاحظات / الشروط</h4>
                                <p class="text-sm font-bold text-amber-900 leading-relaxed">{{ quotation.notes }}</p>
                            </div>
                            
                            <div class="text-sm text-slate-500 font-medium">
                                <p>بواسطة: <span class="font-bold text-slate-700">{{ quotation.user ?? '—' }}</span></p>
                            </div>
                        </div>
                        
                        <div class="w-full md:w-1/3">
                            <div class="rounded-2xl bg-slate-50 p-6 border border-slate-200">
                                <dl class="space-y-3 text-sm">
                                    <div class="flex justify-between font-bold text-slate-600">
                                        <dt>المجموع (قبل الخصم والضريبة)</dt>
                                        <dd dir="ltr">{{ money(quotation.subtotal) }}</dd>
                                    </div>
                                    <div v-if="quotation.discount_amount > 0" class="flex justify-between font-bold text-red-600">
                                        <dt>الخصم</dt>
                                        <dd dir="ltr">-{{ money(quotation.discount_amount) }}</dd>
                                    </div>
                                    <div class="flex justify-between font-bold text-slate-600">
                                        <dt>الضريبة</dt>
                                        <dd dir="ltr">{{ money(quotation.tax_amount) }}</dd>
                                    </div>
                                    
                                    <div class="my-4 border-t border-slate-200"></div>
                                    
                                    <div class="flex justify-between items-center text-lg">
                                        <dt class="font-black text-slate-900">الإجمالي النهائي</dt>
                                        <dd class="font-black text-emerald-700" dir="ltr">{{ money(quotation.total) }}</dd>
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
    body { background-color: white !important; }
    #app-sidebar, #app-header { display: none !important; }
}
</style>
