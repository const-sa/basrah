<script setup lang="ts">
import WhatsappAccountCard, { type Account, type Option, type UnitOption } from '@/components/whatsapp/WhatsappAccountCard.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link } from '@inertiajs/vue3';
import { Globe, History, Plus, SlidersHorizontal } from 'lucide-vue-next';
import { ref } from 'vue';

defineProps<{
    accounts: Account[];
    general: { configured: boolean; number: string | null; name: string; sent: number; failed: number };
    sections: Option[];
    units: UnitOption[];
    drivers: Option[];
    lookup_enabled: boolean;
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'لوحة التحكم', href: '/admin' },
    { title: 'إعدادات الواتساب', href: '/admin/settings/whatsapp' },
    { title: 'أرقام الأقسام', href: '/admin/settings/whatsapp/accounts' },
];

const adding = ref(false);
</script>

<template>
    <Head title="أرقام واتساب الأقسام" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="min-h-full space-y-5 bg-slate-100 p-5">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h1 class="text-2xl font-extrabold text-slate-900">أرقام واتساب الأقسام</h1>
                    <p class="mt-1 text-sm font-medium text-slate-600">
                        لكل قسمٍ أو قاعةٍ رقمه واشتراكه: تخرج رسائل الحجز من رقم وحدته، وإلا من رقم قسمه، وإلا من البوابة العامة.
                    </p>
                </div>
                <div class="flex items-center gap-2">
                    <Link href="/admin/whatsapp-log" class="inline-flex items-center gap-1.5 rounded-md border border-slate-200 bg-white px-4 py-2 text-sm font-bold text-slate-700 hover:bg-slate-50">
                        <History class="h-4 w-4" /> السجل
                    </Link>
                    <button v-if="!adding" type="button" @click="adding = true" class="inline-flex items-center gap-1.5 rounded-md bg-blue-600 px-4 py-2 text-sm font-bold text-white shadow-sm hover:bg-blue-700">
                        <Plus class="h-4 w-4" /> إضافة رقم
                    </button>
                </div>
            </div>

            <!-- البوابة العامة: ما لا رقم لقسمه -->
            <div class="flex flex-wrap items-center gap-4 rounded-2xl border border-slate-200 bg-white px-5 py-4 shadow-sm">
                <span class="flex h-10 w-10 items-center justify-center rounded-full bg-slate-700 text-white"><Globe class="h-5 w-5" /></span>
                <div class="flex-1">
                    <p class="font-extrabold text-slate-800">البوابة العامة — {{ general.name }}</p>
                    <p class="text-xs font-bold text-slate-500">
                        لكل ما ليس له رقم قسم
                        <span v-if="general.number" dir="ltr" class="ms-2 text-slate-700">+{{ general.number }}</span>
                        · هذا الشهر: {{ general.sent }} أُرسلت، {{ general.failed }} فشلت
                    </p>
                </div>
                <span :class="['rounded-full px-3 py-1 text-xs font-extrabold', general.configured ? 'bg-emerald-100 text-emerald-700' : 'bg-amber-100 text-amber-700']">
                    {{ general.configured ? 'مهيّأة' : 'غير مهيّأة' }}
                </span>
                <Link href="/admin/settings/whatsapp" class="inline-flex items-center gap-1.5 rounded-xl border border-slate-200 px-3 py-2 text-sm font-bold text-slate-700 hover:bg-slate-50">
                    <SlidersHorizontal class="h-4 w-4" /> إعداداتها
                </Link>
            </div>

            <WhatsappAccountCard
                v-if="adding"
                :sections="sections"
                :units="units"
                :drivers="drivers"
                :lookup-enabled="lookup_enabled"
                @saved="adding = false"
                @cancel="adding = false"
            />

            <WhatsappAccountCard
                v-for="account in accounts"
                :key="account.id"
                :account="account"
                :sections="sections"
                :units="units"
                :drivers="drivers"
                :lookup-enabled="lookup_enabled"
            />

            <p v-if="!accounts.length && !adding" class="rounded-2xl border-2 border-dashed border-slate-300 py-10 text-center text-sm font-bold text-slate-400">
                لا أرقام للأقسام بعد — كل الرسائل تخرج من البوابة العامة.
            </p>
        </div>
    </AppLayout>
</template>
