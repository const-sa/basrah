<script setup lang="ts">
import { usePermissions } from '@/composables/usePermissions';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/vue3';
import { Archive, RotateCcw, Trash2 } from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';

interface Row {
    type: string;
    type_label: string;
    group: string;
    id: number;
    name: string;
    deleted_at: string | null;
    created_at: string | null;
    deleted_by: string | null;
}

interface TypeOption {
    key: string;
    label: string;
    group: string;
    count: number;
}

const props = defineProps<{
    records: { data: Row[]; links: { url: string | null; label: string; active: boolean }[] };
    filters: Record<string, string | null>;
    types: TypeOption[];
    stats: { total: number; types: number; today: number };
}>();

const { can } = usePermissions();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'لوحة التحكم', href: '/admin' },
    { title: 'الأرشيف', href: '/admin/archive' },
];

const form = ref({
    type: props.filters.type ?? '',
    search: props.filters.search ?? '',
    from: props.filters.from ?? '',
    to: props.filters.to ?? '',
});

// المرشّح يُطبَّق فورًا — الشاشة تُفتح بحثًا عن سجلٍ بعينه حُذف بالخطأ.
let timer: ReturnType<typeof setTimeout> | undefined;
watch(
    form,
    (value) => {
        clearTimeout(timer);
        timer = setTimeout(() => {
            router.get('/admin/archive', { ...value }, { preserveState: true, replace: true, preserveScroll: true });
        }, 300);
    },
    { deep: true },
);

const reset = () => (form.value = { type: '', search: '', from: '', to: '' });

// الأنواع مجموعةً بأنظمتها — قائمةٌ مسطّحة بخمسة وعشرين نوعًا لا تُقرأ.
const grouped = computed(() => {
    const groups = new Map<string, TypeOption[]>();
    for (const type of props.types) {
        groups.set(type.group, [...(groups.get(type.group) ?? []), type]);
    }
    return [...groups.entries()].map(([group, items]) => ({ group, items }));
});

const restore = (row: Row) => {
    if (!confirm(`استرجاع ${row.type_label} «${row.name}» وإعادته إلى الخدمة؟`)) return;

    router.post(`/admin/archive/${row.type}/${row.id}/restore`, {}, { preserveScroll: true });
};

// الإتلاف النهائي يُطلب مرتين عمدًا: لا استرجاع بعده ولا أرشيف.
const purge = (row: Row) => {
    if (!confirm(`حذف ${row.type_label} «${row.name}» نهائيًا؟ لا يمكن التراجع عن هذه الخطوة.`)) return;
    if (!confirm('تأكيد أخير: سيُمحى السجل من قاعدة البيانات ولن يظهر في الأرشيف بعدها.')) return;

    router.delete(`/admin/archive/${row.type}/${row.id}`, { preserveScroll: true });
};
</script>

