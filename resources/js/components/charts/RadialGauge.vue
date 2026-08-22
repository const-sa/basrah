<script setup lang="ts">
/** حلقة نسبة — رقم واحد يُقرأ بلمحة، والقوس يقول أين موقعه من المئة. */
import { computed } from 'vue';

const props = withDefaults(
    defineProps<{
        value: number;
        color: string;
        caption?: string;
        size?: number;
    }>(),
    { size: 132 },
);

const R = 52;
const C = 2 * Math.PI * R;
const pct = computed(() => Math.max(0, Math.min(100, props.value)));
const dash = computed(() => `${((pct.value / 100) * C).toFixed(2)} ${C.toFixed(2)}`);
</script>

<template>
    <div class="flex flex-col items-center">
        <svg :width="size" :height="size" viewBox="0 0 128 128" role="img" :aria-label="`${pct}%`">
            <circle cx="64" cy="64" :r="R" fill="none" stroke="rgba(255,255,255,0.08)" stroke-width="11" />
            <circle
                cx="64"
                cy="64"
                :r="R"
                fill="none"
                :stroke="color"
                stroke-width="11"
                stroke-linecap="round"
                :stroke-dasharray="dash"
                transform="rotate(-90 64 64)"
            />
            <text x="64" y="62" text-anchor="middle" dominant-baseline="middle" fill="#E7EDF5" font-size="26" font-weight="800" direction="ltr">
                {{ pct }}%
            </text>
            <text x="64" y="84" text-anchor="middle" dominant-baseline="middle" fill="#6F829B" font-size="11" font-weight="700">
                {{ caption }}
            </text>
        </svg>
    </div>
</template>
