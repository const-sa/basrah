<script setup lang="ts">
import { Head } from '@inertiajs/vue3';

import AppearanceTabs from '@/components/AppearanceTabs.vue';
import { type BreadcrumbItem } from '@/types';
import { Palette } from 'lucide-vue-next';

import AppLayout from '@/layouts/AppLayout.vue';
import SettingsLayout from '@/layouts/settings/Layout.vue';

const breadcrumbItems: BreadcrumbItem[] = [
    { title: 'لوحة التحكم', href: '/admin' },
    { title: 'المظهر', href: '/admin/settings/appearance' },
];
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbItems">
        <Head title="المظهر" />

        <SettingsLayout>
            <div class="mb-5 flex items-center gap-2 border-b border-slate-100 pb-3">
                <Palette class="h-5 w-5 text-blue-600" />
                <div>
                    <h2 class="text-lg font-bold text-slate-800">المظهر</h2>
                    <p class="text-sm font-medium text-slate-600">اختر مظهر اللوحة: فاتح أو داكن أو حسب الجهاز</p>
                </div>
            </div>

            <AppearanceTabs />
        </SettingsLayout>
    </AppLayout>
</template>
