<script setup lang="ts">
import { StatPill } from '@/components/data-table';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/vue3';
import { FileText } from 'lucide-vue-next';
import { ref } from 'vue';

interface ClientRow {
    id: number;
    name: string;
    mobile: string | null;
    type_label: string;
    outstanding: number;
}

const props = defineProps<{
    clients: { data: ClientRow[]; links: { url: string | null; label: string; active: boolean }[] };
    filters: { search: string | null };
    totalOutstanding: number;
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'لوحة التحكم', href: '/admin' },
    { title: 'ذمم العملاء', href: '/admin/accounting/receivables' },
];

const money = (n: number) => new Intl.NumberFormat('ar-SA-u-nu-latn', { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(n ?? 0);

const search = ref(props.filters.search ?? '');
const apply = () => router.get('/admin/accounting/receivables', { search: search.value || null }, { preserveState: true, replace: true });
</script>

<template>
    <Head title="ذمم العملاء" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="min-h-full space-y-4 bg-slate-100 p-5">
            <div>
                <h1 class="text-2xl font-extrabold text-slate-900">ذمم العملاء</h1>
                <p class="mt-1 text-sm font-medium text-slate-600">كشف حساب موحَّد لكل عميل عبر حجوزاته ومبيعاته وعقوده وسنداته</p>
            </div>

            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <StatPill label="إجمالي مديونية هذه الصفحة" :value="money(totalOutstanding)" variant="danger" />
            </div>

            <div class="rounded-2xl border border-slate-200 bg-white p-3 shadow-sm">
                <input
                    v-model="search"
                    @keyup.enter="apply"
                    @blur="apply"
                    type="text"
                    placeholder="ابحث بالاسم أو الجوال…"
                    class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm sm:w-80"
                />
            </div>

            <div class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="bg-slate-100">
                            <tr>
                                <th class="px-4 py-3 text-right text-xs font-extrabold text-[#1e3a8a]">العميل</th>
                                <th class="px-4 py-3 text-right text-xs font-extrabold text-[#1e3a8a]">النشاط</th>
                                <th class="px-4 py-3 text-left text-xs font-extrabold text-[#1e3a8a]">المديونية</th>
                                <th class="px-4 py-3 text-center text-xs font-extrabold text-[#1e3a8a]">كشف الحساب</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="c in clients.data" :key="c.id" class="border-t border-slate-100 hover:bg-slate-50">
                                <td class="px-4 py-3 text-right">
                                    <div class="font-extrabold text-slate-800">{{ c.name }}</div>
                                    <div class="text-[11px] text-slate-500"><span dir="ltr">{{ c.mobile ?? '—' }}</span></div>
                                </td>
                                <td class="px-4 py-3 text-xs font-bold text-slate-600">{{ c.type_label }}</td>
                                <td class="px-4 py-3 text-left font-extrabold" dir="ltr" :class="c.outstanding > 0 ? 'text-red-600' : 'text-slate-500'">
                                    {{ money(c.outstanding) }}
                                </td>
                                <td class="px-4 py-3 text-center">
                                    <Link :href="`/admin/accounting/receivables/${c.id}`" class="inline-flex items-center gap-1 rounded-lg bg-blue-50 px-2.5 py-1.5 text-xs font-bold text-blue-700 hover:bg-blue-100">
                                        <FileText class="h-3.5 w-3.5" /> عرض
                                    </Link>
                                </td>
                            </tr>
                            <tr v-if="!clients.data.length"><td colspan="4" class="px-4 py-10 text-center text-sm text-slate-500">لا عملاء</td></tr>
                        </tbody>
                    </table>
                </div>

                <div v-if="clients.links.length > 3" class="flex flex-wrap justify-center gap-1 border-t border-slate-100 p-3">
                    <Link v-for="l in clients.links" :key="l.label" :href="l.url ?? '#'"
                        :class="['rounded-lg px-3 py-1.5 text-xs font-bold', l.active ? 'bg-blue-600 text-white' : l.url ? 'bg-white text-slate-600 ring-1 ring-slate-200' : 'text-slate-300']"
                        v-html="l.label" />
                </div>
            </div>
        </div>
    </AppLayout>
</template>
