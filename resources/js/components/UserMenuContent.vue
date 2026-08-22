<script setup lang="ts">
import UserInfo from '@/components/UserInfo.vue';
import { DropdownMenuGroup, DropdownMenuItem, DropdownMenuLabel, DropdownMenuSeparator } from '@/components/ui/dropdown-menu';
import type { User } from '@/types';
import { Link } from '@inertiajs/vue3';
import { LogOut, Settings } from 'lucide-vue-next';

interface Props {
    user: User;
}

defineProps<Props>();
</script>

<template>
    <DropdownMenuLabel class="p-0 font-normal">
        <div class="flex items-center gap-2 px-1 py-1.5 text-left text-sm">
            <UserInfo :user="user" :show-email="true" />
        </div>
    </DropdownMenuLabel>
    <DropdownMenuSeparator />
    <DropdownMenuGroup>
        <DropdownMenuItem :as-child="true">
            <Link class="block w-full" :href="route('profile.edit')" as="button">
                <Settings class="ml-2 h-4 w-4" />
                الإعدادات
            </Link>
        </DropdownMenuItem>
    </DropdownMenuGroup>
    <DropdownMenuSeparator />
    <!-- الخروج أحمر: فِعلٌ يقطع الجلسة، فيُميَّز عمّا فوقه من تنقّلٍ عاديّ. -->
    <DropdownMenuItem :as-child="true" class="text-red-600 focus:bg-red-50 focus:text-red-700">
        <Link class="flex w-full items-center font-bold text-red-600" method="post" :href="route('logout')" as="button">
            <LogOut class="ml-2 h-4 w-4" />
            تسجيل الخروج
        </Link>
    </DropdownMenuItem>
</template>
