<script setup lang="ts">
import { StatusBadge } from '@/components/data-table';
import SmallBox from '@/components/lte/SmallBox.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { Eye, FileSpreadsheet, Pencil, Plus, Power, ReceiptText, Search, Trash2, UserCheck, Users, UserX, X } from 'lucide-vue-next';
import { computed, reactive, ref, watch } from 'vue';

interface ClientRow {
    id: number;
    name: string;
    mobile: string | null;
    email: string | null;
    city: string | null;
    national_id: string | null;
    is_taxable: boolean;
    tax_number: string | null;
    tax_address: string | null;
    is_active: boolean;
    /** العميل النقدي الافتراضي — لا يُوقَف ولا يُحذف. */
    is_walk_in: boolean;
    /** حجوزاته القائمة (غير الملغاة) وفواتير بيعه — مختصر ملفه في الصف. */
    bookings_count: number;
    sales_count: number;
    /** المتبقي على حجوزاته القائمة. */
    remaining: number;
    created_at: string | null;
}
interface Paginated<T> {
    data: T[];
    links: { url: string | null; label: string; active: boolean }[];
    from: number | null;
    to: number | null;
    total: number;
}

interface ClientStats {
    total: number;
    active: number;
    inactive: number;
    taxable: number;
    non_taxable: number;
}

interface Filters {
    name: string;
    mobile: string;
    email: string;
    city: string;
    status: string;
    from: string;
    to: string;
}

const props = defineProps<{
    clients: Paginated<ClientRow>;
    filters: Filters;
    stats: ClientStats;
    cities: string[];
}>();

const money = (n: number) => new Intl.NumberFormat('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(n ?? 0);

// خيارات المدينة في نموذج العميل: قائمة المدن المفعّلة، مع تضمين القيمة الحالية
// إن كانت مخزّنة سابقاً لكنها غير موجودة/موقوفة في قائمة المدن (حفاظاً على البيانات القديمة).
const cityOptions = computed(() => {
    const list = [...props.cities];
    if (form.city && !list.includes(form.city)) {
        list.unshift(form.city);
    }
    return list;
});

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'لوحة التحكم', href: '/admin' },
    { title: 'العملاء', href: '/admin/clients' },
];

// ===== الفلاتر =====
const f = reactive<Filters>({
    name: props.filters.name ?? '',
    mobile: props.filters.mobile ?? '',
    email: props.filters.email ?? '',
    city: props.filters.city ?? '',
    status: props.filters.status ?? '',
    from: props.filters.from ?? '',
    to: props.filters.to ?? '',
});

const reload = () => {
    router.get('/admin/clients', { ...f }, { preserveState: true, replace: true, preserveScroll: true });
};

// بحث فوري (live) أثناء الكتابة مع تأخير بسيط لتقليل الطلبات.
let searchTimer: ReturnType<typeof setTimeout> | undefined;
watch(
    () => [f.name, f.mobile, f.email, f.city],
    () => {
        clearTimeout(searchTimer);
        searchTimer = setTimeout(reload, 350);
    },
);

// رابط تصدير Excel محمّلاً بالفلاتر الحالية.
const exportUrl = computed(() => {
    const params = new URLSearchParams(Object.entries(f).filter(([, v]) => v !== '' && v != null) as [string, string][]);
    const qs = params.toString();
    return `/admin/clients/export${qs ? `?${qs}` : ''}`;
});

// ===== نموذج الإضافة/التعديل =====
const showModal = ref(false);
const editingId = ref<number | null>(null);
const form = useForm({
    name: '',
    mobile: '',
    email: '',
    city: '',
    national_id: '',
    is_taxable: false,
    tax_number: '',
    tax_address: '',
    is_active: true,
});

const openCreate = () => {
    editingId.value = null;
    form.reset();
    form.clearErrors();
    showModal.value = true;
};

