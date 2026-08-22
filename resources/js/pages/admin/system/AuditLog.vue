<script setup lang="ts">
import { usePermissions } from '@/composables/usePermissions';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/vue3';
import { ChevronDown, Download, ShieldCheck, Trash2, RotateCcw, PencilLine, Plus } from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';

interface Change { field: string; label: string; old: unknown; new: unknown }
interface Row {
    id: number; created_at: string | null; actor_name: string; user_id: number | null;
    event: string; event_label: string;
    subject_label: string; auditable_label: string | null; auditable_id: number;
    ip_address: string | null; url: string | null; method: string | null;
    changes: Change[]; changes_count: number;
}

const props = defineProps<{
    logs: { data: Row[]; links: { url: string | null; label: string; active: boolean }[] };
    filters: Record<string, string | null>;
    events: { key: string; label: string }[];
    types: { key: string; label: string }[];
    users: { id: number; name: string }[];
    stats: { total: number; today: number; deletes: number };
}>();

const { can } = usePermissions();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'لوحة التحكم', href: '/admin' },
    { title: 'السجل الرقابي', href: '/admin/audit-log' },
];

const form = ref({
    event: props.filters.event ?? '',
    type: props.filters.type ?? '',
    user_id: props.filters.user_id ?? '',
    from: props.filters.from ?? '',
    to: props.filters.to ?? '',
    search: props.filters.search ?? '',
});

// المرشّح يُطبَّق فورًا: الشاشة تُقرأ بحثًا عن واقعة بعينها، وزرّ «تطبيق»
// خطوةٌ زائدة بين السؤال وجوابه.
let timer: ReturnType<typeof setTimeout> | undefined;
watch(
    form,
    (value) => {
        clearTimeout(timer);
        timer = setTimeout(() => {
            router.get('/admin/audit-log', { ...value }, { preserveState: true, replace: true, preserveScroll: true });
        }, 300);
    },
    { deep: true },
);

const reset = () => (form.value = { event: '', type: '', user_id: '', from: '', to: '', search: '' });

const exportUrl = computed(() => {
    const params = new URLSearchParams(
        Object.entries(form.value).filter(([, v]) => v !== '' && v !== null) as [string, string][],
    );
    return `/admin/audit-log/export?${params.toString()}`;
});

// الصف يُفتح على تفاصيل التغيير — الجدول يبقى مقروءًا والتفصيل عند الطلب.
const openRow = ref<number | null>(null);
const toggle = (id: number) => (openRow.value = openRow.value === id ? null : id);

const eventIcon = (e: string) =>
    ({ created: Plus, updated: PencilLine, deleted: Trash2, restored: RotateCcw })[e] ?? PencilLine;

const eventClass = (e: string) =>
    ({
        created: 'bg-emerald-100 text-emerald-700',
        updated: 'bg-amber-100 text-amber-700',
        deleted: 'bg-red-100 text-red-700',
        restored: 'bg-sky-100 text-sky-700',
    })[e] ?? 'bg-slate-100 text-slate-700';

const show = (v: unknown): string => {
    if (v === null || v === undefined || v === '') return '—';
    if (typeof v === 'boolean') return v ? 'نعم' : 'لا';
    if (typeof v === 'object') return JSON.stringify(v);
    return String(v);
};
</script>

