<script setup lang="ts">
import { TableActionButton } from '@/components/data-table';
import { usePermissions } from '@/composables/usePermissions';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { Copy, Pencil, Plus, Star, Trash2, X } from 'lucide-vue-next';
import { ref } from 'vue';

interface Template {
    id: number; name: string; description: string | null;
    body: string; terms: string | null;
    is_default: boolean; is_active: boolean; contracts_count: number;
}

defineProps<{
    templates: Template[];
    placeholders: { key: string; label: string; token: string }[];
}>();

const { can } = usePermissions();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'لوحة التحكم', href: '/admin' },
    { title: 'العقود', href: '/admin/contracts' },
    { title: 'القوالب', href: '/admin/contract-templates' },
];

const showModal = ref(false);
const editingId = ref<number | null>(null);
const bodyRef = ref<HTMLTextAreaElement | null>(null);

const form = useForm({ name: '', description: '', body: '', terms: '', is_default: false, is_active: true });

const openCreate = () => {
    editingId.value = null;
    form.reset();
    form.clearErrors();
    showModal.value = true;
};

const openEdit = (t: Template) => {
    editingId.value = t.id;
    form.clearErrors();
    form.name = t.name;
    form.description = t.description ?? '';
    form.body = t.body;
    form.terms = t.terms ?? '';
    form.is_default = t.is_default;
    form.is_active = t.is_active;
    showModal.value = true;
};

/** إدراج الحقل في موضع المؤشر بدل إلحاقه في النهاية. */
const insertToken = (token: string) => {
    const el = bodyRef.value;
    if (!el) {
        form.body += token;
        return;
    }
    const start = el.selectionStart ?? form.body.length;
    const end = el.selectionEnd ?? start;
    form.body = form.body.slice(0, start) + token + form.body.slice(end);
    requestAnimationFrame(() => {
        el.focus();
        el.setSelectionRange(start + token.length, start + token.length);
    });
};

const submit = () => {
    const opts = { preserveScroll: true, onSuccess: () => (showModal.value = false) };
    editingId.value ? form.put(`/admin/contract-templates/${editingId.value}`, opts) : form.post('/admin/contract-templates', opts);
};

const destroy = (t: Template) => {
    if (confirm(`حذف القالب «${t.name}»؟`)) router.delete(`/admin/contract-templates/${t.id}`, { preserveScroll: true });
};
</script>

