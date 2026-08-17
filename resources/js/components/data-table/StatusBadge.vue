<script setup lang="ts">
import { computed } from 'vue';

type Variant = 'warning' | 'info' | 'success' | 'danger' | 'primary' | 'neutral';

const props = withDefaults(defineProps<{ variant?: Variant; label?: string }>(), {
    variant: 'neutral',
});

// حبّة حالة صلبة اللون بنص أبيض — بنفس أسلوب الصورة (أصفر «بانتظار» / أزرق «مكتمل»).
const classes = computed(
    () =>
        ({
            warning: 'bg-amber-400 text-white',
            info: 'bg-blue-600 text-white',
            success: 'bg-emerald-500 text-white',
            danger: 'bg-red-500 text-white',
            primary: 'bg-cyan-500 text-white',
            neutral: 'bg-slate-400 text-white',
        })[props.variant],
);
</script>

<template>
    <span :class="['inline-flex items-center rounded-md px-2.5 py-1 text-xs font-bold whitespace-nowrap', classes]">
        <slot>{{ label }}</slot>
    </span>
</template>
