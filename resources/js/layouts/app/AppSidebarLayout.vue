<script setup lang="ts">
import AppContent from '@/components/AppContent.vue';
import AppShell from '@/components/AppShell.vue';
import AppSidebar from '@/components/AppSidebar.vue';
import AppSidebarHeader from '@/components/AppSidebarHeader.vue';
import FlashToast from '@/components/FlashToast.vue';
import type { BreadcrumbItemType, SharedData } from '@/types';
import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

interface Props {
    breadcrumbs?: BreadcrumbItemType[];
}

withDefaults(defineProps<Props>(), {
    breadcrumbs: () => [],
});

const page = usePage<SharedData>();
const version = computed(() => page.props.version);
</script>

<template>
    <AppShell variant="sidebar">
        <AppSidebar />
        <AppContent variant="sidebar">
            <AppSidebarHeader :breadcrumbs="breadcrumbs" />
            <slot />
            <footer class="mt-auto flex flex-wrap items-center justify-center gap-x-2 gap-y-1 border-t border-slate-200 px-5 py-3 text-center text-xs font-bold text-slate-500 print:hidden">
                <span>
                    برمجة وتصميم
                    <a href="https://www.const-tech.org/" target="_blank" rel="noopener" class="brand-text-gradient hover:brightness-110">كواكب التقنية</a>
                    🪐
                </span>
                <span class="text-slate-400">|</span>
                <span>الإصدار {{ version }}</span>
            </footer>
        </AppContent>
        <FlashToast />
    </AppShell>
</template>
