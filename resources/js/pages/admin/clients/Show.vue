<script setup lang="ts">
import { usePermissions } from '@/composables/usePermissions';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ArrowRight, CalendarDays, FileSignature, MessageSquare, Phone, Receipt, Sparkles, User, Wallet } from 'lucide-vue-next';
import { computed, ref } from 'vue';

interface Booking {
    id: number;
    reference: string;
    booking_date: string | null;
    unit: string | null;
    unit_type: string | null;
    event_type: string | null;
    period: string;
    status: string;
    status_label: string;
    color: string;
    total: number;
    paid: number;
    remaining: number;
}

const props = defineProps<{
    client: {
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
        is_walk_in: boolean;
        notes: string | null;
        created_at: string | null;
    };
    stats: {
        bookings_count: number;
        cancelled_count: number;
        bookings_value: number;
        paid: number;
        remaining: number;
        sales_count: number;
        sales_value: number;
        lifetime_value: number;
        last_visit: string | null;
        upcoming: number;
    };
    bookings: Booking[];
    payments: {
        id: number;
        booking_id: number;
        reference: string | null;
        paid_on: string | null;
        type: string;
        type_label: string;
        method: string | null;
        amount: number;
    }[];
    sales: {
        id: number;
        number: string;
        created_at: string | null;
        unit: string | null;
        type: string;
        total: number;
        paid: number;
        remaining: number;
    }[];
    contracts: {
        id: number;
        number: string;
        reference: string | null;
        status: string;
        status_label: string;
        sent_at: string | null;
        created_at: string | null;
    }[];
    vouchers: { id: number; number: string; voucher_date: string | null; type_label: string; amount: number; description: string | null }[];
    services: { name: string; times: number; kind: string }[];
}>();

const { can } = usePermissions();

const breadcrumbs = computed<BreadcrumbItem[]>(() => [
    { title: 'لوحة التحكم', href: '/admin' },
    { title: 'العملاء', href: '/admin/clients' },
    { title: props.client.name, href: `/admin/clients/${props.client.id}` },
]);

