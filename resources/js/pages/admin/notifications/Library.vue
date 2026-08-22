<script setup lang="ts">
import { TableActionButton } from '@/components/data-table';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { AlertTriangle, Bell, Building2, Layers, Megaphone, Pencil, Plus, Send, Trash2, User, Users, Waves, X } from 'lucide-vue-next';
import { computed, ref } from 'vue';

interface Template {
    id: number;
    category: string;
    category_label: string;
    event: string | null;
    event_label: string;
    title: string;
    body: string;
    is_active: boolean;
    sort_order: number;
    created_at: string | null;
}
interface ClientOption {
    id: number;
    name: string;
    mobile: string;
}
interface CatalogEntry {
    key: string;
    label: string;
    hint: string;
    auto?: boolean;
}
interface Catalog {
    categories: CatalogEntry[];
    events: CatalogEntry[];
    variables: Record<string, { key: string; label: string }[]>;
}

const props = defineProps<{
    templates: Template[];
    catalog: Catalog;
    clients: ClientOption[];
    wa_configured: boolean;
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'لوحة التحكم', href: '/admin' },
    { title: 'مكتبة الإشعارات', href: '/admin/notifications/library' },
];

// أيقونة لكل قسم — لتمييز التبويبات بلمحة بصر.
const categoryIcon = (key: string) => {
    if (key === 'chalet') return Layers;
    if (key === 'hall') return Building2;
    if (key === 'pool') return Waves;
    return Megaphone;
};

// ===== التبويبات =====
const activeCategory = ref<'all' | string>('all');

const countFor = (key: string) => props.templates.filter((t) => t.category === key).length;

const visible = computed(() =>
    activeCategory.value === 'all' ? props.templates : props.templates.filter((t) => t.category === activeCategory.value),
);

/** القوالب المعروضة مجمّعةً حسب المناسبة، بترتيب الفهرس لا بترتيب الإدخال. */
const groups = computed(() => {
    const order = props.catalog.events.map((e) => e.key);
    const buckets = new Map<string, { key: string; label: string; hint: string; auto: boolean; items: Template[] }>();

    for (const t of visible.value) {
        const key = t.event ?? 'custom';
        if (!buckets.has(key)) {
            const meta = props.catalog.events.find((e) => e.key === key);
            buckets.set(key, {
                key,
                label: meta?.label ?? t.event_label,
                hint: meta?.hint ?? '',
                auto: meta?.auto ?? false,
                items: [],
            });
        }
        buckets.get(key)!.items.push(t);
    }

    return [...buckets.values()].sort((a, b) => order.indexOf(a.key) - order.indexOf(b.key));
});

// ===== نموذج الإضافة/التعديل =====
const showModal = ref(false);
const editingId = ref<number | null>(null);
const form = useForm({
    category: 'general',
    event: 'custom',
    title: '',
    body: '',
    is_active: true,
    sort_order: 0,
});

const formVariables = computed(() => props.catalog.variables[form.event] ?? props.catalog.variables.custom ?? []);

const openCreate = (category?: string, event?: string) => {
    editingId.value = null;
    form.reset();
    form.clearErrors();
    form.category = category ?? (activeCategory.value === 'all' ? 'general' : activeCategory.value);
    form.event = event ?? 'custom';
    showModal.value = true;
};

