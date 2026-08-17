<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { computed, type Component } from 'vue';

type Variant = 'primary' | 'info' | 'success' | 'warning' | 'danger' | 'secondary';

const props = withDefaults(
    defineProps<{
        value: number | string;
        label: string;
        variant?: Variant;
        icon?: Component;
        footerText?: string;
        footerHref?: string;
    }>(),
    { variant: 'primary' },
);

// مربّع إحصائي بستايل AdminLTE small-box.
const bgClass = computed(
    () =>
        ({
            primary: 'lte-bg-primary',
            info: 'lte-bg-info',
            success: 'lte-bg-success',
            warning: 'lte-bg-warning',
            danger: 'lte-bg-danger',
            secondary: 'lte-bg-secondary',
        })[props.variant],
);
</script>

<template>
    <div :class="['lte-small-box', bgClass]">
        <div class="inner">
            <span class="lte-value">{{ value }}</span>
            <span class="lte-label">{{ label }}</span>
        </div>
        <component :is="icon" v-if="icon" class="lte-icon h-16 w-16" />
        <Link v-if="footerText && footerHref" :href="footerHref" class="lte-footer">{{ footerText }}</Link>
        <span v-else-if="footerText" class="lte-footer">{{ footerText }}</span>
    </div>
</template>
