<script setup lang="ts">
import { Head, Link, usePage } from '@inertiajs/vue3';
import { LayoutDashboard, LogIn } from 'lucide-vue-next';

const page = usePage();
const user = page.props.auth?.user;
</script>

<template>
    <Head title="مرحباً" />

    <div class="brand-gradient-soft flex min-h-screen flex-col">
        <header class="border-b border-border/60 bg-white/70 backdrop-blur">
            <div class="mx-auto flex max-w-6xl items-center justify-between px-6 py-4">
                <div class="flex items-center gap-2 font-extrabold text-foreground">
                    <span class="flex h-9 w-9 items-center justify-center rounded-xl brand-gradient text-white shadow-md">
                        <LayoutDashboard class="h-5 w-5" />
                    </span>
                    <span>لوحة التحكم</span>
                </div>

                <nav class="flex items-center gap-2">
                    <Link
                        v-if="user"
                        :href="route('dashboard')"
                        class="rounded-lg border border-border bg-white px-4 py-2 text-sm font-bold text-foreground transition hover:border-primary hover:text-primary"
                    >
                        لوحة التحكم
                    </Link>
                    <Link
                        v-else
                        :href="route('login')"
                        class="inline-flex items-center gap-2 rounded-lg brand-gradient px-4 py-2 text-sm font-bold text-white shadow-md transition hover:brightness-110"
                    >
                        <LogIn class="h-4 w-4" />
                        دخول الإدارة
                    </Link>
                </nav>
            </div>
        </header>

        <main class="flex flex-1 items-center">
            <div class="mx-auto max-w-3xl px-6 py-16 text-center">
                <h1 class="text-3xl font-extrabold leading-tight text-foreground sm:text-4xl">
                    أدِر أعمالك من
                    <span class="brand-text-gradient">مكان واحد</span>
                </h1>
                <p class="mt-4 text-base text-muted-foreground sm:text-lg">
                    منصة بسيطة وسريعة لإدارة العملاء والمحاسبة بدقة.
                </p>
                <div class="mt-8 flex flex-wrap justify-center gap-3">
                    <Link
                        :href="user ? route('dashboard') : route('login')"
                        class="inline-flex items-center gap-2 rounded-lg brand-gradient px-6 py-3 text-sm font-bold text-white shadow-lg transition hover:brightness-110"
                    >
                        {{ user ? 'الذهاب للوحة التحكم' : 'دخول الإدارة' }}
                    </Link>
                </div>
            </div>
        </main>

        <footer class="border-t border-border/60 bg-white/70 py-4 text-center text-xs text-muted-foreground backdrop-blur">
            © {{ new Date().getFullYear() }} لوحة التحكم
        </footer>
    </div>
</template>
