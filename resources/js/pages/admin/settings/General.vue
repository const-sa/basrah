<script setup lang="ts">
import UploadProgress from '@/components/UploadProgress.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, useForm } from '@inertiajs/vue3';
import { Building2, Image, PenLine, Receipt, Save, Stamp, Upload } from 'lucide-vue-next';
import { ref } from 'vue';

interface SettingsData {
    business_name: string | null;
    logo_url: string | null;
    favicon_url: string | null;
    phone: string | null;
    whatsapp: string | null;
    email: string | null;
    address: string | null;
    tax_enabled: boolean;
    tax_number: string | null;
    tax_rate: number | string | null;
    commercial_register: string | null;
    manager_name: string | null;
    manager_signature_url: string | null;
    finance_manager_name: string | null;
    finance_manager_signature_url: string | null;
    stamp_url: string | null;
}

const props = defineProps<{ settings: SettingsData }>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'لوحة التحكم', href: '/admin' },
    { title: 'الإعدادات العامة', href: '/admin/settings/general' },
];

const logoPreview = ref<string | null>(props.settings.logo_url);
const faviconPreview = ref<string | null>(props.settings.favicon_url);
const managerSignaturePreview = ref<string | null>(props.settings.manager_signature_url);
const financeSignaturePreview = ref<string | null>(props.settings.finance_manager_signature_url);
const stampPreview = ref<string | null>(props.settings.stamp_url);

const form = useForm({
    business_name: props.settings.business_name ?? '',
    phone: props.settings.phone ?? '',
    whatsapp: props.settings.whatsapp ?? '',
    email: props.settings.email ?? '',
    address: props.settings.address ?? '',
    tax_enabled: props.settings.tax_enabled ?? false,
    tax_number: props.settings.tax_number ?? '',
    tax_rate: props.settings.tax_rate ?? 15,
    commercial_register: props.settings.commercial_register ?? '',
    manager_name: props.settings.manager_name ?? '',
    finance_manager_name: props.settings.finance_manager_name ?? '',
    logo: null as File | null,
    favicon: null as File | null,
    manager_signature: null as File | null,
    finance_manager_signature: null as File | null,
    stamp: null as File | null,
});

const onLogoChange = (e: Event) => {
    const file = (e.target as HTMLInputElement).files?.[0] ?? null;
    form.logo = file;
    if (file) logoPreview.value = URL.createObjectURL(file);
};

const onFaviconChange = (e: Event) => {
    const file = (e.target as HTMLInputElement).files?.[0] ?? null;
    form.favicon = file;
    if (file) faviconPreview.value = URL.createObjectURL(file);
};

const onManagerSignatureChange = (e: Event) => {
    const file = (e.target as HTMLInputElement).files?.[0] ?? null;
    form.manager_signature = file;
    if (file) managerSignaturePreview.value = URL.createObjectURL(file);
};

const onFinanceSignatureChange = (e: Event) => {
    const file = (e.target as HTMLInputElement).files?.[0] ?? null;
    form.finance_manager_signature = file;
    if (file) financeSignaturePreview.value = URL.createObjectURL(file);
};

const onStampChange = (e: Event) => {
    const file = (e.target as HTMLInputElement).files?.[0] ?? null;
    form.stamp = file;
    if (file) stampPreview.value = URL.createObjectURL(file);
};

const submit = () => {
    form.post('/admin/settings/general', {
        preserveScroll: true,
        forceFormData: true,
        onSuccess: () => {
            form.logo = null;
            form.favicon = null;
            form.manager_signature = null;
            form.finance_manager_signature = null;
            form.stamp = null;
        },
    });
};
</script>

