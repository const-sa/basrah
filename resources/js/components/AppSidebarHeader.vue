<script setup lang="ts">
import PageSearch from '@/components/PageSearch.vue';
import { Breadcrumb, BreadcrumbItem, BreadcrumbLink, BreadcrumbList, BreadcrumbPage, BreadcrumbSeparator } from '@/components/ui/breadcrumb';
import { SidebarTrigger } from '@/components/ui/sidebar';
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
    <!-- ترويسة رفيعة بيضاء: الهويّة والتنقّل في القائمة اليمنى، وهنا الموضع والأدوات فقط. -->
    <header
        class="sticky top-0 z-30 flex h-[52px] shrink-0 items-center gap-3 border-b border-slate-200 bg-white/95 px-3 text-slate-900 backdrop-blur md:px-5 print:hidden"
    >
        <SidebarTrigger class="-ms-1 shrink-0 text-slate-500 hover:bg-slate-100 hover:text-slate-900" />

        <template v-if="breadcrumbs && breadcrumbs.length > 0">
            <Breadcrumb class="min-w-0">
                <BreadcrumbList class="flex-nowrap text-slate-500">
                    <template v-for="(item, index) in breadcrumbs" :key="index">
                        <BreadcrumbItem class="whitespace-nowrap">
                            <template v-if="index === breadcrumbs.length - 1">
                                <BreadcrumbPage class="font-extrabold text-slate-900">{{ item.title }}</BreadcrumbPage>
                            </template>
                            <template v-else>
                                <BreadcrumbLink :href="item.href" class="text-slate-500 hover:text-blue-700">{{ item.title }}</BreadcrumbLink>
                            </template>
                        </BreadcrumbItem>
                        <BreadcrumbSeparator v-if="index !== breadcrumbs.length - 1" />
                    </template>
                </BreadcrumbList>
            </Breadcrumb>
        </template>

        <!-- بحث عن صفحة -->
        <div class="mx-auto hidden w-full max-w-md sm:block">
            <PageSearch />
        </div>

        <div class="flex shrink-0 items-center gap-2 ltr:ml-auto rtl:mr-auto sm:ltr:ml-0 sm:rtl:mr-0">
            <div class="sm:hidden">
                <PageSearch compact />
            </div>

            <button
                type="button"
                @click="toggle"
                :title="t('header.switch_lang')"
                class="flex items-center gap-1.5 rounded-lg border border-slate-200 bg-white px-2.5 py-1.5 text-xs font-bold text-slate-600 transition hover:border-blue-200 hover:bg-blue-50 hover:text-blue-700"
            >
                <Languages class="h-4 w-4" />
                <span>{{ locale === 'ar' ? 'EN' : 'ع' }}</span>
            </button>

            <Link
                href="/admin/notifications"
                :title="t('header.notifications')"
                class="relative flex h-9 w-9 items-center justify-center rounded-lg border border-slate-200 bg-white text-slate-600 transition hover:border-blue-200 hover:bg-blue-50 hover:text-blue-700"
            >
                <Bell class="h-5 w-5" />
                <span
                    v-if="unread > 0"
                    class="absolute -right-1 -top-1 flex h-5 min-w-5 items-center justify-center rounded-full bg-rose-500 px-1 text-[10px] font-extrabold text-white shadow ring-2 ring-white"
                >
                    {{ unread > 99 ? '99+' : unread }}
                </span>
            </Link>

            <div v-if="user" class="flex items-center gap-2 rounded-lg border border-slate-200 bg-white px-2 py-1 text-sm">
                <span class="flex h-7 w-7 items-center justify-center rounded-md bg-[#0d3a72] text-xs font-bold text-white">{{ initial }}</span>
                <span class="hidden max-w-[10rem] truncate text-xs font-bold text-slate-700 md:inline">{{ user.name }}</span>
            </div>
        </div>
    </header>
</template>