const money = (n: number) => new Intl.NumberFormat('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(n ?? 0);

const tabs = [
    { key: 'bookings', label: 'الحجوزات', icon: CalendarDays },
    { key: 'payments', label: 'سجل التعاملات', icon: Wallet },
    { key: 'sales', label: 'الفواتير', icon: Receipt },
    { key: 'contracts', label: 'العقود', icon: FileSignature },
] as const;

const tab = ref<(typeof tabs)[number]['key']>('bookings');

const count = (key: string) =>
    ({ bookings: props.bookings.length, payments: props.payments.length, sales: props.sales.length, contracts: props.contracts.length })[key] ?? 0;

const statusTone = (color: string) =>
    ({
        emerald: 'bg-emerald-100 text-emerald-700',
        amber: 'bg-amber-100 text-amber-700',
        orange: 'bg-orange-100 text-orange-700',
        sky: 'bg-sky-100 text-sky-700',
        violet: 'bg-violet-100 text-violet-700',
        red: 'bg-red-100 text-red-700',
    })[color] ?? 'bg-slate-100 text-slate-700';

// الملاحظات تُحفظ من الملف وحده — نموذج القائمة لا يرسلها فلا يمحوها.
const notes = useForm({
    name: props.client.name,
    mobile: props.client.mobile ?? '',
    email: props.client.email ?? '',
    city: props.client.city ?? '',
    national_id: props.client.national_id ?? '',
    is_taxable: props.client.is_taxable,
    tax_number: props.client.tax_number ?? '',
    tax_address: props.client.tax_address ?? '',
    is_active: props.client.is_active,
    notes: props.client.notes ?? '',
});

const saveNotes = () => notes.put(`/admin/clients/${props.client.id}`, { preserveScroll: true });

const whatsappLink = computed(() => (props.client.mobile ? `https://wa.me/${props.client.mobile.replace(/[^0-9]/g, '')}` : null));
</script>

<template>
    <Head :title="`ملف العميل — ${client.name}`" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="min-h-full space-y-4 bg-slate-100 p-5">
            <!-- الترويسة -->
            <div class="flex flex-wrap items-start justify-between gap-3 rounded-2xl border border-slate-200 bg-white p-5">
                <div class="flex items-start gap-4">
                    <span class="flex h-14 w-14 shrink-0 items-center justify-center rounded-2xl bg-slate-900 text-xl font-extrabold text-white">
                        {{ client.name.charAt(0) }}
                    </span>
                    <div>
                        <div class="flex flex-wrap items-center gap-2">
                            <h1 class="text-2xl font-extrabold text-slate-900">{{ client.name }}</h1>
                            <span
                                class="rounded-md px-2 py-0.5 text-[11px] font-bold"
                                :class="client.is_active ? 'bg-emerald-100 text-emerald-700' : 'bg-slate-100 text-slate-500'"
                            >
                                {{ client.is_active ? 'مفعّل' : 'موقوف' }}
                            </span>
                            <span v-if="client.is_taxable" class="rounded-md bg-sky-100 px-2 py-0.5 text-[11px] font-bold text-sky-700">
                                عميل ضريبي
                            </span>
                            <span v-if="client.is_walk_in" class="rounded-md bg-amber-100 px-2 py-0.5 text-[11px] font-bold text-amber-700">
                                العميل النقدي
                            </span>
                        </div>
                        <div class="mt-2 flex flex-wrap items-center gap-x-4 gap-y-1 text-xs font-medium text-slate-600">
                            <span v-if="client.mobile" class="inline-flex items-center gap-1" dir="ltr">
                                <Phone class="h-3.5 w-3.5" /> {{ client.mobile }}
                            </span>
                            <span v-if="client.email" dir="ltr">{{ client.email }}</span>
                            <span v-if="client.city">{{ client.city }}</span>
                            <span v-if="client.national_id" dir="ltr">هوية: {{ client.national_id }}</span>
                            <span v-if="client.tax_number" dir="ltr">ضريبي: {{ client.tax_number }}</span>
                            <span>عميل منذ {{ client.created_at }}</span>
                        </div>
                    </div>
                </div>

                <div class="flex items-center gap-2">
                    <a
                        v-if="whatsappLink"
                        :href="whatsappLink"
                        target="_blank"
                        rel="noopener"
                        class="inline-flex items-center gap-1.5 rounded-md bg-emerald-600 px-3 py-2 text-sm font-bold text-white hover:bg-emerald-700"
                    >
                        <MessageSquare class="h-4 w-4" /> واتساب
                    </a>
                    <Link
                        href="/admin/clients"
                        class="inline-flex items-center gap-1.5 rounded-md border border-slate-200 px-3 py-2 text-sm font-bold text-slate-700 hover:bg-slate-50"
                    >
                        <ArrowRight class="h-4 w-4" /> العملاء
                    </Link>
                </div>
            </div>

            <!-- المؤشرات -->
            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <div class="rounded-2xl border border-slate-200 bg-white p-4">
                    <div class="text-xs font-bold text-slate-500">قيمة التعامل الكلية</div>
                    <div class="mt-1 text-2xl font-extrabold text-slate-900" dir="ltr">{{ money(stats.lifetime_value) }}</div>
                    <div class="mt-1 text-[11px] text-slate-400">حجوزات وفواتير</div>
                </div>
                <div class="rounded-2xl border border-slate-200 bg-white p-4">
                    <div class="text-xs font-bold text-slate-500">المدفوع</div>
                    <div class="mt-1 text-2xl font-extrabold text-emerald-700" dir="ltr">{{ money(stats.paid) }}</div>
                </div>
                <div class="rounded-2xl border border-slate-200 bg-white p-4">
                    <div class="text-xs font-bold text-slate-500">المتبقي عليه</div>
                    <div class="mt-1 text-2xl font-extrabold" :class="stats.remaining > 0 ? 'text-red-700' : 'text-slate-900'" dir="ltr">
                        {{ money(stats.remaining) }}
                    </div>
                </div>
                <div class="rounded-2xl border border-slate-200 bg-white p-4">
                    <div class="text-xs font-bold text-slate-500">الحجوزات</div>
                    <div class="mt-1 text-2xl font-extrabold text-slate-900" dir="ltr">{{ stats.bookings_count }}</div>
                    <div class="mt-1 text-[11px] text-slate-400">
                        {{ stats.upcoming }} قادمة · {{ stats.cancelled_count }} ملغاة · آخر زيارة {{ stats.last_visit ?? '—' }}
                    </div>
                </div>
            </div>

            <div class="grid gap-4 lg:grid-cols-3">
                <!-- السجلات -->
                <div class="space-y-3 lg:col-span-2">
                    <div class="flex flex-wrap gap-1.5">
                        <button
                            v-for="t in tabs"
                            :key="t.key"
                            type="button"
                            @click="tab = t.key"
                            class="inline-flex items-center gap-1.5 rounded-lg px-3 py-2 text-xs font-bold"
                            :class="tab === t.key ? 'bg-slate-900 text-white' : 'bg-white text-slate-600 ring-1 ring-slate-200 hover:bg-slate-50'"
                        >
                            <component :is="t.icon" class="h-3.5 w-3.5" />
                            {{ t.label }}
                            <span class="opacity-70" dir="ltr">({{ count(t.key) }})</span>
                        </button>
                    </div>

                    <div class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                        <div class="overflow-x-auto">
                            <!-- الحجوزات -->
                            <table v-if="tab === 'bookings'" class="w-full text-sm">
                                <thead class="bg-slate-100">
                                    <tr>
                                        <th class="px-4 py-3 text-right text-xs font-extrabold text-slate-700">الحجز</th>
                                        <th class="px-4 py-3 text-right text-xs font-extrabold text-slate-700">الوحدة</th>
                                        <th class="px-4 py-3 text-center text-xs font-extrabold text-slate-700">الحالة</th>
                                        <th class="px-4 py-3 text-left text-xs font-extrabold text-slate-700">الإجمالي</th>
                                        <th class="px-4 py-3 text-left text-xs font-extrabold text-slate-700">المدفوع</th>
                                        <th class="px-4 py-3 text-left text-xs font-extrabold text-slate-700">المتبقي</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="b in bookings" :key="b.id" class="border-t border-slate-100 hover:bg-slate-50">
                                        <td class="px-4 py-2.5">
                                            <Link
                                                v-if="can('bookings.view')"
                                                :href="`/admin/bookings/${b.id}/payments`"
                                                class="font-bold text-slate-800 hover:text-blue-600"
                                            >
                                                {{ b.reference }}
                                            </Link>
                                            <span v-else class="font-bold text-slate-800">{{ b.reference }}</span>
                                            <div class="text-[11px] text-slate-500" dir="ltr">{{ b.booking_date }}</div>
                                        </td>
                                        <td class="px-4 py-2.5 text-xs text-slate-600">
                                            {{ b.unit ?? '—' }}
                                            <div class="text-[11px] text-slate-400">{{ b.event_type ?? b.period }}</div>
                                        </td>
                                        <td class="px-4 py-2.5 text-center">
                                            <span class="inline-flex rounded-md px-2 py-0.5 text-[11px] font-bold" :class="statusTone(b.color)">
                                                {{ b.status_label }}
                                            </span>
                                        </td>
                                        <td class="px-4 py-2.5 text-left font-bold text-slate-800" dir="ltr">{{ money(b.total) }}</td>
                                        <td class="px-4 py-2.5 text-left text-emerald-700" dir="ltr">{{ money(b.paid) }}</td>
                                        <td
                                            class="px-4 py-2.5 text-left font-bold"
                                            :class="b.remaining > 0 ? 'text-red-600' : 'text-slate-400'"
                                            dir="ltr"
                                        >
                                            {{ money(b.remaining) }}
                                        </td>
                                    </tr>
                                    <tr v-if="!bookings.length">
                                        <td colspan="6" class="px-4 py-12 text-center text-sm text-slate-500">لا حجوزات لهذا العميل</td>
                                    </tr>
                                </tbody>
                            </table>

                            <!-- سجل التعاملات -->
                            <table v-else-if="tab === 'payments'" class="w-full text-sm">
                                <thead class="bg-slate-100">
                                    <tr>
                                        <th class="px-4 py-3 text-right text-xs font-extrabold text-slate-700">التاريخ</th>
                                        <th class="px-4 py-3 text-right text-xs font-extrabold text-slate-700">الحجز</th>
                                        <th class="px-4 py-3 text-right text-xs font-extrabold text-slate-700">النوع</th>
                                        <th class="px-4 py-3 text-right text-xs font-extrabold text-slate-700">طريقة الدفع</th>
                                        <th class="px-4 py-3 text-left text-xs font-extrabold text-slate-700">المبلغ</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="p in payments" :key="p.id" class="border-t border-slate-100 hover:bg-slate-50">
                                        <td class="px-4 py-2.5 text-xs text-slate-600" dir="ltr">{{ p.paid_on ?? '—' }}</td>
                                        <td class="px-4 py-2.5 text-xs font-bold text-slate-700">{{ p.reference ?? '—' }}</td>
                                        <td class="px-4 py-2.5 text-xs text-slate-600">{{ p.type_label }}</td>
                                        <td class="px-4 py-2.5 text-xs text-slate-600">{{ p.method ?? '—' }}</td>
                                        <td
                                            class="px-4 py-2.5 text-left font-bold"
                                            :class="p.amount < 0 ? 'text-red-600' : 'text-emerald-700'"
                                            dir="ltr"
                                        >
                                            {{ money(p.amount) }}
                                        </td>
                                    </tr>
                                    <tr v-if="!payments.length">
                                        <td colspan="5" class="px-4 py-12 text-center text-sm text-slate-500">لا دفعات مسجّلة</td>
                                    </tr>
                                </tbody>
                            </table>

                            <!-- الفواتير -->
                            <table v-else-if="tab === 'sales'" class="w-full text-sm">
                                <thead class="bg-slate-100">
                                    <tr>
                                        <th class="px-4 py-3 text-right text-xs font-extrabold text-slate-700">الفاتورة</th>
                                        <th class="px-4 py-3 text-right text-xs font-extrabold text-slate-700">الوحدة</th>
                                        <th class="px-4 py-3 text-left text-xs font-extrabold text-slate-700">الإجمالي</th>
                                        <th class="px-4 py-3 text-left text-xs font-extrabold text-slate-700">المدفوع</th>
                                        <th class="px-4 py-3 text-left text-xs font-extrabold text-slate-700">المتبقي</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="s in sales" :key="s.id" class="border-t border-slate-100 hover:bg-slate-50">
                                        <td class="px-4 py-2.5">
                                            <Link
                                                v-if="can('sales.view')"
                                                :href="`/admin/sales/${s.id}`"
                                                class="font-bold text-slate-800 hover:text-blue-600"
                                            >
                                                {{ s.number }}
                                            </Link>
                                            <span v-else class="font-bold text-slate-800">{{ s.number }}</span>
                                            <div class="text-[11px] text-slate-500" dir="ltr">{{ s.created_at }}</div>
                                            <span
                                                v-if="s.type === 'return'"
                                                class="mt-1 inline-flex rounded-md bg-red-100 px-2 py-0.5 text-[10px] font-bold text-red-700"
                                            >
                                                مرتجع
                                            </span>
                                        </td>
                                        <td class="px-4 py-2.5 text-xs text-slate-600">{{ s.unit ?? '—' }}</td>
                                        <td class="px-4 py-2.5 text-left font-bold text-slate-800" dir="ltr">{{ money(s.total) }}</td>
                                        <td class="px-4 py-2.5 text-left text-emerald-700" dir="ltr">{{ money(s.paid) }}</td>
                                        <td
                                            class="px-4 py-2.5 text-left font-bold"
                                            :class="s.remaining > 0 ? 'text-red-600' : 'text-slate-400'"
                                            dir="ltr"
                                        >
                                            {{ money(s.remaining) }}
                                        </td>
                                    </tr>
                                    <tr v-if="!sales.length">
                                        <td colspan="5" class="px-4 py-12 text-center text-sm text-slate-500">لا فواتير لهذا العميل</td>
                                    </tr>
                                </tbody>
                            </table>

                            <!-- العقود -->
                            <table v-else class="w-full text-sm">
                                <thead class="bg-slate-100">
                                    <tr>
                                        <th class="px-4 py-3 text-right text-xs font-extrabold text-slate-700">العقد</th>
                                        <th class="px-4 py-3 text-right text-xs font-extrabold text-slate-700">الحجز</th>
                                        <th class="px-4 py-3 text-center text-xs font-extrabold text-slate-700">الحالة</th>
                                        <th class="px-4 py-3 text-right text-xs font-extrabold text-slate-700">أُرسل في</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="c in contracts" :key="c.id" class="border-t border-slate-100 hover:bg-slate-50">
                                        <td class="px-4 py-2.5">
                                            <Link
                                                v-if="can('contracts.view')"
                                                :href="`/admin/contracts/${c.id}`"
                                                class="font-bold text-slate-800 hover:text-blue-600"
                                            >
                                                {{ c.number }}
                                            </Link>
                                            <span v-else class="font-bold text-slate-800">{{ c.number }}</span>
                                            <div class="text-[11px] text-slate-500" dir="ltr">{{ c.created_at }}</div>
                                        </td>
                                        <td class="px-4 py-2.5 text-xs font-bold text-slate-700">{{ c.reference ?? '—' }}</td>
                                        <td class="px-4 py-2.5 text-center">
                                            <span class="inline-flex rounded-md bg-slate-100 px-2 py-0.5 text-[11px] font-bold text-slate-700">
                                                {{ c.status_label }}
                                            </span>
                                        </td>
                                        <td class="px-4 py-2.5 text-xs text-slate-600" dir="ltr">{{ c.sent_at ?? '—' }}</td>
                                    </tr>
                                    <tr v-if="!contracts.length">
                                        <td colspan="4" class="px-4 py-12 text-center text-sm text-slate-500">لا عقود لهذا العميل</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- العمود الجانبي -->
                <div class="space-y-3">
                    <!-- الملاحظات -->
                    <div class="rounded-2xl border border-slate-200 bg-white p-4">
                        <h2 class="mb-2 flex items-center gap-1.5 text-sm font-extrabold text-slate-700">
                            <User class="h-4 w-4" /> ملاحظات على العميل
                        </h2>
                        <textarea
                            v-model="notes.notes"
                            rows="5"
                            :disabled="!can('clients.edit')"
                            placeholder="ما يُقال عن العميل ولا يُكتب يضيع: تفضيلاته، تنبيهات السداد، من يوصي به…"
                            class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm disabled:bg-slate-50"
                        />
                        <button
                            v-if="can('clients.edit')"
                            type="button"
                            @click="saveNotes"
                            :disabled="notes.processing"
                            class="mt-2 w-full rounded-xl bg-slate-900 px-4 py-2 text-sm font-bold text-white hover:bg-slate-800 disabled:opacity-50"
                        >
                            حفظ الملاحظات
                        </button>
                    </div>

                    <!-- الخدمات السابقة -->
                    <div class="rounded-2xl border border-slate-200 bg-white p-4">
                        <h2 class="mb-2 flex items-center gap-1.5 text-sm font-extrabold text-slate-700">
                            <Sparkles class="h-4 w-4" /> الخدمات السابقة
                        </h2>
                        <div v-if="services.length" class="space-y-1.5">
                            <div v-for="s in services" :key="`${s.kind}-${s.name}`" class="flex items-center justify-between text-xs">
                                <span class="font-bold text-slate-700">
                                    {{ s.name }}
                                    <span class="mr-1 text-[10px] font-medium text-slate-400">{{ s.kind }}</span>
                                </span>
                                <span class="rounded-md bg-slate-100 px-2 py-0.5 font-bold text-slate-600" dir="ltr">{{ s.times }}×</span>
                            </div>
                        </div>
                        <p v-else class="text-xs text-slate-500">لم يأخذ العميل خدمات إضافية بعد</p>
                    </div>

                    <!-- السندات -->
                    <div v-if="vouchers.length" class="rounded-2xl border border-slate-200 bg-white p-4">
                        <h2 class="mb-2 flex items-center gap-1.5 text-sm font-extrabold text-slate-700">
                            <Receipt class="h-4 w-4" /> السندات المرحَّلة
                        </h2>
                        <div class="space-y-1.5">
                            <div v-for="v in vouchers" :key="v.id" class="flex items-center justify-between text-xs">
                                <span class="font-bold text-slate-700">
                                    {{ v.type_label }}
                                    <span class="mr-1 text-[10px] font-medium text-slate-400" dir="ltr">{{ v.voucher_date }}</span>
                                </span>
                                <span class="font-bold text-slate-800" dir="ltr">{{ money(v.amount) }}</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
