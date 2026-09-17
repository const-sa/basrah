<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/vue3';
import {
    AlertTriangle,
    Bell,
    CheckCheck,
    CheckCircle2,
    Eraser,
    ExternalLink,
    Inbox,
    Info,
    ShieldAlert,
    Trash2,
    type LucideIcon,
} from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';

interface Row {
    id: string;
    title: string;
    body: string | null;
    link: string | null;
    category: string;
    category_label: string;
    level: string;
    level_label: string;
    event: string;
    actor_name: string;
    by_you: boolean;
    read: boolean;
    created_at: string | null;
    ago: string | null;
}

interface Option {
    key: string;
    label: string;
}

const props = defineProps<{
    notifications: { data: Row[]; links: { url: string | null; label: string; active: boolean }[] };
    filters: Record<string, string | null>;
    categories: Option[];
    levels: Option[];
    stats: { total: number; unread: number; by_category: Record<string, number> };
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'لوحة التحكم', href: '/admin' },
    { title: 'الإشعارات', href: '/admin/notifications' },
];

const form = ref({
    status: props.filters.status ?? '',
    category: props.filters.category ?? '',
    level: props.filters.level ?? '',
    search: props.filters.search ?? '',
});

// المرشّح يُطبَّق فورًا — الصندوق يُقرأ بحثًا عن خبرٍ بعينه.
let timer: ReturnType<typeof setTimeout> | undefined;
watch(
    form,
    (value) => {
        clearTimeout(timer);
        timer = setTimeout(() => {
            router.get('/admin/notifications', { ...value }, { preserveState: true, replace: true, preserveScroll: true });
        }, 300);
    },
    { deep: true },
);

const styleMap: Record<string, { icon: LucideIcon; ring: string; chip: string }> = {
    success: { icon: CheckCircle2, ring: 'bg-emerald-100 text-emerald-600', chip: 'bg-emerald-50 text-emerald-700 ring-emerald-200' },
    info: { icon: Info, ring: 'bg-sky-100 text-sky-600', chip: 'bg-sky-50 text-sky-700 ring-sky-200' },
    warning: { icon: AlertTriangle, ring: 'bg-amber-100 text-amber-600', chip: 'bg-amber-50 text-amber-700 ring-amber-200' },
    danger: { icon: ShieldAlert, ring: 'bg-rose-100 text-rose-600', chip: 'bg-rose-50 text-rose-700 ring-rose-200' },
};

const styleFor = (level: string) => styleMap[level] ?? styleMap.info;

const unreadIn = (key: string) => props.stats.by_category?.[key] ?? 0;

const pickCategory = (key: string) => (form.value.category = form.value.category === key ? '' : key);

const markAllRead = () => router.patch('/admin/notifications/read-all', {}, { preserveScroll: true });

const clearRead = () => {
    if (confirm('سيُحذف كل ما قرأته من الصندوق. هل تتابع؟')) {
        router.delete('/admin/notifications/clear', { preserveScroll: true });
    }
};

const remove = (row: Row) => router.delete(`/admin/notifications/${row.id}`, { preserveScroll: true });

const hasFilters = computed(() => Object.values(form.value).some((v) => v !== ''));

const reset = () => (form.value = { status: '', category: '', level: '', search: '' });
</script>