<template>
    <Head title="السجل الرقابي" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="min-h-full space-y-4 bg-slate-100 p-5">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h1 class="flex items-center gap-2 text-2xl font-extrabold text-slate-900">
                        <ShieldCheck class="h-6 w-6 text-slate-700" /> السجل الرقابي
                    </h1>
                    <p class="mt-1 text-sm font-medium text-slate-600">
                        من أنشأ ومن عدّل ومن حذف — بتاريخه ووقته. السجل للقراءة فقط ولا يُحرَّر من داخل النظام.
                    </p>
                </div>
                <a
                    v-if="can('audit.export')"
                    :href="exportUrl"
                    class="inline-flex items-center gap-1.5 rounded-md bg-slate-900 px-4 py-2 text-sm font-bold text-white hover:bg-slate-800"
                >
                    <Download class="h-4 w-4" /> تصدير المعروض
                </a>
            </div>

            <!-- المؤشرات -->
            <div class="grid gap-3 sm:grid-cols-3">
                <div class="rounded-2xl border border-slate-200 bg-white p-4">
                    <div class="text-xs font-bold text-slate-500">العمليات المطابقة</div>
                    <div class="mt-1 text-2xl font-extrabold text-slate-900" dir="ltr">{{ stats.total }}</div>
                </div>
                <div class="rounded-2xl border border-slate-200 bg-white p-4">
                    <div class="text-xs font-bold text-slate-500">اليوم</div>
                    <div class="mt-1 text-2xl font-extrabold text-sky-700" dir="ltr">{{ stats.today }}</div>
                </div>
                <div class="rounded-2xl border border-slate-200 bg-white p-4">
                    <div class="text-xs font-bold text-slate-500">عمليات حذف</div>
                    <div class="mt-1 text-2xl font-extrabold text-red-700" dir="ltr">{{ stats.deletes }}</div>
                </div>
            </div>

            <!-- المرشّحات -->
            <div class="grid gap-2 rounded-2xl border border-slate-200 bg-white p-3 md:grid-cols-6">
                <input
                    v-model="form.search"
                    type="search"
                    placeholder="بحث بالسجل أو اسم المستخدم"
                    class="rounded-xl border border-slate-200 px-3 py-2.5 text-sm md:col-span-2"
                />
                <select v-model="form.event" class="rounded-xl border border-slate-200 px-3 py-2.5 text-sm">
                    <option value="">كل العمليات</option>
                    <option v-for="e in events" :key="e.key" :value="e.key">{{ e.label }}</option>
                </select>
                <select v-model="form.type" class="rounded-xl border border-slate-200 px-3 py-2.5 text-sm">
                    <option value="">كل الأنواع</option>
                    <option v-for="t in types" :key="t.key" :value="t.key">{{ t.label }}</option>
                </select>
                <select v-model="form.user_id" class="rounded-xl border border-slate-200 px-3 py-2.5 text-sm">
                    <option value="">كل المستخدمين</option>
                    <option v-for="u in users" :key="u.id" :value="String(u.id)">{{ u.name }}</option>
                </select>
                <div class="flex gap-2">
                    <input v-model="form.from" type="date" class="w-full rounded-xl border border-slate-200 px-2 py-2.5 text-xs" />
                    <input v-model="form.to" type="date" class="w-full rounded-xl border border-slate-200 px-2 py-2.5 text-xs" />
                </div>
                <button
                    type="button"
                    @click="reset"
                    class="rounded-xl border border-slate-200 px-3 py-2.5 text-sm font-bold text-slate-600 hover:bg-slate-50 md:col-span-6 md:w-32"
                >
                    مسح المرشّحات
                </button>
            </div>

            <!-- السجل -->
            <div class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                <table class="w-full text-sm">
                    <thead class="bg-slate-100">
                        <tr>
                            <th class="px-4 py-3 text-right text-xs font-extrabold text-[#1e3a8a]">التاريخ والوقت</th>
                            <th class="px-4 py-3 text-right text-xs font-extrabold text-[#1e3a8a]">المستخدم</th>
                            <th class="px-4 py-3 text-center text-xs font-extrabold text-[#1e3a8a]">العملية</th>
                            <th class="px-4 py-3 text-right text-xs font-extrabold text-[#1e3a8a]">النوع</th>
                            <th class="px-4 py-3 text-right text-xs font-extrabold text-[#1e3a8a]">السجل</th>
                            <th class="px-4 py-3 text-center text-xs font-extrabold text-[#1e3a8a]">IP</th>
                            <th class="px-4 py-3 text-center text-xs font-extrabold text-[#1e3a8a]"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <template v-for="row in logs.data" :key="row.id">
                            <tr class="border-t border-slate-100 hover:bg-slate-50">
                                <td class="px-4 py-2.5 text-xs font-medium text-slate-600" dir="ltr">{{ row.created_at }}</td>
                                <td class="px-4 py-2.5 font-bold text-slate-800">{{ row.actor_name }}</td>
                                <td class="px-4 py-2.5 text-center">
                                    <span class="inline-flex items-center gap-1 rounded-md px-2 py-0.5 text-[11px] font-bold" :class="eventClass(row.event)">
                                        <component :is="eventIcon(row.event)" class="h-3 w-3" />
                                        {{ row.event_label }}
                                    </span>
                                </td>
                                <td class="px-4 py-2.5 text-xs text-slate-600">{{ row.subject_label }}</td>
                                <td class="px-4 py-2.5 text-xs font-bold text-slate-700">{{ row.auditable_label ?? '—' }}</td>
                                <td class="px-4 py-2.5 text-center text-[11px] text-slate-500" dir="ltr">{{ row.ip_address ?? '—' }}</td>
                                <td class="px-4 py-2.5 text-center">
                                    <button
                                        v-if="row.changes_count"
                                        type="button"
                                        @click="toggle(row.id)"
                                        class="inline-flex items-center gap-1 rounded-lg bg-slate-100 px-2 py-1 text-[11px] font-bold text-slate-600 hover:bg-slate-200"
                                    >
                                        {{ row.changes_count }} حقل
                                        <ChevronDown class="h-3 w-3 transition" :class="openRow === row.id ? 'rotate-180' : ''" />
                                    </button>
                                    <span v-else class="text-[11px] text-slate-400">—</span>
                                </td>
                            </tr>
                            <tr v-if="openRow === row.id" class="border-t border-slate-100 bg-slate-50">
                                <td colspan="7" class="px-4 py-3">
                                    <div class="mb-2 text-[11px] font-bold text-slate-500" dir="ltr">
                                        {{ row.method }} {{ row.url }}
                                    </div>
                                    <table class="w-full text-xs">
                                        <thead>
                                            <tr class="text-[#1e3a8a]">
                                                <th class="px-2 py-1 text-right font-extrabold">الحقل</th>
                                                <th class="px-2 py-1 text-right font-extrabold">قبل</th>
                                                <th class="px-2 py-1 text-right font-extrabold">بعد</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <tr v-for="c in row.changes" :key="c.field" class="border-t border-slate-200">
                                                <td class="px-2 py-1 font-bold text-slate-700">{{ c.label }}</td>
                                                <td class="px-2 py-1 text-red-600 line-through decoration-red-300">{{ show(c.old) }}</td>
                                                <td class="px-2 py-1 font-bold text-emerald-700">{{ show(c.new) }}</td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </td>
                            </tr>
                        </template>
                        <tr v-if="!logs.data.length">
                            <td colspan="7" class="px-4 py-12 text-center text-sm text-slate-500">لا عمليات مطابقة</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div v-if="logs.links.length > 3" class="flex flex-wrap justify-center gap-1">
                <Link
                    v-for="link in logs.links"
                    :key="link.label"
                    :href="link.url ?? '#'"
                    preserve-scroll
                    class="rounded-lg px-3 py-1.5 text-xs font-bold"
                    :class="link.active ? 'bg-slate-900 text-white' : link.url ? 'bg-white text-slate-600 ring-1 ring-slate-200 hover:bg-slate-50' : 'cursor-default bg-white text-slate-300'"
                    v-html="link.label"
                />
            </div>
        </div>
    </AppLayout>
</template>
