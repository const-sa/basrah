<script setup lang="ts">
import { StatusBadge } from '@/components/data-table';
import SmallBox from '@/components/lte/SmallBox.vue';
import UploadProgress from '@/components/UploadProgress.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { CheckCircle2, CircleDot, Eye, LifeBuoy, Paperclip, Plus, RotateCcw, Search, Trash2, X } from 'lucide-vue-next';
import { ref, watch } from 'vue';

interface TicketRow {
    id: number;
    number: string;
    title: string;
    content: string;
    client_id: number;
    client_name: string | null;
    attachment_url: string | null;
    status: 'open' | 'closed';
    closed_at: string | null;
    created_at: string | null;
}
interface ClientOption { id: number; name: string; }
interface Paginated<T> {
    data: T[];
    links: { url: string | null; label: string; active: boolean }[];
    from: number | null;
    to: number | null;
    total: number;
}

const props = defineProps<{
    tickets: Paginated<TicketRow>;
    filters: { q: string; status: string };
    stats: { total: number; open: number; closed: number };
    clients: ClientOption[];
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'لوحة التحكم', href: '/admin' },
    { title: 'الدعم الفني', href: '/admin/tickets' },
];

// ===== بحث + فلتر الحالة =====
const search = ref(props.filters.q ?? '');
const status = ref(props.filters.status ?? '');
let timer: ReturnType<typeof setTimeout> | undefined;

const reload = () => {
    router.get('/admin/tickets', { q: search.value, status: status.value }, { preserveState: true, replace: true, preserveScroll: true });
};
watch(search, () => {
    clearTimeout(timer);
    timer = setTimeout(reload, 350);
});
watch(status, reload);

// ===== نموذج فتح تذكرة =====
const showModal = ref(false);
const attachmentName = ref('');
const form = useForm<{ client_id: string; title: string; content: string; attachment: File | null }>({
    client_id: '',
    title: '',
    content: '',
    attachment: null,
});

const openCreate = () => {
    form.reset();
    form.clearErrors();
    attachmentName.value = '';
    showModal.value = true;
};

const onFile = (e: Event) => {
    const file = (e.target as HTMLInputElement).files?.[0] ?? null;
    form.attachment = file;
    attachmentName.value = file?.name ?? '';
};

const submit = () => {
    form.post('/admin/tickets', {
        preserveScroll: true,
        forceFormData: true,
        onSuccess: () => (showModal.value = false),
    });
};

// ===== عرض تفاصيل =====
const showView = ref(false);
const current = ref<TicketRow | null>(null);
const openView = (t: TicketRow) => {
    current.value = t;
    showView.value = true;
};

const close = (t: TicketRow) => {
    if (confirm(`إغلاق التذكرة ${t.number}؟`)) {
        router.patch(`/admin/tickets/${t.id}/close`, {}, { preserveScroll: true });
    }
};
const reopen = (t: TicketRow) => router.patch(`/admin/tickets/${t.id}/reopen`, {}, { preserveScroll: true });
const destroy = (t: TicketRow) => {
    if (confirm(`حذف التذكرة ${t.number}؟`)) {
        router.delete(`/admin/tickets/${t.id}`, { preserveScroll: true });
    }
};
</script>