<template>
    <Head title="الإشعارات" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="space-y-5 p-5">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h1 class="flex items-center gap-2 text-2xl font-extrabold text-slate-900">
                        <Bell class="h-6 w-6 text-emerald-600" /> الإشعارات
                    </h1>
                    <p class="mt-1 text-sm text-slate-500">
                        <template v-if="stats.unread">لديك {{ stats.unread }} إشعار غير مقروء من أصل {{ stats.total }}</template>
                        <template v-else>لا إشعارات غير مقروءة — الصندوق يحوي {{ stats.total }} إشعارًا</template>
                    </p>
                </div>

                <div class="flex flex-wrap items-center gap-2">
                    <button
                        type="button"
                        @click="markAllRead"
                        :disabled="!stats.unread"
                        class="flex items-center gap-1.5 rounded-lg border border-slate-300 bg-white px-3 py-1.5 text-xs font-bold text-slate-700 transition hover:border-emerald-500 hover:bg-emerald-50 hover:text-emerald-700 disabled:cursor-not-allowed disabled:opacity-50"
                    >
                        <CheckCheck class="h-4 w-4" /> تعليم الكل كمقروء
                    </button>
                    <button
                        type="button"
                        @click="clearRead"
                        :disabled="stats.total === stats.unread"
                        class="flex items-center gap-1.5 rounded-lg border border-slate-300 bg-white px-3 py-1.5 text-xs font-bold text-slate-700 transition hover:border-rose-400 hover:bg-rose-50 hover:text-rose-700 disabled:cursor-not-allowed disabled:opacity-50"
                    >
                        <Eraser class="h-4 w-4" /> مسح المقروء
                    </button>
                </div>
            </div>

            <!-- الأقسام: كل قسمٍ بعدد ما لم يُقرأ فيه — يُفتح أثقلها أولاً -->
            <div class="flex flex-wrap gap-2">
                <button
                    v-for="c in categories"
                    :key="c.key"
                    type="button"
                    @click="pickCategory(c.key)"
                    class="flex items-center gap-1.5 rounded-full px-3 py-1.5 text-xs font-bold ring-1 transition"
                    :class="
                        form.category === c.key
                            ? 'bg-slate-900 text-white ring-slate-900'
                            : 'bg-white text-slate-600 ring-slate-200 hover:bg-slate-50'
                    "
                >
                    {{ c.label }}
                    <span
                        v-if="unreadIn(c.key)"
                        class="flex h-5 min-w-5 items-center justify-center rounded-full px-1 text-[10px] font-extrabold"
                        :class="form.category === c.key ? 'bg-white text-slate-900' : 'bg-rose-500 text-white'"
                    >
                        {{ unreadIn(c.key) }}
                    </span>
                </button>
            </div>

            <div class="flex flex-wrap items-center gap-2 rounded-2xl border border-slate-200 bg-white p-3 shadow-sm">
                <input
                    v-model="form.search"
                    type="search"
                    placeholder="ابحث في نص الإشعار أو صاحب الإجراء…"
                    class="min-w-[14rem] flex-1 rounded-lg border border-slate-300 px-3 py-1.5 text-sm focus:border-[#0BA6CE] focus:outline-none"
                />
                <select v-model="form.status" class="rounded-lg border border-slate-300 px-3 py-1.5 text-sm font-bold text-slate-700">
                    <option value="">كل الحالات</option>
                    <option value="unread">غير مقروء</option>
                    <option value="read">مقروء</option>
                </select>
                <select v-model="form.level" class="rounded-lg border border-slate-300 px-3 py-1.5 text-sm font-bold text-slate-700">
                    <option value="">كل الدرجات</option>
                    <option v-for="l in levels" :key="l.key" :value="l.key">{{ l.label }}</option>
                </select>
                <button
                    v-if="hasFilters"
                    type="button"
                    @click="reset"
                    class="rounded-lg px-3 py-1.5 text-xs font-bold text-slate-500 hover:text-slate-800"
                >
                    إلغاء المرشّحات
                </button>
            </div>

            <!-- قائمة الإشعارات -->
            <div class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                <ul class="divide-y divide-slate-100">
                    <li
                        v-for="n in notifications.data"
                        :key="n.id"
                        :class="['group flex items-start gap-3 px-4 py-4 transition hover:bg-slate-50', !n.read ? 'bg-emerald-50/40' : '']"
                    >
                        <span :class="['flex h-10 w-10 shrink-0 items-center justify-center rounded-full', styleFor(n.level).ring]">
                            <component :is="styleFor(n.level).icon" class="h-5 w-5" />
                        </span>

                        <div class="min-w-0 flex-1">
                            <div class="flex flex-wrap items-center gap-2">
                                <p class="font-bold text-slate-900">{{ n.title }}</p>
                                <span v-if="!n.read" class="h-2 w-2 shrink-0 rounded-full bg-rose-500" title="غير مقروء" />
                                <span class="rounded-full px-2 py-0.5 text-[10px] font-bold ring-1" :class="styleFor(n.level).chip">
                                    {{ n.category_label }}
                                </span>
                            </div>
                            <p v-if="n.body" class="mt-0.5 text-sm text-slate-600">{{ n.body }}</p>
                            <p class="mt-1 text-xs text-slate-400">
                                <span :class="n.by_you ? 'font-bold text-slate-500' : ''">{{ n.by_you ? 'بواسطتك' : n.actor_name }}</span>
                                · <span :title="n.created_at ?? ''">{{ n.ago }}</span>
                            </p>
                        </div>

                        <div class="flex shrink-0 items-center gap-1">
                            <Link
                                :href="`/admin/notifications/${n.id}/read`"
                                class="flex h-8 w-8 items-center justify-center rounded-lg text-slate-400 transition hover:bg-[#E6F7FB] hover:text-[#0BA6CE]"
                                :title="n.link ? 'فتح السجل' : 'تعليم كمقروء'"
                            >
                                <ExternalLink v-if="n.link" class="h-4 w-4" />
                                <CheckCheck v-else class="h-4 w-4" />
                            </Link>
                            <button
                                type="button"
                                @click="remove(n)"
                                title="حذف الإشعار"
                                class="flex h-8 w-8 items-center justify-center rounded-lg text-slate-400 transition hover:bg-rose-50 hover:text-rose-600"
                            >
                                <Trash2 class="h-4 w-4" />
                            </button>
                        </div>
                    </li>

                    <li v-if="!notifications.data.length" class="px-4 py-14 text-center">
                        <Inbox class="mx-auto h-10 w-10 text-slate-300" />
                        <p class="mt-2 text-sm text-slate-400">
                            {{ hasFilters ? 'لا إشعارات مطابقة للمرشّحات.' : 'لا توجد إشعارات حالياً.' }}
                        </p>
                    </li>
                </ul>
            </div>

            <div v-if="notifications.links.length > 3" class="flex flex-wrap justify-center gap-1">
                <Link
                    v-for="link in notifications.links"
                    :key="link.label"
                    :href="link.url ?? '#'"
                    preserve-scroll
                    class="rounded-lg px-3 py-1.5 text-xs font-bold"
                    :class="
                        link.active
                            ? 'bg-slate-900 text-white'
                            : link.url
                              ? 'bg-white text-slate-600 ring-1 ring-slate-200 hover:bg-slate-50'
                              : 'cursor-default bg-white text-slate-300'
                    "
                    v-html="link.label"
                />
            </div>
        </div>
    </AppLayout>
</template>
