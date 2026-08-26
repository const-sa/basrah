<script setup lang="ts">
/**
 * A date field that draws the unit's diary while the date is being chosen.
 *
 * The native <input type="date"> has no way to grey out a taken day, so a
 * booked date looked exactly like a free one: the staff member picked it, and
 * only then did the availability panel refuse the save. Here a taken day is
 * struck out and cannot be clicked, and a partly taken one is flagged — the
 * refusal arrives where the choice is made.
 *
 * What the days mean is the caller's business: this component is told which
 * dates are closed and which are partly booked, and never works it out itself.
 */
import { addDays, addMonths, monthName, startOfMonth, todayString } from '@/lib/dates';
import { CalendarDays, ChevronLeft, ChevronRight, Loader2 } from 'lucide-vue-next';
import { computed, onBeforeUnmount, ref, watch } from 'vue';

const props = withDefaults(
    defineProps<{
        modelValue: string;
        /** Earliest day that may be picked. */
        min?: string | null;
        /** Latest day that may be picked. */
        max?: string | null;
        /** Days that are closed outright — clicking them does nothing. */
        blocked?: string[];
        /** Days only partly taken: still pickable, but not everything on them is free. */
        partial?: string[];
        /** Days already covered by the booking, drawn as a band. */
        inRange?: string[];
        /** The diary is still being read — the marks on screen may be stale. */
        loading?: boolean;
        disabled?: boolean;
    }>(),
    {
        min: null,
        max: null,
        blocked: () => [],
        partial: () => [],
        inRange: () => [],
        loading: false,
        disabled: false,
    },
);

const emit = defineEmits<{
    'update:modelValue': [value: string];
    /** The grid now on screen, so the caller can load the days it shows. */
    view: [range: { from: string; to: string }];
}>();

/** ح ن ث ر خ ج س — the week as an Arabic calendar heads it, Sunday first. */
const WEEKDAYS = [
    { short: 'ح', name: 'الأحد' },
    { short: 'ن', name: 'الاثنين' },
    { short: 'ث', name: 'الثلاثاء' },
    { short: 'ر', name: 'الأربعاء' },
    { short: 'خ', name: 'الخميس' },
    { short: 'ج', name: 'الجمعة' },
    { short: 'س', name: 'السبت' },
];

const open = ref(false);
const root = ref<HTMLElement | null>(null);
const today = todayString();

const viewMonth = ref(startOfMonth(props.modelValue || today));

// Opening on a month away from the chosen date would hide the very day being
// changed, so the grid follows the value wherever it moves.
watch(
    () => props.modelValue,
    (value) => {
        if (value) viewMonth.value = startOfMonth(value);
    },
);

/**
 * Six whole weeks from the Sunday on or before the first of the month. A fixed
 * span keeps the popover from changing height as the months are paged.
 */
const gridRange = computed(() => {
    const first = new Date(`${viewMonth.value}T00:00:00`);
    const from = addDays(viewMonth.value, -first.getDay());

    return { from, to: addDays(from, 41) };
});

watch(gridRange, (range) => emit('view', range), { immediate: true });

const blockedSet = computed(() => new Set(props.blocked));
const partialSet = computed(() => new Set(props.partial));
const rangeSet = computed(() => new Set(props.inRange));

interface Cell {
    date: string;
    day: number;
    inMonth: boolean;
    blocked: boolean;
    partial: boolean;
    inRange: boolean;
    disabled: boolean;
}

const cells = computed<Cell[]>(() => {
    const month = viewMonth.value.slice(0, 7);

    return Array.from({ length: 42 }, (_, i) => {
        const date = addDays(gridRange.value.from, i);
        const blocked = blockedSet.value.has(date);
        const outOfBounds = (!!props.min && date < props.min) || (!!props.max && date > props.max);

        return {
            date,
            day: Number(date.slice(8)),
            inMonth: date.slice(0, 7) === month,
            blocked,
            partial: partialSet.value.has(date),
            inRange: rangeSet.value.has(date),
            disabled: blocked || outOfBounds,
        };
    });
});

const cellTitle = (cell: Cell): string => {
    if (cell.blocked) return 'محجوز بالكامل';
    if (cell.partial) return 'بعض الفترات محجوزة';

    return cell.date;
};

const shiftMonth = (months: number) => {
    viewMonth.value = startOfMonth(addMonths(viewMonth.value, months));
};

/** Today is only a jump to its month — picking it is still the grid's rules. */
const goToday = () => {
    viewMonth.value = startOfMonth(today);
};

const pick = (cell: Cell) => {
    if (cell.disabled) return;

    emit('update:modelValue', cell.date);
    open.value = false;
};

const toggle = () => {
    if (props.disabled) return;

    open.value = !open.value;
};

const onDocumentPointer = (e: MouseEvent) => {
    if (!root.value?.contains(e.target as Node)) open.value = false;
};

const onKeydown = (e: KeyboardEvent) => {
    if (e.key === 'Escape') open.value = false;
};

