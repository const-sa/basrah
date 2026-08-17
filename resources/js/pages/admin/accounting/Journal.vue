<script setup lang="ts">
import { usePermissions } from '@/composables/usePermissions';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { ChevronDown, Lock, Plus, RotateCcw, Trash2, X } from 'lucide-vue-next';
import { computed, ref } from 'vue';

interface EntryLine { account: string; debit: number; credit: number; description: string | null }
interface Entry {
    id: number; number: string; entry_date: string; description: string | null;
    status: string; status_label: string; source: string; source_label: string;
    is_system: boolean; total_debit: number; total_credit: number; lines: EntryLine[];
}

const props = defineProps<{
    entries: { data: Entry[]; links: { url: string | null; label: string; active: boolean }[] };
    filters: Record<string, string | null>;
    statuses: { key: string; label: string }[];
    sources: { key: string; label: string }[];
    accounts: { id: number; code: string; name: string }[];
    costCenters: { id: number; code: string; name: string }[];
}>();

const { can } = usePermissions();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'لوحة التحكم', href: '/admin' },
    { title: 'دفتر اليومية', href: '/admin/accounting/journal' },
];

const money = (n: number) => new Intl.NumberFormat('ar-SA-u-nu-latn', { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(n ?? 0);

const filters = ref({ ...props.filters });
const apply = () => router.get('/admin/accounting/journal', filters.value, { preserveState: true, replace: true });

const expanded = ref<Set<number>>(new Set());
const toggle = (id: number) => {
    const s = new Set(expanded.value);
    s.has(id) ? s.delete(id) : s.add(id);
    expanded.value = s;
};

// ── قيد يدوي ────────────────────────────────────────────────
const showModal = ref(false);

const form = useForm({
    entry_date: new Date().toISOString().slice(0, 10),
    description: '',
    lines: [
        { account_id: null as number | null, cost_center_id: null as number | null, debit: 0, credit: 0, description: '' },
        { account_id: null as number | null, cost_center_id: null as number | null, debit: 0, credit: 0, description: '' },
    ],
});

const addLine = () =>
    form.lines.push({ account_id: null, cost_center_id: null, debit: 0, credit: 0, description: '' });
const removeLine = (i: number) => form.lines.length > 2 && form.lines.splice(i, 1);

const totalDebit = computed(() => form.lines.reduce((s, l) => s + (Number(l.debit) || 0), 0));
const totalCredit = computed(() => form.lines.reduce((s, l) => s + (Number(l.credit) || 0), 0));
const balanced = computed(() => Math.abs(totalDebit.value - totalCredit.value) < 0.01 && totalDebit.value > 0);

const openCreate = () => {
    form.reset();
    form.clearErrors();
    showModal.value = true;
};

const submit = () => {
    if (!balanced.value) return;
    form.post('/admin/accounting/journal', { preserveScroll: true, onSuccess: () => (showModal.value = false) });
};

const reverse = (e: Entry) => {
    const reason = prompt(`سبب عكس القيد ${e.number}:`);
    if (reason !== null) {
        router.post(`/admin/accounting/journal/${e.id}/reverse`, { reason }, { preserveScroll: true });
    }
};

const statusClass = (s: string) =>
    ({ posted: 'bg-emerald-100 text-emerald-700', draft: 'bg-amber-100 text-amber-700', reversed: 'bg-slate-200 text-slate-600' })[s] ??
    'bg-slate-100 text-slate-700';
</script>

<template>
    <Head title="دفتر اليومية" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="min-h-full space-y-4 bg-slate-100 p-5">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h1 class="text-2xl font-extrabold text-slate-900">دفتر اليومية</h1>
                    <p class="mt-1 text-sm font-medium text-slate-600">القيد المرحَّل لا يُعدَّل ولا يُحذف — يُعكس بقيد مضاد</p>
                </div>
                <div class="flex gap-2">
                    <Link href="/admin/accounting/reports" class="rounded-md border border-slate-200 bg-white px-4 py-2 text-sm font-bold text-slate-700 hover:bg-slate-50">التقارير المالية</Link>
                    <button v-if="can('journal.create')" type="button" @click="openCreate" class="inline-flex items-center gap-1.5 rounded-md bg-blue-600 px-4 py-2 text-sm font-bold text-white hover:bg-blue-700">
                        <Plus class="h-4 w-4" /> قيد يدوي
                    </button>
                </div>
            </div>

            <div class="rounded-2xl border border-slate-200 bg-white p-3 shadow-sm">
                <div class="grid gap-2 sm:grid-cols-2 lg:grid-cols-4">
                    <select v-model="filters.status" @change="apply" class="rounded-xl border border-slate-200 px-3 py-2.5 text-sm">
                        <option :value="null">كل الحالات</option>
                        <option v-for="s in statuses" :key="s.key" :value="s.key">{{ s.label }}</option>
                    </select>
                    <select v-model="filters.source" @change="apply" class="rounded-xl border border-slate-200 px-3 py-2.5 text-sm">
                        <option :value="null">كل المصادر</option>
                        <option v-for="s in sources" :key="s.key" :value="s.key">{{ s.label }}</option>
                    </select>
                    <input v-model="filters.from" @change="apply" type="date" class="rounded-xl border border-slate-200 px-3 py-2.5 text-sm" />
                    <input v-model="filters.to" @change="apply" type="date" class="rounded-xl border border-slate-200 px-3 py-2.5 text-sm" />
                </div>
            </div>

            <div class="space-y-2">
                <div v-for="e in entries.data" :key="e.id" class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                    <button type="button" @click="toggle(e.id)" class="flex w-full items-center gap-3 px-4 py-3 text-right transition hover:bg-slate-50">
                        <ChevronDown class="h-4 w-4 shrink-0 text-slate-400 transition" :class="expanded.has(e.id) && 'rotate-180'" />
                        <span class="font-mono text-xs font-extrabold text-slate-700" dir="ltr">{{ e.number }}</span>
                        <span class="text-xs text-slate-500" dir="ltr">{{ e.entry_date }}</span>
                        <span class="min-w-0 flex-1 truncate text-sm font-bold text-slate-800">{{ e.description }}</span>
                        <span class="shrink-0 rounded-md px-2 py-0.5 text-[10px] font-bold" :class="statusClass(e.status)">{{ e.status_label }}</span>
                        <span v-if="e.is_system" class="shrink-0 rounded-md bg-sky-100 px-2 py-0.5 text-[10px] font-bold text-sky-700">{{ e.source_label }}</span>
                        <span class="shrink-0 font-extrabold text-slate-800">{{ money(e.total_debit) }}</span>
                    </button>

                    <div v-show="expanded.has(e.id)" class="border-t border-slate-100">
                        <table class="w-full text-xs">
                            <thead class="bg-slate-50">
                                <tr>
                                    <th class="px-4 py-2 text-right font-extrabold text-slate-600">الحساب</th>
                                    <th class="px-4 py-2 text-left font-extrabold text-slate-600">مدين</th>
                                    <th class="px-4 py-2 text-left font-extrabold text-slate-600">دائن</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="(l, i) in e.lines" :key="i" class="border-t border-slate-50">
                                    <td class="px-4 py-1.5 text-slate-700">
                                        {{ l.account }}
                                        <span v-if="l.description" class="text-slate-400"> — {{ l.description }}</span>
                                    </td>
                                    <td class="px-4 py-1.5 text-left font-bold text-slate-800" dir="ltr">{{ l.debit ? money(l.debit) : '' }}</td>
                                    <td class="px-4 py-1.5 text-left font-bold text-slate-800" dir="ltr">{{ l.credit ? money(l.credit) : '' }}</td>
                                </tr>
                                <tr class="border-t border-slate-200 bg-slate-50 font-extrabold">
                                    <td class="px-4 py-1.5 text-slate-700">الإجمالي</td>
                                    <td class="px-4 py-1.5 text-left text-slate-900" dir="ltr">{{ money(e.total_debit) }}</td>
                                    <td class="px-4 py-1.5 text-left text-slate-900" dir="ltr">{{ money(e.total_credit) }}</td>
                                </tr>
                            </tbody>
                        </table>

                        <div v-if="can('journal.approve') && e.status === 'posted'" class="flex justify-end border-t border-slate-100 px-4 py-2">
                            <button type="button" @click="reverse(e)" class="inline-flex items-center gap-1 rounded-lg bg-amber-500 px-3 py-1.5 text-[11px] font-bold text-white hover:bg-amber-600">
                                <RotateCcw class="h-3 w-3" /> عكس القيد
                            </button>
                        </div>
                        <p v-else-if="e.status === 'reversed'" class="flex items-center gap-1 border-t border-slate-100 px-4 py-2 text-[11px] font-bold text-slate-500">
                            <Lock class="h-3 w-3" /> عُكس هذا القيد بقيد مضاد
                        </p>
                    </div>
                </div>

                <p v-if="!entries.data.length" class="rounded-2xl bg-white py-10 text-center text-sm font-medium text-slate-500">لا قيود</p>
            </div>

            <div v-if="entries.links.length > 3" class="flex flex-wrap justify-center gap-1">
                <Link v-for="l in entries.links" :key="l.label" :href="l.url ?? '#'"
                    :class="['rounded-lg px-3 py-1.5 text-xs font-bold', l.active ? 'bg-blue-600 text-white' : l.url ? 'bg-white text-slate-600 ring-1 ring-slate-200' : 'text-slate-300']"
                    v-html="l.label" />
            </div>
        </div>

        <!-- قيد يدوي -->
        <div v-if="showModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4" @click.self="showModal = false">
            <div class="flex max-h-[92vh] w-full max-w-3xl flex-col rounded-2xl bg-white shadow-2xl">
                <div class="flex items-center justify-between border-b border-slate-100 px-6 py-4">
                    <h2 class="text-lg font-extrabold text-slate-900">قيد يدوي</h2>
                    <button type="button" @click="showModal = false" class="text-slate-400 hover:text-slate-600"><X class="h-5 w-5" /></button>
                </div>

                <form @submit.prevent="submit" class="flex min-h-0 flex-1 flex-col">
                    <div class="min-h-0 flex-1 space-y-3 overflow-y-auto px-6 py-4">
                        <div class="grid gap-3 sm:grid-cols-[160px_1fr]">
                            <div>
                                <label class="mb-1 block text-sm font-bold text-slate-700">التاريخ</label>
                                <input v-model="form.entry_date" type="date" class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm" />
                            </div>
                            <div>
                                <label class="mb-1 block text-sm font-bold text-slate-700">البيان</label>
                                <input v-model="form.description" class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm" />
                                <p v-if="form.errors.description" class="mt-1 text-xs text-red-500">{{ form.errors.description }}</p>
                            </div>
                        </div>

                        <div class="space-y-1.5">
                            <div class="flex items-center justify-between">
                                <label class="text-sm font-bold text-slate-700">السطور</label>
                                <button type="button" @click="addLine" class="rounded-lg border border-slate-200 px-2.5 py-1 text-[11px] font-bold text-slate-600 hover:bg-slate-50">+ سطر</button>
                            </div>

                            <div v-for="(l, i) in form.lines" :key="i" class="grid items-center gap-1.5 rounded-xl border border-slate-200 p-2 sm:grid-cols-[1fr_130px_110px_110px_auto]">
                                <select v-model="l.account_id" class="rounded-lg border border-slate-200 px-2 py-1.5 text-xs">
                                    <option :value="null">— الحساب —</option>
                                    <option v-for="a in accounts" :key="a.id" :value="a.id">{{ a.code }} — {{ a.name }}</option>
                                </select>
                                <select v-model="l.cost_center_id" class="rounded-lg border border-slate-200 px-2 py-1.5 text-xs">
                                    <option :value="null">بلا مركز تكلفة</option>
                                    <option v-for="c in costCenters" :key="c.id" :value="c.id">{{ c.name }}</option>
                                </select>
                                <input v-model.number="l.debit" type="number" min="0" step="0.01" placeholder="مدين" class="rounded-lg border border-slate-200 px-2 py-1.5 text-xs" />
                                <input v-model.number="l.credit" type="number" min="0" step="0.01" placeholder="دائن" class="rounded-lg border border-slate-200 px-2 py-1.5 text-xs" />
                                <button type="button" @click="removeLine(i)" :disabled="form.lines.length <= 2" class="text-red-400 hover:text-red-600 disabled:opacity-30"><Trash2 class="h-4 w-4" /></button>
                            </div>
                        </div>

                        <div class="flex items-center justify-between rounded-xl px-4 py-2.5 text-sm" :class="balanced ? 'bg-emerald-50' : 'bg-red-50'">
                            <span class="font-bold" :class="balanced ? 'text-emerald-700' : 'text-red-700'">
                                {{ balanced ? 'القيد متوازن' : 'القيد غير متوازن — لا يُرحَّل' }}
                            </span>
                            <span class="font-extrabold" dir="ltr">{{ money(totalDebit) }} / {{ money(totalCredit) }}</span>
                        </div>
                    </div>

                    <div class="flex justify-end gap-2 border-t border-slate-100 px-6 py-4">
                        <button type="button" @click="showModal = false" class="rounded-xl border border-slate-200 px-4 py-2 text-sm font-bold text-slate-600">إلغاء</button>
                        <button type="submit" :disabled="form.processing || !balanced" class="rounded-md bg-blue-600 px-5 py-2 text-sm font-bold text-white hover:bg-blue-700 disabled:opacity-50">ترحيل القيد</button>
                    </div>
                </form>
            </div>
        </div>
    </AppLayout>
</template>