<template>
    <Head title="الأرشيف" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="min-h-full space-y-4 bg-slate-100 p-5">
            <div>
                <h1 class="flex items-center gap-2 text-2xl font-extrabold text-slate-900">
                    <Archive class="h-6 w-6 text-slate-700" /> الأرشيف — المحذوفات
                </h1>
                <p class="mt-1 text-sm font-medium text-slate-600">
                    ما يُحذف من شاشات النظام لا يُتلف بل يُنقل إلى هنا. يُسترجع بضغطة، والحذف النهائي قرارٌ منفصل لا رجعة فيه.
                </p>
            </div>

            <!-- المؤشرات -->
            <div class="grid gap-3 sm:grid-cols-3">
                <div class="rounded-2xl border border-slate-200 bg-white p-4">
                    <div class="text-xs font-bold text-slate-500">السجلات المطابقة</div>
                    <div class="mt-1 text-2xl font-extrabold text-slate-900" dir="ltr">{{ stats.total }}</div>
                </div>
                <div class="rounded-2xl border border-slate-200 bg-white p-4">
                    <div class="text-xs font-bold text-slate-500">أنواع فيها محذوفات</div>
                    <div class="mt-1 text-2xl font-extrabold text-sky-700" dir="ltr">{{ stats.types }}</div>
                </div>
                <div class="rounded-2xl border border-slate-200 bg-white p-4">
                    <div class="text-xs font-bold text-slate-500">حُذف اليوم</div>
                    <div class="mt-1 text-2xl font-extrabold text-red-700" dir="ltr">{{ stats.today }}</div>
                </div>
            </div>

            <!-- المرشّحات -->
            <div class="grid gap-2 rounded-2xl border border-slate-200 bg-white p-3 md:grid-cols-5">
                <input
                    v-model="form.search"
                    type="search"
                    placeholder="بحث بالاسم أو الرقم"
                    class="rounded-xl border border-slate-200 px-3 py-2.5 text-sm md:col-span-2"
                />
                <select v-model="form.type" class="rounded-xl border border-slate-200 px-3 py-2.5 text-sm">
                    <option value="">كل الأنواع</option>
                    <optgroup v-for="g in grouped" :key="g.group" :label="g.group">
                        <option v-for="t in g.items" :key="t.key" :value="t.key">{{ t.label }} ({{ t.count }})</option>
                    </optgroup>
                </select>
                <div class="flex gap-2">
                    <input v-model="form.from" type="date" class="w-full rounded-xl border border-slate-200 px-2 py-2.5 text-xs" />
                    <input v-model="form.to" type="date" class="w-full rounded-xl border border-slate-200 px-2 py-2.5 text-xs" />
                </div>
                <button
                    type="button"
                    @click="reset"
                    class="rounded-xl border border-slate-200 px-3 py-2.5 text-sm font-bold text-slate-600 hover:bg-slate-50"
                >
                    مسح المرشّحات
                </button>
            </div>

            <!-- المحذوفات -->
            <div class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                <table class="w-full text-sm">
                    <thead class="bg-slate-100">
                        <tr>
                            <th class="px-4 py-3 text-right text-xs font-extrabold text-[#1e3a8a]">تاريخ الحذف</th>
                            <th class="px-4 py-3 text-right text-xs font-extrabold text-[#1e3a8a]">النوع</th>
                            <th class="px-4 py-3 text-right text-xs font-extrabold text-[#1e3a8a]">السجل</th>
                            <th class="px-4 py-3 text-right text-xs font-extrabold text-[#1e3a8a]">حذفه</th>
                            <th class="px-4 py-3 text-center text-xs font-extrabold text-[#1e3a8a]">أُنشئ في</th>
                            <th class="px-4 py-3 text-center text-xs font-extrabold text-[#1e3a8a]"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="row in records.data" :key="`${row.type}-${row.id}`" class="border-t border-slate-100 hover:bg-slate-50">
                            <td class="px-4 py-2.5 text-xs font-medium text-slate-600" dir="ltr">{{ row.deleted_at ?? '—' }}</td>
                            <td class="px-4 py-2.5">
                                <span class="inline-flex rounded-md bg-slate-100 px-2 py-0.5 text-[11px] font-bold text-slate-700">
                                    {{ row.type_label }}
                                </span>
                            </td>
                            <td class="px-4 py-2.5 font-bold text-slate-800">{{ row.name }}</td>
                            <td class="px-4 py-2.5 text-xs text-slate-600">{{ row.deleted_by ?? '—' }}</td>
                            <td class="px-4 py-2.5 text-center text-[11px] text-slate-500" dir="ltr">{{ row.created_at ?? '—' }}</td>
                            <td class="px-4 py-2.5">
                                <div class="flex items-center justify-center gap-1.5">
                                    <button
                                        v-if="can('archive.restore')"
                                        type="button"
                                        @click="restore(row)"
                                        class="inline-flex items-center gap-1 rounded-lg bg-emerald-50 px-2.5 py-1.5 text-[11px] font-bold text-emerald-700 hover:bg-emerald-100"
                                    >
                                        <RotateCcw class="h-3.5 w-3.5" /> استرجاع
                                    </button>
                                    <button
                                        v-if="can('archive.delete')"
                                        type="button"
                                        @click="purge(row)"
                                        class="inline-flex items-center gap-1 rounded-lg bg-red-50 px-2.5 py-1.5 text-[11px] font-bold text-red-700 hover:bg-red-100"
                                    >
                                        <Trash2 class="h-3.5 w-3.5" /> حذف نهائي
                                    </button>
                                </div>
                            </td>
                        </tr>
                        <tr v-if="!records.data.length">
                            <td colspan="6" class="px-4 py-12 text-center text-sm text-slate-500">لا محذوفات مطابقة</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div v-if="records.links.length > 3" class="flex flex-wrap justify-center gap-1">
                <Link
                    v-for="link in records.links"
                    :key="link.label"
                    :href="link.url ?? '#'"
                    preserve-scroll
                    class="rounded-lg px-3 py-1.5 text-xs font-bold"
                    :class="
                        link.active
                            ? 'bg-slate-900 text-white'
                            : link.url
                              ? 'bg-white text-slate-600 ring-1 ring-slate-200 hover:bg-slate-50'
                              : 'cursor-default bg-white text-slate-300'
                    "
                    v-html="link.label"
                />
            </div>
        </div>
    </AppLayout>
</template>
