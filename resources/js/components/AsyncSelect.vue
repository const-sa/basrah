<script setup lang="ts" generic="T extends Record<string, any>">
import { ChevronDown, Search, X } from 'lucide-vue-next';
import { computed, nextTick, onBeforeUnmount, ref, watch } from 'vue';

const props = withDefaults(
    defineProps<{
        modelValue: number | string | null;
        /** الرابط الأساسي لجلب البيانات (مثل /api/search?type=clients) */
        apiUrl: string;
        /** مفتاح القيمة داخل الخيار. */
        valueKey?: string;
        /** مفتاح النص المعروض. */
        labelKey?: string;
        /** الخيار المحدد مسبقًا في حال التعديل */
        initialOption?: T | null;
        placeholder?: string;
        disabled?: boolean;
        clearable?: boolean;
    }>(),
    {
        valueKey: 'id',
        labelKey: 'name',
        placeholder: '— اختر —',
        disabled: false,
        clearable: true,
        initialOption: null,
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

const options = ref<T[]>([]);
const loading = ref(false);
const page = ref(1);
const hasMore = ref(true);

const selectedOption = ref<T | null>(props.initialOption);

watch(
    () => props.initialOption,
    (val) => {
        if (val) {
            selectedOption.value = val;
            if (!options.value.find((o) => o[props.valueKey] === val[props.valueKey])) {
                options.value.push(val as any);
            }
        }
    },
    { deep: true }
);

const selectedLabel = computed(() => (selectedOption.value ? String(selectedOption.value[props.labelKey]) : ''));

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
        maxHeight: `${Math.max(180, Math.min(320, (flip ? rect.top : below) - 12))}px`,
    };
};

let abortController: AbortController | null = null;
let searchTimeout: ReturnType<typeof setTimeout> | null = null;

const fetchOptions = async (isLoadMore = false) => {
    if (loading.value || (!hasMore.value && isLoadMore)) return;
    
    if (!isLoadMore) {
        page.value = 1;
        options.value = [];
        hasMore.value = true;
    }

    loading.value = true;
    
    if (abortController) {
        abortController.abort();
    }
    abortController = new AbortController();

    try {
        const url = new URL(props.apiUrl, window.location.origin);
        url.searchParams.append('page', page.value.toString());
        if (query.value.trim()) {
            url.searchParams.append('q', query.value.trim());
        }

        const res = await fetch(url.toString(), {
            headers: { Accept: 'application/json' },
            signal: abortController.signal
        });

        if (!res.ok) throw new Error('Network error');
        
        const data = await res.json();
        
        if (isLoadMore) {
            options.value = [...options.value, ...data.data];
        } else {
            options.value = data.data;
        }

        hasMore.value = data.current_page < data.last_page;
        page.value++;
        
    } catch (err: any) {
        if (err.name !== 'AbortError') {
            console.error('Error fetching options:', err);
        }
    } finally {
        loading.value = false;
        place();
    }
};

const handleSearch = () => {
    if (searchTimeout) clearTimeout(searchTimeout);
    searchTimeout = setTimeout(() => {
        fetchOptions(false);
    }, 300);
};

const onScroll = (e: Event) => {
    const target = e.target as HTMLElement;
    if (target.scrollTop + target.clientHeight >= target.scrollHeight - 20) {
        fetchOptions(true);
    }
};

const openList = async () => {
    if (props.disabled) return;
    open.value = true;
    query.value = '';
    
    // Fetch initial options if empty or search was modified
    if (options.value.length === 0 || (!selectedOption.value && options.value.length === 1)) {
        await fetchOptions(false);
    }
    
    active.value = options.value.findIndex((o) => o[props.valueKey] === props.modelValue);
    if (active.value < 0) active.value = 0;

    nextTick(() => {
        place();
        searchInput.value?.focus();
        scrollToActive();
    });
};

const closeList = () => {
    open.value = false;
    query.value = '';
    if (abortController) abortController.abort();
};

const toggle = () => (open.value ? closeList() : openList());

const scrollToActive = () => {
    if (!menu.value) return;
    const el = menu.value.children[active.value] as HTMLElement;
    if (!el) return;

    const mRect = menu.value.getBoundingClientRect();
    const eRect = el.getBoundingClientRect();

    if (eRect.bottom > mRect.bottom) {
        menu.value.scrollTop += eRect.bottom - mRect.bottom;
    } else if (eRect.top < mRect.top) {
        menu.value.scrollTop -= mRect.top - eRect.top;
    }
};

const select = (index: number) => {
    const opt = options.value[index];
    if (!opt) return;

    selectedOption.value = opt;
    emit('update:modelValue', opt[props.valueKey] as number | string);
    emit('change', opt);
    closeList();
};

const clear = () => {
    selectedOption.value = null;
    emit('update:modelValue', null);
    emit('change', null);
};

const onKeydown = (e: KeyboardEvent) => {
    if (e.key === 'ArrowDown') {
        e.preventDefault();
        if (!open.value) {
            openList();
            return;
        }
        if (active.value < options.value.length - 1) {
            active.value++;
            scrollToActive();
        }
    } else if (e.key === 'ArrowUp') {
        e.preventDefault();
        if (!open.value) {
            openList();
            return;
        }
        if (active.value > 0) {
            active.value--;
            scrollToActive();
        }
    } else if (e.key === 'Enter') {
        e.preventDefault();
        if (open.value) select(active.value);
        else openList();
    } else if (e.key === 'Escape') {
        closeList();
    }
};

