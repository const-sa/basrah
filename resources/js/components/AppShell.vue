<script setup lang="ts">
import { SidebarProvider } from '@/components/ui/sidebar';
import { onMounted, ref } from 'vue';

interface Props {
    variant?: 'header' | 'sidebar';
}

defineProps<Props>();

const isOpen = ref(true);

// مفتاح جديد (v2) لتجاهل أي حالة «مطوية» قديمة كانت تُخفي القائمة — تبدأ مفتوحة افتراضياً
onMounted(() => {
    isOpen.value = localStorage.getItem('sidebar:v2') !== 'false';
});

const handleSidebarChange = (open: boolean) => {
    isOpen.value = open;
    localStorage.setItem('sidebar:v2', String(open));
};
</script>

<template>
    <div v-if="variant === 'header'" class="flex min-h-screen w-full flex-col">
        <slot />
    </div>
    <SidebarProvider v-else :default-open="isOpen" :open="isOpen" @update:open="handleSidebarChange">
        <slot />
    </SidebarProvider>
</template>
