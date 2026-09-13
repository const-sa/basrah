<script setup lang="ts">
import { StatPill } from '@/components/data-table';
import { usePermissions } from '@/composables/usePermissions';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, router, useForm } from '@inertiajs/vue3';
import { CheckCircle2, Link2, Trash2, Upload, X } from 'lucide-vue-next';
import { computed, ref } from 'vue';

interface Line {
    id: number; date: string; description: string | null; amount: number;
    reference: string | null; matched: boolean; matched_label: string | null;
}

interface SystemTxn { kind: 'voucher' | 'expense'; id: number; date: string; label: string; amount: number }

const props = defineProps<{
    treasuries: { id: number; name: string }[];
    selectedTreasuryId: number | null;
    bookBalance: number | null;
    lines: Line[];
    unmatchedSystem: SystemTxn[];
    imports: { id: number; original_filename: string; created_at: string }[];
}>();

const { can } = usePermissions();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'لوحة التحكم', href: '/admin' },
    { title: 'التسوية البنكية', href: '/admin/accounting/bank-reconciliation' },
];

const money = (n: number) => new Intl.NumberFormat('ar-SA-u-nu-latn', { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(n ?? 0);

const treasuryId = ref(props.selectedTreasuryId);
const changeTreasury = () => router.get('/admin/accounting/bank-reconciliation', { treasury_id: treasuryId.value }, { preserveState: true, replace: true });

const unmatchedLines = computed(() => props.lines.filter((l) => !l.matched));
const matchedLines = computed(() => props.lines.filter((l) => l.matched));

const showImportModal = ref(false);
const importForm = useForm<{ treasury_id: number | null; file: File | null }>({ treasury_id: treasuryId.value, file: null });
const onFileChange = (e: Event) => (importForm.file = (e.target as HTMLInputElement).files?.[0] ?? null);
const submitImport = () => {
    importForm.treasury_id = treasuryId.value;
    importForm.post('/admin/accounting/bank-reconciliation/import', {
        preserveScroll: true,
        forceFormData: true,
        onSuccess: () => (showImportModal.value = false),
    });
};

const picks = ref<Record<number, string>>({});
const matchLine = (line: Line) => {
    const pick = picks.value[line.id];
    if (!pick) return;
    const [kind, id] = pick.split(':');
    router.post(
        `/admin/accounting/bank-reconciliation/lines/${line.id}/match`,
        kind === 'voucher' ? { voucher_id: id } : { expense_id: id },
        { preserveScroll: true },
    );
};

const unmatchLine = (line: Line) => router.post(`/admin/accounting/bank-reconciliation/lines/${line.id}/unmatch`, {}, { preserveScroll: true });

const deleteImport = (id: number) => {
    if (confirm('حذف هذا الاستيراد بكل حركاته غير المطابَقة؟')) {
        router.delete(`/admin/accounting/bank-reconciliation/imports/${id}`, { preserveScroll: true });
    }
};
</script>

<template>
    <Head title="التسوية البنكية" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="min-h-full space-y-4 bg-slate-100 p-5">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h1 class="text-2xl font-extrabold text-slate-900">التسوية البنكية</h1>
                    <p class="mt-1 text-sm font-medium text-slate-600">استيراد كشف البنك ومطابقته بالسندات والمصروفات المرحَّلة</p>
                </div>
                <button v-if="can('bank_reconciliation.create')" type="button" @click="showImportModal = true" class="inline-flex items-center gap-1.5 rounded-md bg-blue-600 px-4 py-2 text-sm font-bold text-white hover:bg-blue-700">
                    <Upload class="h-4 w-4" /> استيراد كشف
                </button>
            </div>

            <div class="rounded-2xl border border-slate-200 bg-white p-3 shadow-sm">
                <select v-model="treasuryId" @change="changeTreasury" class="rounded-xl border border-slate-200 px-3 py-2.5 text-sm sm:w-64">
                    <option v-for="t in treasuries" :key="t.id" :value="t.id">{{ t.name }}</option>
                </select>
            </div>

            <div v-if="bookBalance !== null" class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                <StatPill label="رصيد الدفاتر" :value="money(bookBalance)" />
                <StatPill label="حركات غير مطابَقة (بنك)" :value="unmatchedLines.length" variant="warning" />
                <StatPill label="حركات نظام غير مطابَقة" :value="unmatchedSystem.length" variant="warning" />
            </div>

            <!-- الاستيرادات -->
            <div v-if="imports.length" class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                <div class="border-b border-slate-100 px-4 py-3"><h2 class="text-sm font-extrabold text-slate-800">آخر الاستيرادات</h2></div>
                <ul class="divide-y divide-slate-100">
                    <li v-for="imp in imports" :key="imp.id" class="flex items-center justify-between px-4 py-2.5 text-sm">
                        <span class="font-bold text-slate-700">{{ imp.original_filename }}</span>
                        <div class="flex items-center gap-3">
                            <span class="text-xs text-slate-500" dir="ltr">{{ imp.created_at }}</span>
                            <button v-if="can('bank_reconciliation.delete')" type="button" @click="deleteImport(imp.id)" title="حذف" class="text-red-500 hover:text-red-700">
                                <Trash2 class="h-3.5 w-3.5" />
                            </button>
                        </div>
                    </li>
                </ul>
            </div>

            <!-- حركات كشف البنك غير المطابَقة -->
            <div class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                <div class="border-b border-slate-100 px-4 py-3">
                    <h2 class="font-extrabold text-slate-800">حركات كشف البنك غير المطابَقة</h2>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="bg-slate-50">
                            <tr>
                                <th class="px-4 py-2.5 text-right text-xs font-extrabold text-[#1e3a8a]">التاريخ</th>
                                <th class="px-4 py-2.5 text-right text-xs font-extrabold text-[#1e3a8a]">البيان</th>
                                <th class="px-4 py-2.5 text-left text-xs font-extrabold text-[#1e3a8a]">المبلغ</th>
                                <th class="px-4 py-2.5 text-right text-xs font-extrabold text-[#1e3a8a]">طابِق بحركة النظام</th>
                                <th class="px-4 py-2.5 text-center text-xs font-extrabold text-[#1e3a8a]"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="l in unmatchedLines" :key="l.id" class="border-t border-slate-100">
                                <td class="px-4 py-2.5 text-xs text-slate-600" dir="ltr">{{ l.date }}</td>
                                <td class="px-4 py-2.5 text-slate-700">{{ l.description ?? '—' }}</td>
                                <td class="px-4 py-2.5 text-left font-bold" :class="l.amount >= 0 ? 'text-emerald-600' : 'text-red-600'" dir="ltr">{{ money(l.amount) }}</td>
                                <td class="px-4 py-2.5">
                                    <select v-model="picks[l.id]" class="w-full rounded-lg border border-slate-200 px-2 py-1.5 text-xs">
                                        <option value="">—</option>
                                        <option v-for="s in unmatchedSystem" :key="`${s.kind}:${s.id}`" :value="`${s.kind}:${s.id}`">{{ s.label }} ({{ money(s.amount) }})</option>
                                    </select>
                                </td>
                                <td class="px-4 py-2.5 text-center">
                                    <button v-if="can('bank_reconciliation.edit')" type="button" @click="matchLine(l)" :disabled="!picks[l.id]" title="مطابقة" class="rounded-lg bg-emerald-500 p-1.5 text-white hover:bg-emerald-600 disabled:opacity-40">
                                        <Link2 class="h-3.5 w-3.5" />
                                    </button>
                                </td>
                            </tr>
                            <tr v-if="!unmatchedLines.length"><td colspan="5" class="px-4 py-10 text-center text-sm text-slate-500">لا حركات غير مطابَقة</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- حركات مطابَقة -->
            <div v-if="matchedLines.length" class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                <div class="border-b border-slate-100 px-4 py-3"><h2 class="font-extrabold text-slate-800">حركات مطابَقة</h2></div>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="bg-slate-50">
                            <tr>
                                <th class="px-4 py-2.5 text-right text-xs font-extrabold text-[#1e3a8a]">التاريخ</th>
                                <th class="px-4 py-2.5 text-right text-xs font-extrabold text-[#1e3a8a]">البيان</th>
                                <th class="px-4 py-2.5 text-left text-xs font-extrabold text-[#1e3a8a]">المبلغ</th>
                                <th class="px-4 py-2.5 text-right text-xs font-extrabold text-[#1e3a8a]">مطابَق بـ</th>
                                <th class="px-4 py-2.5 text-center text-xs font-extrabold text-[#1e3a8a]"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="l in matchedLines" :key="l.id" class="border-t border-slate-100">
                                <td class="px-4 py-2.5 text-xs text-slate-600" dir="ltr">{{ l.date }}</td>
                                <td class="px-4 py-2.5 text-slate-700">{{ l.description ?? '—' }}</td>
                                <td class="px-4 py-2.5 text-left font-bold" dir="ltr">{{ money(l.amount) }}</td>
                                <td class="px-4 py-2.5 text-xs font-bold text-emerald-700" dir="ltr">
                                    <CheckCircle2 class="me-1 inline h-3.5 w-3.5" />{{ l.matched_label }}
                                </td>
                                <td class="px-4 py-2.5 text-center">
                                    <button v-if="can('bank_reconciliation.edit')" type="button" @click="unmatchLine(l)" title="فك المطابقة" class="rounded-lg bg-slate-200 p-1.5 text-slate-600 hover:bg-slate-300">
                                        <X class="h-3.5 w-3.5" />
                                    </button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div v-if="showImportModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4" @click.self="showImportModal = false">
            <div class="w-full max-w-sm rounded-2xl bg-white shadow-2xl">
                <div class="flex items-center justify-between border-b border-slate-100 px-6 py-4">
                    <h2 class="text-lg font-extrabold text-slate-900">استيراد كشف بنك</h2>
                    <button type="button" @click="showImportModal = false" class="text-slate-400 hover:text-slate-600"><X class="h-5 w-5" /></button>
                </div>
                <form @submit.prevent="submitImport" class="space-y-3 px-6 py-4">
                    <p class="text-xs text-slate-500">ملف CSV بأعمدة: التاريخ، البيان، المبلغ (موجب إيداع/سالب سحب)، مرجع اختياري.</p>
                    <input type="file" accept=".csv,.txt" @change="onFileChange" class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm" />
                    <p v-if="importForm.errors.file" class="text-xs text-red-500">{{ importForm.errors.file }}</p>
                    <div class="flex justify-end gap-2 pt-2">
                        <button type="button" @click="showImportModal = false" class="rounded-xl border border-slate-200 px-4 py-2 text-sm font-bold text-slate-600">إلغاء</button>
                        <button type="submit" :disabled="importForm.processing || !importForm.file" class="rounded-md bg-blue-600 px-5 py-2 text-sm font-bold text-white hover:bg-blue-700 disabled:opacity-60">استيراد</button>
                    </div>
                </form>
            </div>
        </div>
    </AppLayout>
</template>
