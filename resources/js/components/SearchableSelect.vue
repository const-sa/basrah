<script setup lang="ts" generic="T extends Record<string, any>">
/**
 * قائمة منسدلة بالبحث (نمط select2).
 *
 * بُنيت داخليًا بدل جلب مكتبة: الحاجة محدودة (بحث + تنقّل بلوحة المفاتيح
 * + اتجاه RTL)، وإضافة اعتمادية لأجلها تكلفة صيانة لا تُبرَّر.
 */
import { ChevronDown, Search, X } from 'lucide-vue-next';
import { computed, nextTick, onBeforeUnmount, ref, watch } from 'vue';

const props = withDefaults(
    defineProps<{
        modelValue: number | string | null;
        options: T[];
        /** مفتاح القيمة داخل الخيار. */
        valueKey?: string;
        /** مفتاح النص المعروض. */
        labelKey?: string;
        /** مفاتيح إضافية تدخل في البحث (كود، باركود…). */
        searchKeys?: string[];
        placeholder?: string;
        disabled?: boolean;
        /** إظهار زر المسح. */
        clearable?: boolean;
    }>(),
    {
        valueKey: 'id',
        labelKey: 'name',
        searchKeys: () => [],
        placeholder: '— اختر —',
        disabled: false,
        clearable: true,
    },
);

const emit = defineEmits<{
    'update:modelValue': [value: number | string | null];
    change: [option: T | null];
}>();

const open = ref(false);
const query = ref('');
const active = ref(0);
const root = ref<HTMLElement | null>(null);
const menu = ref<HTMLElement | null>(null);
const searchInput = ref<HTMLInputElement | null>(null);

const selected = computed<T | null>(
    () => props.options.find((o) => o[props.valueKey] === props.modelValue) ?? null,
);

const selectedLabel = computed(() => (selected.value ? String(selected.value[props.labelKey]) : ''));

/** البحث في النص المعروض وفي المفاتيح الإضافية معًا. */
const filtered = computed<T[]>(() => {
    const q = query.value.trim().toLowerCase();
    if (!q) return props.options;

    return props.options.filter((o) => {
        const haystack = [o[props.labelKey], ...props.searchKeys.map((k) => o[k])]
            .filter((v) => v !== null && v !== undefined)
            .join(' ')
            .toLowerCase();

        return haystack.includes(q);
    });
});

watch(filtered, () => (active.value = 0));

/**
 * القائمة تُعرض في <body> بموضع ثابت: داخل الجدول أغلفة ذات overflow
 * تقصّ أي عنصر مطلق، فكانت الخيارات تختفي تحت حافة البطاقة.
 */
const MENU_HEIGHT = 300;
const menuStyle = ref<Record<string, string>>({});

const place = () => {
    const el = root.value;
    if (!el) return;

    const rect = el.getBoundingClientRect();
    const width = Math.max(rect.width, 224);
    const below = window.innerHeight - rect.bottom;
    const flip = below < MENU_HEIGHT && rect.top > below;

    menuStyle.value = {
        width: `${width}px`,
        left: `${Math.max(8, Math.min(rect.left, window.innerWidth - width - 8))}px`,
        ...(flip
            ? { bottom: `${window.innerHeight - rect.top + 4}px` }
            : { top: `${rect.bottom + 4}px` }),
        maxHeight: `${Math.max(180, (flip ? rect.top : below) - 12)}px`,
    };
};

const openList = async () => {
    if (props.disabled) return;
    open.value = true;
    query.value = '';
    place();
    await nextTick();
    searchInput.value?.focus();
};

const close = () => {
    open.value = false;
    query.value = '';
};

const pick = (option: T) => {
    emit('update:modelValue', option[props.valueKey]);
    emit('change', option);
    close();
};

const clear = () => {
    emit('update:modelValue', null);
    emit('change', null);
};

const onKeydown = (e: KeyboardEvent) => {
    if (!open.value) return;

    if (e.key === 'ArrowDown') {
        e.preventDefault();
        active.value = Math.min(active.value + 1, filtered.value.length - 1);
    } else if (e.key === 'ArrowUp') {
        e.preventDefault();
        active.value = Math.max(active.value - 1, 0);
    } else if (e.key === 'Enter') {
        e.preventDefault();
        const option = filtered.value[active.value];
        if (option) pick(option);
    } else if (e.key === 'Escape') {
        close();
    }
};

