<script setup lang="ts">
import { StatPill } from '@/components/data-table';
import { usePermissions } from '@/composables/usePermissions';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/vue3';
import { Eye, Printer, FileText, Search, X, Pencil, Trash2 } from 'lucide-vue-next';
import { computed, ref } from 'vue';

interface QuotationRow {
    id: number; number: string;
    date: string; time: string; valid_until: string | null;
    client: string | null; user: string | null;
    subtotal: number; tax_amount: number; discount_amount: number;
    total: number;
    status: string; status_label: string;
}

interface QuotationLine {
    id: number; item_id: number; name: string; code: string | null;
    quantity: number; unit_price: number; total_price: number; tax_amount: number;
}

interface QuotationDetails {
    quotation: QuotationRow & {
        notes: string | null; client_mobile: string | null;
    };
    items: QuotationLine[];
}

const props = defineProps<{
    quotations: { data: QuotationRow[]; links: { url: string | null; label: string; active: boolean }[] };
    stats: {
        count: number; total: number; accepted_count: number; pending_count: number;
    };
    filters: Record<string, string | null>;
    clients: { id: number; name: string }[];
}>();

const { can } = usePermissions();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'لوحة التحكم', href: '/admin' },
    { title: 'عروض الأسعار', href: '/admin/quotations' },
];