watch(open, (isOpen) => {
    if (isOpen) {
        document.addEventListener('mousedown', onDocumentPointer);
        document.addEventListener('keydown', onKeydown);
    } else {
        document.removeEventListener('mousedown', onDocumentPointer);
        document.removeEventListener('keydown', onKeydown);

        // Paging months and then closing without picking must not leave the
        // caller loading a stretch of days the chosen date is not in — the
        // marks around it would quietly go blank.
        viewMonth.value = startOfMonth(props.modelValue || today);
    }
});

onBeforeUnmount(() => {
    document.removeEventListener('mousedown', onDocumentPointer);
    document.removeEventListener('keydown', onKeydown);
});

/** Marks worth explaining — the legend hides when there is nothing to explain. */
const hasMarks = computed(() => props.blocked.length > 0 || props.partial.length > 0);
</script>

<template>
    <div ref="root" class="relative">
        <button
            type="button"
            :disabled="disabled"
            @click="toggle"
            class="flex w-full items-center gap-2 rounded-xl border border-slate-400 bg-white px-3 py-2.5 text-right text-sm transition hover:border-slate-500 focus:border-emerald-500 focus:outline-none focus:ring-2 focus:ring-emerald-100 disabled:cursor-not-allowed disabled:bg-slate-100"
            :class="open && 'border-teal-500 ring-2 ring-teal-100'"
        >
            <CalendarDays class="h-4 w-4 shrink-0 text-slate-500" />
            <span class="flex-1 font-extrabold text-slate-900" dir="ltr">{{ modelValue || '—' }}</span>
            <Loader2 v-if="loading" class="h-3.5 w-3.5 shrink-0 animate-spin text-slate-400" />
        </button>

        <div
            v-if="open"
            class="absolute top-full z-40 mt-1 w-[19rem] rounded-2xl border border-slate-200 bg-white p-3 shadow-xl ltr:left-0 rtl:right-0"
        >
            <!-- The page is RTL, so the right-hand chevron is the one that steps back a month -->
            <div class="mb-2 flex items-center justify-between gap-2">
                <button type="button" @click="shiftMonth(-1)" class="rounded-lg p-1.5 text-slate-500 transition hover:bg-slate-100 hover:text-slate-800">
                    <ChevronRight class="h-4 w-4" />
                </button>
                <div class="text-sm font-extrabold text-slate-800">{{ monthName(viewMonth) }}</div>
                <button type="button" @click="shiftMonth(1)" class="rounded-lg p-1.5 text-slate-500 transition hover:bg-slate-100 hover:text-slate-800">
                    <ChevronLeft class="h-4 w-4" />
                </button>
            </div>

            <div class="grid grid-cols-7 gap-0.5 text-center">
                <div v-for="d in WEEKDAYS" :key="d.short" :title="d.name" class="py-1 text-[11px] font-extrabold text-slate-400">
                    {{ d.short }}
                </div>

                <button
                    v-for="cell in cells"
                    :key="cell.date"
                    type="button"
                    :disabled="cell.disabled"
                    :title="cellTitle(cell)"
                    @click="pick(cell)"
                    class="relative h-8 rounded-lg text-xs font-bold transition"
                    :class="[
                        cell.date === modelValue
                            ? 'bg-teal-600 text-white shadow-sm'
                            : cell.blocked
                              ? 'cursor-not-allowed bg-red-50 text-red-300 line-through'
                              : cell.disabled
                                ? 'cursor-not-allowed text-slate-300'
                                : cell.inRange
                                  ? 'bg-teal-50 text-teal-700 hover:bg-teal-100'
                                  : 'text-slate-700 hover:bg-slate-100',
                        cell.inMonth || cell.date === modelValue ? '' : 'opacity-40',
                        cell.date === today && cell.date !== modelValue ? 'ring-1 ring-inset ring-teal-400' : '',
                    ]"
                >
                    {{ cell.day }}
                    <span
                        v-if="cell.partial && !cell.blocked"
                        class="absolute inset-x-0 bottom-0.5 mx-auto h-1 w-1 rounded-full"
                        :class="cell.date === modelValue ? 'bg-white' : 'bg-amber-500'"
                    />
                </button>
            </div>

            <div class="mt-2 flex items-center justify-between gap-2 border-t border-slate-100 pt-2">
                <div v-if="hasMarks" class="flex flex-wrap items-center gap-2 text-[10px] font-bold text-slate-500">
                    <span class="flex items-center gap-1"><span class="h-2 w-2 rounded-sm bg-red-200"></span> محجوز</span>
                    <span class="flex items-center gap-1"><span class="h-1.5 w-1.5 rounded-full bg-amber-500"></span> فترات محجوزة</span>
                </div>
                <span v-else></span>
                <button type="button" @click="goToday" class="rounded-lg px-2 py-1 text-[11px] font-bold text-teal-700 transition hover:bg-teal-50">
                    اليوم
                </button>
            </div>
        </div>
    </div>
</template>
