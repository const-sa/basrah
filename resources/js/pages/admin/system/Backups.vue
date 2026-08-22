<script setup lang="ts">
import { usePermissions } from '@/composables/usePermissions';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { AlertTriangle, Clock, DatabaseBackup, Download, HardDrive, Loader2, RotateCcw, Trash2, Upload } from 'lucide-vue-next';
import { computed, ref } from 'vue';

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

const props = defineProps<{
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
        driver: string;
        upload_max_mb: number;
        extensions: string[];
    };
}>();

const { can } = usePermissions();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'لوحة التحكم', href: '/admin' },
    { title: 'الإعدادات', href: '/admin/settings/general' },
    { title: 'النسخ الاحتياطي', href: '/admin/backups' },
];

const running = ref(false);
const restoring = ref<number | null>(null);

const runNow = () => {
    if (!confirm('أخذ نسخة احتياطية الآن؟ قد تستغرق دقيقة على قاعدة كبيرة.')) return;

    running.value = true;
    router.post('/admin/backups', {}, { preserveScroll: true, onFinish: () => (running.value = false) });
};

const remove = (backup: BackupRow) => {
    if (!confirm(`حذف النسخة ${backup.filename}؟ لا يمكن التراجع.`)) return;

    router.delete(`/admin/backups/${backup.id}`, { preserveScroll: true });
};

/* ── رفع قاعدة بيانات من الخارج ─────────────────────────────────────── */

const accept = computed(() => props.stats.extensions.map((e) => `.${e}`).join(','));

const upload = useForm<{ file: File | null; restore: boolean }>({ file: null, restore: false });

const pickFile = (event: Event) => {
    upload.file = (event.target as HTMLInputElement).files?.[0] ?? null;
};

const submitUpload = () => {
    if (!upload.file) return;

    // الاستعادة تكتب فوق القاعدة كلها — تأكيدٌ صريح قبلها لا نافذةٌ تُغلق بالخطأ.
    if (upload.restore && !confirm(`استبدال قاعدة البيانات الحالية بمحتوى «${upload.file.name}»؟\n\nكل البيانات الحالية ستُستبدل. تُؤخذ نسخة أمان تلقائيًا قبل التنفيذ.`)) {
        return;
    }

    upload.post('/admin/backups/upload', {
        preserveScroll: true,
        forceFormData: true,
        onSuccess: () => {
            upload.reset();
            const input = document.getElementById('backup-file') as HTMLInputElement | null;
            if (input) input.value = '';
        },
    });
};

/* ── الاستعادة من نسخة محفوظة ───────────────────────────────────────── */