const money = (n: number) => new Intl.NumberFormat('ar-SA-u-nu-latn', { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(n ?? 0);
const qty = (n: number) => new Intl.NumberFormat('ar-SA-u-nu-latn', { maximumFractionDigits: 3 }).format(n ?? 0);

// ── Filtering ─────────────────────────────────────────────────
const filters = ref({ ...props.filters });

const apply = () =>
    router.get('/admin/quotations', filters.value, { preserveState: true, preserveScroll: true, replace: true });

const reset = () => {
    filters.value = {
        client_id: null, status: null, from: null, to: null, search: null,
    };
    apply();
};

const statusClass = (s: string) =>
    ({
        accepted: 'bg-emerald-100 text-emerald-700',
        pending: 'bg-amber-100 text-amber-700',
        rejected: 'bg-red-100 text-red-700',
    })[s] ?? 'bg-slate-100 text-slate-700';

// ── Details Modal ──────────────────────────────────────────
const details = ref<QuotationDetails | null>(null);
const detailsLoading = ref(false);
const activeQuotation = ref<QuotationRow | null>(null);

const loadDetails = async (quotation: QuotationRow) => {
    detailsLoading.value = true;
    try {
        const res = await fetch(`/admin/quotations/${quotation.id}`, { headers: { Accept: 'application/json' } });
        details.value = (await res.json()) as QuotationDetails;
    } finally {
        detailsLoading.value = false;
    }
};

const openDetails = (quotation: QuotationRow) => {
    activeQuotation.value = quotation;
    details.value = null;
    loadDetails(quotation);
};

const closeDetails = () => {
    activeQuotation.value = null;
    details.value = null;
};

const destroy = (q: QuotationRow) => {
    if (confirm(`هل أنت متأكد من حذف عرض السعر رقم ${q.number}؟`)) {
        router.delete(`/admin/quotations/${q.id}`, { preserveScroll: true });
    }
};

const changeStatus = (status: string) => {
    if (!activeQuotation.value) return;
    router.post(`/admin/quotations/${activeQuotation.value.id}/status`, { status }, {
        preserveScroll: true,
        onSuccess: () => {
            if (activeQuotation.value) {
                loadDetails(activeQuotation.value);
            }
        }
    });
};
</script>

<template>
    <Head title="عروض الأسعار" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="min-h-full space-y-4 bg-slate-100 p-5">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h1 class="text-2xl font-extrabold text-slate-900">سجل عروض الأسعار</h1>
                    <p class="mt-1 text-sm font-medium text-slate-600">
                        متابعة عروض الأسعار وحالاتها
                    </p>
                </div>
                <Link v-if="can('quotations.create')" href="/admin/quotations/create" class="inline-flex items-center gap-1.5 rounded-md bg-blue-600 px-4 py-2 text-sm font-bold text-white hover:bg-blue-700">
                    <FileText class="h-4 w-4" /> إصدار عرض سعر
                </Link>
            </div>

            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <StatPill label="إجمالي العروض" :value="String(stats.count)" />
                <StatPill label="القيمة الإجمالية" :value="money(stats.total)" variant="info" />
                <StatPill label="عروض مقبولة" :value="String(stats.accepted_count)" variant="success" />
                <StatPill label="عروض قيد الانتظار" :value="String(stats.pending_count)" variant="warning" />
            </div>

            <div class="rounded-2xl border border-slate-200 bg-white p-3 shadow-sm">
                <div class="grid gap-2 sm:grid-cols-2 lg:grid-cols-5">
                    <select v-model="filters.client_id" @change="apply" class="rounded-xl border border-slate-200 px-3 py-2.5 text-sm">
                        <option :value="null">كل العملاء</option>
                        <option v-for="c in clients" :key="c.id" :value="c.id">{{ c.name }}</option>
                    </select>

                    <select v-model="filters.status" @change="apply" class="rounded-xl border border-slate-200 px-3 py-2.5 text-sm">
                        <option :value="null">كل الحالات</option>
                        <option value="pending">قيد الانتظار</option>
                        <option value="accepted">مقبول</option>
                        <option value="rejected">مرفوض</option>
                    </select>

                    <input v-model="filters.from" @change="apply" type="date" class="rounded-xl border border-slate-200 px-3 py-2.5 text-sm" />
                    <input v-model="filters.to" @change="apply" type="date" class="rounded-xl border border-slate-200 px-3 py-2.5 text-sm" />

                    <div class="relative">
                        <Search class="absolute top-1/2 right-3 h-4 w-4 -translate-y-1/2 text-slate-400" />
                        <input
                            v-model="filters.search" @keyup.enter="apply" type="search"
                            placeholder="رقم العرض أو العميل"
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
                                <th class="px-4 py-3 text-right text-xs font-extrabold text-[#1e3a8a]">العرض</th>
                                <th class="px-4 py-3 text-right text-xs font-extrabold text-[#1e3a8a]">العميل</th>
                                <th class="px-4 py-3 text-center text-xs font-extrabold text-[#1e3a8a]">صالح حتى</th>
                                <th class="px-4 py-3 text-left text-xs font-extrabold text-[#1e3a8a]">الضريبة</th>
                                <th class="px-4 py-3 text-left text-xs font-extrabold text-[#1e3a8a]">الإجمالي</th>
                                <th class="px-4 py-3 text-center text-xs font-extrabold text-[#1e3a8a]">الحالة</th>
                                <th class="px-4 py-3 text-center text-xs font-extrabold text-[#1e3a8a]">إجراءات</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="q in quotations.data" :key="q.id" class="border-t border-slate-100 hover:bg-slate-50">
                                <td class="px-4 py-3">
                                    <div class="font-extrabold text-slate-800" dir="ltr">{{ q.number }}</div>
                                    <div class="text-[11px] text-slate-500" dir="ltr">{{ q.date }} · {{ q.time }}</div>
                                </td>
                                <td class="px-4 py-3">
                                    <div class="text-xs font-bold text-slate-700">{{ q.client ?? '—' }}</div>
                                </td>
                                <td class="px-4 py-3 text-center text-[11px] font-bold text-slate-600" dir="ltr">{{ q.valid_until ?? '—' }}</td>
                                <td class="px-4 py-3 text-left text-xs font-bold" :class="q.tax_amount > 0 ? 'text-slate-600' : 'text-slate-400'" dir="ltr">
                                    {{ q.tax_amount > 0 ? money(q.tax_amount) : '—' }}
                                </td>
                                <td class="px-4 py-3 text-left font-extrabold text-slate-800" dir="ltr">{{ money(q.total) }}</td>
                                <td class="px-4 py-3 text-center">
                                    <span class="rounded-md px-2 py-0.5 text-[11px] font-bold" :class="statusClass(q.status)">
                                        {{ q.status_label }}
                                    </span>
                                </td>
                                <td class="px-4 py-3">
                                    <div class="flex items-center justify-center gap-1">
                                        <button type="button" @click="openDetails(q)" title="تفاصيل العرض" class="rounded-lg bg-slate-500 p-1.5 text-white hover:bg-slate-600">
                                            <Eye class="h-3.5 w-3.5" />
                                        </button>
                                        <Link v-if="can('quotations.edit')" :href="`/admin/quotations/${q.id}/edit`" title="تعديل" class="rounded-lg bg-emerald-100 p-1.5 text-emerald-700 hover:bg-emerald-200">
                                            <Pencil class="h-3.5 w-3.5" />
                                        </Link>
                                        <button v-if="can('quotations.delete')" type="button" @click="destroy(q)" title="حذف" class="rounded-lg bg-red-100 p-1.5 text-red-700 hover:bg-red-200">
                                            <Trash2 class="h-3.5 w-3.5" />
                                        </button>
                                        <a :href="`/admin/quotations/${q.id}/pdf`" target="_blank" title="عرض PDF" class="rounded-lg bg-blue-500 p-1.5 text-white hover:bg-blue-600 inline-block">
                                            <Printer class="h-3.5 w-3.5" />
                                        </a>
                                    </div>
                                </td>
                            </tr>
                            <tr v-if="!quotations.data.length">
                                <td colspan="7" class="px-4 py-10 text-center text-sm text-slate-500">لا توجد عروض أسعار مطابقة للتصفية</td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div v-if="quotations.links.length > 3" class="flex flex-wrap justify-center gap-1 border-t border-slate-100 p-3">
                    <Link
                        v-for="l in quotations.links" :key="l.label" :href="l.url ?? '#'"
                        :class="['rounded-lg px-3 py-1.5 text-xs font-bold', l.active ? 'bg-blue-600 text-white' : l.url ? 'bg-white text-slate-600 ring-1 ring-slate-200' : 'text-slate-300']"
                        v-html="l.label"
                    />
                </div>
            </div>
        </div>

        <Teleport to="body">
        <div v-if="activeQuotation" class="invoice-overlay fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-black/40 p-4" @click.self="closeDetails">
            <div class="invoice-sheet my-auto w-full max-w-3xl rounded-2xl bg-white shadow-2xl">
                <div class="flex items-center justify-between gap-2 border-b border-slate-100 px-6 py-3">
                    <div class="flex items-center gap-2">
                        <h2 class="text-lg font-bold text-slate-800">عرض سعر {{ activeQuotation.number }}</h2>
                        <span class="rounded-md px-2 py-0.5 text-[11px] font-bold" :class="statusClass(activeQuotation.status)">
                            {{ activeQuotation.status_label }}
                        </span>
                    </div>
                    <div class="flex items-center gap-2">
                        <a :href="`/admin/quotations/${activeQuotation.id}/pdf?download=1`" class="rounded-md bg-blue-600 px-4 py-2 text-sm font-bold text-white hover:bg-blue-700">تحميل PDF</a>
                        <button type="button" @click="closeDetails" class="text-slate-400 hover:text-slate-600"><X class="h-5 w-5" /></button>
                    </div>
                </div>

                <p v-if="detailsLoading" class="py-16 text-center text-sm text-slate-500">جارٍ التحميل…</p>

                <div v-else-if="details" class="invoice-body space-y-5 px-8 py-6">
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <p class="text-sm font-bold text-slate-600">العميل: <span class="font-extrabold text-slate-900">{{ details.quotation.client ?? '—' }}</span></p>
                            <p class="text-sm font-bold text-slate-600">الجوال: <span dir="ltr">{{ details.quotation.client_mobile ?? '—' }}</span></p>
                        </div>
                        <div class="text-left">
                            <p class="text-sm font-bold text-slate-600">تاريخ الإصدار: <span dir="ltr">{{ details.quotation.date }}</span></p>
                            <p class="text-sm font-bold text-slate-600">صالح حتى: <span dir="ltr">{{ details.quotation.valid_until ?? 'غير محدد' }}</span></p>
                        </div>
                    </div>



                    <table class="w-full border border-slate-300 text-xs">
                        <thead class="bg-slate-100">
                            <tr>
                                <th class="border border-slate-300 px-2 py-2 text-center font-extrabold text-[#1e3a8a]">#</th>
                                <th class="border border-slate-300 px-2 py-2 text-right font-extrabold text-[#1e3a8a]">الصنف</th>
                                <th class="border border-slate-300 px-2 py-2 text-center font-extrabold text-[#1e3a8a]">الكمية</th>
                                <th class="border border-slate-300 px-2 py-2 text-center font-extrabold text-[#1e3a8a]">السعر</th>
                                <th class="border border-slate-300 px-2 py-2 text-center font-extrabold text-[#1e3a8a]">الضريبة</th>
                                <th class="border border-slate-300 px-2 py-2 text-center font-extrabold text-[#1e3a8a]">الإجمالي</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="(l, i) in details.items" :key="l.id">
                                <td class="border border-slate-300 px-2 py-1.5 text-center text-slate-500" dir="ltr">{{ i + 1 }}</td>
                                <td class="border border-slate-300 px-2 py-1.5 font-bold text-slate-800">{{ l.name }}</td>
                                <td class="border border-slate-300 px-2 py-1.5 text-center" dir="ltr">{{ qty(l.quantity) }}</td>
                                <td class="border border-slate-300 px-2 py-1.5 text-center" dir="ltr">{{ money(l.unit_price) }}</td>
                                <td class="border border-slate-300 px-2 py-1.5 text-center text-slate-500" dir="ltr">{{ money(l.tax_amount) }}</td>
                                <td class="border border-slate-300 px-2 py-1.5 text-center font-extrabold" dir="ltr">{{ money(l.total_price + l.tax_amount) }}</td>
                            </tr>
                        </tbody>
                    </table>

                    <div class="grid items-start gap-6 sm:grid-cols-2">
                        <div class="order-2 sm:order-1"></div>
                        <dl class="order-1 space-y-1 text-xs sm:order-2">
                            <div class="flex justify-between border-b border-slate-100 py-1">
                                <dt class="font-bold text-slate-600">الإجمالي قبل الضريبة</dt>
                                <dd class="font-bold text-slate-800" dir="ltr">{{ money(details.quotation.subtotal) }}</dd>
                            </div>
                            <div v-if="details.quotation.discount_amount > 0" class="flex justify-between border-b border-slate-100 py-1">
                                <dt class="font-bold text-slate-600">الخصم الممنوح</dt>
                                <dd class="font-bold text-slate-800" dir="ltr">{{ money(details.quotation.discount_amount) }}</dd>
                            </div>
                            <div class="flex justify-between border-b border-slate-100 py-1">
                                <dt class="font-bold text-slate-600">ضريبة القيمة المضافة</dt>
                                <dd class="font-bold text-slate-800" dir="ltr">{{ money(details.quotation.tax_amount) }}</dd>
                            </div>
                            <div class="flex justify-between bg-slate-800 px-2 py-1.5 text-sm text-white">
                                <dt class="font-extrabold">الإجمالي شامل الضريبة</dt>
                                <dd class="font-extrabold" dir="ltr">{{ money(details.quotation.total) }}</dd>
                            </div>
                        </dl>
                    </div>

                    <p v-if="details.quotation.notes" class="rounded-lg bg-slate-50 px-3 py-2 text-xs text-slate-600">
                        ملاحظات وشروط العرض: {{ details.quotation.notes }}
                    </p>
                </div>
            </div>
        </div>
        </Teleport>
    </AppLayout>
</template>
