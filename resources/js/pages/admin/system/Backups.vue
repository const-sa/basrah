<script setup lang="ts">
import { usePermissions } from '@/composables/usePermissions';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/vue3';
import { AlertTriangle, Clock, DatabaseBackup, Download, HardDrive, Loader2, Trash2 } from 'lucide-vue-next';
import { ref } from 'vue';

interface BackupRow {
    id: number;
    filename: string;
    size: number;
    size_label: string;
    status: string;
    status_label: string;
    trigger_label: string;
    method: string | null;
    driver: string | null;
    duration: number;
    error: string | null;
    created_at: string | null;
    creator: string | null;
    exists: boolean;
}

defineProps<{
    backups: { data: BackupRow[]; links: { url: string | null; label: string; active: boolean }[] };
    stats: {
        total: number;
        failed: number;
        last_at: string | null;
        last_size: string | null;
        is_stale: boolean;
        keep: number;
        schedule: string;
        cron_hint: string;
    };
}>();

const { can } = usePermissions();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'لوحة التحكم', href: '/admin' },
    { title: 'النسخ الاحتياطي', href: '/admin/backups' },
];

const running = ref(false);

const runNow = () => {
    if (!confirm('أخذ نسخة احتياطية الآن؟ قد تستغرق دقيقة على قاعدة كبيرة.')) return;

    running.value = true;
    router.post('/admin/backups', {}, { preserveScroll: true, onFinish: () => (running.value = false) });
};

const remove = (backup: BackupRow) => {
    if (!confirm(`حذف النسخة ${backup.filename}؟ لا يمكن التراجع.`)) return;

    router.delete(`/admin/backups/${backup.id}`, { preserveScroll: true });
};

const statusClass = (status: string) =>
    ({ completed: 'bg-emerald-100 text-emerald-700', running: 'bg-amber-100 text-amber-700', failed: 'bg-red-100 text-red-700' })[status] ??
    'bg-slate-100 text-slate-700';
</script>