const openEdit = (c: ClientRow) => {
    editingId.value = c.id;
    form.reset();
    form.clearErrors();
    form.name = c.name;
    form.mobile = c.mobile ?? '';
    form.email = c.email ?? '';
    form.city = c.city ?? '';
    form.national_id = c.national_id ?? '';
    form.is_taxable = c.is_taxable;
    form.tax_number = c.tax_number ?? '';
    form.tax_address = c.tax_address ?? '';
    form.is_active = c.is_active;
    showModal.value = true;
};

const submit = () => {
    if (editingId.value) {
        form.put(`/admin/clients/${editingId.value}`, { preserveScroll: true, onSuccess: () => (showModal.value = false) });
    } else {
        form.post('/admin/clients', { preserveScroll: true, onSuccess: () => (showModal.value = false) });
    }
};

const toggle = (c: ClientRow) => router.patch(`/admin/clients/${c.id}/toggle`, {}, { preserveScroll: true });

const destroy = (c: ClientRow) => {
    if (confirm(`حذف العميل «${c.name}»؟`)) {
        router.delete(`/admin/clients/${c.id}`, { preserveScroll: true });
    }
};
</script>

<template>
    <Head title="العملاء" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="space-y-4 p-5">
            <h1 class="text-2xl font-extrabold text-slate-900">العملاء</h1>

            <!-- مربّعات الإحصائيات -->
            <div class="grid grid-cols-2 gap-4 lg:grid-cols-4">
                <SmallBox :value="stats.total" label="كل العملاء" variant="info" :icon="Users" />
                <SmallBox :value="stats.active" label="المفعّلون" variant="success" :icon="UserCheck" />
                <SmallBox :value="stats.inactive" label="الموقوفون" variant="danger" :icon="UserX" />
                <SmallBox :value="stats.taxable" label="عملاء ضريبيون" variant="warning" :icon="ReceiptText" />
            </div>

            <!-- بطاقة العملاء بنمط AdminLTE (User Directory) -->
            <div class="lte-card">
                <!-- الرأس: العنوان + بحث + فلتر + أزرار -->
                <div class="lte-card-header">
                    <h3 class="lte-card-title">دليل العملاء</h3>
                    <div class="flex flex-wrap items-center gap-2">
                        <!-- بحث -->
                        <div class="flex items-stretch overflow-hidden rounded-md border border-slate-300">
                            <span class="flex items-center bg-slate-50 px-2.5 text-slate-400"><Search class="h-4 w-4" /></span>
                            <input
                                v-model="f.name"
                                type="search"
                                placeholder="بحث عن عميل"
                                class="w-44 border-0 px-3 py-2 text-sm focus:outline-none focus:ring-0"
                            />
                        </div>
                        <!-- فلتر الحالة -->
                        <select v-model="f.status" @change="reload" class="rounded-md border border-slate-300 px-3 py-2 text-sm">
                            <option value="">كل الحالات</option>
                            <option value="active">مفعّل</option>
                            <option value="inactive">موقوف</option>
                            <option value="taxable">ضريبي</option>
                            <option value="non_taxable">بدون ضريبة</option>
                        </select>
                        <!-- إضافة -->
                        <button
                            type="button"
                            @click="openCreate"
                            class="inline-flex items-center gap-1.5 rounded-md bg-blue-600 px-4 py-2 text-sm font-bold text-white transition hover:bg-blue-700"
                        >
                            <Plus class="h-4 w-4" /> عميل جديد
                        </button>
                        <!-- تصدير -->
                        <a
                            :href="exportUrl"
                            class="inline-flex items-center gap-1.5 rounded-md bg-emerald-600 px-4 py-2 text-sm font-bold text-white transition hover:bg-emerald-700"
                        >
                            <FileSpreadsheet class="h-4 w-4" /> تصدير
                        </a>
                    </div>
                </div>

                <!-- الجدول (card-body p-0) -->
                <div class="overflow-x-auto">
                    <table class="w-full min-w-[860px] text-sm">
                        <thead>
                            <tr class="border-b-2 border-[#dee2e6] text-[#1e3a8a]">
                                <th class="px-4 py-3 text-start font-semibold">العميل</th>
                                <th class="px-4 py-3 text-start font-semibold">الجوال</th>
                                <th class="px-4 py-3 text-start font-semibold">البريد</th>
                                <th class="px-4 py-3 text-center font-semibold">التعاملات</th>
                                <th class="px-4 py-3 text-center font-semibold">النوع</th>
                                <th class="px-4 py-3 text-center font-semibold">الحالة</th>
                                <th class="px-4 py-3 text-center font-semibold">التاريخ</th>
                                <th class="px-4 py-3 text-end font-semibold">الإجراءات</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="c in clients.data" :key="c.id" class="border-b border-slate-100 align-middle transition hover:bg-slate-50">
                                <!-- العميل: الاسم -->
                                <td class="px-4 py-2.5">
                                    <div class="flex items-center gap-1.5">
                                        <!-- الاسم بابُ الملف: سجل العميل كله خلف نقرة واحدة -->
                                        <Link :href="`/admin/clients/${c.id}`" class="font-bold text-slate-800 hover:text-blue-600 hover:underline">
                                            {{ c.name }}
                                        </Link>
                                        <span
                                            v-if="c.is_walk_in"
                                            class="rounded bg-emerald-100 px-1.5 py-0.5 text-[10px] font-extrabold text-emerald-800"
                                            >افتراضي</span
                                        >
                                    </div>
                                    <div v-if="c.national_id" class="text-xs text-slate-500" dir="ltr">{{ c.national_id }}</div>
                                </td>
                                <td class="px-4 py-2.5 font-bold text-slate-900" dir="ltr">{{ c.mobile || '—' }}</td>
                                <td class="px-4 py-2.5" dir="ltr">{{ c.email || '—' }}</td>
                                <!-- التعاملات: بابٌ إلى ملفه — الأرقام تُغري بالنقر قبل السؤال -->
                                <td class="px-4 py-2.5 text-center">
                                    <Link :href="`/admin/clients/${c.id}`" class="inline-flex flex-col items-center gap-1">
                                        <span class="inline-flex items-center gap-1">
                                            <span
                                                class="rounded bg-blue-50 px-1.5 py-0.5 text-[11px] font-extrabold text-blue-700"
                                                title="الحجوزات القائمة"
                                                >{{ c.bookings_count }} حجز</span
                                            >
                                            <span
                                                class="rounded bg-slate-100 px-1.5 py-0.5 text-[11px] font-extrabold text-slate-600"
                                                title="فواتير البيع"
                                                >{{ c.sales_count }} فاتورة</span
                                            >
                                        </span>
                                        <span v-if="c.remaining > 0" class="text-[11px] font-bold text-red-600" dir="ltr"
                                            >{{ money(c.remaining) }} متبقٍ</span
                                        >
                                    </Link>
                                </td>
                                <td class="px-4 py-2.5 text-center">
                                    <StatusBadge :variant="c.is_taxable ? 'info' : 'neutral'" :label="c.is_taxable ? 'ضريبي' : 'عادي'" />
                                </td>
                                <td class="px-4 py-2.5 text-center">
                                    <StatusBadge :variant="c.is_active ? 'success' : 'danger'" :label="c.is_active ? 'مفعّل' : 'موقوف'" />
                                </td>
                                <td class="px-4 py-2.5 text-center text-slate-500" dir="ltr">{{ c.created_at || '—' }}</td>
                                <!-- الإجراءات: مجموعة أزرار محدّدة (outline) بنمط AdminLTE -->
                                <td class="px-4 py-2.5">
                                    <div class="flex justify-end">
                                        <div
                                            class="inline-flex divide-x divide-slate-300 overflow-hidden rounded-md border border-slate-300 rtl:divide-x-reverse"
                                        >
                                            <Link
                                                :href="`/admin/clients/${c.id}`"
                                                title="ملف العميل: حجوزاته وفواتيره ودفعاته"
                                                class="px-2.5 py-2 text-blue-600 transition hover:bg-blue-50"
                                            >
                                                <Eye class="h-4 w-4" />
                                            </Link>
                                            <button
                                                type="button"
                                                @click="openEdit(c)"
                                                title="تعديل"
                                                class="px-2.5 py-2 text-slate-600 transition hover:bg-slate-100"
                                            >
                                                <Pencil class="h-4 w-4" />
                                            </button>
                                            <button
                                                v-if="!c.is_walk_in"
                                                type="button"
                                                @click="toggle(c)"
                                                title="تفعيل/إيقاف"
                                                class="px-2.5 py-2 text-emerald-600 transition hover:bg-emerald-50"
                                            >
                                                <Power class="h-4 w-4" />
                                            </button>
                                            <button
                                                v-if="!c.is_walk_in"
                                                type="button"
                                                @click="destroy(c)"
                                                title="حذف"
                                                class="px-2.5 py-2 text-red-600 transition hover:bg-red-50"
                                            >
                                                <Trash2 class="h-4 w-4" />
                                            </button>
                                        </div>
                                    </div>
                                </td>
                            </tr>
                            <tr v-if="clients.data.length === 0">
                                <td colspan="8" class="px-4 py-12 text-center text-sm text-slate-400">لا يوجد عملاء مطابقون.</td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- الفوتر: العدّ + ترقيم الصفحات -->
                <div class="lte-card-footer">
                    <div class="text-sm font-medium text-slate-500">
                        عرض {{ clients.from ?? 0 }} إلى {{ clients.to ?? 0 }} من {{ clients.total }} عميل
                    </div>
                    <div
                        v-if="clients.links.length > 3"
                        class="inline-flex divide-x divide-slate-300 overflow-hidden rounded-md border border-slate-300 rtl:divide-x-reverse"
                    >
                        <template v-for="(link, i) in clients.links" :key="i">
                            <Link
                                v-if="link.url"
                                :href="link.url"
                                preserve-scroll
                                :class="[
                                    'px-3 py-1.5 text-sm transition',
                                    link.active ? 'bg-blue-600 font-bold text-white' : 'bg-white text-slate-600 hover:bg-slate-50',
                                ]"
                                v-html="link.label"
                            />
                            <span v-else class="bg-white px-3 py-1.5 text-sm text-slate-300" v-html="link.label" />
                        </template>
                    </div>
                </div>
            </div>
        </div>

        <!-- نافذة النموذج -->
        <div v-if="showModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4" @click.self="showModal = false">
            <div class="max-h-[90vh] w-full max-w-md overflow-y-auto rounded-2xl bg-white p-6 shadow-2xl">
                <div class="mb-4 flex items-center justify-between">
                    <h2 class="text-lg font-extrabold text-slate-900">{{ editingId ? 'تعديل عميل' : 'عميل جديد' }}</h2>
                    <button type="button" @click="showModal = false" class="rounded-lg p-1 text-slate-400 hover:bg-slate-100 hover:text-slate-600">
                        <X class="h-5 w-5" />
                    </button>
                </div>
                <form @submit.prevent="submit" class="space-y-3">
                    <div>
                        <label class="mb-1 block text-sm font-bold text-slate-700">اسم العميل</label>
                        <input
                            v-model="form.name"
                            type="text"
                            class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm focus:border-emerald-400 focus:outline-none focus:ring-2 focus:ring-emerald-100"
                        />
                        <p v-if="form.errors.name" class="mt-1 text-xs text-red-500">{{ form.errors.name }}</p>
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="mb-1 block text-sm font-bold text-slate-700">الجوال</label>
                            <input
                                v-model="form.mobile"
                                type="text"
                                dir="ltr"
                                class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm focus:border-emerald-400 focus:outline-none focus:ring-2 focus:ring-emerald-100"
                            />
                            <p v-if="form.errors.mobile" class="mt-1 text-xs text-red-500">{{ form.errors.mobile }}</p>
                        </div>
                        <div>
                            <label class="mb-1 block text-sm font-bold text-slate-700">المدينة</label>
                            <select
                                v-model="form.city"
                                class="w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm focus:border-emerald-400 focus:outline-none focus:ring-2 focus:ring-emerald-100"
                            >
                                <option value="">— اختر المدينة —</option>
                                <option v-for="city in cityOptions" :key="city" :value="city">{{ city }}</option>
                            </select>
                            <p v-if="form.errors.city" class="mt-1 text-xs text-red-500">{{ form.errors.city }}</p>
                        </div>
                    </div>
                    <div>
                        <label class="mb-1 block text-sm font-bold text-slate-700">البريد الإلكتروني</label>
                        <input
                            v-model="form.email"
                            type="email"
                            dir="ltr"
                            class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm focus:border-emerald-400 focus:outline-none focus:ring-2 focus:ring-emerald-100"
                        />
                        <p v-if="form.errors.email" class="mt-1 text-xs text-red-500">{{ form.errors.email }}</p>
                    </div>
                    <div>
                        <label class="mb-1 block text-sm font-bold text-slate-700">
                            رقم الهوية <span class="font-medium text-slate-400">(اختياري)</span>
                        </label>
                        <input
                            v-model="form.national_id"
                            type="text"
                            dir="ltr"
                            class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm focus:border-emerald-400 focus:outline-none focus:ring-2 focus:ring-emerald-100"
                        />
                        <p v-if="form.errors.national_id" class="mt-1 text-xs text-red-500">{{ form.errors.national_id }}</p>
                    </div>

                    <!-- سويتش العميل الضريبي -->
                    <div class="flex items-center justify-between rounded-xl border border-slate-200 bg-slate-50 px-3 py-2.5">
                        <span class="text-sm font-bold text-slate-700">عميل ضريبي</span>
                        <button
                            type="button"
                            role="switch"
                            :aria-checked="form.is_taxable"
                            @click="form.is_taxable = !form.is_taxable"
                            :class="[
                                'relative inline-flex h-6 w-11 items-center rounded-full transition',
                                form.is_taxable ? 'brand-gradient' : 'bg-slate-300',
                            ]"
                        >
                            <span
                                :class="[
                                    'inline-block h-4 w-4 transform rounded-full bg-white transition',
                                    form.is_taxable ? '-translate-x-1' : '-translate-x-6',
                                ]"
                            ></span>
                        </button>
                    </div>

                    <!-- بيانات الضريبة -->
                    <template v-if="form.is_taxable">
                        <div>
                            <label class="mb-1 block text-sm font-bold text-slate-700">الرقم الضريبي</label>
                            <input
                                v-model="form.tax_number"
                                type="text"
                                dir="ltr"
                                class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm focus:border-emerald-400 focus:outline-none focus:ring-2 focus:ring-emerald-100"
                            />
                            <p v-if="form.errors.tax_number" class="mt-1 text-xs text-red-500">{{ form.errors.tax_number }}</p>
                        </div>
                        <div>
                            <label class="mb-1 block text-sm font-bold text-slate-700">العنوان الضريبي</label>
                            <textarea
                                v-model="form.tax_address"
                                rows="2"
                                class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm focus:border-emerald-400 focus:outline-none focus:ring-2 focus:ring-emerald-100"
                            ></textarea>
                            <p v-if="form.errors.tax_address" class="mt-1 text-xs text-red-500">{{ form.errors.tax_address }}</p>
                        </div>
                    </template>

                    <label class="flex items-center gap-2 text-sm font-bold text-slate-700">
                        <input
                            v-model="form.is_active"
                            type="checkbox"
                            class="h-4 w-4 rounded border-slate-300 text-emerald-600 focus:ring-emerald-200"
                        />
                        عميل نشط
                    </label>
                    <div class="flex justify-end gap-2 pt-2">
                        <button
                            type="button"
                            @click="showModal = false"
                            class="rounded-xl border border-slate-200 bg-white px-4 py-2 text-sm font-bold text-slate-600 hover:bg-slate-50"
                        >
                            إلغاء
                        </button>
                        <button
                            type="submit"
                            :disabled="form.processing"
                            class="brand-gradient rounded-xl px-5 py-2 text-sm font-bold text-white shadow-md transition hover:brightness-110 disabled:opacity-60"
                        >
                            حفظ
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </AppLayout>
</template>
