<script setup lang="ts">
import SearchableSelect from '@/components/SearchableSelect.vue';
import { usePermissions } from '@/composables/usePermissions';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { Ban, Plus, Repeat, Trash2, X } from 'lucide-vue-next';
import { ref } from 'vue';

interface Asset {
    id: number; code: string; name: string; category: string | null; cost_center: string | null;
    purchase_date: string; cost: number; salvage_value: number; useful_life_months: number;
    monthly_depreciation: number; accumulated_depreciation: number; book_value: number;
    status: string; status_label: string;
}

const props = defineProps<{
    assets: { data: Asset[]; links: { url: string | null; label: string; active: boolean }[] };
    filters: { status: string | null; cost_center_id: number | null };
    costCenters: { id: number; name: string; code: string }[];
    statuses: { key: string; label: string }[];
}>();

const { can } = usePermissions();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'لوحة التحكم', href: '/admin' },
    { title: 'الأصول الثابتة', href: '/admin/accounting/fixed-assets' },
];

const money = (n: number) => new Intl.NumberFormat('ar-SA-u-nu-latn', { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(n ?? 0);

const filters = ref({ ...props.filters });
const apply = () => router.get('/admin/accounting/fixed-assets', filters.value, { preserveState: true, replace: true });

const showModal = ref(false);
const form = useForm({
    name: '', category: '', cost_center_id: null as number | null,
    purchase_date: new Date().toISOString().slice(0, 10),
    cost: 0, salvage_value: 0, useful_life_months: 60, notes: '',
});

const openCreate = () => {
    form.reset();
    form.clearErrors();
    showModal.value = true;
};

const submit = () => form.post('/admin/accounting/fixed-assets', { preserveScroll: true, onSuccess: () => (showModal.value = false) });

const dispose = (a: Asset) => {
    if (confirm(`استبعاد الأصل "${a.name}"؟ لن يُرحَّل له إهلاك بعد اليوم.`)) {
        router.post(`/admin/accounting/fixed-assets/${a.id}/dispose`, {}, { preserveScroll: true });
    }
};

const destroy = (a: Asset) => {
    if (confirm(`حذف الأصل "${a.name}"؟`)) {
        router.delete(`/admin/accounting/fixed-assets/${a.id}`, { preserveScroll: true });
    }
};

const showDepreciationModal = ref(false);
const depreciationForm = useForm({ period: new Date().toISOString().slice(0, 7) });
const postDepreciation = () =>
    depreciationForm.post('/admin/accounting/fixed-assets/post-depreciation', {
        preserveScroll: true,
        onSuccess: () => (showDepreciationModal.value = false),
    });
</script>

<template>
    <Head title="الأصول الثابتة والإهلاك" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="min-h-full space-y-4 bg-slate-100 p-5">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h1 class="text-2xl font-extrabold text-slate-900">الأصول الثابتة والإهلاك</h1>
                    <p class="mt-1 text-sm font-medium text-slate-600">قسط ثابت شهري — يُرحَّل بزرار واحد لكل الأصول النشطة معًا</p>
                </div>
                <div class="flex gap-2">
                    <button v-if="can('fixed_assets.approve')" type="button" @click="showDepreciationModal = true" class="inline-flex items-center gap-1.5 rounded-md bg-emerald-600 px-4 py-2 text-sm font-bold text-white hover:bg-emerald-700">
                        <Repeat class="h-4 w-4" /> ترحيل إهلاك الشهر
                    </button>
                    <button v-if="can('fixed_assets.create')" type="button" @click="openCreate" class="inline-flex items-center gap-1.5 rounded-md bg-blue-600 px-4 py-2 text-sm font-bold text-white hover:bg-blue-700">
                        <Plus class="h-4 w-4" /> أصل جديد
                    </button>
                </div>
            </div>

            <div class="rounded-2xl border border-slate-200 bg-white p-3 shadow-sm">
                <div class="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
                    <select v-model="filters.status" @change="apply" class="rounded-xl border border-slate-200 px-3 py-2.5 text-sm">
                        <option :value="null">كل الحالات</option>
                        <option v-for="s in statuses" :key="s.key" :value="s.key">{{ s.label }}</option>
                    </select>
                    <SearchableSelect
                        v-model="filters.cost_center_id"
                        :options="costCenters"
                        :search-keys="['code']"
                        placeholder="كل مراكز التكلفة"
                        @change="apply"
                    />
                </div>
            </div>

            <div class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="bg-slate-100">
                            <tr>
                                <th class="px-4 py-3 text-right text-xs font-extrabold text-[#1e3a8a]">الأصل</th>
                                <th class="px-4 py-3 text-right text-xs font-extrabold text-[#1e3a8a]">مركز التكلفة</th>
                                <th class="px-4 py-3 text-left text-xs font-extrabold text-[#1e3a8a]">التكلفة</th>
                                <th class="px-4 py-3 text-left text-xs font-extrabold text-[#1e3a8a]">القسط الشهري</th>
                                <th class="px-4 py-3 text-left text-xs font-extrabold text-[#1e3a8a]">مجمع الإهلاك</th>
                                <th class="px-4 py-3 text-left text-xs font-extrabold text-[#1e3a8a]">القيمة الدفترية</th>
                                <th class="px-4 py-3 text-center text-xs font-extrabold text-[#1e3a8a]">الحالة</th>
                                <th class="px-4 py-3 text-center text-xs font-extrabold text-[#1e3a8a]">إجراءات</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="a in assets.data" :key="a.id" class="border-t border-slate-100 hover:bg-slate-50">
                                <td class="px-4 py-3 text-right">
                                    <div class="font-extrabold text-slate-800">{{ a.name }}</div>
                                    <!-- dir on the span, not the block, so the code stays under the name -->
                                    <div class="text-[11px] text-slate-500"><span dir="ltr">{{ a.code }}</span> — {{ a.category ?? '—' }}</div>
                                </td>
                                <td class="px-4 py-3 text-xs font-bold text-slate-600">{{ a.cost_center ?? '—' }}</td>
                                <td class="px-4 py-3 text-left font-bold text-slate-800" dir="ltr">{{ money(a.cost) }}</td>
                                <td class="px-4 py-3 text-left text-slate-700" dir="ltr">{{ money(a.monthly_depreciation) }}</td>
                                <td class="px-4 py-3 text-left text-amber-700" dir="ltr">{{ money(a.accumulated_depreciation) }}</td>
                                <td class="px-4 py-3 text-left font-extrabold text-slate-900" dir="ltr">{{ money(a.book_value) }}</td>
                                <td class="px-4 py-3 text-center">
                                    <span class="rounded-md px-2 py-0.5 text-[11px] font-bold" :class="a.status === 'active' ? 'bg-emerald-100 text-emerald-700' : 'bg-slate-200 text-slate-600'">{{ a.status_label }}</span>
                                </td>
                                <td class="px-4 py-3">
                                    <div class="flex items-center justify-center gap-1">
                                        <button v-if="can('fixed_assets.edit') && a.status === 'active'" type="button" @click="dispose(a)" title="استبعاد" class="rounded-lg bg-amber-500 p-1.5 text-white hover:bg-amber-600">
                                            <Ban class="h-3.5 w-3.5" />
                                        </button>
                                        <button v-if="can('fixed_assets.delete')" type="button" @click="destroy(a)" title="حذف" class="rounded-lg bg-red-500 p-1.5 text-white hover:bg-red-600">
                                            <Trash2 class="h-3.5 w-3.5" />
                                        </button>
                                    </div>
                                </td>
                            </tr>
                            <tr v-if="!assets.data.length"><td colspan="8" class="px-4 py-10 text-center text-sm text-slate-500">لا أصول مسجَّلة</td></tr>
                        </tbody>
                    </table>
                </div>

                <div v-if="assets.links.length > 3" class="flex flex-wrap justify-center gap-1 border-t border-slate-100 p-3">
                    <Link v-for="l in assets.links" :key="l.label" :href="l.url ?? '#'"
                        :class="['rounded-lg px-3 py-1.5 text-xs font-bold', l.active ? 'bg-blue-600 text-white' : l.url ? 'bg-white text-slate-600 ring-1 ring-slate-200' : 'text-slate-300']"
                        v-html="l.label" />
                </div>
            </div>
        </div>

        <!-- أصل جديد -->
        <div v-if="showModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4" @click.self="showModal = false">
            <div class="flex max-h-[92vh] w-full max-w-lg flex-col rounded-2xl bg-white shadow-2xl">
                <div class="flex items-center justify-between border-b border-slate-100 px-6 py-4">
                    <h2 class="text-lg font-extrabold text-slate-900">أصل ثابت جديد</h2>
                    <button type="button" @click="showModal = false" class="text-slate-400 hover:text-slate-600"><X class="h-5 w-5" /></button>
                </div>

                <form @submit.prevent="submit" class="flex min-h-0 flex-1 flex-col">
                    <div class="min-h-0 flex-1 space-y-3 overflow-y-auto px-6 py-4">
                        <div>
                            <label class="mb-1 block text-sm font-bold text-slate-700">اسم الأصل</label>
                            <input v-model="form.name" type="text" class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm" />
                            <p v-if="form.errors.name" class="mt-1 text-xs text-red-500">{{ form.errors.name }}</p>
                        </div>

                        <div class="grid gap-3 sm:grid-cols-2">
                            <div>
                                <label class="mb-1 block text-sm font-bold text-slate-700">التصنيف</label>
                                <input v-model="form.category" type="text" placeholder="تكييفات، أثاث…" class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm" />
                            </div>
                            <div>
                                <label class="mb-1 block text-sm font-bold text-slate-700">مركز التكلفة</label>
                                <SearchableSelect
                                    v-model="form.cost_center_id"
                                    :options="costCenters"
                                    :search-keys="['code']"
                                    placeholder="— اختر المركز —"
                                />
                                <p v-if="form.errors.cost_center_id" class="mt-1 text-xs text-red-500">{{ form.errors.cost_center_id }}</p>
                            </div>
                        </div>

                        <div class="grid gap-3 sm:grid-cols-2">
                            <div>
                                <label class="mb-1 block text-sm font-bold text-slate-700">تاريخ الشراء</label>
                                <input v-model="form.purchase_date" type="date" class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm" />
                            </div>
                            <div>
                                <label class="mb-1 block text-sm font-bold text-slate-700">التكلفة</label>
                                <input v-model.number="form.cost" type="number" min="0.01" step="0.01" class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm" />
                                <p v-if="form.errors.cost" class="mt-1 text-xs text-red-500">{{ form.errors.cost }}</p>
                            </div>
                        </div>

                        <div class="grid gap-3 sm:grid-cols-2">
                            <div>
                                <label class="mb-1 block text-sm font-bold text-slate-700">القيمة التخريدية</label>
                                <input v-model.number="form.salvage_value" type="number" min="0" step="0.01" class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm" />
                            </div>
                            <div>
                                <label class="mb-1 block text-sm font-bold text-slate-700">العمر الإنتاجي (أشهر)</label>
                                <input v-model.number="form.useful_life_months" type="number" min="1" class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm" />
                                <p v-if="form.errors.useful_life_months" class="mt-1 text-xs text-red-500">{{ form.errors.useful_life_months }}</p>
                            </div>
                        </div>

                        <div>
                            <label class="mb-1 block text-sm font-bold text-slate-700">ملاحظات</label>
                            <textarea v-model="form.notes" rows="2" class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm"></textarea>
                        </div>
                    </div>

                    <div class="flex justify-end gap-2 border-t border-slate-100 px-6 py-4">
                        <button type="button" @click="showModal = false" class="rounded-xl border border-slate-200 px-4 py-2 text-sm font-bold text-slate-600">إلغاء</button>
                        <button type="submit" :disabled="form.processing" class="rounded-md bg-blue-600 px-5 py-2 text-sm font-bold text-white hover:bg-blue-700 disabled:opacity-60">حفظ</button>
                    </div>
                </form>
            </div>
        </div>

        <!-- ترحيل إهلاك الشهر -->
        <div v-if="showDepreciationModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4" @click.self="showDepreciationModal = false">
            <div class="w-full max-w-sm rounded-2xl bg-white shadow-2xl">
                <div class="flex items-center justify-between border-b border-slate-100 px-6 py-4">
                    <h2 class="text-lg font-extrabold text-slate-900">ترحيل إهلاك الشهر</h2>
                    <button type="button" @click="showDepreciationModal = false" class="text-slate-400 hover:text-slate-600"><X class="h-5 w-5" /></button>
                </div>
                <form @submit.prevent="postDepreciation" class="space-y-3 px-6 py-4">
                    <div>
                        <label class="mb-1 block text-sm font-bold text-slate-700">الشهر</label>
                        <input v-model="depreciationForm.period" type="month" class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm" />
                        <p v-if="depreciationForm.errors.period" class="mt-1 text-xs text-red-500">{{ depreciationForm.errors.period }}</p>
                    </div>
                    <p class="text-xs text-slate-500">يُرحَّل قيد واحد مجمَّع لكل الأصول النشطة التي لم يُرحَّل لها إهلاك عن هذا الشهر بعد.</p>
                    <div class="flex justify-end gap-2 pt-2">
                        <button type="button" @click="showDepreciationModal = false" class="rounded-xl border border-slate-200 px-4 py-2 text-sm font-bold text-slate-600">إلغاء</button>
                        <button type="submit" :disabled="depreciationForm.processing" class="rounded-md bg-emerald-600 px-5 py-2 text-sm font-bold text-white hover:bg-emerald-700 disabled:opacity-60">ترحيل</button>
                    </div>
                </form>
            </div>
        </div>
    </AppLayout>
</template>
