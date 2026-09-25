<script setup lang="ts">
import SettingsTabs from '@/components/SettingsTabs.vue';
import UploadProgress from '@/components/UploadProgress.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, useForm } from '@inertiajs/vue3';
import { Building2, Image, PenLine, Save, Stamp, Upload, Waves } from 'lucide-vue-next';
import { ref } from 'vue';

interface SettingsData {
    business_name: string | null;
    logo_url: string | null;
    favicon_url: string | null;
    /** The pools activity's own letterhead — a separate business: blank stays blank, never the Diwan's. */
    pools_name: string | null;
    pools_logo_url: string | null;
    pools_phone: string | null;
    pools_whatsapp: string | null;
    pools_email: string | null;
    pools_address: string | null;
    pools_tax_number: string | null;
    pools_commercial_register: string | null;
    pools_manager_name: string | null;
    pools_signature_url: string | null;
    pools_stamp_url: string | null;
    phone: string | null;
    whatsapp: string | null;
    email: string | null;
    address: string | null;
    instagram: string | null;
    tiktok: string | null;
    snapchat: string | null;
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
const poolsLogoPreview = ref<string | null>(props.settings.pools_logo_url);
const faviconPreview = ref<string | null>(props.settings.favicon_url);
const managerSignaturePreview = ref<string | null>(props.settings.manager_signature_url);
const financeSignaturePreview = ref<string | null>(props.settings.finance_manager_signature_url);
const stampPreview = ref<string | null>(props.settings.stamp_url);
const poolsSignaturePreview = ref<string | null>(props.settings.pools_signature_url);
const poolsStampPreview = ref<string | null>(props.settings.pools_stamp_url);

const form = useForm({
    business_name: props.settings.business_name ?? '',
    pools_name: props.settings.pools_name ?? '',
    pools_phone: props.settings.pools_phone ?? '',
    pools_whatsapp: props.settings.pools_whatsapp ?? '',
    pools_email: props.settings.pools_email ?? '',
    pools_address: props.settings.pools_address ?? '',
    pools_tax_number: props.settings.pools_tax_number ?? '',
    pools_commercial_register: props.settings.pools_commercial_register ?? '',
    pools_manager_name: props.settings.pools_manager_name ?? '',
    phone: props.settings.phone ?? '',
    whatsapp: props.settings.whatsapp ?? '',
    email: props.settings.email ?? '',
    address: props.settings.address ?? '',
    instagram: props.settings.instagram ?? '',
    tiktok: props.settings.tiktok ?? '',
    snapchat: props.settings.snapchat ?? '',
    commercial_register: props.settings.commercial_register ?? '',
    manager_name: props.settings.manager_name ?? '',
    finance_manager_name: props.settings.finance_manager_name ?? '',
    logo: null as File | null,
    pools_logo: null as File | null,
    favicon: null as File | null,
    manager_signature: null as File | null,
    finance_manager_signature: null as File | null,
    stamp: null as File | null,
    pools_signature: null as File | null,
    pools_stamp: null as File | null,
});

const onLogoChange = (e: Event) => {
    const file = (e.target as HTMLInputElement).files?.[0] ?? null;
    form.logo = file;
    if (file) logoPreview.value = URL.createObjectURL(file);
};

const onPoolsLogoChange = (e: Event) => {
    const file = (e.target as HTMLInputElement).files?.[0] ?? null;
    form.pools_logo = file;
    if (file) poolsLogoPreview.value = URL.createObjectURL(file);
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

const onPoolsSignatureChange = (e: Event) => {
    const file = (e.target as HTMLInputElement).files?.[0] ?? null;
    form.pools_signature = file;
    if (file) poolsSignaturePreview.value = URL.createObjectURL(file);
};

const onPoolsStampChange = (e: Event) => {
    const file = (e.target as HTMLInputElement).files?.[0] ?? null;
    form.pools_stamp = file;
    if (file) poolsStampPreview.value = URL.createObjectURL(file);
};

const submit = () => {
    form.post('/admin/settings/general', {
        preserveScroll: true,
        forceFormData: true,
        onSuccess: () => {
            form.logo = null;
            form.pools_logo = null;
            form.favicon = null;
            form.manager_signature = null;
            form.finance_manager_signature = null;
            form.stamp = null;
            form.pools_signature = null;
            form.pools_stamp = null;
        },
    });
};
</script>

<template>
    <Head title="الإعدادات العامة" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <form @submit.prevent="submit" class="min-h-full space-y-5 bg-slate-100 p-5">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <SettingsTabs />
                <div class="text-start">
                    <h1 class="text-2xl font-extrabold text-slate-900">الإعدادات العامة</h1>
                    <p class="mt-1 text-sm font-medium text-slate-600">شعار النشاط وبياناته وتواقيعه</p>
                </div>
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
                            <div
                                class="flex h-20 w-20 items-center justify-center overflow-hidden rounded-xl border border-dashed border-slate-700 bg-slate-900 p-1"
                            >
                                <img v-if="logoPreview" :src="logoPreview" alt="logo" class="h-full w-full object-contain" />
                                <Image v-else class="h-6 w-6 text-slate-300" />
                            </div>
                            <label
                                class="inline-flex cursor-pointer items-center gap-1.5 rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm font-bold text-slate-600 transition hover:bg-slate-50"
                            >
                                <Upload class="h-4 w-4" /> اختيار صورة
                                <input type="file" accept="image/*" class="hidden" @change="onLogoChange" />
                            </label>
                        </div>
                        <p v-if="form.errors.logo" class="mt-1 text-xs text-red-500">{{ form.errors.logo }}</p>
                    </div>
                    <div>
                        <label class="mb-2 block text-sm font-bold text-slate-700">أيقونة المتصفح (Favicon)</label>
                        <div class="flex items-center gap-4">
                            <div
                                class="flex h-20 w-20 items-center justify-center overflow-hidden rounded-xl border border-dashed border-slate-700 bg-slate-900 p-1"
                            >
                                <img v-if="faviconPreview" :src="faviconPreview" alt="favicon" class="h-full w-full object-contain" />
                                <Image v-else class="h-6 w-6 text-slate-300" />
                            </div>
                            <label
                                class="inline-flex cursor-pointer items-center gap-1.5 rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm font-bold text-slate-600 transition hover:bg-slate-50"
                            >
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
                <p class="-mt-2 mb-4 text-xs text-slate-500">
                    الهاتف والواتساب والبريد وحسابات التواصل والعنوان تظهر للزوّار في قسم «بيانات التواصل» بالصفحة الرئيسية للموقع.
                </p>
                <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    <div>
                        <label class="mb-1 block text-sm font-bold text-slate-700">اسم النشاط</label>
                        <input
                            v-model="form.business_name"
                            type="text"
                            class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm focus:border-emerald-400 focus:outline-none focus:ring-2 focus:ring-emerald-100"
                        />
                        <p v-if="form.errors.business_name" class="mt-1 text-xs text-red-500">{{ form.errors.business_name }}</p>
                    </div>
                    <div>
                        <label class="mb-1 block text-sm font-bold text-slate-700">الهاتف</label>
                        <input
                            v-model="form.phone"
                            type="text"
                            dir="ltr"
                            class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm focus:border-emerald-400 focus:outline-none focus:ring-2 focus:ring-emerald-100"
                        />
                        <p v-if="form.errors.phone" class="mt-1 text-xs text-red-500">{{ form.errors.phone }}</p>
                    </div>
                    <div>
                        <label class="mb-1 block text-sm font-bold text-slate-700">واتساب</label>
                        <input
                            v-model="form.whatsapp"
                            type="text"
                            dir="ltr"
                            class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm focus:border-emerald-400 focus:outline-none focus:ring-2 focus:ring-emerald-100"
                        />
                        <p v-if="form.errors.whatsapp" class="mt-1 text-xs text-red-500">{{ form.errors.whatsapp }}</p>
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
                        <label class="mb-1 block text-sm font-bold text-slate-700">السجل التجاري</label>
                        <input
                            v-model="form.commercial_register"
                            type="text"
                            dir="ltr"
                            class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm focus:border-emerald-400 focus:outline-none focus:ring-2 focus:ring-emerald-100"
                        />
                        <p v-if="form.errors.commercial_register" class="mt-1 text-xs text-red-500">{{ form.errors.commercial_register }}</p>
                    </div>
                    <div>
                        <label class="mb-1 block text-sm font-bold text-slate-700">العنوان</label>
                        <input
                            v-model="form.address"
                            type="text"
                            class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm focus:border-emerald-400 focus:outline-none focus:ring-2 focus:ring-emerald-100"
                        />
                        <p v-if="form.errors.address" class="mt-1 text-xs text-red-500">{{ form.errors.address }}</p>
                    </div>
                    <!-- Social accounts: a handle or a pasted profile link. -->
                    <div>
                        <label class="mb-1 block text-sm font-bold text-slate-700">انستغرام</label>
                        <input
                            v-model="form.instagram"
                            type="text"
                            dir="ltr"
                            placeholder="diwanalmasara"
                            class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm focus:border-emerald-400 focus:outline-none focus:ring-2 focus:ring-emerald-100"
                        />
                        <p v-if="form.errors.instagram" class="mt-1 text-xs text-red-500">{{ form.errors.instagram }}</p>
                    </div>
                    <div>
                        <label class="mb-1 block text-sm font-bold text-slate-700">تيك توك</label>
                        <input
                            v-model="form.tiktok"
                            type="text"
                            dir="ltr"
                            placeholder="diwanalmasara"
                            class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm focus:border-emerald-400 focus:outline-none focus:ring-2 focus:ring-emerald-100"
                        />
                        <p v-if="form.errors.tiktok" class="mt-1 text-xs text-red-500">{{ form.errors.tiktok }}</p>
                    </div>
                    <div>
                        <label class="mb-1 block text-sm font-bold text-slate-700">سناب شات</label>
                        <input
                            v-model="form.snapchat"
                            type="text"
                            dir="ltr"
                            placeholder="diwanalmasara"
                            class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm focus:border-emerald-400 focus:outline-none focus:ring-2 focus:ring-emerald-100"
                        />
                        <p v-if="form.errors.snapchat" class="mt-1 text-xs text-red-500">{{ form.errors.snapchat }}</p>
                    </div>
                </div>
                <p class="mt-3 text-xs text-slate-500">اكتب معرّف الحساب وحده أو الصق رابطه كاملًا — كلاهما يعمل.</p>
            </div>

            <!-- The pools activity trades under its own letterhead, printed on its contracts. -->
            <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                <div class="mb-4 flex items-center gap-2">
                    <Waves class="h-5 w-5 text-emerald-600" />
                    <h2 class="text-lg font-bold text-slate-800">هوية نشاط المسابح</h2>
                </div>
                <p class="mb-4 text-sm font-medium text-slate-500">
                    المسابح جهة مستقلة عن الديوان: تُطبع هذه البيانات وحدها على فواتير المسابح وعروض أسعارها ومشترياتها وعقودها. ما يُترك فارغًا يُحذف من الورقة، ولا يُستعار من بيانات الديوان.
                </p>
                <div class="grid gap-5 sm:grid-cols-2">
                    <div>
                        <label class="mb-1 block text-sm font-bold text-slate-700">اسم النشاط</label>
                        <input
                            v-model="form.pools_name"
                            type="text"
                            class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm focus:border-emerald-400 focus:outline-none focus:ring-2 focus:ring-emerald-100"
                        />
                        <p v-if="form.errors.pools_name" class="mt-1 text-xs text-red-500">{{ form.errors.pools_name }}</p>

                        <label class="mb-1 mt-3 block text-sm font-bold text-slate-700">الهاتف</label>
                        <input
                            v-model="form.pools_phone"
                            type="text"
                            dir="ltr"
                            class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm focus:border-emerald-400 focus:outline-none focus:ring-2 focus:ring-emerald-100"
                        />
                        <p v-if="form.errors.pools_phone" class="mt-1 text-xs text-red-500">{{ form.errors.pools_phone }}</p>
                    </div>
                    <div>
                        <label class="mb-2 block text-sm font-bold text-slate-700">شعار النشاط</label>
                        <div class="flex items-center gap-4">
                            <div
                                class="flex h-20 w-20 items-center justify-center overflow-hidden rounded-xl border border-dashed border-slate-700 bg-slate-900 p-1"
                            >
                                <img v-if="poolsLogoPreview" :src="poolsLogoPreview" alt="pools logo" class="h-full w-full object-contain" />
                                <Image v-else class="h-6 w-6 text-slate-300" />
                            </div>
                            <label
                                class="inline-flex cursor-pointer items-center gap-1.5 rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm font-bold text-slate-600 transition hover:bg-slate-50"
                            >
                                <Upload class="h-4 w-4" /> اختيار صورة
                                <input type="file" accept="image/*" class="hidden" @change="onPoolsLogoChange" />
                            </label>
                        </div>
                        <p v-if="form.errors.pools_logo" class="mt-1 text-xs text-red-500">{{ form.errors.pools_logo }}</p>
                    </div>
                </div>
                <div class="mt-5 grid gap-5 border-t border-slate-100 pt-5 sm:grid-cols-2">
                    <div>
                        <label class="mb-1 block text-sm font-bold text-slate-700">واتساب</label>
                        <input v-model="form.pools_whatsapp" type="text" dir="ltr" class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm focus:border-emerald-400 focus:outline-none focus:ring-2 focus:ring-emerald-100" />
                        <p v-if="form.errors.pools_whatsapp" class="mt-1 text-xs text-red-500">{{ form.errors.pools_whatsapp }}</p>
                    </div>
                    <div>
                        <label class="mb-1 block text-sm font-bold text-slate-700">البريد الإلكتروني</label>
                        <input v-model="form.pools_email" type="email" dir="ltr" class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm focus:border-emerald-400 focus:outline-none focus:ring-2 focus:ring-emerald-100" />
                        <p v-if="form.errors.pools_email" class="mt-1 text-xs text-red-500">{{ form.errors.pools_email }}</p>
                    </div>
                    <div>
                        <label class="mb-1 block text-sm font-bold text-slate-700">الرقم الضريبي</label>
                        <input v-model="form.pools_tax_number" type="text" dir="ltr" class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm focus:border-emerald-400 focus:outline-none focus:ring-2 focus:ring-emerald-100" />
                        <p v-if="form.errors.pools_tax_number" class="mt-1 text-xs text-red-500">{{ form.errors.pools_tax_number }}</p>
                    </div>
                    <div>
                        <label class="mb-1 block text-sm font-bold text-slate-700">السجل التجاري</label>
                        <input v-model="form.pools_commercial_register" type="text" dir="ltr" class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm focus:border-emerald-400 focus:outline-none focus:ring-2 focus:ring-emerald-100" />
                        <p v-if="form.errors.pools_commercial_register" class="mt-1 text-xs text-red-500">{{ form.errors.pools_commercial_register }}</p>
                    </div>
                    <div class="sm:col-span-2">
                        <label class="mb-1 block text-sm font-bold text-slate-700">العنوان</label>
                        <input v-model="form.pools_address" type="text" class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm focus:border-emerald-400 focus:outline-none focus:ring-2 focus:ring-emerald-100" />
                        <p v-if="form.errors.pools_address" class="mt-1 text-xs text-red-500">{{ form.errors.pools_address }}</p>
                    </div>
                    <div>
                        <label class="mb-1 block text-sm font-bold text-slate-700">اسم المدير</label>
                        <input v-model="form.pools_manager_name" type="text" class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm focus:border-emerald-400 focus:outline-none focus:ring-2 focus:ring-emerald-100" />
                        <p v-if="form.errors.pools_manager_name" class="mt-1 text-xs text-red-500">{{ form.errors.pools_manager_name }}</p>
                    </div>
                    <div></div>
                    <div>
                        <label class="mb-2 block text-sm font-bold text-slate-700">توقيع المدير</label>
                        <div class="flex items-center gap-4">
                            <div class="flex h-20 w-28 items-center justify-center overflow-hidden rounded-xl border border-dashed border-slate-300 bg-slate-50 p-1">
                                <img v-if="poolsSignaturePreview" :src="poolsSignaturePreview" alt="" class="h-full w-full object-contain" />
                                <Image v-else class="h-6 w-6 text-slate-300" />
                            </div>
                            <label class="inline-flex cursor-pointer items-center gap-1.5 rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm font-bold text-slate-600 transition hover:bg-slate-50">
                                <Upload class="h-4 w-4" /> اختيار صورة
                                <input type="file" accept="image/*" class="hidden" @change="onPoolsSignatureChange" />
                            </label>
                        </div>
                        <p v-if="form.errors.pools_signature" class="mt-1 text-xs text-red-500">{{ form.errors.pools_signature }}</p>
                    </div>
                    <div>
                        <label class="mb-2 block text-sm font-bold text-slate-700">ختم المؤسسة</label>
                        <div class="flex items-center gap-4">
                            <div class="flex h-20 w-28 items-center justify-center overflow-hidden rounded-xl border border-dashed border-slate-300 bg-slate-50 p-1">
                                <img v-if="poolsStampPreview" :src="poolsStampPreview" alt="" class="h-full w-full object-contain" />
                                <Image v-else class="h-6 w-6 text-slate-300" />
                            </div>
                            <label class="inline-flex cursor-pointer items-center gap-1.5 rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm font-bold text-slate-600 transition hover:bg-slate-50">
                                <Upload class="h-4 w-4" /> اختيار صورة
                                <input type="file" accept="image/*" class="hidden" @change="onPoolsStampChange" />
                            </label>
                        </div>
                        <p v-if="form.errors.pools_stamp" class="mt-1 text-xs text-red-500">{{ form.errors.pools_stamp }}</p>
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
                        <input
                            v-model="form.manager_name"
                            type="text"
                            class="mb-3 w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm focus:border-emerald-400 focus:outline-none focus:ring-2 focus:ring-emerald-100"
                        />
                        <p v-if="form.errors.manager_name" class="mb-2 text-xs text-red-500">{{ form.errors.manager_name }}</p>

                        <label class="mb-1 block text-sm font-bold text-slate-700">التوقيع الإلكتروني</label>
                        <div class="flex items-center gap-4">
                            <div
                                class="flex h-20 w-32 items-center justify-center overflow-hidden rounded-xl border border-dashed border-slate-300 bg-white"
                            >
                                <img
                                    v-if="managerSignaturePreview"
                                    :src="managerSignaturePreview"
                                    alt="signature"
                                    class="h-full w-full object-contain"
                                />
                                <PenLine v-else class="h-6 w-6 text-slate-300" />
                            </div>
                            <label
                                class="inline-flex cursor-pointer items-center gap-1.5 rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm font-bold text-slate-600 transition hover:bg-slate-50"
                            >
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
                        <input
                            v-model="form.finance_manager_name"
                            type="text"
                            class="mb-3 w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm focus:border-emerald-400 focus:outline-none focus:ring-2 focus:ring-emerald-100"
                        />
                        <p v-if="form.errors.finance_manager_name" class="mb-2 text-xs text-red-500">{{ form.errors.finance_manager_name }}</p>

                        <label class="mb-1 block text-sm font-bold text-slate-700">التوقيع الإلكتروني</label>
                        <div class="flex items-center gap-4">
                            <div
                                class="flex h-20 w-32 items-center justify-center overflow-hidden rounded-xl border border-dashed border-slate-300 bg-white"
                            >
                                <img
                                    v-if="financeSignaturePreview"
                                    :src="financeSignaturePreview"
                                    alt="signature"
                                    class="h-full w-full object-contain"
                                />
                                <PenLine v-else class="h-6 w-6 text-slate-300" />
                            </div>
                            <label
                                class="inline-flex cursor-pointer items-center gap-1.5 rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm font-bold text-slate-600 transition hover:bg-slate-50"
                            >
                                <Upload class="h-4 w-4" /> رفع التوقيع
                                <input type="file" accept="image/*" class="hidden" @change="onFinanceSignatureChange" />
                            </label>
                        </div>
                        <p v-if="form.errors.finance_manager_signature" class="mt-1 text-xs text-red-500">
                            {{ form.errors.finance_manager_signature }}
                        </p>
                    </div>

                    <!-- ختم المؤسسة -->
                    <div class="rounded-xl border border-slate-100 bg-slate-50/60 p-4 sm:col-span-2">
                        <h3 class="mb-3 flex items-center gap-1.5 text-sm font-extrabold text-slate-800">
                            <Stamp class="h-4 w-4 text-emerald-600" /> ختم المؤسسة
                        </h3>
                        <div class="flex items-center gap-4">
                            <div
                                class="flex h-24 w-24 items-center justify-center overflow-hidden rounded-xl border border-dashed border-slate-300 bg-white"
                            >
                                <img v-if="stampPreview" :src="stampPreview" alt="stamp" class="h-full w-full object-contain" />
                                <Stamp v-else class="h-7 w-7 text-slate-300" />
                            </div>
                            <label
                                class="inline-flex cursor-pointer items-center gap-1.5 rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm font-bold text-slate-600 transition hover:bg-slate-50"
                            >
                                <Upload class="h-4 w-4" /> رفع صورة الختم
                                <input type="file" accept="image/*" class="hidden" @change="onStampChange" />
                            </label>
                        </div>
                        <p class="mt-2 text-xs font-medium text-slate-500">يُفضّل صورة بخلفية شفافة (PNG) لظهور الختم بشكل أفضل على المستندات.</p>
                        <p v-if="form.errors.stamp" class="mt-1 text-xs text-red-500">{{ form.errors.stamp }}</p>
                    </div>
                </div>
            </div>

            <!-- أزرار الحفظ -->
            <div class="flex justify-end gap-2 pt-1">
                <button
                    type="submit"
                    :disabled="form.processing"
                    class="inline-flex items-center gap-1.5 rounded-md bg-blue-600 px-5 py-2 text-sm font-bold text-white shadow-sm transition hover:bg-blue-700 disabled:opacity-60"
                >
                    <Save class="h-4 w-4" /> حفظ التغييرات
                </button>
            </div>
        </form>
    </AppLayout>
</template>
