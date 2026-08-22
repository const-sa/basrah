<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { Save } from 'lucide-vue-next';
import { computed, ref } from 'vue';

interface Row {
    employee_id: number; employee_no: string | null; name: string;
    status: string; check_in: string | null; check_out: string | null;
    overtime_hours: number; notes: string | null;
}

const props = defineProps<{
    date: string;
    rows: Row[];
    statuses: { key: string; label: string }[];
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'لوحة التحكم', href: '/admin' },
    { title: 'الحضور', href: '/admin/hr/attendance' },
];

const selectedDate = ref(props.date);
const changeDate = () => router.get('/admin/hr/attendance', { date: selectedDate.value }, { preserveState: false });

const form = useForm({ date: props.date, rows: props.rows.map((r) => ({ ...r })) });

/** تعليم الجميع بحالة واحدة — أسرع من ضبط 18 صفًا يدويًا. */
const markAll = (status: string) => form.rows.forEach((r) => (r.status = status));

const counts = computed(() => {
    const map: Record<string, number> = {};
    for (const r of form.rows) map[r.status] = (map[r.status] ?? 0) + 1;
    return map;
});

const submit = () => form.post('/admin/hr/attendance', { preserveScroll: true });

const statusClass = (s: string) =>
    ({
        present: 'bg-emerald-100 text-emerald-700',
        absent: 'bg-red-100 text-red-700',
        late: 'bg-amber-100 text-amber-700',
        leave: 'bg-sky-100 text-sky-700',
        holiday: 'bg-slate-200 text-slate-600',
    })[s] ?? 'bg-slate-100 text-slate-700';
</script>

<template>
    <Head title="الحضور" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="min-h-full space-y-4 bg-slate-100 p-5">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h1 class="text-2xl font-extrabold text-slate-900">الحضور والانصراف</h1>
                    <p class="mt-1 text-sm font-medium text-slate-600">الغياب يُثبَت هنا ويُخصم تلقائيًا من مسيّر الراتب</p>
                </div>
                <Link href="/admin/hr/staff" class="rounded-md border border-slate-200 bg-white px-4 py-2 text-sm font-bold text-slate-700 hover:bg-slate-50">ملفات الموظفين</Link>
            </div>

            <div class="flex flex-wrap items-center gap-3 rounded-2xl border border-slate-200 bg-white p-3 shadow-sm">
                <label class="text-sm font-bold text-slate-700">التاريخ</label>
                <input v-model="selectedDate" @change="changeDate" type="date" class="rounded-xl border border-slate-200 px-3 py-2 text-sm" />

                <span class="mx-2 h-6 w-px bg-slate-200"></span>

                <span class="text-xs font-bold text-slate-600">تعليم الجميع:</span>
                <button v-for="s in statuses" :key="s.key" type="button" @click="markAll(s.key)" class="rounded-lg px-2.5 py-1 text-[11px] font-bold transition" :class="statusClass(s.key)">
                    {{ s.label }}
                </button>

                <span class="ms-auto flex flex-wrap gap-1.5">
                    <span v-for="s in statuses" :key="s.key" v-show="counts[s.key]" class="rounded-md px-2 py-0.5 text-[11px] font-bold" :class="statusClass(s.key)">
                        {{ s.label }}: {{ counts[s.key] }}
                    </span>
                </span>
            </div>

            <form @submit.prevent="submit">
                <div class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead class="bg-slate-100">
                                <tr>
                                    <th class="px-4 py-3 text-right text-xs font-extrabold text-[#1e3a8a]">الموظف</th>
                                    <th class="px-4 py-3 text-center text-xs font-extrabold text-[#1e3a8a]">الحالة</th>
                                    <th class="px-4 py-3 text-center text-xs font-extrabold text-[#1e3a8a]">الحضور</th>
                                    <th class="px-4 py-3 text-center text-xs font-extrabold text-[#1e3a8a]">الانصراف</th>
                                    <th class="px-4 py-3 text-center text-xs font-extrabold text-[#1e3a8a]">ساعات إضافية</th>
                                    <th class="px-4 py-3 text-right text-xs font-extrabold text-[#1e3a8a]">ملاحظة</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="r in form.rows" :key="r.employee_id" class="border-t border-slate-100">
                                    <td class="px-4 py-2">
                                        <div class="font-bold text-slate-800">{{ r.name }}</div>
                                        <div class="text-[10px] text-slate-500" dir="ltr">{{ r.employee_no }}</div>
                                    </td>
                                    <td class="px-4 py-2 text-center">
                                        <select v-model="r.status" class="rounded-lg border-0 px-2 py-1 text-xs font-bold focus:ring-2 focus:ring-emerald-200" :class="statusClass(r.status)">
                                            <option v-for="s in statuses" :key="s.key" :value="s.key">{{ s.label }}</option>
                                        </select>
                                    </td>
                                    <td class="px-4 py-2 text-center">
                                        <input v-model="r.check_in" type="time" class="rounded-lg border border-slate-200 px-2 py-1 text-xs" />
                                    </td>
                                    <td class="px-4 py-2 text-center">
                                        <input v-model="r.check_out" type="time" class="rounded-lg border border-slate-200 px-2 py-1 text-xs" />
                                    </td>
                                    <td class="px-4 py-2 text-center">
                                        <input v-model.number="r.overtime_hours" type="number" min="0" max="24" step="0.5" class="w-20 rounded-lg border border-slate-200 px-2 py-1 text-center text-xs" />
                                    </td>
                                    <td class="px-4 py-2">
                                        <input v-model="r.notes" class="w-full rounded-lg border border-slate-200 px-2 py-1 text-xs" />
                                    </td>
                                </tr>
                                <tr v-if="!form.rows.length"><td colspan="6" class="px-4 py-10 text-center text-sm text-slate-500">لا موظفين على رأس العمل</td></tr>
                            </tbody>
                        </table>
                    </div>

                    <div v-if="form.rows.length" class="flex justify-end border-t border-slate-100 p-3">
                        <button type="submit" :disabled="form.processing" class="inline-flex items-center gap-1.5 rounded-md bg-blue-600 px-5 py-2 text-sm font-bold text-white hover:bg-blue-700 disabled:opacity-60">
                            <Save class="h-4 w-4" /> حفظ الحضور
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </AppLayout>
</template>
