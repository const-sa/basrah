<script setup lang="ts">
import type { SharedData } from '@/types';
import { usePage } from '@inertiajs/vue3';
import { LayoutDashboard } from 'lucide-vue-next';
import { computed } from 'vue';

interface Props {
    class?: string;
    // على الترويسة الداكنة ينقلب الشعار إلى زجاجٍ فاتح، وعلى الفاتحة يبقى أزرق.
    tone?: 'light' | 'dark';
    // الصفّ الواحد ضيّق؛ فإن ضاق أُسقط السطر الثاني وبقي الاسم.
    subtitle?: boolean;
}

withDefaults(defineProps<Props>(), { tone: 'light', subtitle: true });

/**
 * الشعار واسم المنشأة من الإعدادات لا من ثابتٍ في الشيفرة: يرفع المدير
 * شعارًا من شاشة «الإعدادات العامة» فيتبدّل هنا وفي كل موضعٍ آخر معًا.
 */
const page = usePage<SharedData>();
const brand = computed(() => page.props.brand ?? { name: 'لوحة التحكم', logo_url: null });
</script>

<template>
    <!--
        خلفية داكنة تحت الشعار في الحالتين: شعار المنشأة يحمل خطًّا أبيض،
        ووضعُه على بلاطةٍ بيضاء يُخفي نصفه. البلاطة تضمن التباين أيًّا كان
        الملف المرفوع.
    -->
    <div
        class="flex aspect-square size-9 shrink-0 items-center justify-center overflow-hidden rounded-xl text-white"
        :class="
            brand.logo_url
                ? tone === 'dark'
                    ? 'bg-white/10 ring-1 ring-inset ring-white/20'
                    : 'bg-slate-900 ring-1 ring-inset ring-slate-800'
                : tone === 'dark'
                  ? 'bg-white/15 ring-1 ring-inset ring-white/25'
                  : 'bg-blue-700 shadow-[0_4px_10px_rgba(29,78,216,0.25)]'
        "
    >
        <img v-if="brand.logo_url" :src="brand.logo_url" :alt="brand.name" class="size-full object-contain p-1" />
        <LayoutDashboard v-else class="size-5" />
    </div>
    <div class="ms-1 grid flex-1 text-start text-sm">
        <span class="truncate font-extrabold leading-none" :class="tone === 'dark' ? 'text-white' : 'text-slate-900'">{{ brand.name }}</span>
        <span v-if="subtitle" class="mt-0.5 text-xs" :class="tone === 'dark' ? 'text-slate-300' : 'text-slate-500'">لوحة التحكم</span>
    </div>
</template>
