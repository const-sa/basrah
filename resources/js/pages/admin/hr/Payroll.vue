<script setup lang="ts">
import { usePermissions } from '@/composables/usePermissions';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { CheckCircle2, ChevronDown, Play } from 'lucide-vue-next';
import { ref } from 'vue';

interface Line {
    employee_name: string | null;
    basic_salary: number; allowances: number; overtime_amount: number; bonus: number;
    absence_deduction: number; advance_deduction: number;
    worked_days: number; absent_days: number; gross: number; net: number;
}
interface Payroll {
    id: number; number: string; year: number; month: number; period_label: string;
    status: string; status_label: string;
    total_gross: number; total_deductions: number; total_net: number;
    lines_count: number; lines: Line[];
}

const props = defineProps<{
    payrolls: { data: Payroll[]; links: { url: string | null; label: string; active: boolean }[] };
    months: { key: number; label: string }[];
    currentYear: number;
    currentMonth: number;
}>();

const { can } = usePermissions();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'لوحة التحكم', href: '/admin' },
    { title: 'الرواتب', href: '/admin/hr/payroll' },
];

const money = (n: number) => new Intl.NumberFormat('ar-SA-u-nu-latn', { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(n ?? 0);

const expanded = ref<Set<number>>(new Set());
const toggle = (id: number) => {
    const s = new Set(expanded.value);
    s.has(id) ? s.delete(id) : s.add(id);
    expanded.value = s;
};

const form = useForm({ year: props.currentYear, month: props.currentMonth });
const generate = () => form.post('/admin/hr/payroll/generate', { preserveScroll: true });

const approve = (p: Payroll) => {
    if (confirm(`اعتماد مسيّر ${p.period_label}؟ سيُرحَّل القيد المحاسبي وتُستقطع السلف.`)) {
        router.post(`/admin/hr/payroll/${p.id}/approve`, {}, { preserveScroll: true });
    }
};
</script>

<template>
    <Head title="مسيّر الرواتب" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="min-h-full space-y-4 bg-slate-100 p-5">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h1 class="text-2xl font-extrabold text-slate-900">مسيّر الرواتب</h1>
                    <p class="mt-1 text-sm font-medium text-slate-600">أساسي + بدلات + إضافي + مكافآت − غياب − سلف</p>
                </div>
                <Link href="/admin/hr/staff" class="rounded-md border border-slate-200 bg-white px-4 py-2 text-sm font-bold text-slate-700 hover:bg-slate-50">ملفات الموظفين</Link>
            </div>

            <div v-if="can('payroll.create')" class="flex flex-wrap items-end gap-3 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
                <div>
                    <label class="mb-1 block text-sm font-bold text-slate-700">السنة</label>
                    <input v-model.number="form.year" type="number" min="2020" max="2100" class="w-28 rounded-xl border border-slate-200 px-3 py-2 text-sm" />
                </div>
                <div>
                    <label class="mb-1 block text-sm font-bold text-slate-700">الشهر</label>
                    <select v-model.number="form.month" class="rounded-xl border border-slate-200 px-3 py-2 text-sm">
                        <option v-for="m in months" :key="m.key" :value="m.key">{{ m.label }}</option>
                    </select>
                </div>
                <button type="button" @click="generate" :disabled="form.processing" class="inline-flex items-center gap-1.5 rounded-md bg-blue-600 px-5 py-2 text-sm font-bold text-white hover:bg-blue-700 disabled:opacity-60">
                    <Play class="h-4 w-4" /> توليد المسيّر
                </button>
                <p class="text-[11px] font-medium text-slate-500">إعادة التوليد تستبدل السطور ما دام المسيّر مسوّدة.</p>
            </div>

            <div class="space-y-2">
                <div v-for="p in payrolls.data" :key="p.id" class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                    <div class="flex flex-wrap items-center gap-3 px-4 py-3">
                        <button type="button" @click="toggle(p.id)" class="flex min-w-0 flex-1 items-center gap-3 text-right">
                            <ChevronDown class="h-4 w-4 shrink-0 text-slate-400 transition" :class="expanded.has(p.id) && 'rotate-180'" />
                            <span class="font-extrabold text-slate-900">{{ p.period_label }}</span>
                            <span class="font-mono text-[11px] text-slate-500" dir="ltr">{{ p.number }}</span>
                            <span class="rounded-md px-2 py-0.5 text-[11px] font-bold" :class="p.status === 'draft' ? 'bg-amber-100 text-amber-700' : 'bg-emerald-100 text-emerald-700'">{{ p.status_label }}</span>
                            <span class="text-xs text-slate-500">{{ p.lines_count }} موظف</span>
                        </button>

                        <div class="flex items-center gap-4 text-sm">
                            <span class="text-slate-600">الإجمالي <b class="text-slate-800" dir="ltr">{{ money(p.total_gross) }}</b></span>
                            <span class="text-red-600">الخصومات <b dir="ltr">{{ money(p.total_deductions) }}</b></span>
                            <span class="font-extrabold text-emerald-600">الصافي <span dir="ltr">{{ money(p.total_net) }}</span></span>

                            <button v-if="can('payroll.approve') && p.status === 'draft'" type="button" @click="approve(p)" class="inline-flex items-center gap-1 rounded-lg bg-emerald-500 px-3 py-1.5 text-[11px] font-bold text-white hover:bg-emerald-600">
                                <CheckCircle2 class="h-3.5 w-3.5" /> اعتماد
                            </button>
                        </div>
                    </div>

                    <div v-show="expanded.has(p.id)" class="overflow-x-auto border-t border-slate-100">
                        <table class="w-full text-xs">
                            <thead class="bg-slate-50">
                                <tr>
                                    <th class="px-4 py-2 text-right font-extrabold text-[#1e3a8a]">الموظف</th>
                                    <th class="px-3 py-2 text-center font-extrabold text-[#1e3a8a]">أيام</th>
                                    <th class="px-3 py-2 text-left font-extrabold text-[#1e3a8a]">الأساسي</th>
                                    <th class="px-3 py-2 text-left font-extrabold text-[#1e3a8a]">البدلات</th>
                                    <th class="px-3 py-2 text-left font-extrabold text-[#1e3a8a]">إضافي</th>
                                    <th class="px-3 py-2 text-left font-extrabold text-[#1e3a8a]">مكافآت</th>
                                    <th class="px-3 py-2 text-left font-extrabold text-[#1e3a8a]">غياب</th>
                                    <th class="px-3 py-2 text-left font-extrabold text-[#1e3a8a]">سلف</th>
                                    <th class="px-3 py-2 text-left font-extrabold text-[#1e3a8a]">الصافي</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="(l, i) in p.lines" :key="i" class="border-t border-slate-50">
                                    <td class="px-4 py-1.5 font-bold text-slate-700">{{ l.employee_name }}</td>
                                    <td class="px-3 py-1.5 text-center">
                                        <span class="text-slate-700">{{ l.worked_days }}</span>
                                        <span v-if="l.absent_days" class="text-red-600"> (−{{ l.absent_days }})</span>
                                    </td>
                                    <td class="px-3 py-1.5 text-left text-slate-700" dir="ltr">{{ money(l.basic_salary) }}</td>
                                    <td class="px-3 py-1.5 text-left text-slate-700" dir="ltr">{{ money(l.allowances) }}</td>
                                    <td class="px-3 py-1.5 text-left text-emerald-600" dir="ltr">{{ l.overtime_amount ? money(l.overtime_amount) : '—' }}</td>
                                    <td class="px-3 py-1.5 text-left text-emerald-700" dir="ltr">{{ l.bonus ? money(l.bonus) : '—' }}</td>
                                    <td class="px-3 py-1.5 text-left text-red-600" dir="ltr">{{ l.absence_deduction ? money(l.absence_deduction) : '—' }}</td>
                                    <td class="px-3 py-1.5 text-left text-red-600" dir="ltr">{{ l.advance_deduction ? money(l.advance_deduction) : '—' }}</td>
                                    <td class="px-3 py-1.5 text-left font-extrabold text-slate-900" dir="ltr">{{ money(l.net) }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <p v-if="!payrolls.data.length" class="rounded-2xl bg-white py-10 text-center text-sm font-medium text-slate-500">لا مسيّرات — ولّد مسيّر الشهر أعلاه.</p>
            </div>
        </div>
    </AppLayout>
</template>
