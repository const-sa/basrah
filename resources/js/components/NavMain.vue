<script setup lang="ts">
import { SidebarGroup, SidebarGroupLabel, SidebarMenu, SidebarMenuButton, SidebarMenuItem, SidebarMenuSub, SidebarMenuSubButton, SidebarMenuSubItem } from '@/components/ui/sidebar';
import { type NavItem, type SharedData } from '@/types';
import { Link, usePage } from '@inertiajs/vue3';
import { ChevronDown } from 'lucide-vue-next';
import { ref } from 'vue';

defineProps<{
    items: NavItem[];
}>();

const page = usePage<SharedData>();

// نحفظ تفضيل المستخدم لكل قائمة (مفتوحة/مغلقة) في المتصفح حتى لا تنغلق عند التنقل بين الصفحات.
// نستخدم حالة صريحة لكل قائمة: عندما يحددها المستخدم نحترم اختياره، وإلا نفتح المجموعة النشطة افتراضياً.
const STORAGE_KEY = 'sidebar-open-menus';
const loadState = (): Record<string, boolean> => {
    try {
        const raw = localStorage.getItem(STORAGE_KEY);
        const parsed = raw ? JSON.parse(raw) : null;
        // ترقية الصيغة القديمة (مصفوفة عناوين مفتوحة) إلى الصيغة الجديدة
        if (Array.isArray(parsed)) {
            return Object.fromEntries(parsed.map((t: string) => [t, true]));
        }
        return parsed && typeof parsed === 'object' ? parsed : {};
    } catch {
        return {};
    }
};
const menuState = ref<Record<string, boolean>>(loadState());
const persist = () => {
    try {
        localStorage.setItem(STORAGE_KEY, JSON.stringify(menuState.value));
    } catch {
        /* تجاهل أخطاء التخزين */
    }
};

const isActive = (href: string) => {
    if (href === '/admin') return page.url === '/admin';
    return page.url === href || page.url.startsWith(href + '/');
};

const isGroupActive = (item: NavItem): boolean => {
    if (!item.children) return false;
    return item.children.some((c) => isActive(c.href) || isGroupActive(c));
};

const isOpen = (item: NavItem): boolean => {
    const explicit = menuState.value[item.title];
    if (explicit !== undefined) return explicit;
    return isGroupActive(item);
};

const toggleMenu = (item: NavItem) => {
    menuState.value = { ...menuState.value, [item.title]: !isOpen(item) };
    persist();
};
</script>

<template>
    <SidebarGroup class="px-2 py-0">
        <SidebarGroupLabel>القائمة</SidebarGroupLabel>
        <SidebarMenu>
            <template v-for="item in items" :key="item.title">
                <!-- Parent with children -->
                <SidebarMenuItem v-if="item.children && item.children.length > 0">
                    <SidebarMenuButton @click="toggleMenu(item)" :is-active="isGroupActive(item)" class="cursor-pointer">
                        <component :is="item.icon" v-if="item.icon" />
                        <span>{{ item.title }}</span>
                        <ChevronDown :class="['ms-auto h-4 w-4 transition-transform', isOpen(item) && 'rotate-180']" />
                    </SidebarMenuButton>
                    <SidebarMenuSub v-if="isOpen(item)">
                        <template v-for="child in item.children" :key="child.title">
                            <!-- Nested sub-group (مجموعة فرعية متداخلة) -->
                            <SidebarMenuSubItem v-if="child.children && child.children.length > 0">
                                <SidebarMenuSubButton class="cursor-pointer" :is-active="isGroupActive(child)" @click="toggleMenu(child)">
                                    <component :is="child.icon" v-if="child.icon" class="h-3.5 w-3.5" />
                                    <span>{{ child.title }}</span>
                                    <ChevronDown :class="['ms-auto h-3.5 w-3.5 transition-transform', isOpen(child) && 'rotate-180']" />
                                </SidebarMenuSubButton>
                                <SidebarMenuSub v-if="isOpen(child)">
                                    <SidebarMenuSubItem v-for="grand in child.children" :key="grand.title">
                                        <SidebarMenuSubButton as-child :is-active="isActive(grand.href)">
                                            <Link :href="grand.href" class="relative">
                                                <component :is="grand.icon" v-if="grand.icon" class="h-3.5 w-3.5" />
                                                <span>{{ grand.title }}</span>
                                                <span v-if="grand.badge !== undefined && grand.badge !== null && grand.badge !== 0" class="ms-auto inline-flex h-4 min-w-[16px] items-center justify-center rounded-full brand-gradient px-1 text-[9px] font-bold text-white">
                                                    {{ grand.badge }}
                                                </span>
                                            </Link>
                                        </SidebarMenuSubButton>
                                    </SidebarMenuSubItem>
                                </SidebarMenuSub>
                            </SidebarMenuSubItem>

                            <!-- Regular child link -->
                            <SidebarMenuSubItem v-else>
                                <SidebarMenuSubButton as-child :is-active="isActive(child.href)">
                                    <Link :href="child.href" class="relative">
                                        <component :is="child.icon" v-if="child.icon" class="h-3.5 w-3.5" />
                                        <span>{{ child.title }}</span>
                                        <span v-if="child.badge !== undefined && child.badge !== null && child.badge !== 0" class="ms-auto inline-flex h-4 min-w-[16px] items-center justify-center rounded-full brand-gradient px-1 text-[9px] font-bold text-white">
                                            {{ child.badge }}
                                        </span>
                                    </Link>
                                </SidebarMenuSubButton>
                            </SidebarMenuSubItem>
                        </template>
                    </SidebarMenuSub>
                </SidebarMenuItem>

                <!-- Regular item -->
                <SidebarMenuItem v-else>
                    <SidebarMenuButton as-child :is-active="isActive(item.href)">
                        <Link :href="item.href" class="relative">
                            <component :is="item.icon" v-if="item.icon" />
                            <span>{{ item.title }}</span>
                            <span v-if="item.badge !== undefined && item.badge !== null && item.badge !== 0" class="ms-auto inline-flex h-5 min-w-[20px] items-center justify-center rounded-full brand-gradient px-1.5 text-[10px] font-bold text-white">
                                {{ item.badge }}
                            </span>
                        </Link>
                    </SidebarMenuButton>
                </SidebarMenuItem>
            </template>
        </SidebarMenu>
    </SidebarGroup>
</template>
