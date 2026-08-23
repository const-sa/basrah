<script setup lang="ts">
import { StatusBadge } from '@/components/data-table';
import SmallBox from '@/components/lte/SmallBox.vue';
import { usePermissions } from '@/composables/usePermissions';
import SettingsTabs from '@/components/SettingsTabs.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, router, useForm } from '@inertiajs/vue3';
import { Banknote, CreditCard, Landmark, Lock, Pencil, Plus, Power, Trash2, WalletCards, X } from 'lucide-vue-next';
import { computed, ref } from 'vue';

interface MethodRow {
    id: number;
    code: string;
    name: string;
    deposits_to: string;
    deposits_to_label: string;
    is_credit: boolean;
    is_active: boolean;
    is_system: boolean;
    sort_order: number;
    usage_count: number;
}

interface Option { key: string; label: string }

const props = defineProps<{
    methods: MethodRow[];
    destinations: Option[];
    stats: { total: number; active: number; inactive: number };
}>();

const { can } = usePermissions();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'لوحة التحكم', href: '/admin' },
    { title: 'طرق الدفع', href: '/admin/settings/payment-methods' },
];

const activeCount = computed(() => props.stats.active);

// ── النموذج ─────────────────────────────────────────────────
const showModal = ref(false);
const editing = ref<MethodRow | null>(null);

const form = useForm({
    code: '',
    name: '',
    deposits_to: 'cash',
    is_credit: false,
    is_active: true,
    sort_order: 0,
});

const openCreate = () => {
    editing.value = null;
    form.reset();
    form.clearErrors();
    // الترتيب يلي آخر طريقة، فالجديدة تظهر في ذيل القائمة لا في صدرها.
    form.sort_order = props.methods.length ? Math.max(...props.methods.map((m) => m.sort_order)) + 1 : 1;
    showModal.value = true;
};

const openEdit = (m: MethodRow) => {
    editing.value = m;
    form.clearErrors();
    form.code = m.code;
    form.name = m.name;
    form.deposits_to = m.deposits_to;
    form.is_credit = m.is_credit;
    form.is_active = m.is_active;
    form.sort_order = m.sort_order;
    showModal.value = true;
};

const submit = () => {
    const done = { preserveScroll: true, onSuccess: () => (showModal.value = false) };

    if (editing.value) {
        form.put(`/admin/settings/payment-methods/${editing.value.id}`, done);
    } else {
        form.post('/admin/settings/payment-methods', done);
    }
};

const toggle = (m: MethodRow) => router.patch(`/admin/settings/payment-methods/${m.id}/toggle`, {}, { preserveScroll: true });

const destroy = (m: MethodRow) => {
    if (confirm(`حذف طريقة الدفع «${m.name}»؟`)) {
        router.delete(`/admin/settings/payment-methods/${m.id}`, { preserveScroll: true });
    }
};

const destinationIcon = (key: string) => (key === 'bank' ? Landmark : Banknote);
</script>

