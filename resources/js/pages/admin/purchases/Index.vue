<script setup lang="ts">
import { StatPill } from '@/components/data-table';
import { usePermissions } from '@/composables/usePermissions';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem, type PaymentMethodOption } from '@/types';
import { Head, Link, router } from '@inertiajs/vue3';
import { Eye, Receipt, Search, X, Pencil, Trash2 } from 'lucide-vue-next';
import { computed, ref } from 'vue';

interface PurchaseRow {
    id: number; number: string;
    date: string; time: string;
    supplier: string | null;
    department: string | null; user: string | null;
    payment_method_id: number; method_label: string;
    subtotal: number; tax_amount: number; discount_amount: number;
    total: number;
    paid: number; remaining: number;
    status: string; status_label: string;
}

interface PurchaseLine {
    id: number; item_id: number; name: string; code: string | null;
    quantity: number; unit_cost: number; total_cost: number;
}

interface PurchaseDetails {
    purchase: PurchaseRow & {
        notes: string | null;
    };
    items: PurchaseLine[];
}

const props = defineProps<{
    purchases: { data: PurchaseRow[]; links: { url: string | null; label: string; active: boolean }[] };
    stats: {
        count: number; purchases_total: number; tax_total: number;
        paid: number; remaining: number;
    };
    filters: Record<string, string | null>;
    departments: { id: number; name: string; code: string | null }[];
    suppliers: { id: number; name: string }[];
    methods: PaymentMethodOption[];
}>();

const { can } = usePermissions();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'لوحة التحكم', href: '/admin' },
    { title: 'سجل المشتريات', href: '/admin/purchases' },
];