// إغلاق عند النقر خارج المكون
const onClickOutside = (e: MouseEvent) => {
    if (
        open.value &&
        root.value &&
        !root.value.contains(e.target as Node) &&
        menu.value &&
        !menu.value.contains(e.target as Node)
    ) {
        closeList();
    }
};

// تحديث الموضع عند التمرير في المستند
const onDocScroll = () => {
    if (open.value) place();
};

window.addEventListener('click', onClickOutside);
window.addEventListener('scroll', onDocScroll, true);
window.addEventListener('resize', place);

onBeforeUnmount(() => {
    window.removeEventListener('click', onClickOutside);
    window.removeEventListener('scroll', onDocScroll, true);
    window.removeEventListener('resize', place);
    if (abortController) abortController.abort();
    if (searchTimeout) clearTimeout(searchTimeout);
});
</script>

<template>
    <div ref="root" class="relative" @keydown="onKeydown">
        <!-- Input Wrapper -->
        <div
            class="flex min-h-[38px] cursor-pointer items-center justify-between rounded-lg border border-slate-300 bg-white px-3 shadow-sm ring-blue-500/20 transition-all focus-within:border-blue-500 focus-within:ring-4 hover:border-slate-400"
            :class="{
                'pointer-events-none bg-slate-50 opacity-50': disabled,
                '!border-blue-500 !ring-4': open,
            }"
            @click="toggle"
        >
            <div class="flex-1 truncate py-1.5 pr-1 text-sm font-bold" :class="modelValue ? 'text-slate-800' : 'text-slate-400'">
                {{ selectedLabel || placeholder }}
            </div>

            <div class="flex shrink-0 items-center gap-1 pl-1 text-slate-400">
                <button
                    v-if="clearable && modelValue"
                    type="button"
                    class="rounded p-0.5 hover:bg-slate-100 hover:text-slate-600 focus:outline-none"
                    @click.stop="clear"
                >
                    <X class="h-4 w-4" />
                </button>
                <ChevronDown class="h-4 w-4 transition-transform duration-200" :class="{ 'rotate-180': open }" />
            </div>
        </div>

        <!-- Dropdown Menu -->
        <Teleport to="body">
            <Transition
                enter-active-class="transition ease-out duration-100"
                enter-from-class="opacity-0 scale-95"
                enter-to-class="opacity-100 scale-100"
                leave-active-class="transition ease-in duration-75"
                leave-from-class="opacity-100 scale-100"
                leave-to-class="opacity-0 scale-95"
            >
                <div
                    v-if="open"
                    ref="menu"
                    class="fixed z-[100] flex flex-col overflow-hidden rounded-xl border border-slate-200 bg-white shadow-xl ring-1 ring-black/5"
                    :style="menuStyle"
                >
                    <!-- Search Input -->
                    <div class="shrink-0 border-b border-slate-100 p-2">
                        <div class="relative">
                            <Search class="absolute right-2.5 top-2.5 h-4 w-4 text-slate-400" />
                            <input
                                ref="searchInput"
                                v-model="query"
                                type="text"
                                class="w-full rounded-lg border border-slate-200 bg-slate-50 py-1.5 pl-3 pr-9 text-sm font-bold text-slate-800 focus:border-blue-500 focus:bg-white focus:outline-none focus:ring-1 focus:ring-blue-500"
                                placeholder="ابحث..."
                                @input="handleSearch"
                            />
                        </div>
                    </div>

                    <!-- Options List -->
                    <ul class="flex-1 overflow-y-auto p-1" @scroll="onScroll">
                        <li v-if="loading && options.length === 0" class="px-3 py-4 text-center text-sm text-slate-500">
                            جارٍ التحميل...
                        </li>
                        <li v-else-if="options.length === 0" class="px-3 py-4 text-center text-sm text-slate-500">
                            لا توجد نتائج مطابقة
                        </li>
                        <template v-else>
                            <li
                                v-for="(o, i) in options"
                                :key="o[valueKey]"
                                class="flex cursor-pointer items-center justify-between rounded-lg px-3 py-2 text-sm font-bold transition-colors"
                                :class="[
                                    modelValue === o[valueKey]
                                        ? 'bg-blue-50 text-blue-700'
                                        : i === active
                                          ? 'bg-slate-100 text-slate-900'
                                          : 'text-slate-700 hover:bg-slate-50',
                                ]"
                                @click.stop="select(i)"
                                @mouseenter="active = i"
                            >
                                <slot name="option" :option="o">
                                    <div class="flex flex-col">
                                        <span>{{ o[labelKey] }}</span>
                                        <span v-if="o.category" class="text-[11px] text-slate-500">{{ o.category }}</span>
                                        <span v-else-if="o.mobile" class="text-[11px] text-slate-500" dir="ltr">{{ o.mobile }}</span>
                                    </div>
                                </slot>
                            </li>
                            <li v-if="loading && options.length > 0" class="px-3 py-2 text-center text-xs text-slate-500">
                                يتم تحميل المزيد...
                            </li>
                        </template>
                    </ul>
                </div>
            </Transition>
        </Teleport>
    </div>
</template>
