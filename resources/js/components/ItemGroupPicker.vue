<script setup lang="ts">
import { type ItemGroupOption } from '@/types/item-groups';
import { Layers, Plus } from 'lucide-vue-next';
import { computed, ref } from 'vue';

const props = withDefaults(
    defineProps<{
        groups: ItemGroupOption[];
        disabled?: boolean;
        label?: string;
    }>(),
    {
        disabled: false,
        label: 'اختر من مجموعة محفوظة',
    },
);

const emit = defineEmits<{ select: [group: ItemGroupOption] }>();

/**
 * The select snaps back to its placeholder after every pick: it is a button
 * that happens to be a list, not a field holding a value. Leaving the last
 * group showing would read as «this invoice is that group», which it is not —
 * the lines are editable from the moment they land.
 */
const chosen = ref<number | ''>('');

const onChange = () => {
    const group = props.groups.find((g) => g.id === chosen.value);
    chosen.value = '';

    if (group) emit('select', group);
};

const isEmpty = computed(() => props.groups.length === 0);
</script>

<template>
    <div class="flex items-center gap-2">
        <label class="flex shrink-0 items-center gap-1.5 text-xs font-extrabold text-slate-700">
            <Layers class="h-4 w-4" /> {{ label }}
        </label>

        <div class="relative">
            <select
                v-model="chosen"
                :disabled="disabled || isEmpty"
                :title="isEmpty ? 'لا توجد مجموعات محفوظة' : label"
                class="w-56 appearance-none rounded-lg border-2 border-slate-400 bg-white py-2 pr-3 pl-8 text-sm font-extrabold text-slate-950 transition focus:border-emerald-700 focus:outline-none focus:ring-2 focus:ring-emerald-200 disabled:cursor-not-allowed disabled:border-slate-300 disabled:bg-slate-100 disabled:text-slate-500"
                @change="onChange"
            >
                <option value="" disabled>
                    {{ isEmpty ? '— لا توجد مجموعات —' : '— اختر مجموعة —' }}
                </option>
                <option v-for="g in groups" :key="g.id" :value="g.id">
                    {{ g.name }} ({{ g.items.length }} صنف)
                </option>
            </select>
            <Plus class="pointer-events-none absolute top-1/2 left-2.5 h-3.5 w-3.5 -translate-y-1/2 text-slate-500" />
        </div>
    </div>
</template>