const money = (n: number) => new Intl.NumberFormat('ar-SA-u-nu-latn', { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(n ?? 0);
const qty = (n: number) => new Intl.NumberFormat('ar-SA-u-nu-latn', { maximumFractionDigits: 3 }).format(n ?? 0);

// ── Filtering ─────────────────────────────────────────────────
const filters = ref({ ...props.filters });

const apply = () =>
    router.get('/admin/purchases', filters.value, { preserveState: true, preserveScroll: true, replace: true });

const reset = () => {
    filters.value = {
        department_id: filters.value.department_id,
        supplier_id: null, payment_method_id: null, from: null, to: null, search: null,
    };
    apply();
};

const departmentTitle = computed(
    () => props.departments.find((d) => String(d.id) === String(filters.value.department_id))?.name ?? 'كل الأقسام',
);

const statusClass = (s: string) =>
    ({
        paid: 'bg-emerald-100 text-emerald-700',
        partial: 'bg-amber-100 text-amber-700',
        unpaid: 'bg-red-100 text-red-700',
    })[s] ?? 'bg-slate-100 text-slate-700';

// ── Details Modal ──────────────────────────────────────────
const details = ref<PurchaseDetails | null>(null);
const detailsLoading = ref(false);
const activePurchase = ref<PurchaseRow | null>(null);

const loadDetails = async (purchase: PurchaseRow) => {
    detailsLoading.value = true;
    try {
        const res = await fetch(`/admin/purchases/${purchase.id}`, { headers: { Accept: 'application/json' } });
        details.value = (await res.json()) as PurchaseDetails;
    } finally {
        detailsLoading.value = false;
    }
};

const openDetails = (purchase: PurchaseRow) => {
    activePurchase.value = purchase;
    details.value = null;
    loadDetails(purchase);
};

const closeDetails = () => {
    activePurchase.value = null;
    details.value = null;
};

const destroy = (p: PurchaseRow) => {
    if (confirm(`هل أنت متأكد من حذف فاتورة المشتريات رقم ${p.number}؟ تنبيه: سيتم عكس كميات المخزون لهذه الفاتورة.`)) {
        router.delete(`/admin/purchases/${p.id}`, { preserveScroll: true });
    }
};
</script>

<template>
    <Head title="سجل المشتريات" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="min-h-full space-y-4 bg-slate-100 p-5">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h1 class="text-2xl font-extrabold text-slate-900">سجل المشتريات — {{ departmentTitle }}</h1>
                    <p class="mt-1 text-sm font-medium text-slate-600">
                        فواتير المشتريات ومبالغها ومواردها
                    </p>
                </div>
                <Link v-if="can('purchases.create')" href="/admin/purchases/create" class="inline-flex items-center gap-1.5 rounded-md bg-blue-600 px-4 py-2 text-sm font-bold text-white hover:bg-blue-700">
                    <Receipt class="h-4 w-4" /> فاتورة مشتريات جديدة
                </Link>
            </div>

            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
                <StatPill label="عدد الفواتير" :value="String(stats.count)" />
                <StatPill label="إجمالي المشتريات" :value="money(stats.purchases_total)" variant="info" />
                <StatPill label="الضريبة" :value="money(stats.tax_total)" />
                <StatPill label="المسدّد" :value="money(stats.paid)" variant="success" />
                <StatPill label="المتبقي" :value="money(stats.remaining)" :variant="stats.remaining > 0 ? 'danger' : 'success'" />
            </div>

            <div class="rounded-2xl border border-slate-200 bg-white p-3 shadow-sm">
                <div class="grid gap-2 sm:grid-cols-2 lg:grid-cols-4">
                    <select v-model="filters.department_id" @change="apply" class="rounded-xl border border-slate-200 px-3 py-2.5 text-sm">
                        <option v-for="d in departments" :key="d.id" :value="String(d.id)">{{ d.name }}</option>
                        <option value="all">كل الأقسام</option>
                    </select>

                    <select v-model="filters.supplier_id" @change="apply" class="rounded-xl border border-slate-200 px-3 py-2.5 text-sm">
                        <option :value="null">كل الموردين</option>
                        <option v-for="s in suppliers" :key="s.id" :value="s.id">{{ s.name }}</option>
                    </select>

                    <select v-model="filters.payment_method_id" @change="apply" class="rounded-xl border border-slate-200 px-3 py-2.5 text-sm">
                        <option :value="null">كل طرق الدفع</option>
                        <option v-for="m in methods" :key="m.id" :value="m.id">{{ m.label }}</option>
                    </select>

                    <input v-model="filters.from" @change="apply" type="date" class="rounded-xl border border-slate-200 px-3 py-2.5 text-sm" />
                    <input v-model="filters.to" @change="apply" type="date" class="rounded-xl border border-slate-200 px-3 py-2.5 text-sm" />

                    <div class="relative sm:col-span-2">
                        <Search class="absolute top-1/2 right-3 h-4 w-4 -translate-y-1/2 text-slate-400" />
                        <input
                            v-model="filters.search" @keyup.enter="apply" type="search"
                            placeholder="رقم الفاتورة أو اسم المورد"
                            class="w-full rounded-xl border border-slate-200 py-2.5 pr-9 pl-3 text-sm"
                        />
                    </div>
                </div>

                <div class="mt-2 flex flex-wrap items-center gap-2">
                    <button type="button" @click="apply" class="rounded-lg bg-slate-800 px-4 py-1.5 text-xs font-bold text-white hover:bg-slate-900">تطبيق</button>
                    <button type="button" @click="reset" class="rounded-lg border border-slate-200 px-4 py-1.5 text-xs font-bold text-slate-600 hover:bg-slate-50">مسح التصفية</button>
                </div>
            </div>

            <div class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="bg-slate-100">
                            <tr>
                                <th class="px-4 py-3 text-right text-xs font-extrabold text-[#1e3a8a]">الفاتورة</th>
                                <th class="px-4 py-3 text-right text-xs font-extrabold text-[#1e3a8a]">المورد</th>
                                <th class="px-4 py-3 text-center text-xs font-extrabold text-[#1e3a8a]">الدفع</th>
                                <th class="px-4 py-3 text-left text-xs font-extrabold text-[#1e3a8a]">الضريبة</th>
                                <th class="px-4 py-3 text-left text-xs font-extrabold text-[#1e3a8a]">الإجمالي</th>
                                <th class="px-4 py-3 text-left text-xs font-extrabold text-[#1e3a8a]">المسدّد</th>
                                <th class="px-4 py-3 text-left text-xs font-extrabold text-[#1e3a8a]">المتبقي</th>
                                <th class="px-4 py-3 text-center text-xs font-extrabold text-[#1e3a8a]">الحالة</th>
                                <th class="px-4 py-3 text-center text-xs font-extrabold text-[#1e3a8a]">إجراءات</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="p in purchases.data" :key="p.id" class="border-t border-slate-100 hover:bg-slate-50">
                                <td class="px-4 py-3">
                                    <div class="font-extrabold text-slate-800" dir="ltr">{{ p.number }}</div>
                                    <div class="text-[11px] text-slate-500" dir="ltr">{{ p.date }} · {{ p.time }}</div>
                                </td>
                                <td class="px-4 py-3">
                                    <div class="text-xs font-bold text-slate-700">{{ p.supplier ?? '—' }}</div>
                                    <div class="text-[11px] text-slate-500">{{ p.department ?? '—' }}</div>
                                </td>
                                <td class="px-4 py-3 text-center text-[11px] font-bold text-slate-600">{{ p.method_label }}</td>
                                <td class="px-4 py-3 text-left text-xs font-bold" :class="p.tax_amount > 0 ? 'text-slate-600' : 'text-slate-400'" dir="ltr">
                                    {{ p.tax_amount > 0 ? money(p.tax_amount) : '—' }}
                                </td>
                                <td class="px-4 py-3 text-left font-extrabold text-slate-800" dir="ltr">{{ money(p.total) }}</td>
                                <td class="px-4 py-3 text-left text-xs font-bold text-emerald-700" dir="ltr">{{ money(p.paid) }}</td>
                                <td class="px-4 py-3 text-left text-xs font-extrabold" :class="p.remaining > 0 ? 'text-red-600' : 'text-slate-400'" dir="ltr">
                                    {{ p.remaining > 0 ? money(p.remaining) : '—' }}
                                </td>
                                <td class="px-4 py-3 text-center">
                                    <span class="rounded-md px-2 py-0.5 text-[11px] font-bold" :class="statusClass(p.status)">
                                        {{ p.status_label }}
                                    </span>
                                </td>
                                <td class="px-4 py-3">
                                    <div class="flex items-center justify-center gap-1">
                                        <button type="button" @click="openDetails(p)" title="تفاصيل الفاتورة" class="rounded-lg bg-slate-500 p-1.5 text-white hover:bg-slate-600">
                                            <Eye class="h-3.5 w-3.5" />
                                        </button>
                                        <Link v-if="can('purchases.edit')" :href="`/admin/purchases/${p.id}/edit`" title="تعديل" class="rounded-lg bg-emerald-100 p-1.5 text-emerald-700 hover:bg-emerald-200">
                                            <Pencil class="h-3.5 w-3.5" />
                                        </Link>
                                        <button v-if="can('purchases.delete')" type="button" @click="destroy(p)" title="حذف" class="rounded-lg bg-red-100 p-1.5 text-red-700 hover:bg-red-200">
                                            <Trash2 class="h-3.5 w-3.5" />
                                        </button>
                                    </div>
                                </td>
                            </tr>
                            <tr v-if="!purchases.data.length">
                                <td colspan="9" class="px-4 py-10 text-center text-sm text-slate-500">لا فواتير مطابقة للتصفية</td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div v-if="purchases.links.length > 3" class="flex flex-wrap justify-center gap-1 border-t border-slate-100 p-3">
                    <Link
                        v-for="l in purchases.links" :key="l.label" :href="l.url ?? '#'"
                        :class="['rounded-lg px-3 py-1.5 text-xs font-bold', l.active ? 'bg-blue-600 text-white' : l.url ? 'bg-white text-slate-600 ring-1 ring-slate-200' : 'text-slate-300']"
                        v-html="l.label"
                    />
                </div>
            </div>
        </div>

        <Teleport to="body">
        <div v-if="activePurchase" class="invoice-overlay fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-black/40 p-4" @click.self="closeDetails">
            <div class="invoice-sheet my-auto w-full max-w-3xl rounded-2xl bg-white shadow-2xl">
                <div class="flex items-center justify-between gap-2 border-b border-slate-100 px-6 py-3">
                    <h2 class="text-lg font-bold text-slate-800">تفاصيل الفاتورة {{ activePurchase.number }}</h2>
                    <button type="button" @click="closeDetails" class="text-slate-400 hover:text-slate-600"><X class="h-5 w-5" /></button>
                </div>

                <p v-if="detailsLoading" class="py-16 text-center text-sm text-slate-500">جارٍ التحميل…</p>

                <div v-else-if="details" class="invoice-body space-y-5 px-8 py-6">
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <p class="text-sm font-bold text-slate-600">المورد: <span class="font-extrabold text-slate-900">{{ details.purchase.supplier ?? '—' }}</span></p>
                            <p class="text-sm font-bold text-slate-600">تاريخ: <span dir="ltr">{{ details.purchase.date }} {{ details.purchase.time }}</span></p>
                        </div>
                        <div class="text-left">
                            <p class="text-sm font-bold text-slate-600">طريقة الدفع: {{ details.purchase.method_label }}</p>
                            <p class="text-sm font-bold text-slate-600">المستخدم: {{ details.purchase.user }}</p>
                        </div>
                    </div>

                    <table class="w-full border border-slate-300 text-xs">
                        <thead class="bg-slate-100">
                            <tr>
                                <th class="border border-slate-300 px-2 py-2 text-center font-extrabold text-[#1e3a8a]">#</th>
                                <th class="border border-slate-300 px-2 py-2 text-right font-extrabold text-[#1e3a8a]">الصنف</th>
                                <th class="border border-slate-300 px-2 py-2 text-center font-extrabold text-[#1e3a8a]">الكمية</th>
                                <th class="border border-slate-300 px-2 py-2 text-center font-extrabold text-[#1e3a8a]">تكلفة الوحدة</th>
                                <th class="border border-slate-300 px-2 py-2 text-center font-extrabold text-[#1e3a8a]">الإجمالي</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="(l, i) in details.items" :key="l.id">
                                <td class="border border-slate-300 px-2 py-1.5 text-center text-slate-500" dir="ltr">{{ i + 1 }}</td>
                                <td class="border border-slate-300 px-2 py-1.5 font-bold text-slate-800">{{ l.name }}</td>
                                <td class="border border-slate-300 px-2 py-1.5 text-center" dir="ltr">{{ qty(l.quantity) }}</td>
                                <td class="border border-slate-300 px-2 py-1.5 text-center" dir="ltr">{{ money(l.unit_cost) }}</td>
                                <td class="border border-slate-300 px-2 py-1.5 text-center font-extrabold" dir="ltr">{{ money(l.total_cost) }}</td>
                            </tr>
                        </tbody>
                    </table>

                    <div class="grid items-start gap-6 sm:grid-cols-2">
                        <div class="order-2 sm:order-1"></div>
                        <dl class="order-1 space-y-1 text-xs sm:order-2">
                            <div class="flex justify-between border-b border-slate-100 py-1">
                                <dt class="font-bold text-slate-600">الإجمالي قبل الضريبة</dt>
                                <dd class="font-bold text-slate-800" dir="ltr">{{ money(details.purchase.subtotal) }}</dd>
                            </div>
                            <div v-if="details.purchase.discount_amount > 0" class="flex justify-between border-b border-slate-100 py-1">
                                <dt class="font-bold text-slate-600">الخصم</dt>
                                <dd class="font-bold text-slate-800" dir="ltr">{{ money(details.purchase.discount_amount) }}</dd>
                            </div>
                            <div class="flex justify-between border-b border-slate-100 py-1">
                                <dt class="font-bold text-slate-600">ضريبة القيمة المضافة</dt>
                                <dd class="font-bold text-slate-800" dir="ltr">{{ money(details.purchase.tax_amount) }}</dd>
                            </div>
                            <div class="flex justify-between bg-slate-800 px-2 py-1.5 text-sm text-white">
                                <dt class="font-extrabold">الإجمالي شامل الضريبة</dt>
                                <dd class="font-extrabold" dir="ltr">{{ money(details.purchase.total) }}</dd>
                            </div>
                            <div class="flex justify-between py-1">
                                <dt class="font-bold text-slate-600">المسدّد</dt>
                                <dd class="font-bold text-emerald-700" dir="ltr">{{ money(details.purchase.paid) }}</dd>
                            </div>
                            <div class="flex justify-between py-1">
                                <dt class="font-bold text-slate-600">المتبقي</dt>
                                <dd class="font-extrabold" :class="details.purchase.remaining > 0 ? 'text-red-600' : 'text-slate-500'" dir="ltr">
                                    {{ money(details.purchase.remaining) }}
                                </dd>
                            </div>
                        </dl>
                    </div>

                    <p v-if="details.purchase.notes" class="rounded-lg bg-slate-50 px-3 py-2 text-xs text-slate-600 print:bg-transparent print:px-0">
                        ملاحظات: {{ details.purchase.notes }}
                    </p>
                </div>
            </div>
        </div>
        </Teleport>
    </AppLayout>
</template>
