<script setup lang="ts">
import { StatPill, TableActionButton } from '@/components/data-table';
import { usePermissions } from '@/composables/usePermissions';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { Building2, CalendarDays, PartyPopper, Pencil, Plus, Power, Trash2, X } from 'lucide-vue-next';
import { computed, ref } from 'vue';

interface Hall { id: number; name: string; code: string }

interface EventType {
    id: number;
    unit_id: number;
    name: string;
    description: string | null;
    color: string;
    price: number;
    is_active: boolean;
    bookings_count: number;
}

const props = defineProps<{
    types: EventType[];
    halls: Hall[];
    colors: { key: string; label: string }[];
    stats: { total: number; priced: number; inactive: number };
}>();

const { can } = usePermissions();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'لوحة التحكم', href: '/admin' },
    { title: 'حجوزات القاعات', href: '/admin/bookings/halls' },
    { title: 'أنواع المناسبات', href: '/admin/event-types' },
];

const money = (n: number) => new Intl.NumberFormat('ar-SA-u-nu-latn', { maximumFractionDigits: 2 }).format(n ?? 0);

/** أنواع كل قاعة تحت عنوانها — النوع يتبع قاعته، والعرض يتبع ذلك. */
const typesOf = (hallId: number) => props.types.filter((t) => t.unit_id === hallId);

/** ألوان الشارة — مطابقة لمفاتيح EventType::COLORS في الخادم. */
const badgeClass = (color: string) =>
    ({
        emerald: 'bg-emerald-100 text-emerald-700',
        sky: 'bg-sky-100 text-sky-700',
        violet: 'bg-violet-100 text-violet-700',
        amber: 'bg-amber-100 text-amber-700',
        rose: 'bg-rose-100 text-rose-700',
        slate: 'bg-slate-200 text-slate-700',
    })[color] ?? 'bg-slate-100 text-slate-700';

const dotClass = (color: string) =>
    ({
        emerald: 'bg-emerald-500',
        sky: 'bg-sky-500',
        violet: 'bg-violet-500',
        amber: 'bg-amber-500',
        rose: 'bg-rose-500',
        slate: 'bg-slate-500',
    })[color] ?? 'bg-slate-400';

// ── النموذج ─────────────────────────────────────────────────
const showModal = ref(false);
const editingId = ref<number | null>(null);

const form = useForm({
    unit_id: null as number | null,
    name: '',
    description: '',
    color: 'emerald',
    price: 0,
    is_active: true,
});

const editingHall = computed(() => props.halls.find((h) => h.id === form.unit_id) ?? null);

/** أنواع شائعة تُضاف بضغطة — تختصر التهيئة الأولى لكل قاعة. */
const presets = ['زواج', 'ملكة', 'خطوبة', 'تخرّج', 'عقيقة', 'اجتماع', 'مؤتمر', 'عزاء'];

const openCreate = (hallId: number, name = '') => {
    editingId.value = null;
    form.reset();
    form.clearErrors();
    form.unit_id = hallId;
    form.name = name;
    showModal.value = true;
};

const openEdit = (t: EventType) => {
    editingId.value = t.id;
    form.clearErrors();
    form.unit_id = t.unit_id;
    form.name = t.name;
    form.description = t.description ?? '';
    form.color = t.color;
    form.price = t.price;
    form.is_active = t.is_active;
    showModal.value = true;
};

const submit = () => {
    const opts = { preserveScroll: true, onSuccess: () => (showModal.value = false) };
    editingId.value ? form.put(`/admin/event-types/${editingId.value}`, opts) : form.post('/admin/event-types', opts);
};

const toggle = (t: EventType) => router.patch(`/admin/event-types/${t.id}/toggle`, {}, { preserveScroll: true });

const destroy = (t: EventType) => {
    if (confirm(`حذف نوع المناسبة «${t.name}»؟`)) {
        router.delete(`/admin/event-types/${t.id}`, { preserveScroll: true });
    }
};
</script>

