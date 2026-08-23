<script setup lang="ts">
/**
 * Tab strip shared by the settings screens, mirroring the sidebar's settings
 * group so the same set of destinations reads the same in both places.
 *
 * Tabs the account cannot open are dropped rather than shown disabled — the
 * server guards each route anyway, and a dead tab only invites a 403.
 */
import { usePermissions } from '@/composables/usePermissions';
import { Link, usePage } from '@inertiajs/vue3';
import { Clock, CreditCard, DatabaseBackup, MessageCircle, SlidersHorizontal } from 'lucide-vue-next';
import { computed } from 'vue';

const page = usePage();
const { can } = usePermissions();

const tabs = [
    { label: 'الإعدادات العامة', href: '/admin/settings/general', icon: SlidersHorizontal, perm: 'settings.view' },
    { label: 'أوقات الحجز', href: '/admin/settings/booking-times', icon: Clock, perm: 'settings.view' },
    { label: 'واتساب', href: '/admin/settings/whatsapp', icon: MessageCircle, perm: 'settings.view' },
    { label: 'طرق الدفع', href: '/admin/settings/payment-methods', icon: CreditCard, perm: 'payment_methods.view' },
    { label: 'النسخ الاحتياطي', href: '/admin/backups', icon: DatabaseBackup, perm: 'backups.view' },
];

const visible = computed(() => tabs.filter((t) => can(t.perm)));

// Longest match wins, so a nested page keeps its own tab lit rather than
// lighting every tab whose path is a prefix of it.
const activeHref = computed(() => {
    const url = page.url.split('?')[0];

    return visible.value
        .filter((t) => url === t.href || url.startsWith(`${t.href}/`))
        .sort((a, b) => b.href.length - a.href.length)[0]?.href;
});
</script>

<template>
    <div class="flex flex-wrap items-center gap-2">
        <Link
            v-for="tab in visible"
            :key="tab.href"
            :href="tab.href"
            :class="[
                'inline-flex items-center gap-1.5 rounded-md px-3.5 py-2 text-sm font-bold transition',
                tab.href === activeHref
                    ? 'bg-blue-600 text-white shadow-sm'
                    : 'border border-slate-200 bg-white text-slate-700 hover:border-blue-200 hover:bg-slate-50 hover:text-blue-600',
            ]"
        >
            <component :is="tab.icon" class="h-4 w-4" />
            <span>{{ tab.label }}</span>
        </Link>
    </div>
</template>
