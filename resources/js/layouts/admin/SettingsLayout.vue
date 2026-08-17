<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { FileText, Flag, MapPin, Route, Settings as SettingsIcon, Truck } from 'lucide-vue-next';
import { computed } from 'vue';

const page = usePage();
const currentPath = computed(() => page.url);

const tabs = [
    { label: 'الإعدادات', href: '/admin/settings', icon: SettingsIcon },
    { label: 'العقود', href: '/admin/settings/contracts', icon: FileText },
    { label: 'الوجهات', href: '/admin/settings/destinations', icon: MapPin },
    { label: 'أسعار الرحلات', href: '/admin/settings/trip-prices', icon: Route },
    { label: 'أنواع المركبات', href: '/admin/settings/vehicle-types', icon: Truck },
    { label: 'الجنسيات', href: '/admin/settings/nationalities', icon: Flag },
];

const isActive = (href: string) => {
    if (href === '/admin/settings') return currentPath.value === '/admin/settings';
    return currentPath.value.startsWith(href);
};

defineProps<{
    title: string;
}>();
</script>

<template>
    <div class="space-y-5 p-5">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div class="order-2 flex flex-wrap items-center gap-2 sm:order-1">
                <Link
                    v-for="tab in tabs"
                    :key="tab.href"
                    :href="tab.href"
                    :class="[
                        'inline-flex items-center gap-2 rounded-lg px-4 py-2 text-sm font-bold transition',
                        isActive(tab.href)
                            ? 'brand-gradient text-white shadow-lg ring-2 ring-indigo-300'
                            : 'bg-white text-slate-700 border border-slate-200 hover:bg-slate-50 hover:text-indigo-600 hover:border-indigo-200',
                    ]"
                >
                    <component :is="tab.icon" class="h-4 w-4" />
                    <span>{{ tab.label }}</span>
                </Link>
            </div>

            <div class="order-1 text-right sm:order-2">
                <div class="text-xs text-muted-foreground">الرئيسية</div>
                <h1 class="text-2xl font-extrabold text-foreground">{{ title }}</h1>
            </div>
        </div>

        <div class="rounded-xl border border-slate-200 bg-white p-6 shadow-sm">
            <slot />
        </div>
    </div>
</template>
