<script setup lang="ts">
import { StatPill, TableActionButton } from '@/components/data-table';
import PageShortcuts from '@/components/PageShortcuts.vue';
import { usePermissions } from '@/composables/usePermissions';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { CheckCircle2, Eye, MessageCircle, Plus, Search, Trash2, X } from 'lucide-vue-next';
import { computed, ref } from 'vue';

interface Contract {
    id: number; number: string; status: string; status_label: string;
    client_name: string | null; client_mobile: string | null;
    from_quotation: boolean; subject: string | null; quotation_number: string | null;
    booking_reference: string | null; unit_name: string | null;
    booking_date: string | null; total_amount: string | null;
    sent_at: string | null; created_at: string;
}

interface QuotationOption {
    id: number; label: string; department: string | null; status: string; accepted: boolean;
}

const props = defineProps<{
    contracts: { data: Contract[]; links: { url: string | null; label: string; active: boolean }[] };
    // 'all' is the contracts section's own register; 'quotation' is the same
    // screen opened from the pools menu, narrowed to that activity's contracts.
    scope: 'all' | 'quotation';
    filters: Record<string, string | null>;
    statuses: { key: string; label: string }[];
    templates: { id: number; name: string; is_default: boolean }[];
    bookings: { id: number; label: string }[];
    quotations: QuotationOption[];
    stats: { total: number; draft: number; sent: number; signed: number };
}>();

const { can } = usePermissions();

const poolsOnly = computed(() => props.scope === 'quotation');

// Filtering and paging must stay inside the register the employee opened —
// posting them to /admin/contracts would quietly widen a pools screen into
// every hall and chalet rental in the business.
const basePath = computed(() => (poolsOnly.value ? '/admin/pools/contracts' : '/admin/contracts'));

const breadcrumbs = computed<BreadcrumbItem[]>(() => [
    { title: 'لوحة التحكم', href: '/admin' },
    ...(poolsOnly.value ? [{ title: 'المسابح — بيع وصيانة', href: '/admin/pos' }] : []),
    { title: 'العقود', href: basePath.value },
]);

const filters = ref({ ...props.filters });
const apply = () => router.get(basePath.value, filters.value, { preserveState: true, replace: true });

const showModal = ref(false);

// A contract is always drawn from a source document, never typed: a booking for
// halls and chalets, a quotation for pools. The two never mix in one form —
// picking a source picks which field is required and where the form posts.
type Source = 'booking' | 'quotation';
const source = ref<Source>('booking');

const form = useForm({
    booking_id: null as number | null,
    quotation_id: null as number | null,
    contract_template_id: null as number | null,
});

// The pools register draws from quotations only — a contract made from a
// booking would not appear in the very screen that created it.
const sources = computed<Source[]>(() => (poolsOnly.value ? ['quotation'] : ['booking', 'quotation']));

const openCreate = () => {
    form.reset();
    form.clearErrors();
    form.contract_template_id = props.templates.find((t) => t.is_default)?.id ?? props.templates[0]?.id ?? null;
    // Open on whichever source actually has something to draw from, so the
    // employee does not land on an empty list and think the screen is broken.
    source.value = poolsOnly.value || (!props.bookings.length && props.quotations.length) ? 'quotation' : 'booking';
    showModal.value = true;
};

const pickSource = (next: Source) => {
    source.value = next;
    form.clearErrors();
};

const canSubmit = computed(() => (source.value === 'booking' ? !!form.booking_id : !!form.quotation_id));

const submit = () => {
    const isBooking = source.value === 'booking';

    form
        .transform((data) => ({
            contract_template_id: data.contract_template_id,
            ...(isBooking ? { booking_id: data.booking_id } : { quotation_id: data.quotation_id }),
        }))
        .post(isBooking ? '/admin/contracts' : '/admin/contracts/from-quotation', {
            preserveScroll: true,
            onSuccess: () => (showModal.value = false),
        });
};

