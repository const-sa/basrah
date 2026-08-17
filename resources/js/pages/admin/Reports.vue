<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head } from '@inertiajs/vue3';
import { FileBarChart2 } from 'lucide-vue-next';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'لوحة التحكم', href: '/admin' },
    { title: 'التقارير', href: '/admin/reports' },
];
</script>

<template>
    <Head title="التقارير" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="space-y-6 p-5">
            <h1 class="text-2xl font-extrabold text-slate-900">التقارير</h1>

            <div class="flex flex-col items-center justify-center gap-3 rounded-2xl border border-dashed border-slate-300 bg-white p-16 text-center shadow-sm">
                <span class="flex h-14 w-14 items-center justify-center rounded-2xl bg-emerald-50 text-emerald-600">
                    <FileBarChart2 class="h-7 w-7" />
                </span>
                <h2 class="text-lg font-extrabold text-slate-800">قسم التقارير</h2>
                <p class="max-w-md text-sm text-slate-500">
                    هذه صفحة عامة جاهزة لإضافة تقارير مشروعك. أضِف هنا الرسوم البيانية والجداول التي تحتاجها.
                </p>
            </div>
        </div>
    </AppLayout>
</template>
