<script setup lang="ts">
import type { SharedData } from '@/types';
import { usePage } from '@inertiajs/vue3';
import { AlertTriangle, CheckCircle2, X } from 'lucide-vue-next';
import { ref, watch } from 'vue';

const page = usePage<SharedData>();

const show = ref(false);
const message = ref('');
const kind = ref<'success' | 'warning'>('success');
let timer: ReturnType<typeof setTimeout> | undefined;

const trigger = (text: string, type: 'success' | 'warning') => {
    message.value = text;
    kind.value = type;
    show.value = true;
    clearTimeout(timer);
    timer = setTimeout(() => (show.value = false), 4000);
};

watch(
    () => [page.props.flash?.success, page.props.flash?.warning] as const,
    ([success, warning]) => {
        if (success) trigger(success, 'success');
        else if (warning) trigger(warning, 'warning');
    },
    { immediate: true },
);
</script>

<template>
    <Transition
        enter-active-class="transition duration-300 ease-out"
        enter-from-class="translate-y-4 opacity-0"
        enter-to-class="translate-y-0 opacity-100"
        leave-active-class="transition duration-200 ease-in"
        leave-from-class="translate-y-0 opacity-100"
        leave-to-class="translate-y-4 opacity-0"
    >
        <div v-if="show && message" class="fixed bottom-6 left-1/2 z-[60] -translate-x-1/2 print:hidden">
            <div
                :class="[
                    'flex items-center gap-2.5 rounded-2xl px-4 py-3 text-sm font-bold text-white shadow-2xl',
                    kind === 'success' ? 'bg-emerald-600' : 'bg-amber-600',
                ]"
            >
                <CheckCircle2 v-if="kind === 'success'" class="h-5 w-5 shrink-0" />
                <AlertTriangle v-else class="h-5 w-5 shrink-0" />
                <span>{{ message }}</span>
                <button type="button" @click="show = false" class="rounded-lg p-0.5 text-white/80 transition hover:bg-white/20 hover:text-white">
                    <X class="h-4 w-4" />
                </button>
            </div>
        </div>
    </Transition>
</template>
