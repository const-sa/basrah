<script setup lang="ts">
/**
 * منحنى سلسلة زمنية مصغّر — قياس واحد فقط.
 *
 * لا يُرسم قياسان بمحورين مختلفين في رسم واحد لأن سُلّمين على شبكة
 * واحدة يصنعان تقاطعات لا معنى لها؛ القياس الثاني يأخذ رسمًا مستقلًا.
 */
import { computed, ref, useId } from 'vue';

const props = withDefaults(
    defineProps<{
        values: number[];
        labels: string[];
        color: string;
        title: string;
        format?: (n: number) => string;
        height?: number;
    }>(),
    { height: 128 },
);

const uid = useId().replace(/[^a-zA-Z0-9_-]/g, '');
const W = 620;
const PAD = { t: 14, b: 20, s: 10 };

const H = computed(() => props.height);
const max = computed(() => Math.max(1, ...props.values));
const step = computed(() => (W - PAD.s * 2) / Math.max(1, props.values.length - 1));
const plot = computed(() => H.value - PAD.t - PAD.b);

const x = (i: number) => PAD.s + i * step.value;
const y = (v: number) => PAD.t + (1 - v / max.value) * plot.value;

const line = computed(() => props.values.map((v, i) => `${i ? 'L' : 'M'}${x(i).toFixed(1)},${y(v).toFixed(1)}`).join(' '));

const area = computed(() => {
    const n = props.values.length;

    return n ? `${line.value} L${x(n - 1).toFixed(1)},${H.value - PAD.b} L${x(0).toFixed(1)},${H.value - PAD.b} Z` : '';
});

const grid = computed(() => [0, 0.5, 1].map((f) => PAD.t + f * plot.value));

// عرض كل تسمية يحجب جارتها على الجوال، فتُعرض واحدة كل خطوتين.
const tickEvery = computed(() => Math.ceil(props.values.length / 7));

const hover = ref<number | null>(null);
const nf = new Intl.NumberFormat('ar-SA-u-nu-latn', { maximumFractionDigits: 0 });
const fmt = (n: number) => (props.format ? props.format(n) : nf.format(n));
const total = computed(() => props.values.reduce((a, b) => a + b, 0));
</script>

<template>
    <div>
        <div class="mb-1 flex items-baseline justify-between gap-2">
            <span class="text-[11px] font-bold text-slate-400">{{ title }}</span>
            <span class="text-[11px] font-bold" :style="{ color }" dir="ltr">{{ fmt(total) }}</span>
        </div>

        <div class="relative" @mouseleave="hover = null">
            <svg :viewBox="`0 0 ${W} ${H}`" class="w-full" role="img" :aria-label="title">
                <defs>
                    <linearGradient :id="`fill-${uid}`" x1="0" y1="0" x2="0" y2="1">
                        <stop offset="0%" :stop-color="color" stop-opacity="0.38" />
                        <stop offset="100%" :stop-color="color" stop-opacity="0.02" />
                    </linearGradient>
                </defs>

                <line v-for="(g, i) in grid" :key="i" :x1="0" :x2="W" :y1="g" :y2="g" stroke="rgba(255,255,255,0.07)" stroke-width="1" />

                <path :d="area" :fill="`url(#fill-${uid})`" />
                <path :d="line" fill="none" :stroke="color" stroke-width="2" stroke-linejoin="round" stroke-linecap="round" />

                <circle
                    v-if="values.length"
                    :cx="x(values.length - 1)"
                    :cy="y(values[values.length - 1])"
                    r="3.5"
                    :fill="color"
                    stroke="#0D1F38"
                    stroke-width="2"
                />
                <circle v-if="hover !== null" :cx="x(hover)" :cy="y(values[hover])" r="5" :fill="color" stroke="#0D1F38" stroke-width="2" />
                <line
                    v-if="hover !== null"
                    :x1="x(hover)"
                    :x2="x(hover)"
                    :y1="PAD.t - 6"
                    :y2="H - PAD.b"
                    stroke="rgba(255,255,255,0.25)"
                    stroke-width="1"
                />

                <text
                    v-for="(l, i) in labels"
                    :key="l + i"
                    v-show="i % tickEvery === 0 || i === labels.length - 1"
                    :x="x(i)"
                    :y="H - 6"
                    text-anchor="middle"
                    fill="#6F829B"
                    font-size="10"
                    direction="ltr"
                >
                    {{ l }}
                </text>

                <rect
                    v-for="(v, i) in values"
                    :key="`hit-${i}`"
                    :x="x(i) - step / 2"
                    y="0"
                    :width="step"
                    :height="H"
                    fill="transparent"
                    @mouseenter="hover = i"
                />
            </svg>

            <div
                v-if="hover !== null"
                class="pointer-events-none absolute -top-1 z-10 -translate-x-1/2 rounded-md border border-white/10 bg-[#0A1A2F] px-2 py-1 text-center shadow-lg"
                :style="{ left: `${((x(hover) / W) * 100).toFixed(2)}%` }"
            >
                <div class="text-[10px] font-bold text-slate-400" dir="ltr">{{ labels[hover] }}</div>
                <div class="text-xs font-extrabold" :style="{ color }" dir="ltr">{{ fmt(values[hover]) }}</div>
            </div>
        </div>
    </div>
</template>
