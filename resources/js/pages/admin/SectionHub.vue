<script setup lang="ts">
import { useNavigation } from '@/composables/useNavigation';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link } from '@inertiajs/vue3';
import { computed } from 'vue';

/**
 * شاشات قسمٍ واحد مبسوطةً في الصفحة بأيقونة واسم، بدل قائمةٍ جانبية تُفتح وتُغلق.
 *
 * المصدر شجرة التنقّل نفسها لا قائمةٌ مكتوبة هنا: ما يُضاف إلى القائمة يظهر
 * هنا من تلقائه، وما لا يملك المستخدم صلاحيته محذوفٌ أصلًا من الشجرة فلا
 * يُعرض له بابٌ يُفضي إلى ٤٠٣.
 */
const props = defineProps<{
    section: { key: string; href: string; label: string; description: string | null };
}>();

const { navItems } = useNavigation();

const group = computed(() => navItems.value.find((item) => item.href === props.section.href));

const screens = computed(() => group.value?.children ?? []);

const breadcrumbs = computed<BreadcrumbItem[]>(() => [
    { title: 'لوحة التحكم', href: '/admin' },
    { title: props.section.label, href: props.section.href },
]);
</script>

<template>
    <Head :title="section.label" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="min-h-full space-y-5 bg-slate-100 p-5">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h1 class="flex items-center gap-2 text-2xl font-extrabold text-slate-900">
                        <component :is="group?.icon" v-if="group?.icon" class="h-6 w-6 text-blue-600" />
                        {{ section.label }}
                    </h1>
                    <p v-if="section.description" class="mt-1 text-sm font-medium text-slate-600">{{ section.description }}</p>
                </div>
                <span v-if="screens.length" class="rounded-lg bg-white px-3 py-1.5 text-xs font-bold text-slate-500 ring-1 ring-slate-200">
                    {{ screens.length }} شاشة
                </span>
            </div>

            <div v-if="screens.length" class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
                <Link
                    v-for="screen in screens"
                    :key="screen.href"
                    :href="screen.href"
                    class="group relative flex items-center gap-3 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm transition hover:-translate-y-0.5 hover:border-blue-300 hover:shadow-md"
                >
                    <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-blue-50 text-blue-600 transition group-hover:bg-blue-600 group-hover:text-white">
                        <component :is="screen.icon" v-if="screen.icon" class="h-5 w-5" />
                    </span>
                    <span class="min-w-0 flex-1">
                        <span class="block truncate text-sm font-extrabold text-slate-800 group-hover:text-blue-700">{{ screen.title }}</span>
                    </span>
                    <span
                        v-if="screen.badge !== undefined && screen.badge !== null && screen.badge !== 0"
                        class="brand-gradient inline-flex h-5 min-w-[20px] items-center justify-center rounded-full px-1.5 text-[10px] font-bold text-white"
                    >
                        {{ screen.badge }}
                    </span>
                </Link>
            </div>

            <!-- الشجرة تُنظَّف بالصلاحيات، فالقسم الفارغ يعني أن المستخدم لا يملك شاشةً فيه -->
            <div v-else class="rounded-2xl border border-slate-200 bg-white px-4 py-12 text-center text-sm font-bold text-slate-500">
                لا توجد شاشات في هذا القسم ضمن صلاحياتك.
            </div>
        </div>
    </AppLayout>
</template>
