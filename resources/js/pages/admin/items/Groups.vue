<script setup lang="ts">
import { StatPill, TableActionButton } from '@/components/data-table';
import SearchableSelect from '@/components/SearchableSelect.vue';
import { usePermissions } from '@/composables/usePermissions';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, router, useForm } from '@inertiajs/vue3';
import { Copy, Layers, Pencil, Plus, Power, Search, Trash2, X } from 'lucide-vue-next';
import { computed, ref } from 'vue';

interface ItemOption {
    id: number;
    code: string | null;
    name: string;
    category: string | null;
    price: number;
    tax_rate: number;
}

interface GroupMember {
    id: number;
    code: string | null;
    name: string;
    category: string | null;
    price: number;
    is_active: boolean;
}

interface Group {
    id: number;
    name: string;
    description: string | null;
    is_active: boolean;
    items: GroupMember[];
}

const props = defineProps<{
    groups: Group[];
    filters: { search: string | null };
    items: ItemOption[];
    stats: { total: number; inactive: number };
}>();

const { can } = usePermissions();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'لوحة التحكم', href: '/admin' },
    { title: 'الأصناف', href: '/admin/items' },
    { title: 'مجموعات الأصناف', href: '/admin/item-groups' },
];

const money = (n: number) => new Intl.NumberFormat('ar-SA-u-nu-latn', { maximumFractionDigits: 2 }).format(n ?? 0);

const search = ref(props.filters.search ?? '');
const applyFilter = () =>
    router.get('/admin/item-groups', { search: search.value || null }, { preserveState: true, replace: true });

// ── النموذج ─────────────────────────────────────────────────
const showModal = ref(false);
const editingId = ref<number | null>(null);

const form = useForm({
    name: '',
    description: '' as string | null,
    is_active: true,
    item_ids: [] as number[],
});

/** الصنف المختار من قائمة البحث — يُفرَّغ فور إضافته فتبقى القائمة جاهزة للتالي. */
const picking = ref<number | null>(null);

const itemOf = (id: number) => props.items.find((i) => i.id === id) ?? null;

/** ما لم يُختَر بعد — فلا يظهر الصنف مرتين في قائمة الاختيار ولا في المجموعة. */
const available = computed(() => props.items.filter((i) => !form.item_ids.includes(i.id)));

const addItem = (id: number | string | null) => {
    const numeric = Number(id);
    picking.value = null;

    if (!numeric || form.item_ids.includes(numeric)) return;

    form.item_ids.push(numeric);
};

const removeItem = (id: number) => {
    form.item_ids = form.item_ids.filter((i) => i !== id);
};

const openCreate = () => {
    editingId.value = null;
    form.reset();
    form.clearErrors();
    form.item_ids = [];
    showModal.value = true;
};

const openEdit = (group: Group) => {
    editingId.value = group.id;
    form.clearErrors();
    form.name = group.name;
    form.description = group.description;
    form.is_active = group.is_active;
    // الصنف المحذوف من الملف لا يعود في قائمة الاختيار، فيُطرح عند التحرير بدل
    // أن يُحفظ من جديد بمعرّف لا يقابله صنف.
    form.item_ids = group.items.filter((i) => itemOf(i.id)).map((i) => i.id);
    showModal.value = true;
};

const closeModal = () => {
    showModal.value = false;
    editingId.value = null;
};

const submit = () => {
    const options = { preserveScroll: true, onSuccess: () => closeModal() };

    if (editingId.value) {
        form.put(`/admin/item-groups/${editingId.value}`, options);
    } else {
        form.post('/admin/item-groups', options);
    }
};

const toggle = (group: Group) => router.patch(`/admin/item-groups/${group.id}/toggle`, {}, { preserveScroll: true });

const duplicate = (group: Group) => router.post(`/admin/item-groups/${group.id}/duplicate`, {}, { preserveScroll: true });

const destroy = (group: Group) => {
    if (confirm(`هل أنت متأكد من حذف مجموعة «${group.name}»؟`)) {
        router.delete(`/admin/item-groups/${group.id}`, { preserveScroll: true });
    }
};

/** قيمة المجموعة بأسعار اليوم — تقديرٌ يُعين على المراجعة لا سعرٌ محفوظ. */
const groupTotal = (group: Group) => group.items.reduce((sum, i) => sum + i.price, 0);
</script>

