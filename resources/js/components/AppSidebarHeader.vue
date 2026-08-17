<script setup lang="ts">
import PageSearch from '@/components/PageSearch.vue';
import { Breadcrumb, BreadcrumbItem, BreadcrumbLink, BreadcrumbList, BreadcrumbPage, BreadcrumbSeparator } from '@/components/ui/breadcrumb';
import AppLogo from '@/components/AppLogo.vue';
import { useLocale } from '@/composables/useLocale';
import type { BreadcrumbItemType, SharedData } from '@/types';
import { Link, usePage } from '@inertiajs/vue3';
import { Bell, Languages } from 'lucide-vue-next';
import { computed } from 'vue';

defineProps<{
    breadcrumbs?: BreadcrumbItemType[];
}>();

const page = usePage<SharedData>();
const user = page.props.auth?.user;
const initial = (user?.name || user?.email || '?').charAt(0).toUpperCase();
const unread = computed(() => page.props.notificationsUnread ?? 0);

const { t, toggle, locale } = useLocale();
</script>

<template>
    <header class="sticky top-0 z-30 flex h-[58px] shrink-0 items-center justify-between gap-2 border-b border-slate-800 bg-slate-900 px-4 text-white transition-[width,height] ease-linear group-has-[[data-collapsible=icon]]/sidebar-wrapper:h-12 md:px-6 print:hidden">
        <div class="flex items-center gap-3 text-white">
            <!-- الشعار انتقل إلى الترويسة مع القائمة: كان رأس الشريط الجانبي،
                 وبزواله يبقى للنظام هويةٌ ظاهرة وطريقٌ إلى لوحة التحكم. -->
            <Link href="/admin" class="flex shrink-0 items-center rounded-lg transition hover:brightness-110">
                <AppLogo />
            </Link>
            <template v-if="breadcrumbs && breadcrumbs.length > 0">
                <Breadcrumb>
                    <BreadcrumbList class="text-slate-300">
                        <template v-for="(item, index) in breadcrumbs" :key="index">
                            <BreadcrumbItem>
                                <template v-if="index === breadcrumbs.length - 1">
                                    <BreadcrumbPage class="text-white">{{ item.title }}</BreadcrumbPage>
                                </template>
                                <template v-else>
                                    <BreadcrumbLink :href="item.href" class="text-slate-300 hover:text-white">{{ item.title }}</BreadcrumbLink>
                                </template>
                            </BreadcrumbItem>
                            <BreadcrumbSeparator v-if="index !== breadcrumbs.length - 1" />
                        </template>
                    </BreadcrumbList>
                </Breadcrumb>
            </template>
        </div>

        <!-- بحث عن صفحة (وسط الهيدر) -->
        <div class="mx-2 hidden flex-1 justify-center sm:flex md:mx-6">
            <PageSearch />
        </div>

        <div class="flex items-center gap-3">
            <button type="button" @click="toggle" :title="t('header.switch_lang')"
                class="flex items-center gap-1.5 rounded-full border border-white/15 bg-white/10 px-3 py-1.5 text-xs font-bold text-white transition hover:bg-white/20">
                <Languages class="h-4 w-4" />
                <span>{{ locale === 'ar' ? 'EN' : 'ع' }}</span>
            </button>
            <Link href="/admin/notifications" :title="t('header.notifications')"
                class="relative flex h-9 w-9 items-center justify-center rounded-full border border-white/15 bg-white/10 text-white transition hover:bg-white/20">
                <Bell class="h-5 w-5" />
                <span v-if="unread > 0"
                    class="absolute -right-1 -top-1 flex h-5 min-w-5 items-center justify-center rounded-full bg-rose-500 px-1 text-[10px] font-extrabold text-white shadow ring-2 ring-slate-900">
                    {{ unread > 99 ? '99+' : unread }}
                </span>
            </Link>
            <div v-if="user" class="flex items-center gap-2 rounded-full border border-white/15 bg-white/10 px-2.5 py-1 text-sm">
                <span class="flex h-7 w-7 items-center justify-center rounded-full brand-gradient text-xs font-bold text-white">{{ initial }}</span>
                <span class="hidden text-xs font-bold text-white sm:inline">{{ user.name }}</span>
            </div>
        </div>
    </header>
</template>