<template>
    <Head title="قوالب العقود" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="min-h-full space-y-4 bg-slate-100 p-5">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h1 class="text-2xl font-extrabold text-slate-900">قوالب العقود</h1>
                    <p class="mt-1 text-sm font-medium text-slate-600">حقول ديناميكية تُملأ من الحجز — تعديل القالب لا يمسّ عقودًا صدرت</p>
                </div>
                <div class="flex gap-2">
                    <Link href="/admin/contracts" class="rounded-md border border-slate-200 bg-white px-4 py-2 text-sm font-bold text-slate-700 hover:bg-slate-50">العقود</Link>
                    <button v-if="can('contract_templates.create')" type="button" @click="openCreate" class="inline-flex items-center gap-1.5 rounded-md bg-blue-600 px-4 py-2 text-sm font-bold text-white hover:bg-blue-700">
                        <Plus class="h-4 w-4" /> قالب جديد
                    </button>
                </div>
            </div>

            <div class="grid gap-4 lg:grid-cols-2">
                <div v-for="t in templates" :key="t.id" class="flex flex-col rounded-2xl border border-slate-200 bg-white p-5 shadow-sm" :class="!t.is_active && 'opacity-60'">
                    <div class="mb-2 flex items-start justify-between gap-2">
                        <div>
                            <div class="flex items-center gap-1.5 text-base font-extrabold text-slate-900">
                                {{ t.name }}
                                <Star v-if="t.is_default" class="h-4 w-4 fill-amber-400 text-amber-400" />
                            </div>
                            <p v-if="t.description" class="text-xs font-medium text-slate-500">{{ t.description }}</p>
                        </div>
                        <div class="flex shrink-0 gap-1">
                            <TableActionButton v-if="can('contract_templates.edit')" variant="edit" :icon="Pencil" title="تعديل" @click="openEdit(t)" />
                            <TableActionButton v-if="can('contract_templates.delete')" variant="danger" :icon="Trash2" title="حذف" @click="destroy(t)" />
                        </div>
                    </div>

                    <pre class="mb-2 max-h-40 flex-1 overflow-y-auto whitespace-pre-wrap rounded-xl bg-slate-50 p-3 font-sans text-[11px] leading-6 text-slate-600">{{ t.body }}</pre>

                    <div class="text-xs font-bold text-slate-500">{{ t.contracts_count }} عقد صدر من هذا القالب</div>
                </div>

                <p v-if="!templates.length" class="rounded-2xl bg-white py-10 text-center text-sm text-slate-500 lg:col-span-2">لا قوالب — أضف قالبًا لتتمكن من توليد العقود.</p>
            </div>
        </div>

        <div v-if="showModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4" @click.self="showModal = false">
            <div class="flex max-h-[92vh] w-full max-w-4xl flex-col rounded-2xl bg-white shadow-2xl">
                <div class="flex items-center justify-between border-b border-slate-100 px-6 py-4">
                    <h2 class="text-lg font-extrabold text-slate-900">{{ editingId ? 'تعديل قالب' : 'قالب جديد' }}</h2>
                    <button type="button" @click="showModal = false" class="text-slate-400 hover:text-slate-600"><X class="h-5 w-5" /></button>
                </div>

                <form @submit.prevent="submit" class="flex min-h-0 flex-1 flex-col">
                    <div class="min-h-0 flex-1 overflow-y-auto px-6 py-4">
                        <div class="grid gap-4 lg:grid-cols-[1fr_240px]">
                            <div class="space-y-3">
                                <div class="grid gap-3 sm:grid-cols-2">
                                    <div>
                                        <label class="mb-1 block text-sm font-bold text-slate-700">اسم القالب</label>
                                        <input v-model="form.name" class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm" />
                                        <p v-if="form.errors.name" class="mt-1 text-xs text-red-500">{{ form.errors.name }}</p>
                                    </div>
                                    <div>
                                        <label class="mb-1 block text-sm font-bold text-slate-700">الوصف</label>
                                        <input v-model="form.description" class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm" />
                                    </div>
                                </div>

                                <div>
                                    <label class="mb-1 block text-sm font-bold text-slate-700">نص العقد</label>
                                    <textarea ref="bodyRef" v-model="form.body" rows="12" class="w-full rounded-xl border border-slate-200 px-3 py-2.5 font-mono text-xs leading-6"></textarea>
                                    <p v-if="form.errors.body" class="mt-1 text-xs text-red-500">{{ form.errors.body }}</p>
                                </div>

                                <div>
                                    <label class="mb-1 block text-sm font-bold text-slate-700">الشروط والأحكام</label>
                                    <textarea v-model="form.terms" rows="6" class="w-full rounded-xl border border-slate-200 px-3 py-2.5 font-mono text-xs leading-6"></textarea>
                                </div>

                                <div class="flex flex-wrap gap-4">
                                    <label class="flex cursor-pointer items-center gap-2 text-sm font-bold text-slate-700">
                                        <input type="checkbox" v-model="form.is_default" class="h-4 w-4 rounded border-slate-300 text-amber-500" />
                                        القالب الافتراضي
                                    </label>
                                    <label class="flex cursor-pointer items-center gap-2 text-sm font-bold text-slate-700">
                                        <input type="checkbox" v-model="form.is_active" class="h-4 w-4 rounded border-slate-300 text-emerald-600" />
                                        فعّال
                                    </label>
                                </div>
                            </div>

                            <!-- الحقول المتاحة -->
                            <aside class="rounded-xl border border-slate-200 bg-slate-50 p-3">
                                <h3 class="mb-2 flex items-center gap-1.5 text-xs font-extrabold text-slate-700">
                                    <Copy class="h-3.5 w-3.5" /> الحقول المتاحة
                                </h3>
                                <p class="mb-2 text-[10px] font-medium text-slate-500">اضغط على الحقل لإدراجه في موضع المؤشر</p>
                                <div class="max-h-80 space-y-1 overflow-y-auto">
                                    <button
                                        v-for="p in placeholders" :key="p.key" type="button" @click="insertToken(p.token)"
                                        class="flex w-full items-center justify-between gap-1 rounded-lg bg-white px-2 py-1.5 text-right text-[11px] ring-1 ring-slate-200 transition hover:bg-emerald-50 hover:ring-emerald-300"
                                    >
                                        <span class="font-bold text-slate-700">{{ p.label }}</span>
                                        <span class="font-mono text-[9px] text-slate-400" dir="ltr">{{ p.token }}</span>
                                    </button>
                                </div>
                            </aside>
                        </div>
                    </div>

                    <div class="flex justify-end gap-2 border-t border-slate-100 px-6 py-4">
                        <button type="button" @click="showModal = false" class="rounded-xl border border-slate-200 px-4 py-2 text-sm font-bold text-slate-600">إلغاء</button>
                        <button type="submit" :disabled="form.processing" class="rounded-md bg-blue-600 px-5 py-2 text-sm font-bold text-white hover:bg-blue-700 disabled:opacity-60">حفظ</button>
                    </div>
                </form>
            </div>
        </div>
    </AppLayout>
</template>