<template>
    <Head title="أنواع المناسبات" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="min-h-full space-y-5 bg-slate-100 p-5">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h1 class="text-2xl font-extrabold text-slate-900">أنواع المناسبات</h1>
                    <p class="mt-1 text-sm font-medium text-slate-600">
                        لكل قاعة أنواعها وأسعارها — واختيار النوع في الحجز يملأ السعر الأساسي
                    </p>
                </div>
                <Link href="/admin/bookings/halls" class="inline-flex items-center gap-1.5 rounded-md border border-slate-200 bg-white px-4 py-2 text-sm font-bold text-slate-700 hover:bg-slate-50">
                    <CalendarDays class="h-4 w-4" /> حجوزات القاعات
                </Link>
            </div>

            <div class="grid grid-cols-3 gap-3">
                <StatPill label="الأنواع" :value="stats.total" variant="primary" />
                <StatPill label="عليها سعر" :value="stats.priced" variant="info" />
                <StatPill label="معطّلة" :value="stats.inactive" variant="danger" />
            </div>

            <!-- قسم لكل قاعة: النوع يتبع قاعته فلا يُعرض في قائمة واحدة مختلطة -->
            <div v-for="hall in halls" :key="hall.id" class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                <div class="flex flex-wrap items-center justify-between gap-2 border-b border-slate-200 bg-slate-50 px-4 py-3">
                    <h2 class="flex items-center gap-2 text-sm font-extrabold text-slate-800">
                        <Building2 class="h-4 w-4 text-slate-400" />
                        {{ hall.name }}
                        <span class="text-[11px] font-bold text-slate-400" dir="ltr">{{ hall.code }}</span>
                    </h2>
                    <button v-if="can('event_types.create')" type="button" @click="openCreate(hall.id)" class="inline-flex items-center gap-1.5 rounded-md bg-blue-600 px-3 py-1.5 text-xs font-bold text-white hover:bg-blue-700">
                        <Plus class="h-3.5 w-3.5" /> نوع جديد
                    </button>
                </div>

                <table v-if="typesOf(hall.id).length" class="w-full">
                    <thead class="bg-slate-100/70">
                        <tr>
                            <th class="px-4 py-2 text-right text-xs font-extrabold text-slate-700">النوع</th>
                            <th class="px-4 py-2 text-right text-xs font-extrabold text-slate-700">الوصف</th>
                            <th class="px-4 py-2 text-center text-xs font-extrabold text-slate-700">سعر الحجز</th>
                            <th class="px-4 py-2 text-center text-xs font-extrabold text-slate-700">الحجوزات</th>
                            <th class="px-4 py-2 text-center text-xs font-extrabold text-slate-700">الحالة</th>
                            <th class="px-4 py-2"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="t in typesOf(hall.id)" :key="t.id" class="border-t border-slate-100 hover:bg-slate-50" :class="!t.is_active && 'opacity-60'">
                            <td class="px-4 py-3">
                                <span class="inline-flex items-center gap-2 rounded-lg px-2.5 py-1 text-sm font-extrabold" :class="badgeClass(t.color)">
                                    <span class="h-2 w-2 rounded-full" :class="dotClass(t.color)"></span>
                                    {{ t.name }}
                                </span>
                            </td>
                            <td class="px-4 py-3 text-xs font-medium text-slate-500">{{ t.description || '—' }}</td>
                            <td class="px-4 py-3 text-center">
                                <span v-if="t.price > 0" class="font-extrabold text-emerald-600">{{ money(t.price) }}</span>
                                <span v-else class="text-xs font-medium text-slate-400">تسعيرة القاعة</span>
                            </td>
                            <td class="px-4 py-3 text-center text-sm font-bold text-slate-600">{{ t.bookings_count }}</td>
                            <td class="px-4 py-3 text-center">
                                <span class="rounded px-2 py-0.5 text-[11px] font-bold" :class="t.is_active ? 'bg-emerald-100 text-emerald-700' : 'bg-red-100 text-red-600'">
                                    {{ t.is_active ? 'فعّال' : 'معطّل' }}
                                </span>
                            </td>
                            <td class="px-4 py-3">
                                <div class="flex justify-end gap-1">
                                    <TableActionButton v-if="can('event_types.edit')" variant="edit" :icon="Pencil" title="تعديل" @click="openEdit(t)" />
                                    <TableActionButton v-if="can('event_types.edit')" variant="warning" :icon="Power" title="تفعيل/تعطيل" @click="toggle(t)" />
                                    <TableActionButton v-if="can('event_types.delete')" variant="danger" :icon="Trash2" title="حذف" @click="destroy(t)" />
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>

                <!-- الإضافة السريعة تظهر حيث تنفع: قاعة لم تُهيّأ أنواعها بعد -->
                <div v-else class="px-4 py-6 text-center">
                    <PartyPopper class="mx-auto mb-2 h-7 w-7 text-slate-300" />
                    <p class="mb-3 text-xs font-medium text-slate-500">لا أنواع لهذه القاعة بعد.</p>
                    <div v-if="can('event_types.create')" class="flex flex-wrap justify-center gap-1.5">
                        <button
                            v-for="p in presets" :key="p" type="button" @click="openCreate(hall.id, p)"
                            class="rounded-lg bg-slate-100 px-3 py-1.5 text-xs font-bold text-slate-600 transition hover:bg-emerald-100 hover:text-emerald-700"
                        >+ {{ p }}</button>
                    </div>
                </div>
            </div>

            <div v-if="!halls.length" class="rounded-2xl border border-slate-200 bg-white px-4 py-12 text-center shadow-sm">
                <Building2 class="mx-auto mb-2 h-8 w-8 text-slate-300" />
                <p class="text-sm font-medium text-slate-500">لا قاعات لعرض أنواع مناسباتها.</p>
            </div>
        </div>

        <!-- نموذج نوع المناسبة -->
        <div v-if="showModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4" @click.self="showModal = false">
            <div class="w-full max-w-lg rounded-2xl bg-white shadow-2xl">
                <div class="flex items-center justify-between border-b border-slate-100 px-6 py-4">
                    <div>
                        <h2 class="text-lg font-extrabold text-slate-900">{{ editingId ? 'تعديل نوع مناسبة' : 'نوع مناسبة جديد' }}</h2>
                        <p v-if="editingHall" class="text-xs font-bold text-slate-500">{{ editingHall.name }}</p>
                    </div>
                    <button type="button" @click="showModal = false" class="rounded-lg p-1 text-slate-400 hover:bg-slate-100"><X class="h-5 w-5" /></button>
                </div>

                <form @submit.prevent="submit">
                    <div class="space-y-4 px-6 py-4">
                        <div>
                            <label class="mb-1 block text-sm font-bold text-slate-700">القاعة</label>
                            <select v-model="form.unit_id" class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm">
                                <option :value="null">— اختر القاعة —</option>
                                <option v-for="h in halls" :key="h.id" :value="h.id">{{ h.name }}</option>
                            </select>
                            <p v-if="form.errors.unit_id" class="mt-1 text-xs text-red-500">{{ form.errors.unit_id }}</p>
                        </div>

                        <div>
                            <label class="mb-1 block text-sm font-bold text-slate-700">اسم النوع</label>
                            <input v-model="form.name" class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm focus:border-emerald-400 focus:outline-none focus:ring-2 focus:ring-emerald-100" placeholder="زواج" />
                            <p v-if="form.errors.name" class="mt-1 text-xs text-red-500">{{ form.errors.name }}</p>
                        </div>

                        <div>
                            <label class="mb-1 block text-sm font-bold text-slate-700">سعر الحجز بهذا النوع</label>
                            <input v-model.number="form.price" type="number" min="0" step="0.01" class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm" />
                            <p class="mt-1 text-[11px] font-medium leading-5 text-slate-500">
                                يظهر سعرًا أساسيًا في نموذج الحجز عند اختيار هذا النوع، ويحل محل تسعيرة القاعة.
                                اتركه صفرًا ليتّبع الحجز تسعيرة القاعة (يوم عادي / نهاية أسبوع / موسم).
                            </p>
                            <p v-if="form.errors.price" class="mt-1 text-xs text-red-500">{{ form.errors.price }}</p>
                        </div>

                        <div>
                            <label class="mb-1 block text-sm font-bold text-slate-700">الوصف</label>
                            <textarea v-model="form.description" rows="2" class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm"></textarea>
                        </div>

                        <div>
                            <label class="mb-1.5 block text-sm font-bold text-slate-700">اللون</label>
                            <div class="flex flex-wrap gap-1.5">
                                <button
                                    v-for="c in colors" :key="c.key" type="button" @click="form.color = c.key"
                                    class="inline-flex items-center gap-1.5 rounded-lg border-2 px-3 py-1.5 text-xs font-bold transition"
                                    :class="form.color === c.key ? 'border-slate-800 ' + badgeClass(c.key) : 'border-transparent ' + badgeClass(c.key)"
                                >
                                    <span class="h-2 w-2 rounded-full" :class="dotClass(c.key)"></span> {{ c.label }}
                                </button>
                            </div>
                        </div>

                        <label class="flex cursor-pointer items-center gap-2 text-sm font-bold text-slate-700">
                            <input type="checkbox" v-model="form.is_active" class="h-4 w-4 rounded border-slate-300 text-emerald-600" />
                            فعّال ويظهر في نموذج الحجز
                        </label>
                    </div>

                    <div class="flex justify-end gap-2 border-t border-slate-100 px-6 py-4">
                        <button type="button" @click="showModal = false" class="rounded-xl border border-slate-200 bg-white px-4 py-2 text-sm font-bold text-slate-600 hover:bg-slate-50">إلغاء</button>
                        <button type="submit" :disabled="form.processing" class="rounded-md bg-blue-600 px-5 py-2 text-sm font-bold text-white shadow-sm transition hover:bg-blue-700 disabled:opacity-60">حفظ</button>
                    </div>
                </form>
            </div>
        </div>
    </AppLayout>
</template>
