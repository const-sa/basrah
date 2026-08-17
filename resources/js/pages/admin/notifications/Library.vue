<script setup lang="ts">
import { TableActionButton } from '@/components/data-table';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { AlertTriangle, Bell, Megaphone, Pencil, Plus, Send, Trash2, User, Users, X } from 'lucide-vue-next';
import { computed, ref } from 'vue';

interface Template {
    id: number;
    title: string;
    body: string;
    created_at: string | null;
}
interface ClientOption {
    id: number;
    name: string;
    mobile: string;
}

defineProps<{
    templates: Template[];
    clients: ClientOption[];
    wa_configured: boolean;
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'لوحة التحكم', href: '/admin' },
    { title: 'مكتبة الإشعارات', href: '/admin/notifications/library' },
];

// ===== نموذج الإضافة/التعديل =====
const showModal = ref(false);
const editingId = ref<number | null>(null);
const form = useForm({ title: '', body: '' });

const openCreate = () => {
    editingId.value = null;
    form.reset();
    form.clearErrors();
    showModal.value = true;
};

const openEdit = (t: Template) => {
    editingId.value = t.id;
    form.clearErrors();
    form.title = t.title;
    form.body = t.body;
    showModal.value = true;
};

const submit = () => {
    const opts = { preserveScroll: true, onSuccess: () => (showModal.value = false) };
    if (editingId.value) {
        form.put(`/admin/notifications/library/${editingId.value}`, opts);
    } else {
        form.post('/admin/notifications/library', opts);
    }
};

const destroy = (t: Template) => {
    if (confirm(`حذف الإشعار «${t.title}» من المكتبة؟`)) {
        useForm({}).delete(`/admin/notifications/library/${t.id}`, { preserveScroll: true });
    }
};

// ===== نموذج الإرسال =====
const showSend = ref(false);
const sendingTemplate = ref<Template | null>(null);
const sendForm = useForm({ target: 'all' as 'all' | 'client', client_id: '' as number | string });

const openSend = (t: Template) => {
    sendingTemplate.value = t;
    sendForm.reset();
    sendForm.clearErrors();
    sendForm.target = 'all';
    showSend.value = true;
};

const canSend = computed(() => sendForm.target === 'all' || !!sendForm.client_id);

const submitSend = () => {
    if (!sendingTemplate.value) return;
    sendForm.post(`/admin/notifications/library/${sendingTemplate.value.id}/send`, {
        preserveScroll: true,
        onSuccess: () => (showSend.value = false),
    });
};
</script>

