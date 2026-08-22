<script setup lang="ts">
import { LayoutDashboard } from 'lucide-vue-next';

interface Props {
    class?: string;
    // على الترويسة الداكنة ينقلب الشعار إلى زجاجٍ فاتح، وعلى الفاتحة يبقى أزرق.
    tone?: 'light' | 'dark';
    // الصفّ الواحد ضيّق؛ فإن ضاق أُسقط السطر الإنجليزي وبقي الاسم.
    subtitle?: boolean;
}

withDefaults(defineProps<Props>(), { tone: 'light', subtitle: true });
</script>

<template>
    <div
        class="flex aspect-square size-9 items-center justify-center rounded-xl text-white"
        :class="tone === 'dark' ? 'bg-white/15 ring-1 ring-inset ring-white/25' : 'bg-blue-700 shadow-[0_4px_10px_rgba(29,78,216,0.25)]'"
    >
        <LayoutDashboard class="size-5" />
    </div>
    <div class="ms-1 grid flex-1 text-start text-sm">
        <span class="truncate font-extrabold leading-none" :class="tone === 'dark' ? 'text-white' : 'text-slate-900'">لوحة التحكم</span>
        <span v-if="subtitle" class="mt-0.5 text-xs" :class="tone === 'dark' ? 'text-slate-300' : 'text-slate-500'">Admin Dashboard</span>
    </div>
</template>
