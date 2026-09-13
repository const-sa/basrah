<script setup lang="ts">
// A date field picked in Hijri and stored in Gregorian: the clerk agrees the
// date with the client in Hijri, and <input type="date"> only shows Gregorian.
import { todayString } from '@/lib/dates';
import { HIJRI_MONTHS, hijriMonthLength, hijriOf, hijriToDate, toHijri, weekdayName } from '@/lib/hijri';
import { CalendarDays, ChevronLeft, ChevronRight } from 'lucide-vue-next';
import { computed, onBeforeUnmount, ref, watch } from 'vue';

const props = withDefaults(
    defineProps<{
        modelValue: string;
        /** Earliest day that may be picked — Gregorian. */
        min?: string | null;
        disabled?: boolean;
    }>(),
    { min: null, disabled: false },
);

const emit = defineEmits<{ 'update:modelValue': [value: string] }>();

/** ح ن ث ر خ ج س — the week as an Arabic calendar heads it. */
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

const monthOf = (date: string) => {
    const h = hijriOf(date || today) ?? hijriOf(today)!;

    return { year: h.year, month: h.month };
};

const view = ref(monthOf(props.modelValue));

// The grid follows the value, or it would open away from the day being changed.
watch(
    () => props.modelValue,
    (value) => {
        if (value) view.value = monthOf(value);
    },
);

interface Cell {
    date: string;
    day: number;
    disabled: boolean;
}

/** The month's days, led by blanks so the first falls under its weekday. */
const cells = computed<(Cell | null)[]>(() => {
    const { year, month } = view.value;
    const first = hijriToDate(year, month, 1);
    const lead = new Date(`${first}T12:00:00`).getDay();

    return [
        ...Array.from<null>({ length: lead }).fill(null),
        ...Array.from({ length: hijriMonthLength(year, month) }, (_, i) => {
            const date = hijriToDate(year, month, i + 1);

            return { date, day: i + 1, disabled: !!props.min && date < props.min };
        }),
    ];
});

const viewLabel = computed(() => `${HIJRI_MONTHS[view.value.month - 1]} ${view.value.year} هـ`);

const shiftMonth = (step: number) => {
    let { year, month } = view.value;

    month += step;

    if (month < 1) {
        month = 12;
        year -= 1;
    }

    if (month > 12) {
        month = 1;
        year += 1;
    }

    view.value = { year, month };
};

/** Today is only a jump to its month — picking it still obeys min. */
const goToday = () => {
    view.value = monthOf(today);
};

const pick = (cell: Cell) => {
    if (cell.disabled) return;

    emit('update:modelValue', cell.date);
    open.value = false;
};

const toggle = () => {
    if (!props.disabled) open.value = !open.value;
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
        view.value = monthOf(props.modelValue);
    }
});

onBeforeUnmount(() => {
    document.removeEventListener('mousedown', onDocumentPointer);
    document.removeEventListener('keydown', onKeydown);
});
</script>

<template>
    <div ref="root" class="relative">
        <button
            type="button"
            :disabled="disabled"
            @click="toggle"
            class="flex w-full items-center gap-2 rounded-xl border border-slate-300 bg-white px-3 py-2 text-right transition hover:border-slate-400 focus:border-emerald-500 focus:outline-none focus:ring-2 focus:ring-emerald-100 disabled:cursor-not-allowed disabled:bg-slate-50"
            :class="open && 'border-emerald-500 ring-2 ring-emerald-100'"
        >
            <CalendarDays class="h-4 w-4 shrink-0 text-slate-500" />
            <span class="flex-1">
                <span class="block text-[15px] font-extrabold leading-tight text-slate-900">{{ toHijri(modelValue) || '—' }}</span>
                <!-- Gregorian under it, not instead of it: that is what is stored. -->
                <span class="mt-0.5 block text-[11px] font-bold leading-none text-slate-500" dir="ltr">{{ modelValue || '—' }}</span>
            </span>
        </button>

        <div
            v-if="open"
            class="absolute top-full z-40 mt-1 w-[18rem] rounded-2xl border border-slate-200 bg-white p-3 shadow-xl ltr:left-0 rtl:right-0"
        >
            <!-- The page is RTL, so the right chevron steps back a month. -->
            <div class="mb-2 flex items-center justify-between gap-2">
                <button type="button" @click="shiftMonth(-1)" class="rounded-lg p-1.5 text-slate-500 transition hover:bg-slate-100 hover:text-slate-800">
                    <ChevronRight class="h-4 w-4" />
                </button>
                <div class="text-sm font-extrabold text-slate-800">{{ viewLabel }}</div>
                <button type="button" @click="shiftMonth(1)" class="rounded-lg p-1.5 text-slate-500 transition hover:bg-slate-100 hover:text-slate-800">
                    <ChevronLeft class="h-4 w-4" />
                </button>
            </div>

            <div class="grid grid-cols-7 gap-0.5 text-center">
                <div v-for="d in WEEKDAYS" :key="d.short" :title="d.name" class="py-1 text-[11px] font-extrabold text-slate-400">
                    {{ d.short }}
                </div>

                <template v-for="(cell, i) in cells" :key="cell?.date ?? `gap-${i}`">
                    <span v-if="!cell" />
                    <button
                        v-else
                        type="button"
                        :disabled="cell.disabled"
                        :title="cell.date"
                        @click="pick(cell)"
                        class="flex h-9 items-center justify-center rounded-lg text-[13px] font-bold transition"
                        :class="[
                            cell.date === modelValue
                                ? 'bg-emerald-600 text-white shadow-sm'
                                : cell.disabled
                                  ? 'cursor-not-allowed text-slate-300'
                                  : 'text-slate-700 hover:bg-slate-100',
                            cell.date === today && cell.date !== modelValue ? 'ring-1 ring-inset ring-emerald-400' : '',
                        ]"
                    >
                        {{ cell.day }}
                    </button>
                </template>
            </div>

            <div class="mt-2 flex items-center justify-between gap-2 border-t border-slate-100 pt-2">
                <span class="text-[11px] font-bold text-slate-500">{{ weekdayName(modelValue) }}</span>
                <button type="button" @click="goToday" class="rounded-lg px-2 py-1 text-[11px] font-bold text-emerald-700 transition hover:bg-emerald-50">
                    اليوم
                </button>
            </div>
        </div>
    </div>
</template>
