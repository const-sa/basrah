<script setup lang="ts">
import AppLogoIcon from '@/components/AppLogoIcon.vue';
import PageSearch from '@/components/PageSearch.vue';
import UserMenuContent from '@/components/UserMenuContent.vue';
import { Breadcrumb, BreadcrumbItem, BreadcrumbLink, BreadcrumbList, BreadcrumbPage, BreadcrumbSeparator } from '@/components/ui/breadcrumb';
import { DropdownMenu, DropdownMenuContent, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { SidebarTrigger } from '@/components/ui/sidebar';
import { useLocale } from '@/composables/useLocale';
import type { BreadcrumbItemType, SharedData } from '@/types';
import { Link, usePage } from '@inertiajs/vue3';
import { Bell, ChevronDown, Languages } from 'lucide-vue-next';
import { computed } from 'vue';

defineProps<{
    breadcrumbs?: BreadcrumbItemType[];
}>();

const page = usePage<SharedData>();
const user = page.props.auth?.user;
const initial = (user?.name || user?.email || '?').charAt(0).toUpperCase();
const unread = computed(() => page.props.notificationsUnread ?? 0);

const { t, toggle, locale } = useLocale();

const brand = computed(() => page.props.brand ?? { name: '', logo_url: null });
</script>

<template>
    <!-- ترويسة رفيعة بيضاء: الهويّة والتنقّل في القائمة اليمنى، وهنا الموضع والأدوات فقط. -->
    <header
        class="sticky top-0 z-30 flex h-14 shrink-0 items-center gap-3 border-b-2 border-slate-300 bg-white px-3 text-slate-900 shadow-sm md:px-5 print:hidden"
    >
        <SidebarTrigger class="-ms-1 shrink-0 text-[#0BA6CE] hover:bg-[#E6F7FB] hover:text-[#0990B4]" />

        <!--
            الشعار في الترويسة أيضًا لا في الشريط وحده: الشريط يُطوى إلى
            أيقونات ويختفي كليًا على الجوال، فتبقى الشاشة بلا هوية.
            بلاطة داكنة تحته لأن خطّ الشعار أبيض يذوب في ترويسةٍ بيضاء.
        -->
        <Link href="/admin" class="flex shrink-0 items-center gap-2" :title="brand.name">
            <span
                v-if="brand.logo_url"
                class="flex size-9 items-center justify-center overflow-hidden rounded-lg bg-slate-900 p-1 ring-1 ring-inset ring-slate-800"
            >
                <AppLogoIcon class="size-full" />
            </span>
            <span class="hidden max-w-[9rem] truncate text-sm font-extrabold text-slate-800 lg:inline">{{ brand.name }}</span>
        </Link>

        <template v-if="breadcrumbs && breadcrumbs.length > 0">
            <Breadcrumb class="min-w-0">
                <BreadcrumbList class="flex-nowrap text-slate-600">
                    <template v-for="(item, index) in breadcrumbs" :key="index">
                        <BreadcrumbItem class="whitespace-nowrap">
                            <template v-if="index === breadcrumbs.length - 1">
                                <BreadcrumbPage class="font-extrabold text-[#0BA6CE]">{{ item.title }}</BreadcrumbPage>
                            </template>
                            <template v-else>
                                <BreadcrumbLink :href="item.href" class="font-semibold text-[#0BA6CE]/80 hover:text-[#0BA6CE]">{{ item.title }}</BreadcrumbLink>
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
                class="flex items-center gap-1.5 rounded-lg border border-slate-300 bg-white px-2.5 py-1.5 text-xs font-bold text-slate-700 transition hover:border-[#0BA6CE] hover:bg-[#E6F7FB] hover:text-[#0BA6CE]"
            >
                <Languages class="h-4 w-4" />
                <span>{{ locale === 'ar' ? 'EN' : 'ع' }}</span>
            </button>

            <Link
                href="/admin/notifications"
                :title="t('header.notifications')"
                class="relative flex h-9 w-9 items-center justify-center rounded-lg border border-slate-300 bg-white text-slate-700 transition hover:border-[#0BA6CE] hover:bg-[#E6F7FB] hover:text-[#0BA6CE]"
            >
                <Bell class="h-5 w-5" />
                <span
                    v-if="unread > 0"
                    class="absolute -right-1 -top-1 flex h-5 min-w-5 items-center justify-center rounded-full bg-rose-500 px-1 text-[10px] font-extrabold text-white shadow ring-2 ring-white"
                >
                    {{ unread > 99 ? '99+' : unread }}
                </span>
            </Link>

            <!-- بطاقة المستخدم قائمةٌ لا نصًّا: كانت المخرج الوحيد للخروج
                 من النظام معلَّقًا في مكوّنٍ لا يعرضه أي تخطيط. -->
            <DropdownMenu v-if="user">
                <DropdownMenuTrigger
                    class="flex items-center gap-2 rounded-lg border border-slate-300 bg-white px-2 py-1 text-sm transition hover:border-[#0BA6CE] hover:bg-[#E6F7FB]"
                >
                    <span class="flex h-7 w-7 items-center justify-center rounded-md bg-[#0BA6CE] text-xs font-bold text-white">{{ initial }}</span>
                    <span class="hidden max-w-[10rem] truncate text-xs font-bold text-slate-800 md:inline">{{ user.name }}</span>
                    <ChevronDown class="h-3.5 w-3.5 shrink-0 text-slate-500" />
                </DropdownMenuTrigger>
                <DropdownMenuContent align="end" class="w-56">
                    <UserMenuContent :user="user" />
                </DropdownMenuContent>
            </DropdownMenu>
        </div>
    </header>
</template>