<template>
    <Head title="النسخ الاحتياطي" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="min-h-full space-y-4 bg-slate-100 p-5">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h1 class="flex items-center gap-2 text-2xl font-extrabold text-slate-900">
                        <DatabaseBackup class="h-6 w-6 text-slate-700" /> النسخ الاحتياطي
                    </h1>
                    <p class="mt-1 text-sm font-medium text-slate-600">
                        نسخة كل ليلة الساعة {{ stats.schedule }}، ويُحتفظ بآخر {{ stats.keep }} نسخة. والنسخة ملفٌ يحمل النظام كله.
                    </p>
                </div>
                <button
                    v-if="can('backups.create')"
                    type="button"
                    @click="runNow"
                    :disabled="running"
                    class="inline-flex items-center gap-1.5 rounded-md bg-blue-600 px-4 py-2 text-sm font-bold text-white hover:bg-blue-700 disabled:opacity-60"
                >
                    <Loader2 v-if="running" class="h-4 w-4 animate-spin" />
                    <DatabaseBackup v-else class="h-4 w-4" />
                    {{ running ? 'جارٍ أخذ النسخة…' : 'نسخة الآن' }}
                </button>
            </div>

            <!-- تنبيه التأخّر: أخطر عطلٍ في النسخ هو الصامت -->
            <div
                v-if="stats.is_stale"
                class="flex items-start gap-2 rounded-xl border-2 border-amber-200 bg-amber-50 p-4 text-sm font-bold text-amber-800"
            >
                <AlertTriangle class="mt-0.5 h-5 w-5 shrink-0" />
                <div>
                    <p>{{ stats.last_at ? 'مضى أكثر من يومين على آخر نسخة احتياطية.' : 'لا توجد نسخة احتياطية بعد.' }}</p>
                    <p class="mt-1 text-xs font-medium">
                        تأكّد من وجود سطر المجدول في cron على الخادم:
                        <code class="mt-1 block rounded-lg bg-white/70 px-2 py-1 text-[11px] text-slate-700" dir="ltr">
                            {{ stats.cron_hint }}
                        </code>
                    </p>
                </div>
            </div>

            <!-- المؤشرات -->
            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <div class="rounded-2xl border border-slate-200 bg-white p-4">
                    <div class="text-xs font-bold text-slate-500">آخر نسخة</div>
                    <div class="mt-1 text-lg font-extrabold text-slate-900" dir="ltr">{{ stats.last_at ?? '—' }}</div>
                    <div class="mt-0.5 text-[11px] font-medium text-slate-500">{{ stats.last_size ?? 'لا نسخ بعد' }}</div>
                </div>
                <div class="rounded-2xl border border-slate-200 bg-white p-4">
                    <div class="text-xs font-bold text-slate-500">النسخ المحفوظة</div>
                    <div class="mt-1 flex items-center gap-1.5 text-2xl font-extrabold text-slate-900">
                        <HardDrive class="h-5 w-5 text-slate-400" />
                        <span dir="ltr">{{ stats.total }}</span>
                    </div>
                </div>
                <div class="rounded-2xl border border-slate-200 bg-white p-4">
                    <div class="text-xs font-bold text-slate-500">نسخ فاشلة</div>
                    <div class="mt-1 text-2xl font-extrabold" :class="stats.failed ? 'text-red-700' : 'text-slate-900'" dir="ltr">
                        {{ stats.failed }}
                    </div>
                </div>
                <div class="rounded-2xl border border-slate-200 bg-white p-4">
                    <div class="text-xs font-bold text-slate-500">الجدولة</div>
                    <div class="mt-1 flex items-center gap-1.5 text-lg font-extrabold text-slate-900">
                        <Clock class="h-4 w-4 text-slate-400" />
                        <span dir="ltr">{{ stats.schedule }}</span>
                    </div>
                    <div class="mt-0.5 text-[11px] font-medium text-slate-500">يوميًا · يُحتفظ بآخر {{ stats.keep }}</div>
                </div>
            </div>

            <!-- السجل -->
            <div class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="bg-slate-100">
                            <tr>
                                <th class="px-4 py-3 text-right text-xs font-extrabold text-slate-700">الملف</th>
                                <th class="px-4 py-3 text-center text-xs font-extrabold text-slate-700">الحالة</th>
                                <th class="px-4 py-3 text-right text-xs font-extrabold text-slate-700">المصدر</th>
                                <th class="px-4 py-3 text-center text-xs font-extrabold text-slate-700">الحجم</th>
                                <th class="px-4 py-3 text-center text-xs font-extrabold text-slate-700">المدة</th>
                                <th class="px-4 py-3 text-center text-xs font-extrabold text-slate-700">إجراءات</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="b in backups.data" :key="b.id" class="border-t border-slate-100 hover:bg-slate-50">
                                <td class="px-4 py-2.5">
                                    <div class="font-bold text-slate-800" dir="ltr">{{ b.filename }}</div>
                                    <div class="text-[11px] text-slate-500" dir="ltr">{{ b.created_at }}</div>
                                    <div v-if="b.error" class="mt-1 text-[11px] font-bold text-red-600">{{ b.error }}</div>
                                </td>
                                <td class="px-4 py-2.5 text-center">
                                    <span class="inline-flex rounded-md px-2 py-0.5 text-[11px] font-bold" :class="statusClass(b.status)">
                                        {{ b.status_label }}
                                    </span>
                                </td>
                                <td class="px-4 py-2.5 text-xs text-slate-600">
                                    {{ b.trigger_label }}
                                    <div class="text-[11px] text-slate-400">{{ b.creator ?? 'المجدول' }} · {{ b.method ?? '—' }}</div>
                                </td>
                                <td class="px-4 py-2.5 text-center text-xs font-bold text-slate-700" dir="ltr">{{ b.size_label }}</td>
                                <td class="px-4 py-2.5 text-center text-xs text-slate-500" dir="ltr">{{ b.duration }}s</td>
                                <td class="px-4 py-2.5">
                                    <div class="flex items-center justify-center gap-1.5">
                                        <a
                                            v-if="b.exists"
                                            :href="`/admin/backups/${b.id}/download`"
                                            class="inline-flex items-center gap-1 rounded-lg bg-slate-100 px-2.5 py-1.5 text-[11px] font-bold text-slate-700 hover:bg-slate-200"
                                        >
                                            <Download class="h-3.5 w-3.5" /> تنزيل
                                        </a>
                                        <span v-else-if="b.status === 'completed'" class="text-[11px] text-slate-400">الملف غير موجود</span>
                                        <button
                                            v-if="can('backups.delete')"
                                            type="button"
                                            @click="remove(b)"
                                            class="inline-flex items-center gap-1 rounded-lg bg-red-50 px-2.5 py-1.5 text-[11px] font-bold text-red-700 hover:bg-red-100"
                                        >
                                            <Trash2 class="h-3.5 w-3.5" /> حذف
                                        </button>
                                    </div>
                                </td>
                            </tr>
                            <tr v-if="!backups.data.length">
                                <td colspan="6" class="px-4 py-12 text-center text-sm text-slate-500">لا نسخ احتياطية بعد</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <div v-if="backups.links.length > 3" class="flex flex-wrap justify-center gap-1">
                <Link
                    v-for="link in backups.links"
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
