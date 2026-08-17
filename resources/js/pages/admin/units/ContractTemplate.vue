<script setup lang="ts">
import { usePermissions } from '@/composables/usePermissions';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { Eye, FileText, Pencil, Printer, RotateCcw } from 'lucide-vue-next';
import { computed, ref } from 'vue';

interface Placeholder {
    key: string;
    label: string;
    token: string;
}

const props = defineProps<{
    template: {
        id: number;
        name: string;
        description: string | null;
        body: string;
        terms: string | null;
        is_default: boolean;
        is_active: boolean;
        contracts_count: number;
    };
    placeholders: Placeholder[];
}>();

const { can } = usePermissions();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'لوحة التحكم', href: '/admin' },
    { title: 'القاعات', href: '/admin/units/halls' },
    { title: 'نموذج العقد', href: '/admin/units/contract-template' },
];

// عرض أولاً: النموذج نص يُقرأ أكثر مما يُعدّل، والتحرير خطوة واعية.
const editing = ref(false);

const form = useForm({
    body: props.template.body,
    terms: props.template.terms ?? '',
    is_default: props.template.is_default,
    is_active: props.template.is_active,
});

const startEdit = () => {
    form.body = props.template.body;
    form.terms = props.template.terms ?? '';
    form.is_default = props.template.is_default;
    form.is_active = props.template.is_active;
    form.clearErrors();
    editing.value = true;
};

const submit = () =>
    form.put('/admin/units/contract-template', {
        preserveScroll: true,
        onSuccess: () => (editing.value = false),
    });

const resetToDefault = () => {
    if (confirm('استعادة النص الأصلي للنموذج؟ سيُستبدل النص الحالي بالصياغة المثبّتة في النظام.')) {
        router.post('/admin/units/contract-template/reset', {}, { preserveScroll: true });
    }
};

/** إدراج حقل ديناميكي في نهاية نص العقد أثناء التحرير. */
const insertToken = (token: string) => {
    form.body = `${form.body}${form.body.endsWith('\n') ? '' : '\n'}${token}`;
};

const fullText = computed(() =>
    [props.template.body, props.template.terms].filter(Boolean).join('\n\n'),
);

const print = () => window.print();
</script>

