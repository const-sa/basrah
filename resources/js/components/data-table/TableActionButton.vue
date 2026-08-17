<script setup lang="ts">
import { computed, type Component } from 'vue';

type Variant = 'danger' | 'edit' | 'view' | 'success' | 'primary' | 'warning' | 'dark' | 'muted';

const props = withDefaults(
    defineProps<{
        variant?: Variant;
        icon?: Component;
        label?: string;
        title?: string;
    }>(),
    { variant: 'primary' },
);

// أزرار إجراءات مربّعة صلبة اللون بأيقونة بيضاء — بنفس ألوان الصورة.
const classes = computed(
    () =>
        ({
            danger: 'bg-red-500 hover:bg-red-700 active:bg-red-800',
            edit: 'bg-cyan-500 hover:bg-cyan-700 active:bg-cyan-800',
            view: 'bg-violet-500 hover:bg-violet-600',
            success: 'bg-emerald-500 hover:bg-emerald-600',
            primary: 'bg-blue-600 hover:bg-blue-700',
            warning: 'bg-amber-500 hover:bg-amber-600',
            // المستندات المطبوعة (السند) بلون حيادي غامق — إجراءٌ لا يغيّر شيئًا
            dark: 'bg-slate-800 hover:bg-slate-900',
            // إجراءٌ لم يُنفَّذ بعد (توليد العقد) — باهتٌ حتى يُنشأ المستند
            muted: 'bg-slate-400 hover:bg-slate-500',
        })[props.variant],
);
</script>

<template>
    <button
        type="button"
        :title="title"
        :class="[
            'inline-flex items-center justify-center gap-1.5 rounded-lg text-white shadow-sm transition',
            label ? 'px-3 py-2 text-xs font-bold' : 'h-9 w-9',
            classes,
        ]"
    >
        <component :is="icon" v-if="icon" class="h-4 w-4" />
        <span v-if="label">{{ label }}</span>
    </button>
</template>