const restore = (backup: BackupRow) => {
    if (!confirm(`استعادة القاعدة من «${backup.filename}»؟\n\nكل البيانات الحالية ستُستبدل بما في هذه النسخة. تُؤخذ نسخة أمان تلقائيًا قبل التنفيذ.`)) {
        return;
    }

    restoring.value = backup.id;
    router.post(
        `/admin/backups/${backup.id}/restore`,
        {},
        { preserveScroll: true, onFinish: () => (restoring.value = null) },
    );
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
                <div class="flex flex-wrap items-center gap-2">
                    <!-- تنزيل مباشر: تُؤخذ نسخة طازجة ويصل ملفها بنقرة واحدة -->
                    <a
                        v-if="can('backups.create')"
                        href="/admin/backups/export"
                        class="inline-flex items-center gap-1.5 rounded-md bg-emerald-600 px-4 py-2 text-sm font-bold text-white hover:bg-emerald-700"
                    >
                        <Download class="h-4 w-4" /> تنزيل قاعدة البيانات
                    </a>
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

            <!-- رفع قاعدة بيانات من الخارج -->
            <form
                v-if="can('backups.create')"
                @submit.prevent="submitUpload"
                class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm"
            >
                <div class="mb-1 flex items-center gap-2">
                    <Upload class="h-5 w-5 text-blue-600" />
                    <h2 class="text-lg font-bold text-slate-800">رفع قاعدة البيانات</h2>
                </div>
                <p class="mb-4 text-xs font-medium text-slate-500">
                    ملف نسخة ({{ stats.extensions.map((e) => '.' + e).join(' · ') }}) بحدّ أقصى
                    <span dir="ltr">{{ stats.upload_max_mb }}MB</span>. يُحفظ ضمن النسخ، ويُستعاد فورًا إن أشّرت على الخانة.
                </p>

                <div class="grid gap-4 lg:grid-cols-[1fr_auto] lg:items-end">
                    <div>
                        <input
                            id="backup-file"
                            type="file"
                            :accept="accept"
                            @change="pickFile"
                            class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm file:me-3 file:rounded-lg file:border-0 file:bg-slate-100 file:px-3 file:py-1.5 file:text-xs file:font-bold file:text-slate-700 focus:border-blue-400 focus:outline-none focus:ring-2 focus:ring-blue-100"
                        />
                        <p v-if="upload.errors.file" class="mt-1 text-xs font-bold text-red-500">{{ upload.errors.file }}</p>

                        <label class="mt-3 flex cursor-pointer items-start gap-2 text-sm font-bold text-slate-700">
                            <input v-model="upload.restore" type="checkbox" :disabled="!can('backups.restore')" class="mt-0.5 h-4 w-4 rounded border-slate-300 text-red-600 focus:ring-red-200 disabled:opacity-50" />
                            <span>
                                استعادة القاعدة فور الرفع
                                <span class="block text-[11px] font-medium text-red-600">
                                    يستبدل كل البيانات الحالية بما في الملف. تُؤخذ نسخة أمان تلقائيًا قبل التنفيذ.
                                </span>
                                <span v-if="!can('backups.restore')" class="block text-[11px] font-medium text-slate-500">
                                    ليس لديك صلاحية الاستعادة — سيُحفظ الملف فقط.
                                </span>
                            </span>
                        </label>
                    </div>

                    <button
                        type="submit"
                        :disabled="!upload.file || upload.processing"
                        class="inline-flex items-center justify-center gap-1.5 rounded-md px-5 py-2 text-sm font-bold text-white transition disabled:opacity-50"
                        :class="upload.restore ? 'bg-red-600 hover:bg-red-700' : 'bg-blue-600 hover:bg-blue-700'"
                    >
                        <Loader2 v-if="upload.processing" class="h-4 w-4 animate-spin" />
                        <Upload v-else class="h-4 w-4" />
                        {{ upload.processing ? 'جارٍ الرفع…' : upload.restore ? 'رفع واستعادة' : 'رفع الملف' }}
                    </button>
                </div>

                <!-- شريط تقدّم الرفع: ملفٌ بمئات الميجابايت بلا مؤشر يبدو معلّقًا -->
                <div v-if="upload.progress" class="mt-3 h-1.5 overflow-hidden rounded-full bg-slate-100">
                    <div class="h-full rounded-full bg-blue-600 transition-all" :style="{ width: `${upload.progress.percentage}%` }"></div>
                </div>

                <p class="mt-3 text-[11px] font-medium text-slate-500">
                    القاعدة الحالية من نوع <span class="font-bold" dir="ltr">{{ stats.driver }}</span> — ونسخةُ قاعدةٍ من نوع آخر لا تصلح مكانها.
                </p>
            </form>

            <!-- السجل -->
            <div class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="bg-slate-100">
                            <tr>
                                <th class="px-4 py-3 text-right text-xs font-extrabold text-[#1e3a8a]">الملف</th>
                                <th class="px-4 py-3 text-center text-xs font-extrabold text-[#1e3a8a]">الحالة</th>
                                <th class="px-4 py-3 text-right text-xs font-extrabold text-[#1e3a8a]">المصدر</th>
                                <th class="px-4 py-3 text-center text-xs font-extrabold text-[#1e3a8a]">الحجم</th>
                                <th class="px-4 py-3 text-center text-xs font-extrabold text-[#1e3a8a]">المدة</th>
                                <th class="px-4 py-3 text-center text-xs font-extrabold text-[#1e3a8a]">إجراءات</th>
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
                                            v-if="b.exists && can('backups.restore')"
                                            type="button"
                                            @click="restore(b)"
                                            :disabled="restoring !== null"
                                            class="inline-flex items-center gap-1 rounded-lg bg-amber-50 px-2.5 py-1.5 text-[11px] font-bold text-amber-700 hover:bg-amber-100 disabled:opacity-50"
                                        >
                                            <Loader2 v-if="restoring === b.id" class="h-3.5 w-3.5 animate-spin" />
                                            <RotateCcw v-else class="h-3.5 w-3.5" />
                                            {{ restoring === b.id ? 'جارٍ…' : 'استعادة' }}
                                        </button>
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
