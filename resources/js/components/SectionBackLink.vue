<script setup lang="ts">
/**
 * رابط العودة إلى صفحة القسم — يقوم مقام شريط التبويبات في الشاشات التي
 * انتقلت من الإعدادات إلى قسمها.
 *
 * صفحة القسم تعرض شاشاته كلها بأيقوناتها، فشريطُ تبويباتٍ يكرّر بعضها هنا
 * يزاحمها ولا يزيد عليها. ورابطٌ واحد يكفي للرجوع إلى حيث تُختار الشاشة.
 */
import { Link } from '@inertiajs/vue3';
import { ChevronRight } from 'lucide-vue-next';

withDefaults(
    defineProps<{
        href?: string;
        label?: string;
    }>(),
    { href: '/admin/accounting', label: 'المحاسبة' },
);
</script>

<template>
    <Link
        :href="href"
        class="inline-flex items-center gap-1 rounded-lg border border-slate-200 bg-white px-3 py-1.5 text-xs font-bold text-slate-600 transition hover:border-blue-200 hover:text-blue-600"
    >
        <ChevronRight class="h-3.5 w-3.5" />
        <span>{{ label }}</span>
    </Link>
</template>
