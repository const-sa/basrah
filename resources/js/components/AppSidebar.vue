<script setup lang="ts">
import AppLogo from '@/components/AppLogo.vue';
import NavMain from '@/components/NavMain.vue';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
    SidebarRail,
} from '@/components/ui/sidebar';
import { useLocale } from '@/composables/useLocale';
import { useNavigation } from '@/composables/useNavigation';
import { Link } from '@inertiajs/vue3';
import { ExternalLink } from 'lucide-vue-next';

// القائمة إلى اليمين: تسعُ مجموعاتٍ لا يسعها صفٌّ أفقيّ، وهنا تنفرد كلٌّ منها بسطرها.
const { navItems } = useNavigation();
// الجهة تتبع اتجاه اللغة: يمينًا في العربية ويسارًا في الإنجليزية، وإلا لتعارض
// موضع القائمة الثابت مع مكان الفراغ المحجوز لها في التدفّق.
const { t, isRtl } = useLocale();
</script>

<template>
    <Sidebar :side="isRtl ? 'right' : 'left'" collapsible="icon" class="border-none">
        <SidebarHeader class="border-b border-white/10 px-2 py-2">
            <SidebarMenu>
                <SidebarMenuItem>
                    <SidebarMenuButton size="lg" as-child class="hover:bg-white/10">
                        <Link href="/admin" class="flex items-center gap-2">
                            <AppLogo tone="dark" />
                        </Link>
                    </SidebarMenuButton>
                </SidebarMenuItem>
            </SidebarMenu>
        </SidebarHeader>

        <SidebarContent class="py-2">
            <NavMain :items="navItems" />
        </SidebarContent>

        <SidebarFooter class="border-t border-white/10">
            <SidebarMenu>
                <SidebarMenuItem>
                    <SidebarMenuButton as-child class="hover:bg-white/10">
                        <a href="/" target="_blank" rel="noopener">
                            <ExternalLink />
                            <span>{{ t('nav.visit_site') }}</span>
                        </a>
                    </SidebarMenuButton>
                </SidebarMenuItem>
            </SidebarMenu>
        </SidebarFooter>

        <SidebarRail />
    </Sidebar>
</template>
