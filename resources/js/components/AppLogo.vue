<script setup lang="ts">
import { LayoutDashboard } from 'lucide-vue-next';

interface Props {
    class?: string;
}

defineProps<Props>();
</script>

<template>
    <div class="flex aspect-square size-9 items-center justify-center rounded-xl brand-gradient text-white shadow-[0_4px_10px_rgba(16,185,129,0.25)]">
        <LayoutDashboard class="size-5" />
    </div>
    <div class="ms-1 grid flex-1 text-start text-sm">
        <span class="truncate font-extrabold leading-none text-white">لوحة التحكم</span>
        <span class="mt-0.5 text-xs text-slate-300">Admin Dashboard</span>
    </div>
</template>