<template>
    <Head title="مجموعات الأصناف" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="min-h-full space-y-4 bg-slate-100 p-5">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h1 class="flex items-center gap-2 text-2xl font-extrabold text-slate-900">
                        <Layers class="h-6 w-6" /> مجموعات الأصناف
                    </h1>
                    <p class="mt-1 text-sm font-medium text-slate-600">
                        تحديد محفوظ لأصناف تُطلب معًا — تُملأ به الفاتورة أو عرض السعر دفعةً واحدة
                    </p>
                </div>
                <button
                    v-if="can('item_groups.create')" type="button" @click="openCreate"
                    class="inline-flex items-center gap-1.5 rounded-md bg-blue-600 px-4 py-2 text-sm font-bold text-white hover:bg-blue-700"
                >
                    <Plus class="h-4 w-4" /> مجموعة جديدة
                </button>
            </div>

            <div class="flex flex-wrap items-center gap-3">
                <StatPill label="عدد المجموعات" :value="String(stats.total)" />
                <StatPill label="موقوفة" :value="String(stats.inactive)" variant="warning" />

                <div class="relative ms-auto">
                    <Search class="absolute top-1/2 right-3 h-4 w-4 -translate-y-1/2 text-slate-400" />
                    <input
                        v-model="search" @keyup.enter="applyFilter" @search="applyFilter" type="search"
                        placeholder="ابحث باسم المجموعة"
                        class="w-64 rounded-xl border border-slate-200 py-2.5 pr-9 pl-3 text-sm"
                    />
                </div>
            </div>

            <div v-if="!groups.length" class="rounded-2xl border-2 border-dashed border-slate-300 bg-white p-10 text-center">
                <Layers class="mx-auto h-8 w-8 text-slate-400" />
                <p class="mt-2 text-sm font-bold text-slate-700">لا توجد مجموعات محفوظة بعد</p>
                <p class="mt-1 text-xs text-slate-500">
                    أنشئ مجموعة بالأصناف التي تتكرّر في فواتيرك، ثم اخترها من الفاتورة أو عرض السعر لتُضاف كلها دفعة واحدة.
                </p>
            </div>

            <div v-else class="grid gap-3 lg:grid-cols-2">
                <div
                    v-for="g in groups" :key="g.id"
                    class="rounded-2xl border-2 border-slate-300 bg-white p-4 shadow-sm"
                    :class="{ 'opacity-60': !g.is_active }"
                >
                    <div class="flex flex-wrap items-start justify-between gap-2">
                        <div class="min-w-0">
                            <h3 class="flex items-center gap-2 text-base font-extrabold text-slate-950">
                                {{ g.name }}
                                <span v-if="!g.is_active" class="rounded bg-amber-100 px-1.5 py-0.5 text-[10px] font-bold text-amber-800">موقوفة</span>
                            </h3>
                            <p v-if="g.description" class="mt-0.5 text-xs text-slate-600">{{ g.description }}</p>
                        </div>

                        <div class="flex shrink-0 items-center gap-1">
                            <TableActionButton
                                v-if="can('item_groups.edit')" variant="edit" :icon="Pencil"
                                title="تعديل" @click="openEdit(g)"
                            />
                            <TableActionButton
                                v-if="can('item_groups.create')" variant="dark" :icon="Copy"
                                title="نسخ المجموعة" @click="duplicate(g)"
                            />
                            <TableActionButton
                                v-if="can('item_groups.edit')" variant="warning" :icon="Power"
                                :title="g.is_active ? 'إيقاف' : 'تفعيل'" @click="toggle(g)"
                            />
                            <TableActionButton
                                v-if="can('item_groups.delete')" variant="danger" :icon="Trash2"
                                title="حذف" @click="destroy(g)"
                            />
                        </div>
                    </div>

                    <div class="mt-3 flex flex-wrap gap-1.5">
                        <span
                            v-for="i in g.items" :key="i.id"
                            class="inline-flex items-center gap-1.5 rounded-lg px-2 py-1 text-xs font-bold"
                            :class="i.is_active ? 'bg-slate-200 text-slate-900' : 'bg-red-100 text-red-800 line-through'"
                            :title="i.is_active ? (i.category ?? '') : 'صنف موقوف — لن يُضاف للفاتورة'"
                        >
                            {{ i.name }}
                            <span class="text-[10px] font-extrabold text-emerald-800" dir="ltr">{{ money(i.price) }}</span>
                        </span>
                    </div>

                    <div class="mt-3 flex items-center justify-between border-t border-slate-200 pt-2 text-xs">
                        <span class="font-extrabold text-slate-700">{{ g.items.length }} صنف</span>
                        <span class="font-extrabold text-slate-700">
                            قيمتها بأسعار اليوم <span class="text-emerald-800" dir="ltr">{{ money(groupTotal(g)) }}</span>
                        </span>
                    </div>
                </div>
            </div>
        </div>

        <!-- نموذج الإضافة والتعديل -->
        <Teleport to="body">
            <div v-if="showModal" class="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-black/40 p-4" @click.self="closeModal">
                <div class="my-8 w-full max-w-2xl rounded-2xl bg-white shadow-xl">
                    <div class="flex items-center justify-between border-b border-slate-200 px-5 py-3">
                        <h2 class="flex items-center gap-2 text-base font-extrabold text-slate-900">
                            <Layers class="h-5 w-5" /> {{ editingId ? 'تعديل المجموعة' : 'مجموعة أصناف جديدة' }}
                        </h2>
                        <button type="button" @click="closeModal" class="rounded-lg p-1.5 text-slate-500 hover:bg-slate-100">
                            <X class="h-4 w-4" />
                        </button>
                    </div>

                    <form @submit.prevent="submit" class="space-y-4 p-5">
                        <div class="grid gap-3 sm:grid-cols-2">
                            <div>
                                <label class="mb-1 block text-sm font-extrabold text-slate-900">اسم المجموعة <span class="text-red-500">*</span></label>
                                <input
                                    v-model="form.name" type="text" required maxlength="255"
                                    placeholder="مثال: صيانة مسبح دورية"
                                    class="w-full rounded-xl border-2 border-slate-300 px-3 py-2.5 text-sm font-bold focus:border-emerald-700 focus:outline-none"
                                />
                                <p v-if="form.errors.name" class="mt-1 text-xs font-bold text-red-600">{{ form.errors.name }}</p>
                            </div>

                            <div>
                                <label class="mb-1 block text-sm font-extrabold text-slate-900">وصف مختصر</label>
                                <input
                                    v-model="form.description" type="text" maxlength="1000"
                                    class="w-full rounded-xl border-2 border-slate-300 px-3 py-2.5 text-sm focus:border-emerald-700 focus:outline-none"
                                />
                            </div>
                        </div>

                        <div>
                            <label class="mb-1 block text-sm font-extrabold text-slate-900">
                                أصناف المجموعة <span class="text-red-500">*</span>
                            </label>
                            <SearchableSelect
                                v-model="picking"
                                :options="available"
                                :search-keys="['code', 'category']"
                                placeholder="ابحث عن صنف لإضافته للمجموعة"
                                @update:model-value="addItem"
                            >
                                <template #option="{ option }">
                                    <span class="flex items-center justify-between gap-2">
                                        <span class="min-w-0">
                                            <span class="font-bold">{{ option.name }}</span>
                                            <span class="block text-[11px] font-bold text-slate-600" dir="ltr">{{ option.code }}</span>
                                        </span>
                                        <span class="shrink-0 text-xs font-extrabold text-emerald-800" dir="ltr">{{ money(option.price) }}</span>
                                    </span>
                                </template>
                            </SearchableSelect>
                            <p v-if="form.errors.item_ids" class="mt-1 text-xs font-bold text-red-600">{{ form.errors.item_ids }}</p>

                            <div v-if="form.item_ids.length" class="mt-3 space-y-1.5 rounded-xl border-2 border-slate-200 bg-slate-50 p-2">
                                <div
                                    v-for="(id, i) in form.item_ids" :key="id"
                                    class="flex items-center justify-between gap-2 rounded-lg bg-white px-3 py-2 shadow-sm"
                                >
                                    <span class="flex min-w-0 items-center gap-2">
                                        <span class="w-5 shrink-0 text-xs font-extrabold text-slate-500">{{ i + 1 }}</span>
                                        <span class="truncate text-sm font-bold text-slate-900">{{ itemOf(id)?.name }}</span>
                                        <span class="shrink-0 text-[11px] font-bold text-slate-500" dir="ltr">{{ itemOf(id)?.code }}</span>
                                    </span>
                                    <span class="flex shrink-0 items-center gap-2">
                                        <span class="text-xs font-extrabold text-emerald-800" dir="ltr">{{ money(itemOf(id)?.price ?? 0) }}</span>
                                        <button type="button" @click="removeItem(id)" title="إزالة من المجموعة" class="rounded-lg p-1 text-red-600 hover:bg-red-100">
                                            <Trash2 class="h-3.5 w-3.5" />
                                        </button>
                                    </span>
                                </div>
                            </div>
                            <p v-else class="mt-2 rounded-lg border border-dashed border-slate-300 px-3 py-4 text-center text-xs font-bold text-slate-500">
                                لم تختر أصنافًا بعد — ابحث أعلاه وأضف ما تريد.
                            </p>
                        </div>

                        <label class="flex items-center gap-2 text-sm font-bold text-slate-800">
                            <input v-model="form.is_active" type="checkbox" class="h-4 w-4 rounded border-slate-400" />
                            مجموعة فعّالة — تظهر في الفواتير وعروض الأسعار
                        </label>

                        <div class="flex items-center justify-end gap-2 border-t border-slate-200 pt-3">
                            <button type="button" @click="closeModal" class="rounded-lg border-2 border-slate-300 px-4 py-2 text-sm font-bold text-slate-700 hover:bg-slate-100">
                                إلغاء
                            </button>
                            <button
                                type="submit" :disabled="form.processing || !form.item_ids.length"
                                class="rounded-lg bg-emerald-700 px-6 py-2 text-sm font-extrabold text-white hover:bg-emerald-800 disabled:cursor-not-allowed disabled:bg-slate-400"
                            >
                                {{ editingId ? 'حفظ التعديلات' : 'حفظ المجموعة' }}
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </Teleport>
    </AppLayout>
</template>
