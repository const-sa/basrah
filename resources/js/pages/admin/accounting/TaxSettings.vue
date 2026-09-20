<script setup lang="ts">
import SectionBackLink from '@/components/SectionBackLink.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, useForm } from '@inertiajs/vue3';
import { Percent, Receipt, Save } from 'lucide-vue-next';

const props = defineProps<{
    settings: { tax_enabled: boolean; tax_number: string | null; tax_rate: number | string | null };
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'لوحة التحكم', href: '/admin' },
    { title: 'المحاسبة', href: '/admin/accounting' },
    { title: 'إعدادات الضريبة', href: '/admin/accounting/tax' },
];

const form = useForm({
    tax_enabled: props.settings.tax_enabled ?? false,
    tax_number: props.settings.tax_number ?? '',
    tax_rate: props.settings.tax_rate ?? 15,
});

const submit = () => form.post('/admin/accounting/tax', { preserveScroll: true });
</script>

<template>
    <Head title="إعدادات الضريبة" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="min-h-full space-y-5 bg-slate-100 p-5">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <SectionBackLink />
                <div class="text-start">
                    <h1 class="text-2xl font-extrabold text-slate-900">إعدادات الضريبة</h1>
                    <p class="mt-1 text-sm font-medium text-slate-600">التفعيل والرقم الضريبي والنسبة المحتسبة</p>
                </div>
            </div>

            <!-- ما حُرِّر بضريبته يبقى عليها: الفاتورة تحمل ضريبتها مخزّنة لا محسوبة عند العرض -->
            <div class="rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-xs font-medium leading-relaxed text-amber-800">
                النسبة تدخل في حساب كل فاتورة وعرض سعر وحجز يُحرَّر بعد الحفظ. وما حُرِّر قبله يبقى بضريبته
                المحسوبة يومها، فلا يتغيّر إقرارُ شهرٍ قُدِّم.
            </div>

            <form @submit.prevent="submit" class="space-y-5">
                <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                    <div class="mb-4 flex items-center justify-between">
                        <div class="flex items-center gap-2">
                            <Receipt class="h-5 w-5 text-emerald-600" />
                            <h2 class="text-lg font-bold text-slate-800">ضريبة القيمة المضافة</h2>
                        </div>
                        <button
                            type="button"
                            role="switch"
                            :aria-checked="form.tax_enabled"
                            @click="form.tax_enabled = !form.tax_enabled"
                            :class="['relative inline-flex h-6 w-11 items-center rounded-full transition', form.tax_enabled ? 'brand-gradient' : 'bg-slate-300']"
                        >
                            <span
                                :class="['inline-block h-4 w-4 transform rounded-full bg-white transition', form.tax_enabled ? '-translate-x-1' : '-translate-x-6']"
                            ></span>
                        </button>
                    </div>

                    <div v-if="form.tax_enabled" class="grid gap-4 sm:grid-cols-2">
                        <div>
                            <label class="mb-1 block text-sm font-bold text-slate-700">الرقم الضريبي</label>
                            <input
                                v-model="form.tax_number"
                                type="text"
                                dir="ltr"
                                class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm focus:border-emerald-400 focus:outline-none focus:ring-2 focus:ring-emerald-100"
                            />
                            <p class="mt-1 text-[11px] font-medium text-slate-500">يُطبع على الفاتورة ويدخل في رمز «زاتكا».</p>
                            <p v-if="form.errors.tax_number" class="mt-1 text-xs text-red-500">{{ form.errors.tax_number }}</p>
                        </div>
                        <div>
                            <label class="mb-1 flex items-center gap-1 text-sm font-bold text-slate-700">
                                <Percent class="h-3.5 w-3.5 text-emerald-500" /> نسبة الضريبة (%)
                            </label>
                            <input
                                v-model="form.tax_rate"
                                type="number"
                                step="0.01"
                                min="0"
                                max="100"
                                dir="ltr"
                                class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm focus:border-emerald-400 focus:outline-none focus:ring-2 focus:ring-emerald-100"
                            />
                            <p v-if="form.errors.tax_rate" class="mt-1 text-xs text-red-500">{{ form.errors.tax_rate }}</p>
                        </div>
                    </div>
                    <p v-else class="text-sm font-medium text-slate-500">الضريبة غير مفعّلة. فعّل السويتش لإدخال البيانات الضريبية.</p>
                </div>

                <button
                    type="submit"
                    :disabled="form.processing"
                    class="inline-flex items-center gap-1.5 rounded-md bg-blue-600 px-5 py-2.5 text-sm font-bold text-white shadow-sm transition hover:bg-blue-700 disabled:opacity-60"
                >
                    <Save class="h-4 w-4" /> حفظ إعدادات الضريبة
                </button>
            </form>
        </div>
    </AppLayout>
</template>
