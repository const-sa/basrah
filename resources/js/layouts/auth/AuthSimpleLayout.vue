<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { LayoutDashboard } from 'lucide-vue-next';

defineProps<{
    title?: string;
    description?: string;
}>();
</script>

<template>
    <div class="brand-gradient-soft flex min-h-svh items-center justify-center p-6">
        <div class="w-full max-w-md">
            <div class="auth-card">
                <div class="mb-6 flex flex-col items-center text-center">
                    <Link :href="route('home')" class="mb-3 flex h-14 w-14 items-center justify-center rounded-2xl brand-gradient text-2xl shadow-lg">
                        <LayoutDashboard class="h-7 w-7" />
                    </Link>
                    <h1 v-if="title" class="text-xl font-extrabold text-foreground">{{ title }}</h1>
                    <p v-if="description" class="mt-1 text-sm text-muted-foreground">{{ description }}</p>
                </div>
                <slot />
            </div>
            <p class="mt-6 text-center text-xs text-muted-foreground">
                © {{ new Date().getFullYear() }} لوحة التحكم — جميع الحقوق محفوظة
            </p>
        </div>
    </div>
</template>
