<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { KeyRound, UserRound } from 'lucide-vue-next';
import { computed } from 'vue';

const tabs = [
    { title: 'الملف الشخصي', href: '/admin/settings/profile', icon: UserRound },
    { title: 'كلمة المرور', href: '/admin/settings/password', icon: KeyRound },
    // مخفيّ من القائمة بطلب الإدارة (الصفحة والمسار باقيان يُفتحان بالرابط المباشر):
    // { title: 'المظهر', href: '/admin/settings/appearance', icon: Palette },
];

const page = usePage();

// From Inertia, not window: the path has to change as the tab does.
const currentPath = computed(() => page.url.split('?')[0]);
</script>

<template>
    <div class="min-h-full space-y-5 bg-slate-100 p-5">
        <div>
            <h1 class="text-2xl font-extrabold text-slate-900">الإعدادات</h1>
            <p class="mt-1 text-sm font-medium text-slate-600">بياناتك الشخصية وكلمة المرور ومظهر اللوحة</p>
        </div>

        <!-- gap, not space-x: space-x keeps its side under RTL and the column drifts. -->
        <div class="flex flex-col gap-5 lg:flex-row">
            <aside class="w-full lg:w-60 lg:shrink-0">
                <nav class="flex flex-row flex-wrap gap-2 lg:flex-col lg:flex-nowrap">
                    <Link
                        v-for="tab in tabs"
                        :key="tab.href"
                        :href="tab.href"
                        :class="[
                            'inline-flex items-center gap-2 rounded-xl px-3.5 py-2.5 text-sm font-bold transition lg:w-full',
                            tab.href === currentPath
                                ? 'bg-blue-600 text-white shadow-sm'
                                : 'border border-slate-200 bg-white text-slate-700 hover:border-blue-200 hover:bg-slate-50 hover:text-blue-600',
                        ]"
                    >
                        <component :is="tab.icon" class="h-4 w-4" />
                        <span>{{ tab.title }}</span>
                    </Link>
                </nav>
            </aside>

            <section class="w-full max-w-2xl rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                <slot />
            </section>
        </div>
    </div>
</template>
