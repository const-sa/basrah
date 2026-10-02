<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link } from '@inertiajs/vue3';
import { Printer } from 'lucide-vue-next';

interface IssueLine { id: number; name: string | null; code: string | null; unit: string | null; quantity: number; unit_cost: number; total_cost: number }

const props = defineProps<{
    issue: {
        id: number; number: string; issue_date: string;
        recipient: string | null; position: string | null; department: string | null; contract: string | null;
        expense_account: string | null; total_cost: number;
        status: string; status_label: string; entry_number: string | null;
        user_name: string | null; cancelled_by: string | null; cancelled_at: string | null;
        notes: string | null; created_at: string;
        items: IssueLine[];
    };
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'لوحة التحكم', href: '/admin' },
    { title: 'أذونات الصرف', href: '/admin/inventory/issues' },
    { title: props.issue.number, href: `/admin/inventory/issues/${props.issue.id}` },
];

const money = (n: number) => new Intl.NumberFormat('ar-SA-u-nu-latn', { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(n ?? 0);

const print = () => window.print();
</script>

<template>
    <Head :title="`إذن صرف ${issue.number}`" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="min-h-full space-y-4 bg-slate-100 p-5 print:bg-white print:p-0">
            <div class="flex flex-wrap items-center justify-between gap-3 print:hidden">
                <h1 class="text-2xl font-extrabold text-slate-900">إذن صرف {{ issue.number }}</h1>
                <div class="flex gap-2">
                    <Link href="/admin/inventory/issues" class="rounded-md border border-slate-200 bg-white px-4 py-2 text-sm font-bold text-slate-700 hover:bg-slate-50">
                        رجوع
                    </Link>
                    <button type="button" class="inline-flex items-center gap-1.5 rounded-md bg-blue-600 px-4 py-2 text-sm font-bold text-white hover:bg-blue-700" @click="print">
                        <Printer class="h-4 w-4" /> طباعة
                    </button>
                </div>
            </div>

            <div class="mx-auto max-w-3xl rounded-2xl border border-slate-200 bg-white p-6 shadow-sm print:max-w-none print:border-0 print:shadow-none">
                <div class="mb-5 flex items-start justify-between border-b border-slate-200 pb-4">
                    <div>
                        <h2 class="text-xl font-extrabold text-slate-900">إذن صرف مخزني</h2>
                        <p class="mt-1 text-sm text-slate-500" dir="ltr">{{ issue.number }}</p>
                    </div>
                    <span
                        class="rounded-md px-3 py-1 text-xs font-bold"
                        :class="issue.status === 'cancelled' ? 'bg-red-100 text-red-700' : 'bg-emerald-100 text-emerald-700'"
                    >
                        {{ issue.status_label }}
                    </span>
                </div>

                <dl class="grid gap-x-6 gap-y-3 text-sm sm:grid-cols-2">
                    <div><dt class="text-xs text-slate-500">تاريخ الصرف</dt><dd class="font-bold text-slate-800" dir="ltr">{{ issue.issue_date }}</dd></div>
                    <div>
                        <dt class="text-xs text-slate-500">المستلم</dt>
                        <dd class="font-bold text-slate-800">{{ issue.recipient ?? '—' }}<span v-if="issue.position" class="font-medium text-slate-500"> ({{ issue.position }})</span></dd>
                    </div>
                    <div><dt class="text-xs text-slate-500">القسم</dt><dd class="font-bold text-slate-800">{{ issue.department ?? 'عام' }}</dd></div>
                    <div><dt class="text-xs text-slate-500">العقد</dt><dd class="font-bold text-slate-800">{{ issue.contract ?? '—' }}</dd></div>
                    <div><dt class="text-xs text-slate-500">حساب المصروف</dt><dd class="font-bold text-slate-800">{{ issue.expense_account ?? '—' }}</dd></div>
                    <div><dt class="text-xs text-slate-500">رقم القيد</dt><dd class="font-bold text-slate-800" dir="ltr">{{ issue.entry_number ?? '—' }}</dd></div>
                </dl>

                <table class="mt-5 w-full border-collapse text-sm">
                    <thead>
                        <tr class="bg-slate-100">
                            <th class="border border-slate-300 px-2 py-1.5 text-center">#</th>
                            <th class="border border-slate-300 px-2 py-1.5 text-right">الصنف</th>
                            <th class="border border-slate-300 px-2 py-1.5 text-center">الكمية</th>
                            <th class="border border-slate-300 px-2 py-1.5 text-center">تكلفة الوحدة</th>
                            <th class="border border-slate-300 px-2 py-1.5 text-center">الإجمالي</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="(l, index) in issue.items" :key="l.id">
                            <td class="border border-slate-300 px-2 py-1.5 text-center">{{ index + 1 }}</td>
                            <td class="border border-slate-300 px-2 py-1.5">
                                {{ l.name }} <span v-if="l.code" class="text-[11px] text-slate-500" dir="ltr">({{ l.code }})</span>
                            </td>
                            <td class="border border-slate-300 px-2 py-1.5 text-center">{{ l.quantity }} {{ l.unit }}</td>
                            <td class="border border-slate-300 px-2 py-1.5 text-center" dir="ltr">{{ money(l.unit_cost) }}</td>
                            <td class="border border-slate-300 px-2 py-1.5 text-center font-bold" dir="ltr">{{ money(l.total_cost) }}</td>
                        </tr>
                    </tbody>
                    <tfoot>
                        <tr class="bg-slate-50">
                            <td colspan="4" class="border border-slate-300 px-2 py-1.5 text-left font-extrabold">الإجمالي</td>
                            <td class="border border-slate-300 px-2 py-1.5 text-center font-extrabold" dir="ltr">{{ money(issue.total_cost) }}</td>
                        </tr>
                    </tfoot>
                </table>

                <p v-if="issue.notes" class="mt-4 text-sm text-slate-700"><b>ملاحظات:</b> {{ issue.notes }}</p>
                <p v-if="issue.status === 'cancelled'" class="mt-2 text-sm font-bold text-red-700">
                    أُلغي بواسطة {{ issue.cancelled_by ?? '—' }} في {{ issue.cancelled_at }}
                </p>

                <div class="mt-10 grid grid-cols-3 gap-6 text-center text-sm">
                    <div>
                        <div class="font-bold text-slate-700">أمين المخزن</div>
                        <div class="mt-1 text-xs text-slate-500">{{ issue.user_name ?? '' }}</div>
                        <div class="mt-8 border-t border-slate-400"></div>
                    </div>
                    <div>
                        <div class="font-bold text-slate-700">المستلم</div>
                        <div class="mt-1 text-xs text-slate-500">{{ issue.recipient ?? '' }}</div>
                        <div class="mt-8 border-t border-slate-400"></div>
                    </div>
                    <div>
                        <div class="font-bold text-slate-700">الاعتماد</div>
                        <div class="mt-1 text-xs text-slate-500">&nbsp;</div>
                        <div class="mt-8 border-t border-slate-400"></div>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