<template>
    <Head title="الإعدادات العامة" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <form @submit.prevent="submit" class="min-h-full space-y-5 bg-slate-100 p-5">
            <div>
                <h1 class="text-2xl font-extrabold text-slate-900">الإعدادات العامة</h1>
                <p class="mt-1 text-sm font-medium text-slate-600">شعار النشاط وبياناته وإعدادات الضريبة</p>
            </div>

            <!-- شريط تحميل المرفوعات أثناء الحفظ -->
            <UploadProgress :progress="form.progress" label="جارٍ رفع الملفات وحفظ الإعدادات" />

            <!-- الشعار والأيقونة -->
            <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                <div class="mb-4 flex items-center gap-2">
                    <Image class="h-5 w-5 text-emerald-600" />
                    <h2 class="text-lg font-bold text-slate-800">الشعار وأيقونة المتصفح</h2>
                </div>
                <div class="grid gap-5 sm:grid-cols-2">
                    <div>
                        <label class="mb-2 block text-sm font-bold text-slate-700">شعار النشاط (Logo)</label>
                        <div class="flex items-center gap-4">
                            <div class="flex h-20 w-20 items-center justify-center overflow-hidden rounded-xl border border-dashed border-slate-700 bg-slate-900 p-1">
                                <img v-if="logoPreview" :src="logoPreview" alt="logo" class="h-full w-full object-contain" />
                                <Image v-else class="h-6 w-6 text-slate-300" />
                            </div>
                            <label class="inline-flex cursor-pointer items-center gap-1.5 rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm font-bold text-slate-600 transition hover:bg-slate-50">
                                <Upload class="h-4 w-4" /> اختيار صورة
                                <input type="file" accept="image/*" class="hidden" @change="onLogoChange" />
                            </label>
                        </div>
                        <p v-if="form.errors.logo" class="mt-1 text-xs text-red-500">{{ form.errors.logo }}</p>
                    </div>
                    <div>
                        <label class="mb-2 block text-sm font-bold text-slate-700">أيقونة المتصفح (Favicon)</label>
                        <div class="flex items-center gap-4">
                            <div class="flex h-20 w-20 items-center justify-center overflow-hidden rounded-xl border border-dashed border-slate-700 bg-slate-900 p-1">
                                <img v-if="faviconPreview" :src="faviconPreview" alt="favicon" class="h-full w-full object-contain" />
                                <Image v-else class="h-6 w-6 text-slate-300" />
                            </div>
                            <label class="inline-flex cursor-pointer items-center gap-1.5 rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm font-bold text-slate-600 transition hover:bg-slate-50">
                                <Upload class="h-4 w-4" /> اختيار صورة
                                <input type="file" accept="image/*" class="hidden" @change="onFaviconChange" />
                            </label>
                        </div>
                        <p v-if="form.errors.favicon" class="mt-1 text-xs text-red-500">{{ form.errors.favicon }}</p>
                    </div>
                </div>
            </div>

            <!-- بيانات النشاط -->
            <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                <div class="mb-4 flex items-center gap-2">
                    <Building2 class="h-5 w-5 text-emerald-600" />
                    <h2 class="text-lg font-bold text-slate-800">بيانات النشاط</h2>
                </div>
                <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    <div>
                        <label class="mb-1 block text-sm font-bold text-slate-700">اسم النشاط</label>
                        <input v-model="form.business_name" type="text" class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm focus:border-emerald-400 focus:outline-none focus:ring-2 focus:ring-emerald-100" />
                        <p v-if="form.errors.business_name" class="mt-1 text-xs text-red-500">{{ form.errors.business_name }}</p>
                    </div>
                    <div>
                        <label class="mb-1 block text-sm font-bold text-slate-700">الهاتف</label>
                        <input v-model="form.phone" type="text" dir="ltr" class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm focus:border-emerald-400 focus:outline-none focus:ring-2 focus:ring-emerald-100" />
                        <p v-if="form.errors.phone" class="mt-1 text-xs text-red-500">{{ form.errors.phone }}</p>
                    </div>
                    <div>
                        <label class="mb-1 block text-sm font-bold text-slate-700">واتساب</label>
                        <input v-model="form.whatsapp" type="text" dir="ltr" class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm focus:border-emerald-400 focus:outline-none focus:ring-2 focus:ring-emerald-100" />
                        <p v-if="form.errors.whatsapp" class="mt-1 text-xs text-red-500">{{ form.errors.whatsapp }}</p>
                    </div>
                    <div>
                        <label class="mb-1 block text-sm font-bold text-slate-700">البريد الإلكتروني</label>
                        <input v-model="form.email" type="email" dir="ltr" class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm focus:border-emerald-400 focus:outline-none focus:ring-2 focus:ring-emerald-100" />
                        <p v-if="form.errors.email" class="mt-1 text-xs text-red-500">{{ form.errors.email }}</p>
                    </div>
                    <div>
                        <label class="mb-1 block text-sm font-bold text-slate-700">السجل التجاري</label>
                        <input v-model="form.commercial_register" type="text" dir="ltr" class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm focus:border-emerald-400 focus:outline-none focus:ring-2 focus:ring-emerald-100" />
                        <p v-if="form.errors.commercial_register" class="mt-1 text-xs text-red-500">{{ form.errors.commercial_register }}</p>
                    </div>
                    <div>
                        <label class="mb-1 block text-sm font-bold text-slate-700">العنوان</label>
                        <input v-model="form.address" type="text" class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm focus:border-emerald-400 focus:outline-none focus:ring-2 focus:ring-emerald-100" />
                        <p v-if="form.errors.address" class="mt-1 text-xs text-red-500">{{ form.errors.address }}</p>
                    </div>
                </div>
            </div>

            <!-- الإدارة والتواقيع -->
            <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                <div class="mb-4 flex items-center gap-2">
                    <PenLine class="h-5 w-5 text-emerald-600" />
                    <h2 class="text-lg font-bold text-slate-800">الإدارة والتواقيع الإلكترونية</h2>
                </div>
                <div class="grid gap-5 sm:grid-cols-2">
                    <!-- المدير -->
                    <div class="rounded-xl border border-slate-100 bg-slate-50/60 p-4">
                        <h3 class="mb-3 text-sm font-extrabold text-slate-800">المدير</h3>
                        <label class="mb-1 block text-sm font-bold text-slate-700">الاسم</label>
                        <input v-model="form.manager_name" type="text" class="mb-3 w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm focus:border-emerald-400 focus:outline-none focus:ring-2 focus:ring-emerald-100" />
                        <p v-if="form.errors.manager_name" class="mb-2 text-xs text-red-500">{{ form.errors.manager_name }}</p>

                        <label class="mb-1 block text-sm font-bold text-slate-700">التوقيع الإلكتروني</label>
                        <div class="flex items-center gap-4">
                            <div class="flex h-20 w-32 items-center justify-center overflow-hidden rounded-xl border border-dashed border-slate-300 bg-white">
                                <img v-if="managerSignaturePreview" :src="managerSignaturePreview" alt="signature" class="h-full w-full object-contain" />
                                <PenLine v-else class="h-6 w-6 text-slate-300" />
                            </div>
                            <label class="inline-flex cursor-pointer items-center gap-1.5 rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm font-bold text-slate-600 transition hover:bg-slate-50">
                                <Upload class="h-4 w-4" /> رفع التوقيع
                                <input type="file" accept="image/*" class="hidden" @change="onManagerSignatureChange" />
                            </label>
                        </div>
                        <p v-if="form.errors.manager_signature" class="mt-1 text-xs text-red-500">{{ form.errors.manager_signature }}</p>
                    </div>

                    <!-- المدير المالي -->
                    <div class="rounded-xl border border-slate-100 bg-slate-50/60 p-4">
                        <h3 class="mb-3 text-sm font-extrabold text-slate-800">المدير المالي</h3>
                        <label class="mb-1 block text-sm font-bold text-slate-700">الاسم</label>
                        <input v-model="form.finance_manager_name" type="text" class="mb-3 w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm focus:border-emerald-400 focus:outline-none focus:ring-2 focus:ring-emerald-100" />
                        <p v-if="form.errors.finance_manager_name" class="mb-2 text-xs text-red-500">{{ form.errors.finance_manager_name }}</p>

                        <label class="mb-1 block text-sm font-bold text-slate-700">التوقيع الإلكتروني</label>
                        <div class="flex items-center gap-4">
                            <div class="flex h-20 w-32 items-center justify-center overflow-hidden rounded-xl border border-dashed border-slate-300 bg-white">
                                <img v-if="financeSignaturePreview" :src="financeSignaturePreview" alt="signature" class="h-full w-full object-contain" />
                                <PenLine v-else class="h-6 w-6 text-slate-300" />
                            </div>
                            <label class="inline-flex cursor-pointer items-center gap-1.5 rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm font-bold text-slate-600 transition hover:bg-slate-50">
                                <Upload class="h-4 w-4" /> رفع التوقيع
                                <input type="file" accept="image/*" class="hidden" @change="onFinanceSignatureChange" />
                            </label>
                        </div>
                        <p v-if="form.errors.finance_manager_signature" class="mt-1 text-xs text-red-500">{{ form.errors.finance_manager_signature }}</p>
                    </div>

                    <!-- ختم المؤسسة -->
                    <div class="rounded-xl border border-slate-100 bg-slate-50/60 p-4 sm:col-span-2">
                        <h3 class="mb-3 flex items-center gap-1.5 text-sm font-extrabold text-slate-800">
                            <Stamp class="h-4 w-4 text-emerald-600" /> ختم المؤسسة
                        </h3>
                        <div class="flex items-center gap-4">
                            <div class="flex h-24 w-24 items-center justify-center overflow-hidden rounded-xl border border-dashed border-slate-300 bg-white">
                                <img v-if="stampPreview" :src="stampPreview" alt="stamp" class="h-full w-full object-contain" />
                                <Stamp v-else class="h-7 w-7 text-slate-300" />
                            </div>
                            <label class="inline-flex cursor-pointer items-center gap-1.5 rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm font-bold text-slate-600 transition hover:bg-slate-50">
                                <Upload class="h-4 w-4" /> رفع صورة الختم
                                <input type="file" accept="image/*" class="hidden" @change="onStampChange" />
                            </label>
                        </div>
                        <p class="mt-2 text-xs font-medium text-slate-500">يُفضّل صورة بخلفية شفافة (PNG) لظهور الختم بشكل أفضل على المستندات.</p>
                        <p v-if="form.errors.stamp" class="mt-1 text-xs text-red-500">{{ form.errors.stamp }}</p>
                    </div>
                </div>
            </div>

            <!-- الضريبة -->
            <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                <div class="mb-4 flex items-center justify-between">
                    <div class="flex items-center gap-2">
                        <Receipt class="h-5 w-5 text-emerald-600" />
                        <h2 class="text-lg font-bold text-slate-800">إعدادات الضريبة</h2>
                    </div>
                    <!-- سويتش تفعيل الضريبة -->
                    <button type="button" role="switch" :aria-checked="form.tax_enabled" @click="form.tax_enabled = !form.tax_enabled"
                        :class="['relative inline-flex h-6 w-11 items-center rounded-full transition', form.tax_enabled ? 'brand-gradient' : 'bg-slate-300']">
                        <span :class="['inline-block h-4 w-4 transform rounded-full bg-white transition', form.tax_enabled ? '-translate-x-1' : '-translate-x-6']"></span>
                    </button>
                </div>

                <div v-if="form.tax_enabled" class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label class="mb-1 block text-sm font-bold text-slate-700">الرقم الضريبي</label>
                        <input v-model="form.tax_number" type="text" dir="ltr" class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm focus:border-emerald-400 focus:outline-none focus:ring-2 focus:ring-emerald-100" />
                        <p v-if="form.errors.tax_number" class="mt-1 text-xs text-red-500">{{ form.errors.tax_number }}</p>
                    </div>
                    <div>
                        <label class="mb-1 block text-sm font-bold text-slate-700">نسبة الضريبة (%)</label>
                        <input v-model="form.tax_rate" type="number" step="0.01" min="0" max="100" dir="ltr" class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm focus:border-emerald-400 focus:outline-none focus:ring-2 focus:ring-emerald-100" />
                        <p v-if="form.errors.tax_rate" class="mt-1 text-xs text-red-500">{{ form.errors.tax_rate }}</p>
                    </div>
                </div>
                <p v-else class="text-sm font-medium text-slate-500">الضريبة غير مفعّلة. فعّل السويتش لإدخال البيانات الضريبية.</p>
            </div>

            <!-- أزرار الحفظ -->
            <div class="flex justify-end gap-2 pt-1">
                <button type="submit" :disabled="form.processing" class="inline-flex items-center gap-1.5 rounded-md bg-blue-600 px-5 py-2 text-sm font-bold text-white shadow-sm transition hover:bg-blue-700 disabled:opacity-60">
                    <Save class="h-4 w-4" /> حفظ التغييرات
                </button>
            </div>
        </form>
    </AppLayout>
</template>
