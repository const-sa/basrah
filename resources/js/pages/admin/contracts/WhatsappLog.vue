<script setup lang="ts">
import { StatPill } from '@/components/data-table';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/vue3';
import { AlertTriangle } from 'lucide-vue-next';
import { computed, ref } from 'vue';

interface Message {
    id: number; to_number: string; body: string;
    purpose: string; purpose_label: string; category_label: string;
    status: string; error: string | null; created_at: string;
}

const props = defineProps<{
    messages: { data: Message[]; links: { url: string | null; label: string; active: boolean }[] };
    filters: Record<string, string | null>;
    purposes: { key: string; label: string }[];
    stats: { messages: number; sent: number; failed: number; conversations: number; limit: number; usage_percent: number };
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'لوحة التحكم', href: '/admin' },
    { title: 'سجل الواتساب', href: '/admin/whatsapp-log' },
];

const filters = ref({ ...props.filters });
const apply = () => router.get('/admin/whatsapp-log', filters.value, { preserveState: true, replace: true });

const nearLimit = computed(() => props.stats.usage_percent >= 80);
</script>

<template>
    <Head title="سجل الواتساب" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="min-h-full space-y-4 bg-slate-100 p-5">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h1 class="text-2xl font-extrabold text-slate-900">سجل رسائل الواتساب</h1>
                    <p class="mt-1 text-sm font-medium text-slate-600">رسائل خدمية فقط — بلا رسائل تسويقية</p>
                </div>
                <Link href="/admin/contracts" class="rounded-md border border-slate-200 bg-white px-4 py-2 text-sm font-bold text-slate-700 hover:bg-slate-50">العقود</Link>
            </div>

            <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
                <StatPill label="الرسائل" :value="stats.messages" variant="primary" />
                <StatPill label="أُرسلت" :value="stats.sent" variant="success" />
                <StatPill label="فشلت" :value="stats.failed" variant="danger" />
                <StatPill label="المحادثات" :value="stats.conversations" variant="dark" />
            </div>

            <!-- استهلاك المحادثات مقابل حد التجديد -->
            <div class="rounded-2xl border p-4 shadow-sm" :class="nearLimit ? 'border-amber-300 bg-amber-50' : 'border-slate-200 bg-white'">
                <div class="mb-2 flex flex-wrap items-center justify-between gap-2">
                    <h2 class="flex items-center gap-1.5 text-sm font-extrabold text-slate-800">
                        <AlertTriangle v-if="nearLimit" class="h-4 w-4 text-amber-600" />
                        استهلاك المحادثات مقابل حد التجديد السنوي
                    </h2>
                    <span class="text-sm font-extrabold" :class="nearLimit ? 'text-amber-700' : 'text-slate-700'" dir="ltr">
                        {{ stats.conversations }} / {{ stats.limit }} ({{ stats.usage_percent }}%)
                    </span>
                </div>
                <div class="h-3 overflow-hidden rounded-full bg-slate-200">
                    <div class="h-full rounded-full transition-all" :class="nearLimit ? 'bg-amber-500' : 'bg-emerald-500'" :style="{ width: `${Math.min(100, stats.usage_percent)}%` }"></div>
                </div>
                <p class="mt-2 text-[11px] font-medium text-slate-500">
                    Meta تسعّر لكل محادثة (24 ساعة) لا لكل رسالة، فالرسائل المتعددة لنفس الرقم في اليوم نفسه محادثة واحدة.
                </p>
            </div>

            <div class="rounded-2xl border border-slate-200 bg-white p-3 shadow-sm">
                <div class="grid gap-2 sm:grid-cols-4">
                    <input v-model="filters.from" @change="apply" type="date" class="rounded-xl border border-slate-200 px-3 py-2.5 text-sm" />
                    <input v-model="filters.to" @change="apply" type="date" class="rounded-xl border border-slate-200 px-3 py-2.5 text-sm" />
                    <select v-model="filters.purpose" @change="apply" class="rounded-xl border border-slate-200 px-3 py-2.5 text-sm">
                        <option :value="null">كل الأغراض</option>
                        <option v-for="p in purposes" :key="p.key" :value="p.key">{{ p.label }}</option>
                    </select>
                    <select v-model="filters.status" @change="apply" class="rounded-xl border border-slate-200 px-3 py-2.5 text-sm">
                        <option :value="null">كل الحالات</option>
                        <option value="sent">أُرسلت</option>
                        <option value="queued">بالطابور</option>
                        <option value="failed">فشلت</option>
                    </select>
                </div>
            </div>

            <div class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="bg-slate-100">
                            <tr>
                                <th class="px-4 py-3 text-right text-xs font-extrabold text-[#1e3a8a]">التاريخ</th>
                                <th class="px-4 py-3 text-right text-xs font-extrabold text-[#1e3a8a]">الرقم</th>
                                <th class="px-4 py-3 text-center text-xs font-extrabold text-[#1e3a8a]">الغرض</th>
                                <th class="px-4 py-3 text-right text-xs font-extrabold text-[#1e3a8a]">الرسالة</th>
                                <th class="px-4 py-3 text-center text-xs font-extrabold text-[#1e3a8a]">الحالة</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="m in messages.data" :key="m.id" class="border-t border-slate-100 hover:bg-slate-50">
                                <td class="px-4 py-2.5 text-xs text-slate-600" dir="ltr">{{ m.created_at }}</td>
                                <td class="px-4 py-2.5 font-bold text-slate-700" dir="ltr">{{ m.to_number }}</td>
                                <td class="px-4 py-2.5 text-center">
                                    <span class="rounded-md bg-sky-100 px-2 py-0.5 text-[11px] font-bold text-sky-700">{{ m.purpose_label }}</span>
                                </td>
                                <td class="max-w-md px-4 py-2.5">
                                    <p class="line-clamp-2 whitespace-pre-wrap text-xs text-slate-600">{{ m.body }}</p>
                                </td>
                                <td class="px-4 py-2.5 text-center">
                                    <span
                                        class="rounded-md px-2 py-0.5 text-[11px] font-bold"
                                        :class="{ 'bg-emerald-100 text-emerald-700': m.status === 'sent', 'bg-amber-100 text-amber-700': m.status === 'queued', 'bg-red-100 text-red-700': m.status === 'failed' }"
                                    >{{ m.status === 'sent' ? 'أُرسلت' : m.status === 'queued' ? 'بالطابور' : 'فشلت' }}</span>
                                </td>
                            </tr>
                            <tr v-if="!messages.data.length"><td colspan="5" class="px-4 py-10 text-center text-sm text-slate-500">لا رسائل</td></tr>
                        </tbody>
                    </table>
                </div>

                <div v-if="messages.links.length > 3" class="flex flex-wrap justify-center gap-1 border-t border-slate-100 p-3">
                    <Link v-for="l in messages.links" :key="l.label" :href="l.url ?? '#'"
                        :class="['rounded-lg px-3 py-1.5 text-xs font-bold', l.active ? 'bg-blue-600 text-white' : l.url ? 'bg-white text-slate-600 ring-1 ring-slate-200' : 'text-slate-300']"
                        v-html="l.label" />
                </div>
            </div>
        </div>
    </AppLayout>
</template>