<template>
    <Head title="الدعم الفني" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="space-y-4 p-5">
            <h1 class="text-2xl font-extrabold text-slate-900">الدعم الفني — التذاكر</h1>

            <!-- مربّعات الإحصائيات -->
            <div class="grid grid-cols-3 gap-4">
                <SmallBox :value="stats.total" label="كل التذاكر" variant="info" :icon="LifeBuoy" />
                <SmallBox :value="stats.open" label="المفتوحة" variant="success" :icon="CircleDot" />
                <SmallBox :value="stats.closed" label="المغلقة" variant="secondary" :icon="CheckCircle2" />
            </div>

            <!-- بطاقة التذاكر بنمط AdminLTE -->
            <div class="lte-card">
                <div class="lte-card-header">
                    <h3 class="lte-card-title">تذاكر الدعم</h3>
                    <div class="flex flex-wrap items-center gap-2">
                        <div class="flex items-stretch overflow-hidden rounded-md border border-slate-300">
                            <span class="flex items-center bg-slate-50 px-2.5 text-slate-400"><Search class="h-4 w-4" /></span>
                            <input v-model="search" type="search" placeholder="بحث برقم أو عنوان أو عميل" class="w-56 border-0 px-3 py-2 text-sm focus:outline-none focus:ring-0" />
                        </div>
                        <select v-model="status" class="rounded-md border border-slate-300 px-3 py-2 text-sm">
                            <option value="">كل الحالات</option>
                            <option value="open">مفتوحة</option>
                            <option value="closed">مغلقة</option>
                        </select>
                        <button type="button" @click="openCreate" class="inline-flex items-center gap-1.5 rounded-md bg-blue-600 px-4 py-2 text-sm font-bold text-white transition hover:bg-blue-700">
                            <Plus class="h-4 w-4" /> تذكرة جديدة
                        </button>
                    </div>
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full min-w-[820px] text-sm">
                        <thead>
                            <tr class="border-b-2 border-[#dee2e6] text-gray-500">
                                <th class="px-4 py-3 text-start font-semibold">رقم التذكرة</th>
                                <th class="px-4 py-3 text-start font-semibold">العنوان</th>
                                <th class="px-4 py-3 text-start font-semibold">العميل</th>
                                <th class="px-4 py-3 text-center font-semibold">مرفق</th>
                                <th class="px-4 py-3 text-center font-semibold">الحالة</th>
                                <th class="px-4 py-3 text-center font-semibold">التاريخ</th>
                                <th class="px-4 py-3 text-end font-semibold">الإجراءات</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="t in tickets.data" :key="t.id" class="border-b border-slate-100 align-middle transition hover:bg-slate-50">
                                <td class="px-4 py-2.5 font-mono font-bold text-slate-900" dir="ltr">{{ t.number }}</td>
                                <td class="px-4 py-2.5">
                                    <button type="button" @click="openView(t)" class="font-bold text-slate-800 hover:text-emerald-600">{{ t.title }}</button>
                                </td>
                                <td class="px-4 py-2.5">{{ t.client_name || '—' }}</td>
                                <td class="px-4 py-2.5 text-center">
                                    <a v-if="t.attachment_url" :href="t.attachment_url" target="_blank" title="فتح المرفق" class="inline-flex text-emerald-600 hover:text-emerald-700"><Paperclip class="h-4 w-4" /></a>
                                    <span v-else class="text-slate-300">—</span>
                                </td>
                                <td class="px-4 py-2.5 text-center">
                                    <StatusBadge :variant="t.status === 'open' ? 'success' : 'neutral'" :label="t.status === 'open' ? 'مفتوحة' : 'مغلقة'" />
                                </td>
                                <td class="px-4 py-2.5 text-center" dir="ltr">{{ t.created_at?.slice(0, 16) }}</td>
                                <td class="px-4 py-2.5">
                                    <div class="flex justify-end">
                                        <div class="inline-flex divide-x divide-slate-300 overflow-hidden rounded-md border border-slate-300 rtl:divide-x-reverse">
                                            <button type="button" @click="openView(t)" title="عرض" class="px-2.5 py-2 text-slate-600 transition hover:bg-slate-100"><Eye class="h-4 w-4" /></button>
                                            <button v-if="t.status === 'open'" type="button" @click="close(t)" title="إغلاق" class="px-2.5 py-2 text-emerald-600 transition hover:bg-emerald-50"><CheckCircle2 class="h-4 w-4" /></button>
                                            <button v-else type="button" @click="reopen(t)" title="إعادة فتح" class="px-2.5 py-2 text-amber-600 transition hover:bg-amber-50"><RotateCcw class="h-4 w-4" /></button>
                                            <button type="button" @click="destroy(t)" title="حذف" class="px-2.5 py-2 text-red-600 transition hover:bg-red-50"><Trash2 class="h-4 w-4" /></button>
                                        </div>
                                    </div>
                                </td>
                            </tr>
                            <tr v-if="tickets.data.length === 0">
                                <td colspan="7" class="px-4 py-12 text-center text-sm text-slate-400">لا توجد تذاكر مطابقة.</td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div class="lte-card-footer">
                    <div class="text-sm font-medium text-slate-500">
                        عرض {{ tickets.from ?? 0 }} إلى {{ tickets.to ?? 0 }} من {{ tickets.total }} تذكرة
                    </div>
                    <div v-if="tickets.links.length > 3" class="inline-flex divide-x divide-slate-300 overflow-hidden rounded-md border border-slate-300 rtl:divide-x-reverse">
                        <template v-for="(link, i) in tickets.links" :key="i">
                            <Link v-if="link.url" :href="link.url" preserve-scroll
                                :class="['px-3 py-1.5 text-sm transition', link.active ? 'bg-blue-600 font-bold text-white' : 'bg-white text-slate-600 hover:bg-slate-50']"
                                v-html="link.label" />
                            <span v-else class="bg-white px-3 py-1.5 text-sm text-slate-300" v-html="link.label" />
                        </template>
                    </div>
                </div>
            </div>
        </div>

        <!-- نافذة فتح تذكرة -->
        <div v-if="showModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4" @click.self="showModal = false">
            <div class="max-h-[90vh] w-full max-w-md overflow-y-auto rounded-2xl bg-white p-6 shadow-2xl">
                <div class="mb-4 flex items-center justify-between">
                    <h2 class="text-lg font-extrabold text-slate-900">تذكرة دعم جديدة</h2>
                    <button type="button" @click="showModal = false" class="rounded-lg p-1 text-slate-400 hover:bg-slate-100 hover:text-slate-600"><X class="h-5 w-5" /></button>
                </div>
                <form @submit.prevent="submit" class="space-y-3">
                    <div>
                        <label class="mb-1 block text-sm font-bold text-slate-900">العميل</label>
                        <select v-model="form.client_id" class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm focus:border-emerald-400 focus:outline-none focus:ring-2 focus:ring-emerald-100">
                            <option value="" disabled>اختر العميل</option>
                            <option v-for="c in clients" :key="c.id" :value="c.id">{{ c.name }}</option>
                        </select>
                        <p v-if="form.errors.client_id" class="mt-1 text-xs text-red-500">{{ form.errors.client_id }}</p>
                    </div>
                    <div>
                        <label class="mb-1 block text-sm font-bold text-slate-900">العنوان</label>
                        <input v-model="form.title" type="text" class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm focus:border-emerald-400 focus:outline-none focus:ring-2 focus:ring-emerald-100" />
                        <p v-if="form.errors.title" class="mt-1 text-xs text-red-500">{{ form.errors.title }}</p>
                    </div>
                    <div>
                        <label class="mb-1 block text-sm font-bold text-slate-900">المحتوى</label>
                        <textarea v-model="form.content" rows="4" class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm focus:border-emerald-400 focus:outline-none focus:ring-2 focus:ring-emerald-100"></textarea>
                        <p v-if="form.errors.content" class="mt-1 text-xs text-red-500">{{ form.errors.content }}</p>
                    </div>
                    <div>
                        <label class="mb-1 block text-sm font-bold text-slate-900">مرفق (اختياري)</label>
                        <label class="flex cursor-pointer items-center gap-2 rounded-xl border border-dashed border-slate-300 px-3 py-2.5 text-sm text-slate-900 hover:border-emerald-400 hover:bg-emerald-50/40">
                            <Paperclip class="h-4 w-4" />
                            <span class="truncate">{{ attachmentName || 'اختر ملفاً (حتى 5MB)' }}</span>
                            <input type="file" class="hidden" @change="onFile" />
                        </label>
                        <p v-if="form.errors.attachment" class="mt-1 text-xs text-red-500">{{ form.errors.attachment }}</p>
                        <UploadProgress :progress="form.progress" label="جارٍ رفع المرفق" />
                    </div>
                    <div class="flex justify-end gap-2 pt-2">
                        <button type="button" @click="showModal = false" class="rounded-xl border border-slate-200 bg-white px-4 py-2 text-sm font-bold text-slate-600 hover:bg-slate-50">إلغاء</button>
                        <button type="submit" :disabled="form.processing" class="rounded-md bg-blue-600 px-5 py-2 text-sm font-bold text-white shadow-sm transition hover:bg-blue-700 disabled:opacity-60">فتح التذكرة</button>
                    </div>
                </form>
            </div>
        </div>

        <!-- نافذة عرض التفاصيل -->
        <div v-if="showView && current" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4" @click.self="showView = false">
            <div class="max-h-[90vh] w-full max-w-lg overflow-y-auto rounded-2xl bg-white p-6 shadow-2xl">
                <div class="mb-4 flex items-center justify-between">
                    <div>
                        <span class="font-mono text-sm font-bold text-emerald-600" dir="ltr">{{ current.number }}</span>
                        <h2 class="text-lg font-extrabold text-slate-900">{{ current.title }}</h2>
                    </div>
                    <button type="button" @click="showView = false" class="rounded-lg p-1 text-slate-400 hover:bg-slate-100 hover:text-slate-600"><X class="h-5 w-5" /></button>
                </div>
                <div class="space-y-3 text-sm">
                    <div class="flex items-center justify-between">
                        <span class="text-slate-900">العميل: <b class="text-slate-900">{{ current.client_name || '—' }}</b></span>
                        <span :class="['rounded-md px-2 py-0.5 text-xs font-bold', current.status === 'open' ? 'bg-emerald-100 text-emerald-700' : 'bg-slate-200 text-slate-900']">{{ current.status === 'open' ? 'مفتوحة' : 'مغلقة' }}</span>
                    </div>
                    <div class="whitespace-pre-wrap rounded-xl bg-slate-50 p-3 text-slate-900">{{ current.content }}</div>
                    <a v-if="current.attachment_url" :href="current.attachment_url" target="_blank" class="inline-flex items-center gap-1.5 rounded-xl border border-slate-200 px-3 py-2 text-sm font-bold text-emerald-600 hover:bg-emerald-50">
                        <Paperclip class="h-4 w-4" /> عرض المرفق
                    </a>
                    <div class="flex justify-end gap-2 border-t border-slate-100 pt-3">
                        <button v-if="current.status === 'open'" type="button" @click="close(current); showView = false" class="inline-flex items-center gap-1.5 rounded-md bg-emerald-600 px-4 py-2 text-sm font-bold text-white shadow-sm transition hover:bg-emerald-700">
                            <CheckCircle2 class="h-4 w-4" /> إغلاق التذكرة
                        </button>
                        <button v-else type="button" @click="reopen(current); showView = false" class="inline-flex items-center gap-1.5 rounded-md bg-amber-500 px-4 py-2 text-sm font-bold text-white shadow-sm transition hover:bg-amber-600">
                            <RotateCcw class="h-4 w-4" /> إعادة فتح
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