<template>
    <Head title="طرق الدفع" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="space-y-4 p-5">
            <SettingsTabs />

            <div>
                <h1 class="text-2xl font-extrabold text-slate-900">طرق الدفع</h1>
                <p class="mt-1 text-sm font-medium text-slate-600">
                    الطريقة المفعّلة تظهر في النظام كله — دفعات الحجوزات وفواتير الكاشير وسندات القبض والصرف.
                    وحساب الإيداع يحدّد أين يُقيَّد المقبوض في الدفاتر.
                </p>
            </div>

            <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                <SmallBox :value="stats.total" label="كل الطرق" variant="info" :icon="WalletCards" />
                <SmallBox :value="activeCount" label="المفعّلة" variant="success" :icon="CreditCard" />
                <SmallBox :value="stats.inactive" label="الموقوفة" variant="danger" :icon="Power" />
            </div>

            <div class="lte-card">
                <div class="lte-card-header">
                    <h3 class="lte-card-title">قائمة طرق الدفع</h3>
                    <button
                        v-if="can('payment_methods.create')" type="button" @click="openCreate"
                        class="inline-flex items-center gap-1.5 rounded-md bg-blue-600 px-4 py-2 text-sm font-bold text-white transition hover:bg-blue-700"
                    >
                        <Plus class="h-4 w-4" /> طريقة جديدة
                    </button>
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full min-w-[900px] text-sm">
                        <thead>
                            <tr class="border-b-2 border-[#dee2e6] text-[#1e3a8a]">
                                <th class="px-4 py-3 text-center font-semibold">#</th>
                                <th class="px-4 py-3 text-start font-semibold">الطريقة</th>
                                <th class="px-4 py-3 text-start font-semibold">حساب الإيداع</th>
                                <th class="px-4 py-3 text-center font-semibold">المستندات</th>
                                <th class="px-4 py-3 text-center font-semibold">الحالة</th>
                                <th class="px-4 py-3 text-end font-semibold">الإجراءات</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="m in methods" :key="m.id" class="border-b border-slate-100 align-middle transition hover:bg-slate-50">
                                <td class="px-4 py-2.5 text-center font-bold text-slate-400">{{ m.sort_order }}</td>
                                <td class="px-4 py-2.5">
                                    <div class="flex items-center gap-2 font-bold text-slate-800">
                                        {{ m.name }}
                                        <!-- الأساسية يعتمدها النظام: تُعرَّف بالقفل فيفهم المستخدم لِمَ لا تُحذف -->
                                        <Lock v-if="m.is_system" class="h-3.5 w-3.5 text-slate-400" title="طريقة أساسية يعتمدها النظام" />
                                    </div>
                                    <div class="mt-0.5 flex items-center gap-1.5">
                                        <code class="text-[11px] font-bold text-slate-400" dir="ltr">{{ m.code }}</code>
                                        <span v-if="m.is_credit" class="rounded bg-amber-100 px-1.5 py-0.5 text-[10px] font-bold text-amber-700">آجلة</span>
                                    </div>
                                </td>
                                <td class="px-4 py-2.5">
                                    <span class="inline-flex items-center gap-1.5 font-bold text-slate-700">
                                        <component :is="destinationIcon(m.deposits_to)" class="h-4 w-4 text-slate-400" />
                                        {{ m.deposits_to_label }}
                                    </span>
                                </td>
                                <td class="px-4 py-2.5 text-center font-bold text-slate-900">{{ m.usage_count }}</td>
                                <td class="px-4 py-2.5 text-center">
                                    <StatusBadge :variant="m.is_active ? 'success' : 'danger'" :label="m.is_active ? 'مفعّلة' : 'موقوفة'" />
                                </td>
                                <td class="px-4 py-2.5">
                                    <div class="flex justify-end">
                                        <div class="inline-flex divide-x divide-slate-300 overflow-hidden rounded-md border border-slate-300 rtl:divide-x-reverse">
                                            <button v-if="can('payment_methods.edit')" type="button" @click="openEdit(m)" title="تعديل" class="px-2.5 py-2 text-slate-600 transition hover:bg-slate-100"><Pencil class="h-4 w-4" /></button>
                                            <button v-if="can('payment_methods.edit') && !m.is_system" type="button" @click="toggle(m)" title="تفعيل/إيقاف" class="px-2.5 py-2 text-emerald-600 transition hover:bg-emerald-50"><Power class="h-4 w-4" /></button>
                                            <button v-if="can('payment_methods.delete') && !m.is_system" type="button" @click="destroy(m)" title="حذف" class="px-2.5 py-2 text-red-600 transition hover:bg-red-50"><Trash2 class="h-4 w-4" /></button>
                                        </div>
                                    </div>
                                </td>
                            </tr>
                            <tr v-if="!methods.length">
                                <td colspan="6" class="px-4 py-12 text-center text-sm text-slate-400">لا توجد طرق دفع.</td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div class="lte-card-footer">
                    <div class="text-sm font-medium text-slate-500">
                        الطريقة المستعملة في مستندات تُعطَّل ولا تُحذف، حتى يبقى كل مستند شاهدًا على طريقة قبضه.
                    </div>
                </div>
            </div>
        </div>

        <!-- نافذة النموذج -->
        <div v-if="showModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4" @click.self="showModal = false">
            <div class="max-h-[90vh] w-full max-w-lg overflow-y-auto rounded-2xl bg-white p-6 shadow-2xl">
                <div class="mb-4 flex items-center justify-between">
                    <h2 class="text-lg font-extrabold text-slate-900">{{ editing ? 'تعديل طريقة دفع' : 'طريقة دفع جديدة' }}</h2>
                    <button type="button" @click="showModal = false" class="rounded-lg p-1 text-slate-400 hover:bg-slate-100 hover:text-slate-600"><X class="h-5 w-5" /></button>
                </div>

                <form @submit.prevent="submit" class="space-y-3">
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="mb-1 block text-sm font-bold text-slate-700">الاسم المعروض</label>
                            <input v-model="form.name" type="text" placeholder="محفظة إلكترونية" class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm focus:border-emerald-400 focus:outline-none focus:ring-2 focus:ring-emerald-100" />
                            <p v-if="form.errors.name" class="mt-1 text-xs text-red-500">{{ form.errors.name }}</p>
                        </div>
                        <div>
                            <label class="mb-1 block text-sm font-bold text-slate-700">الكود</label>
                            <input
                                v-model="form.code" type="text" dir="ltr" placeholder="wallet"
                                :disabled="editing?.is_system"
                                class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm focus:border-emerald-400 focus:outline-none focus:ring-2 focus:ring-emerald-100 disabled:bg-slate-100 disabled:text-slate-400"
                            />
                            <p class="mt-1 text-[11px] font-medium text-slate-400">حروف لاتينية صغيرة وشرطة سفلية</p>
                            <p v-if="form.errors.code" class="mt-1 text-xs text-red-500">{{ form.errors.code }}</p>
                        </div>
                    </div>

                    <div>
                        <label class="mb-1 block text-sm font-bold text-slate-700">حساب الإيداع في الدفاتر</label>
                        <select v-model="form.deposits_to" class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm">
                            <option v-for="d in destinations" :key="d.key" :value="d.key">{{ d.label }}</option>
                        </select>
                        <p class="mt-1 text-[11px] font-medium text-slate-400">
                            الحساب الذي يُقيَّد فيه المقبوض بهذه الطريقة — الشبكة والحوالة في البنك، والنقد في الصندوق.
                        </p>
                        <p v-if="form.errors.deposits_to" class="mt-1 text-xs text-red-500">{{ form.errors.deposits_to }}</p>
                    </div>

                    <label class="flex items-start gap-2 rounded-xl bg-amber-50 p-3 text-sm font-medium text-amber-900">
                        <input v-model="form.is_credit" type="checkbox" :disabled="editing?.is_system" class="mt-0.5 h-4 w-4 rounded border-amber-300 text-amber-600 focus:ring-amber-200 disabled:opacity-50" />
                        <span>
                            <span class="font-bold">طريقة آجلة</span>
                            <span class="mt-0.5 block text-[11px]">لا تُقبض عند إصدار الفاتورة — يبقى المبلغ ذمّةً على العميل، ومرتجعها يُقيَّد على الذمم لا على الخزينة.</span>
                        </span>
                    </label>

                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="mb-1 block text-sm font-bold text-slate-700">ترتيب العرض</label>
                            <input v-model.number="form.sort_order" type="number" min="0" dir="ltr" class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm" />
                            <p v-if="form.errors.sort_order" class="mt-1 text-xs text-red-500">{{ form.errors.sort_order }}</p>
                        </div>
                        <label class="flex items-center gap-2 self-end pb-2.5 text-sm font-bold text-slate-700">
                            <input v-model="form.is_active" type="checkbox" class="h-4 w-4 rounded border-slate-300 text-emerald-600 focus:ring-emerald-200" />
                            مفعّلة
                        </label>
                    </div>

                    <p v-if="editing?.is_system" class="rounded-xl bg-slate-100 p-3 text-[11px] font-medium text-slate-600">
                        طريقة أساسية يعتمدها النظام: يُعدَّل اسمها وحساب إيداعها وترتيبها، ويبقى كودها وصفة الآجل ثابتَين.
                    </p>

                    <div class="flex justify-end gap-2 pt-2">
                        <button type="button" @click="showModal = false" class="rounded-xl border border-slate-200 bg-white px-4 py-2 text-sm font-bold text-slate-600 hover:bg-slate-50">إلغاء</button>
                        <button type="submit" :disabled="form.processing" class="brand-gradient rounded-xl px-5 py-2 text-sm font-bold text-white shadow-md transition hover:brightness-110 disabled:opacity-60">حفظ</button>
                    </div>
                </form>
            </div>
        </div>
    </AppLayout>
</template>