const send = (c: Contract) => {
    if (!c.client_mobile) {
        alert('لا يوجد رقم جوال للعميل.');
        return;
    }
    if (confirm(`إرسال العقد ${c.number} على واتساب ${c.client_mobile}؟`)) {
        router.post(`/admin/contracts/${c.id}/send`, {}, { preserveScroll: true });
    }
};

const markSigned = (c: Contract) => router.patch(`/admin/contracts/${c.id}/status`, { status: 'signed' }, { preserveScroll: true });

const destroy = (c: Contract) => {
    if (confirm(`حذف العقد ${c.number}؟`)) router.delete(`/admin/contracts/${c.id}`, { preserveScroll: true });
};

const statusClass = (s: string) =>
    ({ draft: 'bg-amber-100 text-amber-700', sent: 'bg-sky-100 text-sky-700', signed: 'bg-emerald-100 text-emerald-700', cancelled: 'bg-slate-200 text-slate-600' })[s] ??
    'bg-slate-100 text-slate-700';
</script>

<template>
    <Head :title="poolsOnly ? 'عقود المسابح' : 'العقود'" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="min-h-full space-y-4 bg-slate-100 p-5">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h1 class="text-2xl font-extrabold text-slate-900">{{ poolsOnly ? 'عقود المسابح — بيع وصيانة' : 'العقود' }}</h1>
                    <p class="mt-1 text-sm font-medium text-slate-600">
                        {{
                            poolsOnly
                                ? 'تحرير العقد من عرض السعر المعتمد وإرساله على واتساب العميل'
                                : 'توليد العقد من الحجز أو من عرض السعر وإرساله على واتساب العميل'
                        }}
                    </p>
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    <!-- اختصارات شاشات العقود — تُشتق من القائمة فلا تحتاج صيانة -->
                    <PageShortcuts />
                    <button v-if="can('contracts.create')" type="button" @click="openCreate" class="inline-flex items-center gap-1.5 rounded-md bg-blue-600 px-4 py-2 text-sm font-bold text-white hover:bg-blue-700">
                        <Plus class="h-4 w-4" /> عقد جديد
                    </button>
                </div>
            </div>

            <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
                <StatPill label="إجمالي العقود" :value="stats.total" variant="primary" />
                <StatPill label="مسوّدة" :value="stats.draft" variant="warning" />
                <StatPill label="مُرسل" :value="stats.sent" variant="info" />
                <StatPill label="موقّع" :value="stats.signed" variant="success" />
            </div>

            <div class="rounded-2xl border border-slate-200 bg-white p-3 shadow-sm">
                <div class="grid gap-2 sm:grid-cols-2">
                    <div class="relative">
                        <Search class="absolute top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400 ltr:left-3 rtl:right-3" />
                        <input v-model="filters.search" @keyup.enter="apply" placeholder="رقم العقد أو اسم العميل" class="w-full rounded-xl border border-slate-200 py-2.5 text-sm ltr:pl-9 ltr:pr-3 rtl:pl-3 rtl:pr-9" />
                    </div>
                    <select v-model="filters.status" @change="apply" class="rounded-xl border border-slate-200 px-3 py-2.5 text-sm">
                        <option :value="null">كل الحالات</option>
                        <option v-for="s in statuses" :key="s.key" :value="s.key">{{ s.label }}</option>
                    </select>
                </div>
            </div>

            <div class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="bg-slate-100">
                            <tr>
                                <th class="px-4 py-3 text-right text-xs font-extrabold text-[#1e3a8a]">رقم العقد</th>
                                <th class="px-4 py-3 text-right text-xs font-extrabold text-[#1e3a8a]">
                                    {{ poolsOnly ? 'عرض السعر والموضوع' : 'المصدر' }}
                                </th>
                                <th class="px-4 py-3 text-right text-xs font-extrabold text-[#1e3a8a]">العميل</th>
                                <th class="px-4 py-3 text-center text-xs font-extrabold text-[#1e3a8a]">القيمة</th>
                                <th class="px-4 py-3 text-center text-xs font-extrabold text-[#1e3a8a]">الحالة</th>
                                <th class="px-4 py-3 text-center text-xs font-extrabold text-[#1e3a8a]">إجراءات</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="c in contracts.data" :key="c.id" class="border-t border-slate-100 hover:bg-slate-50">
                                <td class="px-4 py-3">
                                    <div class="font-extrabold text-slate-800" dir="ltr">{{ c.number }}</div>
                                    <div class="text-[11px] text-slate-500" dir="ltr">{{ c.created_at }}</div>
                                </td>
                                <td class="px-4 py-3">
                                    <template v-if="c.from_quotation">
                                        <div class="font-bold text-slate-700" dir="ltr">{{ c.quotation_number ?? '—' }}</div>
                                        <div class="text-[11px] text-slate-500">{{ c.subject ?? 'عرض سعر' }}</div>
                                    </template>
                                    <template v-else>
                                        <div class="font-bold text-slate-700" dir="ltr">{{ c.booking_reference }}</div>
                                        <div class="text-[11px] text-slate-500">{{ c.unit_name }} · {{ c.booking_date }}</div>
                                    </template>
                                </td>
                                <td class="px-4 py-3">
                                    <div class="font-bold text-slate-700">{{ c.client_name ?? '—' }}</div>
                                    <div class="text-[11px] text-slate-500" dir="ltr">{{ c.client_mobile }}</div>
                                </td>
                                <td class="px-4 py-3 text-center">
                                    <span class="font-extrabold text-slate-800" dir="ltr">{{ c.total_amount ?? '—' }}</span>
                                    <span v-if="c.total_amount" class="text-[11px] font-medium text-slate-500"> ريال</span>
                                </td>
                                <td class="px-4 py-3 text-center">
                                    <span class="rounded-md px-2 py-0.5 text-[11px] font-bold" :class="statusClass(c.status)">{{ c.status_label }}</span>
                                    <div v-if="c.sent_at" class="mt-0.5 text-[10px] text-slate-400" dir="ltr">{{ c.sent_at }}</div>
                                </td>
                                <td class="px-4 py-3">
                                    <!-- كل الإجراءات من مكوّن واحد: الحجم واللون
                                         يأتيان منه فلا يختلف زرٌّ عن جاره في الخلية -->
                                    <div class="flex items-center justify-center gap-1.5">
                                        <TableActionButton variant="view" :icon="Eye" title="عرض" :href="`/admin/contracts/${c.id}`" />
                                        <TableActionButton
                                            v-if="can('contracts.send') && c.status !== 'cancelled'"
                                            variant="success"
                                            :icon="MessageCircle"
                                            title="إرسال واتساب"
                                            @click="send(c)"
                                        />
                                        <TableActionButton
                                            v-if="can('contracts.edit') && c.status === 'sent'"
                                            variant="primary"
                                            :icon="CheckCircle2"
                                            title="تعليم كموقّع"
                                            @click="markSigned(c)"
                                        />
                                        <TableActionButton v-if="can('contracts.delete')" variant="danger" :icon="Trash2" title="حذف" @click="destroy(c)" />
                                    </div>
                                </td>
                            </tr>
                            <tr v-if="!contracts.data.length">
                                <td colspan="6" class="px-4 py-10 text-center text-sm text-slate-500">
                                    {{ poolsOnly ? 'لا عقود على عروض أسعار المسابح بعد' : 'لا عقود' }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div v-if="contracts.links.length > 3" class="flex flex-wrap justify-center gap-1 border-t border-slate-100 p-3">
                    <Link v-for="l in contracts.links" :key="l.label" :href="l.url ?? '#'"
                        :class="['rounded-lg px-3 py-1.5 text-xs font-bold', l.active ? 'bg-blue-600 text-white' : l.url ? 'bg-white text-slate-600 ring-1 ring-slate-200' : 'text-slate-300']"
                        v-html="l.label" />
                </div>
            </div>
        </div>

        <div v-if="showModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4" @click.self="showModal = false">
            <div class="w-full max-w-lg rounded-2xl bg-white shadow-2xl">
                <div class="flex items-center justify-between border-b border-slate-100 px-6 py-4">
                    <h2 class="text-lg font-extrabold text-slate-900">
                        {{ source === 'quotation' ? 'تحرير عقد من عرض سعر' : 'توليد عقد من حجز' }}
                    </h2>
                    <button type="button" @click="showModal = false" class="text-slate-400 hover:text-slate-600"><X class="h-5 w-5" /></button>
                </div>

                <form @submit.prevent="submit" class="space-y-3 px-6 py-4">
                    <!-- المصدر: العقد يُحرَّر على مستند سابق لا يُكتب من فراغ -->
                    <div v-if="sources.length > 1" class="grid grid-cols-2 gap-1 rounded-xl bg-slate-100 p-1">
                        <button
                            v-for="s in sources"
                            :key="s"
                            type="button"
                            @click="pickSource(s)"
                            :class="[
                                'rounded-lg px-3 py-2 text-sm font-bold transition',
                                source === s ? 'bg-white text-blue-700 shadow-sm' : 'text-slate-600 hover:text-slate-800',
                            ]"
                        >
                            {{ s === 'booking' ? 'من حجز' : 'من عرض سعر' }}
                        </button>
                    </div>

                    <div v-if="source === 'booking'">
                        <label class="mb-1 block text-sm font-bold text-slate-700">الحجز</label>
                        <select v-model="form.booking_id" class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm">
                            <option :value="null">— اختر الحجز —</option>
                            <option v-for="b in bookings" :key="b.id" :value="b.id">{{ b.label }}</option>
                        </select>
                        <p v-if="form.errors.booking_id" class="mt-1 text-xs text-red-500">{{ form.errors.booking_id }}</p>
                        <p v-if="!bookings.length" class="mt-1 text-xs font-medium text-amber-600">كل الحجوزات لها عقود بالفعل.</p>
                    </div>

                    <div v-else>
                        <label class="mb-1 block text-sm font-bold text-slate-700">عرض السعر</label>
                        <select v-model="form.quotation_id" class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm">
                            <option :value="null">— اختر عرض السعر —</option>
                            <option v-for="q in quotations" :key="q.id" :value="q.id">
                                {{ q.label }}{{ q.accepted ? '' : ' (قيد الانتظار)' }}
                            </option>
                        </select>
                        <p v-if="form.errors.quotation_id" class="mt-1 text-xs text-red-500">{{ form.errors.quotation_id }}</p>
                        <p v-if="!quotations.length" class="mt-1 text-xs font-medium text-amber-600">
                            لا عروض أسعار متاحة — كلها محرَّرة عليها عقود أو مرفوضة.
                        </p>
                        <!-- بنود العرض وقيمته تُنسخ إلى العقد كما هي، فيُقرأ الشرط
                             قبل الاختيار لا بعد إصدار الرقم -->
                        <p v-else class="mt-1 text-xs font-medium text-slate-500">
                            تُنقل بنود العرض وقيمته إلى العقد كما هي، ويُعلَّم العرض «مقبول».
                        </p>
                    </div>

                    <div>
                        <label class="mb-1 block text-sm font-bold text-slate-700">القالب</label>
                        <select v-model="form.contract_template_id" class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm">
                            <option v-for="t in templates" :key="t.id" :value="t.id">{{ t.name }}{{ t.is_default ? ' (افتراضي)' : '' }}</option>
                        </select>
                        <p v-if="!templates.length" class="mt-1 text-xs font-medium text-amber-600">لا قوالب عقود فعّالة — أضف قالبًا أولًا.</p>
                    </div>

                    <div class="flex justify-end gap-2 border-t border-slate-100 pt-4">
                        <button type="button" @click="showModal = false" class="rounded-xl border border-slate-200 px-4 py-2 text-sm font-bold text-slate-600">إلغاء</button>
                        <button type="submit" :disabled="form.processing || !canSubmit" class="rounded-md bg-blue-600 px-5 py-2 text-sm font-bold text-white hover:bg-blue-700 disabled:opacity-50">
                            {{ source === 'quotation' ? 'تحرير العقد' : 'توليد العقد' }}
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </AppLayout>
</template>
