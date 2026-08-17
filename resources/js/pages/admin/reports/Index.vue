<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link } from '@inertiajs/vue3';
import { ArrowLeft, CalendarDays, FileBarChart2, UsersRound, Wallet } from 'lucide-vue-next';

interface ReportMeta {
    key: string;
    label: string;
    description: string;
    group: string;
}

defineProps<{
    groups: { group: string; reports: ReportMeta[] }[];
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'لوحة التحكم', href: '/admin' },
    { title: 'التقارير', href: '/admin/reports' },
];

const groupIcon = (group: string) => ({ الحجوزات: CalendarDays, المالية: Wallet, الموظفون: UsersRound })[group] ?? FileBarChart2;

const groupTone = (group: string) =>
    ({
        الحجوزات: 'bg-sky-50 text-sky-700',
        المالية: 'bg-emerald-50 text-emerald-700',
        الموظفون: 'bg-violet-50 text-violet-700',
    })[group] ?? 'bg-slate-100 text-slate-700';
</script>

<template>
    <Head title="التقارير" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="min-h-full space-y-5 bg-slate-100 p-5">
            <div>
                <h1 class="flex items-center gap-2 text-2xl font-extrabold text-slate-900">
                    <FileBarChart2 class="h-6 w-6 text-slate-700" /> مركز التقارير
                </h1>
                <p class="mt-1 text-sm font-medium text-slate-600">
                    تقارير الحجوزات والمالية والموظفين. كل تقرير يُقرأ بمدّته ومرشّحاته، ويُصدَّر كما هو معروض.
                </p>
            </div>

            <section v-for="group in groups" :key="group.group" class="space-y-3">
                <h2 class="flex items-center gap-2 text-sm font-extrabold text-slate-700">
                    <span class="flex h-8 w-8 items-center justify-center rounded-xl" :class="groupTone(group.group)">
                        <component :is="groupIcon(group.group)" class="h-4 w-4" />
                    </span>
                    {{ group.group }}
                    <span class="text-xs font-bold text-slate-400">({{ group.reports.length }})</span>
                </h2>

                <div class="grid gap-3 md:grid-cols-2 lg:grid-cols-3">
                    <Link
                        v-for="report in group.reports"
                        :key="report.key"
                        :href="`/admin/reports/${report.key}`"
                        class="group flex flex-col justify-between gap-3 rounded-2xl border border-slate-200 bg-white p-4 transition hover:border-slate-300 hover:shadow-sm"
                    >
                        <div>
                            <div class="text-sm font-extrabold text-slate-900">{{ report.label }}</div>
                            <p class="mt-1 text-xs font-medium leading-relaxed text-slate-500">{{ report.description }}</p>
                        </div>
                        <span class="inline-flex items-center gap-1 text-xs font-bold text-slate-400 group-hover:text-slate-700">
                            عرض التقرير <ArrowLeft class="h-3.5 w-3.5" />
                        </span>
                    </Link>
                </div>
            </section>
        </div>
    </AppLayout>
</template>
