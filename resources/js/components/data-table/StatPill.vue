<script setup lang="ts">
import { computed } from 'vue';

type Variant = 'primary' | 'success' | 'info' | 'warning' | 'danger' | 'dark';

const props = withDefaults(defineProps<{ label: string; value?: number | string; variant?: Variant }>(), {
    variant: 'primary',
});

// حبّات إحصائية مثل أعلى الصورة (سماوية/خضراء…): «كل الطلبات : 7».
const classes = computed(
    () =>
        ({
            primary: 'bg-cyan-500',
            success: 'bg-emerald-500',
            info: 'bg-blue-600',
            warning: 'bg-amber-400',
            danger: 'bg-red-500',
            dark: 'bg-slate-700',
        })[props.variant],
);
</script>

<template>
    <span :class="['inline-flex items-center gap-1.5 rounded-lg px-3.5 py-2 text-sm font-bold text-white shadow-sm', classes]">
        <span>{{ label }}</span>
        <template v-if="value !== undefined">
            <span class="opacity-80">:</span>
            <span>{{ value }}</span>
        </template>
    </span>
</template>