<template>
    <Head title="مكتبة الإشعارات" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="min-h-full space-y-5 bg-slate-100 p-5">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h1 class="flex items-center gap-2 text-2xl font-extrabold text-slate-900">
                        <Megaphone class="h-6 w-6 text-emerald-600" /> مكتبة الإشعارات
                    </h1>
                    <p class="mt-1 text-sm font-medium text-slate-600">قوالب جاهزة (عنوان ومحتوى) يمكنك إرسالها لعميل محدّد أو لجميع العملاء عبر الواتساب</p>
                </div>
                <div class="flex items-center gap-2">
                    <Link href="/admin/notifications" class="inline-flex items-center gap-1.5 rounded-xl border border-slate-200 bg-white px-3.5 py-2 text-sm font-bold text-slate-700 shadow-sm transition hover:bg-slate-50">
                        <Bell class="h-4 w-4" /> الإشعارات
                    </Link>
                    <button type="button" @click="openCreate" class="inline-flex items-center gap-1.5 rounded-md bg-blue-600 px-4 py-2 text-sm font-bold text-white shadow-sm transition hover:bg-blue-700">
                        <Plus class="h-4 w-4" /> قالب جديد
                    </button>
                </div>
            </div>

            <!-- تنبيه عدم تفعيل الواتساب -->
            <div v-if="!wa_configured" class="flex items-center gap-2 rounded-2xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm font-bold text-amber-700">
                <AlertTriangle class="h-5 w-5 shrink-0" />
                تكامل الواتساب غير مفعّل، لن يتم الإرسال الفعلي. فعّله من
                <Link href="/admin/settings/whatsapp" class="underline underline-offset-2 hover:text-amber-800">إعدادات الواتساب</Link>.
            </div>

            <!-- شبكة القوالب -->
            <div class="grid grid-cols-1 gap-5 sm:grid-cols-2 lg:grid-cols-3">
                <div v-for="t in templates" :key="t.id" class="group flex flex-col overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm transition duration-200 hover:-translate-y-1 hover:shadow-lg">
                    <div class="h-1.5 brand-gradient"></div>
                    <div class="flex flex-1 flex-col p-5">
                        <div class="mb-3 flex items-start justify-between gap-2">
                            <div class="flex items-center gap-3">
                                <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-2xl brand-gradient text-white shadow-md">
                                    <Megaphone class="h-5 w-5" />
                                </span>
                                <h2 class="text-base font-extrabold text-slate-900">{{ t.title }}</h2>
                            </div>
                            <div class="flex items-center gap-1.5">
                                <TableActionButton variant="edit" :icon="Pencil" title="تعديل" @click="openEdit(t)" />
                                <TableActionButton variant="danger" :icon="Trash2" title="حذف" @click="destroy(t)" />
                            </div>
                        </div>

                        <p class="mb-4 line-clamp-4 flex-1 whitespace-pre-line text-sm font-medium leading-6 text-slate-600">{{ t.body }}</p>

                        <div class="flex items-center justify-between border-t border-slate-100 pt-3">
                            <span class="text-xs font-bold text-slate-500" dir="ltr">{{ t.created_at }}</span>
                            <button type="button" @click="openSend(t)" class="inline-flex items-center gap-1.5 rounded-md bg-blue-600 px-3.5 py-1.5 text-sm font-bold text-white shadow-sm transition hover:bg-blue-700">
                                <Send class="h-4 w-4" /> إرسال
                            </button>
                        </div>
                    </div>
                </div>

                <!-- حالة فارغة -->
                <button v-if="templates.length === 0" type="button" @click="openCreate" class="col-span-full flex flex-col items-center justify-center gap-2 rounded-2xl border-2 border-dashed border-slate-300 bg-white py-16 text-slate-400 transition hover:border-emerald-300 hover:text-emerald-600">
                    <Megaphone class="h-10 w-10" />
                    <span class="text-sm font-bold">لا توجد إشعارات في المكتبة بعد — أضف أول قالب</span>
                </button>
            </div>
        </div>

        <!-- نافذة الإضافة/التعديل -->
        <div v-if="showModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4" @click.self="showModal = false">
            <div class="w-full max-w-lg rounded-2xl bg-white shadow-2xl">
                <div class="flex items-center justify-between border-b border-slate-100 px-6 py-4">
                    <h2 class="text-lg font-extrabold text-slate-900">{{ editingId ? 'تعديل قالب' : 'قالب جديد' }}</h2>
                    <button type="button" @click="showModal = false" class="rounded-lg p-1 text-slate-400 hover:bg-slate-100 hover:text-slate-600"><X class="h-5 w-5" /></button>
                </div>
                <form @submit.prevent="submit" class="space-y-4 px-6 py-5">
                    <div>
                        <label class="mb-1 block text-sm font-bold text-slate-700">العنوان</label>
                        <input v-model="form.title" type="text" placeholder="مثال: عرض خاص لعملائنا" class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm focus:border-emerald-400 focus:outline-none focus:ring-2 focus:ring-emerald-100" />
                        <p v-if="form.errors.title" class="mt-1 text-xs text-red-500">{{ form.errors.title }}</p>
                    </div>
                    <div>
                        <label class="mb-1 block text-sm font-bold text-slate-700">المحتوى</label>
                        <textarea v-model="form.body" rows="6" placeholder="مرحباً {name} 👋 …" class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm leading-7 focus:border-emerald-400 focus:outline-none focus:ring-2 focus:ring-emerald-100"></textarea>
                        <p v-if="form.errors.body" class="mt-1 text-xs text-red-500">{{ form.errors.body }}</p>
                        <p class="mt-1 text-[11px] font-medium text-slate-500">يمكنك استخدام المتغيّرات: <span dir="ltr" class="font-bold text-emerald-700">{name}</span> اسم العميل، <span dir="ltr" class="font-bold text-emerald-700">{business_name}</span> اسم النشاط.</p>
                    </div>
                    <div class="flex justify-end gap-2 pt-1">
                        <button type="button" @click="showModal = false" class="rounded-xl border border-slate-200 bg-white px-4 py-2 text-sm font-bold text-slate-600 hover:bg-slate-50">إلغاء</button>
                        <button type="submit" :disabled="form.processing" class="rounded-md bg-blue-600 px-5 py-2 text-sm font-bold text-white shadow-sm transition hover:bg-blue-700 disabled:opacity-60">حفظ</button>
                    </div>
                </form>
            </div>
        </div>

        <!-- نافذة الإرسال -->
        <div v-if="showSend" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4" @click.self="showSend = false">
            <div class="w-full max-w-md rounded-2xl bg-white shadow-2xl">
                <div class="flex items-center justify-between border-b border-slate-100 px-6 py-4">
                    <h2 class="text-lg font-extrabold text-slate-900">إرسال الإشعار</h2>
                    <button type="button" @click="showSend = false" class="rounded-lg p-1 text-slate-400 hover:bg-slate-100 hover:text-slate-600"><X class="h-5 w-5" /></button>
                </div>
                <form @submit.prevent="submitSend" class="space-y-4 px-6 py-5">
                    <p class="rounded-xl bg-slate-100 px-3 py-2 text-sm font-bold text-slate-700">{{ sendingTemplate?.title }}</p>

                    <div class="grid grid-cols-2 gap-3">
                        <button type="button" @click="sendForm.target = 'all'"
                            :class="['flex flex-col items-center gap-1.5 rounded-xl border-2 p-4 text-sm font-bold transition', sendForm.target === 'all' ? 'border-emerald-500 bg-emerald-50 text-emerald-700' : 'border-slate-200 text-slate-600 hover:border-slate-300']">
                            <Users class="h-6 w-6" /> كل العملاء
                            <span class="text-[11px] font-medium text-slate-500">{{ clients.length }} عميل</span>
                        </button>
                        <button type="button" @click="sendForm.target = 'client'"
                            :class="['flex flex-col items-center gap-1.5 rounded-xl border-2 p-4 text-sm font-bold transition', sendForm.target === 'client' ? 'border-emerald-500 bg-emerald-50 text-emerald-700' : 'border-slate-200 text-slate-600 hover:border-slate-300']">
                            <User class="h-6 w-6" /> عميل محدّد
                        </button>
                    </div>

                    <div v-if="sendForm.target === 'client'">
                        <label class="mb-1 block text-sm font-bold text-slate-700">اختر العميل</label>
                        <select v-model="sendForm.client_id" class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm focus:border-emerald-400 focus:outline-none focus:ring-2 focus:ring-emerald-100">
                            <option value="">— اختر عميلاً —</option>
                            <option v-for="c in clients" :key="c.id" :value="c.id">{{ c.name }} ({{ c.mobile }})</option>
                        </select>
                        <p v-if="clients.length === 0" class="mt-1 text-xs text-amber-600">لا يوجد عملاء نشطون لديهم رقم جوال.</p>
                    </div>

                    <div class="flex justify-end gap-2 pt-1">
                        <button type="button" @click="showSend = false" class="rounded-xl border border-slate-200 bg-white px-4 py-2 text-sm font-bold text-slate-600 hover:bg-slate-50">إلغاء</button>
                        <button type="submit" :disabled="sendForm.processing || !canSend" class="inline-flex items-center gap-1.5 rounded-md bg-blue-600 px-5 py-2 text-sm font-bold text-white shadow-sm transition hover:bg-blue-700 disabled:opacity-60">
                            <Send class="h-4 w-4" /> {{ sendForm.processing ? 'جارٍ الإرسال…' : 'إرسال' }}
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </AppLayout>
</template>