const openEdit = (t: Template) => {
    editingId.value = t.id;
    form.clearErrors();
    form.category = t.category;
    form.event = t.event ?? 'custom';
    form.title = t.title;
    form.body = t.body;
    form.is_active = t.is_active;
    form.sort_order = t.sort_order;
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

const insertVar = (key: string) => {
    form.body = (form.body ?? '') + `{${key}}`;
};

const destroy = (t: Template) => {
    if (confirm(`حذف القالب «${t.title}» من المكتبة؟`)) {
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
                    <p class="mt-1 text-sm font-medium text-slate-600">
                        قوالب مقسّمة حسب القسم (شاليهات / قاعات / مسابح) والمناسبة (ترحيب / تأكيد حجز / فاتورة…). يستعملها النظام تلقائياً، ويمكن إرسالها يدوياً كذلك.
                    </p>
                </div>
                <div class="flex items-center gap-2">
                    <Link href="/admin/notifications" class="inline-flex items-center gap-1.5 rounded-xl border border-slate-200 bg-white px-3.5 py-2 text-sm font-bold text-slate-700 shadow-sm transition hover:bg-slate-50">
                        <Bell class="h-4 w-4" /> الإشعارات
                    </Link>
                    <button type="button" @click="openCreate()" class="inline-flex items-center gap-1.5 rounded-md bg-blue-600 px-4 py-2 text-sm font-bold text-white shadow-sm transition hover:bg-blue-700">
                        <Plus class="h-4 w-4" /> قالب جديد
                    </button>
                </div>
            </div>

            <!-- تنبيه عدم تفعيل الواتساب -->
            <div v-if="!wa_configured" class="flex items-center gap-2 rounded-2xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm font-bold text-amber-700">
                <AlertTriangle class="h-5 w-5 shrink-0" />
                تكامل الواتساب غير مفعّل، لن يتم الإرسال الفعلي. اربط الجهاز من
                <Link href="/admin/settings/whatsapp" class="underline underline-offset-2 hover:text-amber-800">إعدادات الواتساب</Link>.
            </div>

            <!-- تبويبات الأقسام -->
            <div class="flex flex-wrap gap-2 rounded-2xl border border-slate-200 bg-white p-2 shadow-sm">
                <button type="button" @click="activeCategory = 'all'"
                    :class="['inline-flex items-center gap-1.5 rounded-xl px-4 py-2 text-sm font-bold transition', activeCategory === 'all' ? 'brand-gradient text-white shadow' : 'text-slate-600 hover:bg-slate-100']">
                    <Layers class="h-4 w-4" /> الكل
                    <span class="rounded-full bg-black/10 px-1.5 text-[11px]">{{ templates.length }}</span>
                </button>
                <button v-for="c in catalog.categories" :key="c.key" type="button" @click="activeCategory = c.key" :title="c.hint"
                    :class="['inline-flex items-center gap-1.5 rounded-xl px-4 py-2 text-sm font-bold transition', activeCategory === c.key ? 'brand-gradient text-white shadow' : 'text-slate-600 hover:bg-slate-100']">
                    <component :is="categoryIcon(c.key)" class="h-4 w-4" /> {{ c.label }}
                    <span class="rounded-full bg-black/10 px-1.5 text-[11px]">{{ countFor(c.key) }}</span>
                </button>
            </div>

            <!-- المجموعات حسب المناسبة -->
            <section v-for="g in groups" :key="g.key" class="space-y-3">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <div class="flex items-center gap-2">
                        <h2 class="text-lg font-extrabold text-slate-800">{{ g.label }}</h2>
                        <span v-if="g.auto" class="rounded-full bg-emerald-100 px-2.5 py-0.5 text-[11px] font-extrabold text-emerald-700">إرسال آلي</span>
                        <span class="text-xs font-medium text-slate-500">{{ g.hint }}</span>
                    </div>
                    <button type="button" @click="openCreate(activeCategory === 'all' ? undefined : activeCategory, g.key)" class="inline-flex items-center gap-1 rounded-lg border border-slate-200 bg-white px-2.5 py-1 text-xs font-bold text-slate-600 transition hover:bg-slate-50">
                        <Plus class="h-3.5 w-3.5" /> قالب في هذه المناسبة
                    </button>
                </div>

                <div class="grid grid-cols-1 gap-5 sm:grid-cols-2 lg:grid-cols-3">
                    <div v-for="t in g.items" :key="t.id" class="group flex flex-col overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm transition duration-200 hover:-translate-y-1 hover:shadow-lg">
                        <div :class="['h-1.5', t.is_active ? 'brand-gradient' : 'bg-slate-300']"></div>
                        <div class="flex flex-1 flex-col p-5">
                            <div class="mb-3 flex items-start justify-between gap-2">
                                <div class="flex items-center gap-3">
                                    <span :class="['flex h-11 w-11 shrink-0 items-center justify-center rounded-2xl text-white shadow-md', t.is_active ? 'brand-gradient' : 'bg-slate-400']">
                                        <component :is="categoryIcon(t.category)" class="h-5 w-5" />
                                    </span>
                                    <div>
                                        <h3 class="text-base font-extrabold text-slate-900">{{ t.title }}</h3>
                                        <p class="mt-0.5 flex flex-wrap items-center gap-1">
                                            <span class="rounded-full bg-slate-100 px-2 py-0.5 text-[11px] font-bold text-slate-600">{{ t.category_label }}</span>
                                            <span v-if="!t.is_active" class="rounded-full bg-red-100 px-2 py-0.5 text-[11px] font-bold text-red-600">معطّل</span>
                                        </p>
                                    </div>
                                </div>
                                <div class="flex items-center gap-1.5">
                                    <TableActionButton variant="edit" :icon="Pencil" title="تعديل" @click="openEdit(t)" />
                                    <TableActionButton variant="danger" :icon="Trash2" title="حذف" @click="destroy(t)" />
                                </div>
                            </div>

                            <p class="mb-4 line-clamp-5 flex-1 whitespace-pre-line text-sm font-medium leading-6 text-slate-600">{{ t.body }}</p>

                            <div class="flex items-center justify-between border-t border-slate-100 pt-3">
                                <span class="text-xs font-bold text-slate-500" dir="ltr">{{ t.created_at }}</span>
                                <button type="button" @click="openSend(t)" class="inline-flex items-center gap-1.5 rounded-md bg-blue-600 px-3.5 py-1.5 text-sm font-bold text-white shadow-sm transition hover:bg-blue-700">
                                    <Send class="h-4 w-4" /> إرسال
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            <!-- حالة فارغة -->
            <button v-if="groups.length === 0" type="button" @click="openCreate()" class="flex w-full flex-col items-center justify-center gap-2 rounded-2xl border-2 border-dashed border-slate-300 bg-white py-16 text-slate-400 transition hover:border-emerald-300 hover:text-emerald-600">
                <Megaphone class="h-10 w-10" />
                <span class="text-sm font-bold">لا توجد قوالب في هذا القسم — أضف أول قالب</span>
            </button>
        </div>

        <!-- نافذة الإضافة/التعديل -->
        <div v-if="showModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4" @click.self="showModal = false">
            <div class="max-h-[92vh] w-full max-w-2xl overflow-y-auto rounded-2xl bg-white shadow-2xl">
                <div class="sticky top-0 flex items-center justify-between border-b border-slate-100 bg-white px-6 py-4">
                    <h2 class="text-lg font-extrabold text-slate-900">{{ editingId ? 'تعديل قالب' : 'قالب جديد' }}</h2>
                    <button type="button" @click="showModal = false" class="rounded-lg p-1 text-slate-400 hover:bg-slate-100 hover:text-slate-600"><X class="h-5 w-5" /></button>
                </div>
                <form @submit.prevent="submit" class="space-y-4 px-6 py-5">
                    <div class="grid gap-4 sm:grid-cols-2">
                        <div>
                            <label class="mb-1 block text-sm font-bold text-slate-700">القسم</label>
                            <select v-model="form.category" class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm focus:border-emerald-400 focus:outline-none focus:ring-2 focus:ring-emerald-100">
                                <option v-for="c in catalog.categories" :key="c.key" :value="c.key">{{ c.label }}</option>
                            </select>
                            <p v-if="form.errors.category" class="mt-1 text-xs text-red-500">{{ form.errors.category }}</p>
                        </div>
                        <div>
                            <label class="mb-1 block text-sm font-bold text-slate-700">المناسبة</label>
                            <select v-model="form.event" class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm focus:border-emerald-400 focus:outline-none focus:ring-2 focus:ring-emerald-100">
                                <option v-for="e in catalog.events" :key="e.key" :value="e.key">{{ e.label }} — {{ e.hint }}</option>
                            </select>
                            <p v-if="form.errors.event" class="mt-1 text-xs text-red-500">{{ form.errors.event }}</p>
                        </div>
                    </div>

                    <div>
                        <label class="mb-1 block text-sm font-bold text-slate-700">العنوان</label>
                        <input v-model="form.title" type="text" placeholder="مثال: تأكيد حجز شاليه" class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm focus:border-emerald-400 focus:outline-none focus:ring-2 focus:ring-emerald-100" />
                        <p v-if="form.errors.title" class="mt-1 text-xs text-red-500">{{ form.errors.title }}</p>
                    </div>

                    <div>
                        <label class="mb-1 block text-sm font-bold text-slate-700">المحتوى</label>
                        <div class="mb-2 flex flex-wrap items-center gap-1.5">
                            <span class="text-xs font-bold text-slate-600">إدراج متغيّر:</span>
                            <button v-for="v in formVariables" :key="v.key" type="button" @click="insertVar(v.key)" class="rounded-lg bg-slate-200 px-2 py-1 text-[11px] font-bold text-slate-700 transition hover:bg-slate-300">
                                <span dir="ltr">{{ '{' + v.key + '}' }}</span> {{ v.label }}
                            </button>
                        </div>
                        <textarea v-model="form.body" rows="9" placeholder="مرحباً {name} 👋 …" class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm leading-7 focus:border-emerald-400 focus:outline-none focus:ring-2 focus:ring-emerald-100"></textarea>
                        <p v-if="form.errors.body" class="mt-1 text-xs text-red-500">{{ form.errors.body }}</p>
                    </div>

                    <div class="flex flex-wrap items-center justify-between gap-4 rounded-xl bg-slate-50 px-4 py-3">
                        <label class="flex items-center gap-2 text-sm font-bold text-slate-700">
                            <input v-model="form.is_active" type="checkbox" class="h-4 w-4 rounded border-slate-300 text-emerald-600 focus:ring-emerald-200" />
                            مفعّل (يستعمله النظام في الإرسال الآلي)
                        </label>
                        <label class="flex items-center gap-2 text-sm font-bold text-slate-700">
                            الترتيب
                            <input v-model.number="form.sort_order" type="number" min="0" max="999" class="w-20 rounded-xl border border-slate-200 px-2 py-1.5 text-sm" />
                        </label>
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
                    <h2 class="text-lg font-extrabold text-slate-900">إرسال القالب</h2>
                    <button type="button" @click="showSend = false" class="rounded-lg p-1 text-slate-400 hover:bg-slate-100 hover:text-slate-600"><X class="h-5 w-5" /></button>
                </div>
                <form @submit.prevent="submitSend" class="space-y-4 px-6 py-5">
                    <p class="rounded-xl bg-slate-100 px-3 py-2 text-sm font-bold text-slate-700">
                        {{ sendingTemplate?.title }}
                        <span class="text-xs font-medium text-slate-500">— {{ sendingTemplate?.category_label }} / {{ sendingTemplate?.event_label }}</span>
                    </p>

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
                        <label class="mb-1 block text-sm font-bold text-slate-700">العميل</label>
                        <select v-model="sendForm.client_id" class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm focus:border-emerald-400 focus:outline-none focus:ring-2 focus:ring-emerald-100">
                            <option value="">— اختر عميلاً —</option>
                            <option v-for="c in clients" :key="c.id" :value="c.id">{{ c.name }} ({{ c.mobile }})</option>
                        </select>
                        <p v-if="sendForm.errors.client_id" class="mt-1 text-xs text-red-500">{{ sendForm.errors.client_id }}</p>
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
