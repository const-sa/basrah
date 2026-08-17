<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head } from '@inertiajs/vue3';
import { AlertTriangle, Bell, CheckCircle2, Contact, Info, LifeBuoy, type LucideIcon } from 'lucide-vue-next';
import { computed } from 'vue';

interface NotificationRow {
    id: number;
    type: string;
    title: string;
    body: string;
    time: string;
    read: boolean;
}

const props = defineProps<{
    notifications: NotificationRow[];
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'لوحة التحكم', href: '/admin' },
    { title: 'الإشعارات', href: '/admin/notifications' },
];

const styleMap: Record<string, { icon: LucideIcon; classes: string }> = {
    success: { icon: CheckCircle2, classes: 'bg-emerald-100 text-emerald-600' },
    client: { icon: Contact, classes: 'bg-sky-100 text-sky-600' },
    ticket: { icon: LifeBuoy, classes: 'bg-indigo-100 text-indigo-600' },
    warning: { icon: AlertTriangle, classes: 'bg-amber-100 text-amber-600' },
    info: { icon: Info, classes: 'bg-slate-100 text-slate-600' },
};

const styleFor = (type: string) => styleMap[type] ?? styleMap.info;

const unreadCount = computed(() => props.notifications.filter((n) => !n.read).length);
</script>

<template>
    <Head title="الإشعارات" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="space-y-5 p-5">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h1 class="flex items-center gap-2 text-2xl font-extrabold text-slate-900">
                        <Bell class="h-6 w-6 text-emerald-600" /> الإشعارات
                    </h1>
                    <p class="mt-1 text-sm text-slate-500">لديك {{ unreadCount }} إشعار غير مقروء</p>
                </div>
            </div>

            <!-- قائمة الإشعارات -->
            <div class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                <ul class="divide-y divide-slate-100">
                    <li v-for="n in notifications" :key="n.id"
                        :class="['flex items-start gap-3 px-4 py-4 transition hover:bg-slate-50', !n.read ? 'bg-emerald-50/40' : '']">
                        <span :class="['flex h-10 w-10 shrink-0 items-center justify-center rounded-full', styleFor(n.type).classes]">
                            <component :is="styleFor(n.type).icon" class="h-5 w-5" />
                        </span>
                        <div class="min-w-0 flex-1">
                            <div class="flex items-center gap-2">
                                <p class="font-bold text-slate-900">{{ n.title }}</p>
                                <span v-if="!n.read" class="h-2 w-2 shrink-0 rounded-full bg-rose-500" title="غير مقروء" />
                            </div>
                            <p class="mt-0.5 text-sm text-slate-600">{{ n.body }}</p>
                            <p class="mt-1 text-xs text-slate-400">{{ n.time }}</p>
                        </div>
                    </li>
                    <li v-if="notifications.length === 0" class="px-4 py-12 text-center text-sm text-slate-400">
                        لا توجد إشعارات حالياً.
                    </li>
                </ul>
            </div>
        </div>
    </AppLayout>
</template>
