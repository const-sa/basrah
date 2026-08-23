<script setup lang="ts">
import { StatPill, TableActionButton } from '@/components/data-table';
import { usePermissions } from '@/composables/usePermissions';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { CalendarDays, ConciergeBell, Home, Pencil, Plus, Power, Trash2, X } from 'lucide-vue-next';
import { ref } from 'vue';

interface Addon {
    id: number;
    name: string;
    price: number;
    pricing: string;
    pricing_label: string;
    description: string | null;
    is_active: boolean;
    bookings_count: number;
}

defineProps<{
    addons: Addon[];
    pricingModes: { key: string; label: string }[];
    stats: { total: number; active: number; inactive: number; used: number };
}>();

const { can } = usePermissions();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'لوحة التحكم', href: '/admin' },
    { title: 'الخدمات الإضافية', href: '/admin/addons' },
];

const money = (n: number) => new Intl.NumberFormat('ar-SA-u-nu-latn', { maximumFractionDigits: 2 }).format(n ?? 0);

/**
 * What the quantity typed on the booking stands for. The server multiplies
 * price × quantity whatever the mode is, so this labels the number rather
 * than promising the system will work it out.
 */
const quantityMeans = (pricing: string) =>
    ({ per_person: 'الكمية = عدد الأشخاص', per_hour: 'الكمية = عدد الساعات' })[pricing] ?? '';

const showModal = ref(false);
const editingId = ref<number | null>(null);

const form = useForm({
    name: '',
    price: 0,
    pricing: 'fixed',
    description: '',
    is_active: true,
});

const openCreate = () => {
    editingId.value = null;
    form.reset();
    form.clearErrors();
    showModal.value = true;
};

const openEdit = (a: Addon) => {
    editingId.value = a.id;
    form.clearErrors();
    form.name = a.name;
    form.price = a.price;
    form.pricing = a.pricing;
    form.description = a.description ?? '';
    form.is_active = a.is_active;
    showModal.value = true;
};

const submit = () => {
    const opts = { preserveScroll: true, onSuccess: () => (showModal.value = false) };

    if (editingId.value) {
        form.put(`/admin/addons/${editingId.value}`, opts);
    } else {
        form.post('/admin/addons', opts);
    }
};

const toggle = (a: Addon) => router.patch(`/admin/addons/${a.id}/toggle`, {}, { preserveScroll: true });

const destroy = (a: Addon) => {
    if (confirm(`حذف الخدمة «${a.name}»؟`)) {
        router.delete(`/admin/addons/${a.id}`, { preserveScroll: true });
    }
};
</script>

