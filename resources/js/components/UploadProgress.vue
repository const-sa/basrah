<script setup lang="ts">
import { computed } from 'vue';

/**
 * شريط تحميل موحّد لكل عمليات الرفع في المشروع.
 * يمرَّر إليه كائن التقدّم القادم من Inertia (form.progress) ويظهر تلقائياً أثناء الرفع فقط.
 */
const props = withDefaults(
    defineProps<{
        progress: { percentage?: number | null } | null | undefined;
        label?: string;
    }>(),
    { label: 'جارٍ الرفع' },
);

const percent = computed(() => Math.round(props.progress?.percentage ?? 0));
</script>

<template>
    <Transition
        enter-active-class="transition duration-200 ease-out"
        enter-from-class="opacity-0 -translate-y-1"
        enter-to-class="opacity-100 translate-y-0"
        leave-active-class="transition duration-200 ease-in"
        leave-from-class="opacity-100"
        leave-to-class="opacity-0"
    >
        <div v-if="progress" class="mt-2" role="progressbar" :aria-valuenow="percent" aria-valuemin="0" aria-valuemax="100">
            <div class="mb-1 flex items-center justify-between text-xs font-bold text-slate-600">
                <span class="inline-flex items-center gap-1.5">
                    <span class="h-2 w-2 animate-pulse rounded-full bg-emerald-500"></span>
                    {{ label }}
                </span>
                <span class="tabular-nums text-emerald-600">{{ percent }}%</span>
            </div>
            <div class="h-2 w-full overflow-hidden rounded-full bg-slate-200">
                <div
                    class="h-full rounded-full bg-gradient-to-l from-emerald-500 to-teal-400 transition-all duration-150 ease-out"
                    :style="{ width: percent + '%' }"
                >
                    <div class="h-full w-full animate-[shimmer_1.1s_linear_infinite] bg-[linear-gradient(90deg,transparent,rgba(255,255,255,.55),transparent)] bg-[length:40px_100%]"></div>
                </div>
            </div>
        </div>
    </Transition>
</template>

<style scoped>
@keyframes shimmer {
    from {
        background-position: -40px 0;
    }
    to {
        background-position: 100% 0;
    }
}
</style>
