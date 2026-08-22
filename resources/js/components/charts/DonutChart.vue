<script setup lang="ts">
/** حلقة أجزاء من كل — تصلح حين يكون المجموع معنًى (مشغول + متاح = الوحدات). */
import { computed } from 'vue';

const props = withDefaults(
    defineProps<{
        segments: { label: string; value: number; color: string }[];
        centerValue: string | number;
        centerLabel?: string;
        size?: number;
    }>(),
    { size: 132 },
);

const R = 52;
const C = 2 * Math.PI * R;
const GAP = 3; // فاصلٌ بلون السطح يمنع التصاق قوسين متجاورين

const total = computed(() => props.segments.reduce((a, s) => a + s.value, 0));

const arcs = computed(() => {
    let acc = 0;

    return props.segments.map((s) => {
        const frac = total.value ? s.value / total.value : 0;
        const len = Math.max(0, frac * C - (frac > 0 && frac < 1 ? GAP : 0));
        const arc = { ...s, dash: `${len.toFixed(2)} ${(C - len).toFixed(2)}`, offset: (-acc * C).toFixed(2) };
        acc += frac;

        return arc;
    });
});
</script>

<template>
    <div class="flex flex-col items-center gap-2">
        <svg :width="size" :height="size" viewBox="0 0 128 128" role="img" :aria-label="centerLabel">
            <circle cx="64" cy="64" :r="R" fill="none" stroke="rgba(255,255,255,0.08)" stroke-width="11" />
            <circle
                v-for="a in arcs"
                :key="a.label"
                cx="64"
                cy="64"
                :r="R"
                fill="none"
                :stroke="a.color"
                stroke-width="11"
                :stroke-dasharray="a.dash"
                :stroke-dashoffset="a.offset"
                transform="rotate(-90 64 64)"
            />
            <text x="64" y="60" text-anchor="middle" dominant-baseline="middle" fill="#E7EDF5" font-size="24" font-weight="800" direction="ltr">
                {{ centerValue }}
            </text>
            <text x="64" y="82" text-anchor="middle" dominant-baseline="middle" fill="#6F829B" font-size="11" font-weight="700">
                {{ centerLabel }}
            </text>
        </svg>

        <ul class="flex flex-wrap items-center justify-center gap-x-3 gap-y-1">
            <li v-for="s in segments" :key="s.label" class="flex items-center gap-1.5 text-[11px] font-bold text-slate-400">
                <span class="h-2.5 w-2.5 rounded-sm" :style="{ background: s.color }"></span>
                {{ s.label }}
                <span class="text-slate-300" dir="ltr">{{ s.value }}</span>
            </li>
        </ul>
    </div>
</template>