<template>
    <Head title="الخدمات الإضافية" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="min-h-full space-y-5 bg-slate-100 p-5">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h1 class="text-2xl font-extrabold text-slate-900">الخدمات الإضافية</h1>
                    <p class="mt-1 text-sm font-medium text-slate-600">
                        كتالوج واحد تقرؤه نماذج حجز القاعات والشاليهات — الاسم والسعر وطريقة الاحتساب
                    </p>
                </div>
                <div class="flex flex-wrap gap-2">
                    <Link href="/admin/bookings/halls" class="inline-flex items-center gap-1.5 rounded-md border border-slate-200 bg-white px-4 py-2 text-sm font-bold text-slate-700 hover:bg-slate-50">
                        <CalendarDays class="h-4 w-4" /> حجوزات القاعات
                    </Link>
                    <Link href="/admin/bookings/chalets" class="inline-flex items-center gap-1.5 rounded-md border border-slate-200 bg-white px-4 py-2 text-sm font-bold text-slate-700 hover:bg-slate-50">
                        <Home class="h-4 w-4" /> حجوزات الشاليهات
                    </Link>
                    <button v-if="can('addons.create')" type="button" @click="openCreate" class="inline-flex items-center gap-1.5 rounded-md bg-blue-600 px-4 py-2 text-sm font-bold text-white shadow-sm transition hover:bg-blue-700">
                        <Plus class="h-4 w-4" /> خدمة جديدة
                    </button>
                </div>
            </div>

            <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
                <StatPill label="الخدمات" :value="stats.total" variant="primary" />
                <StatPill label="فعّالة" :value="stats.active" variant="success" />
                <StatPill label="مستخدمة في حجوزات" :value="stats.used" variant="info" />
                <StatPill label="معطّلة" :value="stats.inactive" variant="danger" />
            </div>

            <div class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                <table v-if="addons.length" class="w-full">
                    <thead class="bg-slate-100/70">
                        <tr>
                            <th class="px-4 py-2 text-right text-xs font-extrabold text-[#1e3a8a]">الخدمة</th>
                            <th class="px-4 py-2 text-right text-xs font-extrabold text-[#1e3a8a]">الوصف</th>
                            <th class="px-4 py-2 text-center text-xs font-extrabold text-[#1e3a8a]">السعر</th>
                            <th class="px-4 py-2 text-center text-xs font-extrabold text-[#1e3a8a]">طريقة الاحتساب</th>
                            <th class="px-4 py-2 text-center text-xs font-extrabold text-[#1e3a8a]">الحجوزات</th>
                            <th class="px-4 py-2 text-center text-xs font-extrabold text-[#1e3a8a]">الحالة</th>
                            <th class="px-4 py-2"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="a in addons" :key="a.id" class="border-t border-slate-100 hover:bg-slate-50" :class="!a.is_active && 'opacity-60'">
                            <td class="px-4 py-3">
                                <span class="inline-flex items-center gap-2 text-sm font-extrabold text-slate-800">
                                    <ConciergeBell class="h-4 w-4 text-slate-400" /> {{ a.name }}
                                </span>
                            </td>
                            <td class="px-4 py-3 text-xs font-medium text-slate-500">{{ a.description || '—' }}</td>
                            <td class="px-4 py-3 text-center">
                                <span class="font-extrabold text-emerald-600">{{ money(a.price) }}</span>
                                <span v-if="quantityMeans(a.pricing)" class="block text-[10px] font-bold text-slate-400">{{ quantityMeans(a.pricing) }}</span>
                            </td>
                            <td class="px-4 py-3 text-center">
                                <span class="rounded-md bg-slate-100 px-2 py-0.5 text-[11px] font-bold text-slate-600">{{ a.pricing_label }}</span>
                            </td>
                            <td class="px-4 py-3 text-center text-sm font-bold text-slate-600">{{ a.bookings_count }}</td>
                            <td class="px-4 py-3 text-center">
                                <span class="rounded px-2 py-0.5 text-[11px] font-bold" :class="a.is_active ? 'bg-emerald-100 text-emerald-700' : 'bg-red-100 text-red-600'">
                                    {{ a.is_active ? 'فعّالة' : 'معطّلة' }}
                                </span>
                            </td>
                            <td class="px-4 py-3">
                                <div class="flex justify-end gap-1">
                                    <TableActionButton v-if="can('addons.edit')" variant="edit" :icon="Pencil" title="تعديل" @click="openEdit(a)" />
                                    <TableActionButton v-if="can('addons.edit')" :variant="a.is_active ? 'warning' : 'success'" :icon="Power" :title="a.is_active ? 'تعطيل' : 'تفعيل'" @click="toggle(a)" />
                                    <TableActionButton v-if="can('addons.delete')" variant="danger" :icon="Trash2" title="حذف" @click="destroy(a)" />
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>

                <div v-else class="px-4 py-12 text-center">
                    <ConciergeBell class="mx-auto mb-2 h-8 w-8 text-slate-300" />
                    <p class="text-sm font-medium text-slate-500">لا خدمات إضافية بعد.</p>
                </div>
            </div>
        </div>

        <!-- نموذج الخدمة -->
        <div v-if="showModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4" @click.self="showModal = false">
            <div class="w-full max-w-lg rounded-2xl bg-white shadow-2xl">
                <div class="flex items-center justify-between border-b border-slate-100 px-6 py-4">
                    <h2 class="text-lg font-extrabold text-slate-900">{{ editingId ? 'تعديل خدمة' : 'خدمة جديدة' }}</h2>
                    <button type="button" @click="showModal = false" class="rounded-lg p-1 text-slate-400 hover:bg-slate-100"><X class="h-5 w-5" /></button>
                </div>

                <form @submit.prevent="submit">
                    <div class="space-y-4 px-6 py-4">
                        <div>
                            <label class="mb-1 block text-sm font-bold text-slate-700">اسم الخدمة</label>
                            <input v-model="form.name" class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm focus:border-emerald-400 focus:outline-none focus:ring-2 focus:ring-emerald-100" placeholder="ضيافة كاملة" />
                            <p v-if="form.errors.name" class="mt-1 text-xs text-red-500">{{ form.errors.name }}</p>
                        </div>

                        <div class="grid gap-3 sm:grid-cols-2">
                            <div>
                                <label class="mb-1 block text-sm font-bold text-slate-700">السعر</label>
                                <input v-model.number="form.price" type="number" min="0" step="any" dir="ltr" class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm focus:border-emerald-400 focus:outline-none focus:ring-2 focus:ring-emerald-100" />
                                <p v-if="form.errors.price" class="mt-1 text-xs text-red-500">{{ form.errors.price }}</p>
                            </div>
                            <div>
                                <label class="mb-1 block text-sm font-bold text-slate-700">طريقة الاحتساب</label>
                                <select v-model="form.pricing" class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm">
                                    <option v-for="m in pricingModes" :key="m.key" :value="m.key">{{ m.label }}</option>
                                </select>
                                <p v-if="form.errors.pricing" class="mt-1 text-xs text-red-500">{{ form.errors.pricing }}</p>
                            </div>
                        </div>

                        <p class="rounded-xl bg-slate-50 px-3 py-2 text-[11px] font-medium text-slate-500">
                            الإجمالي في الحجز = السعر × الكمية المُدخَلة بجانب الخدمة. وطريقة الاحتساب تقول لمن يُدخِلها ما تعنيه هذه الكمية:
                            «سعر ثابت» كمية واحدة، و«لكل شخص» عدد الأشخاص، و«لكل ساعة» عدد الساعات.
                        </p>

                        <div>
                            <label class="mb-1 block text-sm font-bold text-slate-700">الوصف</label>
                            <textarea v-model="form.description" rows="2" class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm"></textarea>
                        </div>

                        <label class="flex cursor-pointer items-start gap-2 rounded-xl border-2 p-3 transition" :class="form.is_active ? 'border-emerald-200 bg-emerald-50' : 'border-red-200 bg-red-50'">
                            <input type="checkbox" v-model="form.is_active" class="mt-0.5 h-4 w-4 rounded border-slate-300 text-emerald-600" />
                            <span class="text-sm font-bold text-slate-700">
                                فعّالة
                                <span class="block text-[11px] font-medium" :class="form.is_active ? 'text-emerald-600' : 'text-red-600'">
                                    {{ form.is_active ? 'تظهر في نماذج حجز القاعات والشاليهات.' : 'مخفية عن الحجوزات الجديدة، والحجوزات القائمة تبقى كما هي.' }}
                                </span>
                            </span>
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
