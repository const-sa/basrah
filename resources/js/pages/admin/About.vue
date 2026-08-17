<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head } from '@inertiajs/vue3';
import {
    BadgeCheck, Bell, Building2, Contact, FileBarChart2, LifeBuoy, Lightbulb, MessageCircle, Settings, ShieldCheck, Users,
} from 'lucide-vue-next';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'لوحة التحكم', href: '/admin' },
    { title: 'فكرة المشروع', href: '/admin/about' },
];

const stack = [
    { label: 'الإطار الخلفي', value: 'Laravel 12' },
    { label: 'الجسر', value: 'Inertia 2' },
    { label: 'الواجهة', value: 'Vue 3.5 + Vite 6' },
    { label: 'التنسيق', value: 'Tailwind 3.4' },
    { label: 'قاعدة البيانات', value: 'MySQL' },
    { label: 'الثيم', value: 'AdminLTE — RTL' },
];

const modules = [
    { icon: Users, title: 'الموظفون والأدوار', desc: 'حسابات الموظفين + مصفوفة صلاحيات لكل وحدة' },
    { icon: Contact, title: 'العملاء', desc: 'عملاء عاديون وضريبيون، بحث وفلترة وتصدير Excel' },
    { icon: LifeBuoy, title: 'الدعم الفني', desc: 'تذاكر بمرفقات مع فتح وإغلاق وإعادة فتح' },
    { icon: Bell, title: 'الإشعارات ومكتبتها', desc: 'قوالب جاهزة تُرسل للعملاء عبر الواتساب' },
    { icon: MessageCircle, title: 'تكامل الواتساب', desc: 'بوابة c-wts.com + رسالة ترحيب آلية للعميل الجديد' },
    { icon: Building2, title: 'الأقسام والمدن', desc: 'بيانات مرجعية قابلة للإدارة' },
    { icon: FileBarChart2, title: 'التقارير', desc: 'صفحة عامة قابلة للتوسعة' },
    { icon: Settings, title: 'الإعدادات العامة', desc: 'الشعار، بيانات النشاط، الضريبة، التواقيع والختم' },
];

const principles = [
    'واجهة عربية RTL كاملة بثيم AdminLTE موحّد.',
    'نظام جداول مشترك (بطاقة + بحث/فلترة + شارات + أزرار + ترقيم).',
    'أنماط الحقول وألوان الجداول مضبوطة مركزياً — لا تكرار في الصفحات.',
    'نقاط تكامل واضحة (خدمة WaGateway) لتبديل المزوّد بسهولة.',
];
</script>

<template>
    <Head title="فكرة المشروع" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="space-y-4 p-5">
            <h1 class="text-2xl font-extrabold text-slate-900">فكرة المشروع</h1>

            <!-- البطاقة التعريفية -->
            <div class="overflow-hidden rounded-2xl brand-gradient p-6 text-white shadow-sm">
                <div class="flex items-start gap-4">
                    <span class="flex h-14 w-14 shrink-0 items-center justify-center rounded-2xl bg-white/20">
                        <Lightbulb class="h-7 w-7" />
                    </span>
                    <div>
                        <h2 class="text-xl font-extrabold">لوحة تحكم إدارية عامة قابلة لإعادة الاستخدام</h2>
                        <p class="mt-2 max-w-3xl text-sm leading-relaxed text-white/90">
                            قاعدة جاهزة تُبنى عليها أي لوحة تحكم لأي نشاط — بدل إعادة بناء المصادقة والصلاحيات والعملاء
                            والإعدادات والتراسل في كل مشروع من الصفر. مبنية بـ Laravel 12 و Vue 3 عبر Inertia في تطبيق واحد،
                            بواجهة عربية RTL كاملة وثيم AdminLTE.
                        </p>
                    </div>
                </div>
            </div>

            <!-- التقنيات -->
            <div class="lte-card">
                <div class="lte-card-header"><h3 class="lte-card-title">المنظومة التقنية</h3></div>
                <div class="grid grid-cols-2 gap-3 p-4 sm:grid-cols-3 lg:grid-cols-6">
                    <div v-for="s in stack" :key="s.label" class="rounded-xl border border-slate-200 bg-slate-50 p-3 text-center">
                        <p class="text-xs font-bold text-slate-500">{{ s.label }}</p>
                        <p class="mt-1 text-sm font-extrabold text-slate-800">{{ s.value }}</p>
                    </div>
                </div>
            </div>

            <!-- الوحدات -->
            <div class="lte-card">
                <div class="lte-card-header"><h3 class="lte-card-title">وحدات النظام</h3></div>
                <div class="grid gap-3 p-4 sm:grid-cols-2 lg:grid-cols-4">
                    <div v-for="m in modules" :key="m.title" class="flex items-start gap-3 rounded-xl border border-slate-200 bg-white p-4 transition hover:border-emerald-200 hover:shadow-sm">
                        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-emerald-50 text-emerald-600">
                            <component :is="m.icon" class="h-5 w-5" />
                        </span>
                        <div>
                            <h4 class="text-sm font-extrabold text-slate-800">{{ m.title }}</h4>
                            <p class="mt-0.5 text-xs leading-relaxed text-slate-500">{{ m.desc }}</p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- المبادئ -->
            <div class="lte-card">
                <div class="lte-card-header"><h3 class="lte-card-title">مبادئ التصميم</h3></div>
                <ul class="space-y-2 p-4">
                    <li v-for="(p, i) in principles" :key="i" class="flex items-center gap-2 text-sm font-medium text-slate-700">
                        <BadgeCheck class="h-4 w-4 shrink-0 text-emerald-600" />
                        {{ p }}
                    </li>
                </ul>
            </div>

            <!-- الأدوار الافتراضية -->
            <div class="lte-card">
                <div class="lte-card-header"><h3 class="lte-card-title">الأدوار الافتراضية</h3></div>
                <div class="flex flex-wrap gap-3 p-4">
                    <div class="flex items-center gap-2 rounded-xl border border-slate-200 bg-slate-50 px-4 py-2">
                        <ShieldCheck class="h-4 w-4 text-emerald-600" />
                        <span class="text-sm font-bold text-slate-800">مدير عام</span>
                        <span class="text-xs text-slate-500">— كل الصلاحيات، غير قابل للحذف</span>
                    </div>
                    <div class="flex items-center gap-2 rounded-xl border border-slate-200 bg-slate-50 px-4 py-2">
                        <ShieldCheck class="h-4 w-4 text-sky-600" />
                        <span class="text-sm font-bold text-slate-800">محرر</span>
                    </div>
                    <div class="flex items-center gap-2 rounded-xl border border-slate-200 bg-slate-50 px-4 py-2">
                        <ShieldCheck class="h-4 w-4 text-slate-400" />
                        <span class="text-sm font-bold text-slate-800">مشاهد</span>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