<template>
    <Head title="نموذج العقد" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="min-h-full space-y-5 bg-slate-100 p-5">
            <div class="flex flex-wrap items-center justify-between gap-3 print:hidden">
                <div>
                    <h1 class="text-2xl font-extrabold text-slate-900">نموذج العقد</h1>
                    <p class="mt-1 text-sm font-medium text-slate-600">
                        {{ template.description ?? 'نموذج عقد إيجار القاعات — البيانات والشروط والأحكام' }}
                    </p>
                </div>
                <div class="flex flex-wrap gap-2">
                    <Link href="/admin/units/halls" class="rounded-md border border-slate-200 bg-white px-4 py-2 text-sm font-bold text-slate-700 hover:bg-slate-50">
                        القاعات
                    </Link>
                    <button type="button" @click="print" class="inline-flex items-center gap-1.5 rounded-md border border-slate-200 bg-white px-4 py-2 text-sm font-bold text-slate-700 hover:bg-slate-50">
                        <Printer class="h-4 w-4" /> طباعة
                    </button>
                    <button v-if="can('units.edit') && editing" type="button" @click="resetToDefault" class="inline-flex items-center gap-1.5 rounded-md border border-amber-200 bg-amber-50 px-4 py-2 text-sm font-bold text-amber-700 hover:bg-amber-100">
                        <RotateCcw class="h-4 w-4" /> استعادة الأصل
                    </button>
                    <button v-if="can('units.edit')" type="button" @click="editing ? (editing = false) : startEdit()" class="inline-flex items-center gap-1.5 rounded-md bg-blue-600 px-4 py-2 text-sm font-bold text-white hover:bg-blue-700">
                        <component :is="editing ? Eye : Pencil" class="h-4 w-4" />
                        {{ editing ? 'عرض النموذج' : 'تعديل النموذج' }}
                    </button>
                </div>
            </div>

            <!-- عرض النموذج -->
            <div v-if="!editing" class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                <div class="h-1.5 brand-gradient print:hidden"></div>
                <div class="flex items-center gap-3 border-b border-slate-100 px-6 py-4 print:hidden">
                    <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-2xl brand-gradient text-white shadow-md">
                        <FileText class="h-5 w-5" />
                    </span>
                    <div class="min-w-0">
                        <div class="truncate text-base font-extrabold text-slate-900">{{ template.name }}</div>
                        <div class="mt-0.5 flex flex-wrap items-center gap-1">
                            <span v-if="template.is_default" class="rounded bg-emerald-100 px-1.5 py-0.5 text-[10px] font-bold text-emerald-700">القالب الافتراضي</span>
                            <span v-if="!template.is_active" class="rounded bg-red-100 px-1.5 py-0.5 text-[10px] font-bold text-red-600">معطّل</span>
                            <span class="rounded bg-slate-100 px-1.5 py-0.5 text-[10px] font-bold text-slate-600">{{ template.contracts_count }} عقد صادر</span>
                        </div>
                    </div>
                </div>

                <pre class="whitespace-pre-wrap px-6 py-6 text-sm font-medium leading-8 text-slate-800 font-sans">{{ fullText }}</pre>
            </div>

            <!-- تحرير النموذج -->
            <form v-else @submit.prevent="submit" class="space-y-4 print:hidden">
                <div class="rounded-2xl border border-slate-200 bg-white p-3 shadow-sm">
                    <label class="mb-1.5 block text-xs font-bold text-slate-600">الحقول الديناميكية — تُملأ وقت توليد العقد من بيانات الحجز والعميل</label>
                    <div class="flex flex-wrap gap-1">
                        <button
                            v-for="p in placeholders" :key="p.key" type="button" @click="insertToken(p.token)"
                            class="rounded-lg bg-slate-100 px-2 py-1 text-[10px] font-bold text-slate-600 transition hover:bg-emerald-100 hover:text-emerald-700"
                            :title="p.token"
                        >+ {{ p.label }}</button>
                    </div>
                </div>

                <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                    <label class="mb-1 block text-sm font-bold text-slate-700">نص العقد</label>
                    <textarea v-model="form.body" rows="18" dir="rtl" class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm leading-7 focus:border-emerald-400 focus:outline-none focus:ring-2 focus:ring-emerald-100"></textarea>
                    <p v-if="form.errors.body" class="mt-1 text-xs text-red-500">{{ form.errors.body }}</p>
                </div>

                <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                    <label class="mb-1 block text-sm font-bold text-slate-700">الشروط والأحكام</label>
                    <textarea v-model="form.terms" rows="22" dir="rtl" class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm leading-7 focus:border-emerald-400 focus:outline-none focus:ring-2 focus:ring-emerald-100"></textarea>
                    <p v-if="form.errors.terms" class="mt-1 text-xs text-red-500">{{ form.errors.terms }}</p>
                    <p class="mt-1 text-[11px] font-medium text-slate-500">تُلحق بنهاية العقد عند التوليد.</p>
                </div>

                <div class="flex flex-wrap items-center justify-between gap-3 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                    <div class="space-y-2">
                        <label class="flex cursor-pointer items-center gap-2 text-sm font-bold text-slate-700">
                            <input type="checkbox" v-model="form.is_active" class="h-4 w-4 rounded border-slate-300 text-emerald-600" />
                            النموذج فعّال
                        </label>
                        <label class="flex cursor-pointer items-center gap-2 text-sm font-bold text-slate-700">
                            <input type="checkbox" v-model="form.is_default" class="h-4 w-4 rounded border-slate-300 text-emerald-600" />
                            اعتماده القالب الافتراضي للعقود الجديدة
                        </label>
                    </div>
                    <div class="flex gap-2">
                        <button type="button" @click="editing = false" class="rounded-xl border border-slate-200 bg-white px-4 py-2 text-sm font-bold text-slate-600 hover:bg-slate-50">إلغاء</button>
                        <button type="submit" :disabled="form.processing" class="rounded-md bg-blue-600 px-5 py-2 text-sm font-bold text-white shadow-sm transition hover:bg-blue-700 disabled:opacity-60">حفظ</button>
                    </div>
                </div>
            </form>
        </div>
    </AppLayout>
</template>