// الإغلاق عند النقر خارج المكوّن — والقائمة خارجه في شجرة DOM.
const onDocumentClick = (e: MouseEvent) => {
    const target = e.target as Node;
    if (root.value?.contains(target) || menu.value?.contains(target)) return;
    close();
};

watch(open, (isOpen) => {
    if (isOpen) {
        document.addEventListener('mousedown', onDocumentClick);
        window.addEventListener('scroll', place, true);
        window.addEventListener('resize', place);
    } else {
        document.removeEventListener('mousedown', onDocumentClick);
        window.removeEventListener('scroll', place, true);
        window.removeEventListener('resize', place);
    }
});

onBeforeUnmount(() => {
    document.removeEventListener('mousedown', onDocumentClick);
    window.removeEventListener('scroll', place, true);
    window.removeEventListener('resize', place);
});
</script>

<template>
    <div ref="root" class="relative">
        <!-- الزر المعروض -->
        <button
            type="button"
            :disabled="disabled"
            @click="open ? close() : openList()"
            class="flex w-full items-center gap-1.5 rounded-lg border-2 border-slate-400 bg-white px-2.5 py-2 text-right text-sm transition focus:border-emerald-700 focus:outline-none focus:ring-2 focus:ring-emerald-200 disabled:cursor-not-allowed disabled:bg-slate-100 disabled:text-slate-600"
            :class="open && 'border-emerald-700 ring-2 ring-emerald-200'"
        >
            <span class="min-w-0 flex-1 truncate" :class="selected ? 'font-extrabold text-slate-950' : 'font-bold text-slate-600'">
                {{ selectedLabel || placeholder }}
            </span>

            <span
                v-if="clearable && selected && !disabled"
                role="button"
                tabindex="-1"
                title="مسح"
                @click.stop="clear"
                class="shrink-0 rounded p-0.5 text-slate-600 hover:bg-slate-200 hover:text-red-700"
            >
                <X class="h-3.5 w-3.5" />
            </span>

            <ChevronDown class="h-4 w-4 shrink-0 text-slate-700 transition" :class="open && 'rotate-180'" />
        </button>

        <!-- القائمة: في <body> حتى لا تقصّها أغلفة الجدول -->
        <Teleport to="body">
            <div
                v-if="open"
                ref="menu"
                dir="rtl"
                :style="menuStyle"
                class="fixed z-[100] flex flex-col overflow-hidden rounded-xl border-2 border-slate-400 bg-white shadow-2xl"
            >
                <div class="relative shrink-0 border-b-2 border-slate-300">
                    <Search class="absolute top-1/2 h-4 w-4 -translate-y-1/2 text-slate-600 ltr:left-2.5 rtl:right-2.5" />
                    <input
                        ref="searchInput"
                        v-model="query"
                        @keydown="onKeydown"
                        type="text"
                        placeholder="بحث…"
                        class="w-full border-0 py-2 text-sm font-bold text-slate-950 placeholder:font-normal placeholder:text-slate-600 focus:outline-none focus:ring-0 ltr:pl-8 ltr:pr-2 rtl:pl-2 rtl:pr-8"
                    />
                </div>

                <ul class="min-h-0 flex-1 overflow-y-auto py-1">
                    <li
                        v-for="(option, i) in filtered"
                        :key="option[valueKey]"
                        @click="pick(option)"
                        @mouseenter="active = i"
                        class="cursor-pointer px-3 py-1.5 text-sm transition"
                        :class="[
                            i === active ? 'bg-emerald-100' : '',
                            option[valueKey] === modelValue ? 'font-extrabold text-emerald-900' : 'font-medium text-slate-900',
                        ]"
                    >
                        <slot name="option" :option="option">{{ option[labelKey] }}</slot>
                    </li>

                    <li v-if="!filtered.length" class="px-3 py-4 text-center text-xs font-bold text-slate-600">
                        لا نتائج مطابقة
                    </li>
                </ul>
            </div>
        </Teleport>
    </div>
</template>
