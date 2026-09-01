<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/vue3';
import { ArrowDownLeft, ArrowUpRight } from 'lucide-vue-next';
import { ref } from 'vue';

interface Movement {
    id: number; item_name: string | null; item_code: string | null;
    type: string; type_label: string;
    quantity: number; balance_after: number;
    user_name: string | null; notes: string | null; created_at: string;
}

const props = defineProps<{
    movements: { data: Movement[]; links: { url: string | null; label: string; active: boolean }[] };
    filters: { item_id: number | null; type: string | null };
    items: { id: number; name: string }[];
    types: { key: string; label: string }[];
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'لوحة التحكم', href: '/admin' },
    { title: 'الأصناف', href: '/admin/items' },
    { title: 'حركات المخزون', href: '/admin/inventory/movements' },
];

const filters = ref({ ...props.filters });
const apply = () => router.get('/admin/inventory/movements', filters.value, { preserveState: true, replace: true });

const typeClass = (t: string) =>
    ({
        purchase: 'bg-emerald-100 text-emerald-700',
        purchase_revert: 'bg-emerald-50 text-emerald-800',
        sale: 'bg-red-100 text-red-700',
        return: 'bg-sky-100 text-sky-700',
        adjustment: 'bg-amber-100 text-amber-700',
        bundle_consume: 'bg-violet-100 text-violet-700',
        opening: 'bg-slate-200 text-slate-700',
    })[t] ?? 'bg-slate-100 text-slate-700';
</script>

<template>
    <Head title="حركات المخزون" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="min-h-full space-y-4 bg-slate-100 p-5">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h1 class="text-2xl font-extrabold text-slate-900">حركات المخزون</h1>
                    <p class="mt-1 text-sm font-medium text-slate-600">كل تغيّر في الرصيد مُسجَّل هنا بلا استثناء</p>
                </div>
                <Link href="/admin/items" class="rounded-md border border-slate-200 bg-white px-4 py-2 text-sm font-bold text-slate-700 hover:bg-slate-50">الأصناف</Link>
            </div>

            <div class="rounded-2xl border border-slate-200 bg-white p-3 shadow-sm">
                <div class="grid gap-2 sm:grid-cols-2">
                    <select v-model="filters.item_id" @change="apply" class="rounded-xl border border-slate-200 px-3 py-2.5 text-sm">
                        <option :value="null">كل الأصناف</option>
                        <option v-for="i in items" :key="i.id" :value="i.id">{{ i.name }}</option>
                    </select>
                    <select v-model="filters.type" @change="apply" class="rounded-xl border border-slate-200 px-3 py-2.5 text-sm">
                        <option :value="null">كل الأنواع</option>
                        <option v-for="t in types" :key="t.key" :value="t.key">{{ t.label }}</option>
                    </select>
                </div>
            </div>

            <div class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="bg-slate-100">
                            <tr>
                                <th class="px-4 py-3 text-right text-xs font-extrabold text-[#1e3a8a]">التاريخ</th>
                                <th class="px-4 py-3 text-right text-xs font-extrabold text-[#1e3a8a]">الصنف</th>
                                <th class="px-4 py-3 text-center text-xs font-extrabold text-[#1e3a8a]">النوع</th>
                                <th class="px-4 py-3 text-center text-xs font-extrabold text-[#1e3a8a]">الكمية</th>
                                <th class="px-4 py-3 text-center text-xs font-extrabold text-[#1e3a8a]">الرصيد بعدها</th>
                                <th class="px-4 py-3 text-right text-xs font-extrabold text-[#1e3a8a]">المستخدم / ملاحظة</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="m in movements.data" :key="m.id" class="border-t border-slate-100 hover:bg-slate-50">
                                <td class="px-4 py-2.5 text-xs text-slate-600" dir="ltr">{{ m.created_at }}</td>
                                <td class="px-4 py-2.5">
                                    <div class="font-bold text-slate-800">{{ m.item_name }}</div>
                                    <div class="text-[10px] text-slate-500" dir="ltr">{{ m.item_code }}</div>
                                </td>
                                <td class="px-4 py-2.5 text-center">
                                    <span class="rounded-md px-2 py-0.5 text-[11px] font-bold" :class="typeClass(m.type)">{{ m.type_label }}</span>
                                </td>
                                <td class="px-4 py-2.5 text-center">
                                    <span class="inline-flex items-center gap-1 font-extrabold" :class="m.quantity > 0 ? 'text-emerald-600' : 'text-red-600'">
                                        <component :is="m.quantity > 0 ? ArrowUpRight : ArrowDownLeft" class="h-3.5 w-3.5" />
                                        {{ Math.abs(m.quantity) }}
                                    </span>
                                </td>
                                <td class="px-4 py-2.5 text-center font-bold text-slate-700">{{ m.balance_after }}</td>
                                <td class="px-4 py-2.5 text-xs">
                                    <div class="font-bold text-slate-700">{{ m.user_name ?? '—' }}</div>
                                    <div v-if="m.notes" class="text-slate-500">{{ m.notes }}</div>
                                </td>
                            </tr>
                            <tr v-if="!movements.data.length"><td colspan="6" class="px-4 py-10 text-center text-sm text-slate-500">لا حركات</td></tr>
                        </tbody>
                    </table>
                </div>

                <div v-if="movements.links.length > 3" class="flex flex-wrap justify-center gap-1 border-t border-slate-100 p-3">
                    <Link v-for="l in movements.links" :key="l.label" :href="l.url ?? '#'"
                        :class="['rounded-lg px-3 py-1.5 text-xs font-bold', l.active ? 'bg-blue-600 text-white' : l.url ? 'bg-white text-slate-600 ring-1 ring-slate-200' : 'text-slate-300']"
                        v-html="l.label" />
                </div>
            </div>
        </div>
    </AppLayout>
</template>
