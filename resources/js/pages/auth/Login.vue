<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { Eye, EyeOff, LayoutDashboard, LoaderCircle, Mail, UserRound } from 'lucide-vue-next';
import { ref } from 'vue';

interface DemoAccount {
    email: string;
    label: string;
    role: string | null;
}

defineProps<{
    status?: string;
    canResetPassword: boolean;
    /** حسابات التجربة المفعّلة — null حين لا حساب منها مفعّلًا. */
    demo?: { password: string; accounts: DemoAccount[] } | null;
}>();

const form = useForm({
    email: '',
    password: '',
    remember: false,
});

const showPassword = ref(false);

const submit = () => {
    form.post(route('login'), {
        onFinish: () => form.reset('password'),
    });
};

/**
 * الدخول بحساب تجربة بنقرة واحدة: تُعبَّأ الحقول ثم يُرسَل النموذج.
 * كلمة السر واحدة لكل حسابات التجربة، فلا داعي لسؤال المستخدم عنها.
 */
const loginAs = (account: DemoAccount, password: string) => {
    form.email = account.email;
    form.password = password;
    submit();
};
</script>

<template>
    <Head title="تسجيل الدخول" />

    <div class="grid min-h-screen grid-cols-1 lg:grid-cols-2">
        <!-- جانب النموذج (يمين في RTL) -->
        <div class="order-2 flex items-center justify-center bg-[#f7f8fb] p-6 sm:p-10 lg:order-1">
            <div class="w-full max-w-md rounded-2xl border border-slate-200 bg-white p-8 shadow-sm">
                <div class="mb-6">
                    <h2 class="text-2xl font-extrabold text-slate-900">مرحبا بك</h2>
                    <p class="mt-1 text-sm text-slate-500">أدخل البريد الالكتروني وكلمة السر للدخول</p>
                </div>

                <div v-if="status" class="mb-4 rounded-md bg-green-50 px-3 py-2 text-center text-sm font-medium text-green-700">
                    {{ status }}
                </div>

                <form @submit.prevent="submit" class="space-y-4">
                    <div>
                        <label for="email" class="mb-1.5 block text-sm font-bold text-slate-900">البريد الالكتروني</label>
                        <div class="relative">
                            <span class="pointer-events-none absolute inset-y-0 right-3 flex items-center text-slate-400">
                                <Mail class="h-5 w-5" />
                            </span>
                            <input
                                id="email"
                                type="email"
                                required
                                autofocus
                                autocomplete="email"
                                v-model="form.email"
                                dir="ltr"
                                class="w-full rounded-lg border border-slate-200 bg-slate-50 py-3 pe-10 ps-3 text-left text-sm text-slate-900 placeholder:text-slate-400 focus:border-indigo-500 focus:bg-white focus:outline-none focus:ring-2 focus:ring-indigo-100"
                                placeholder="admin@admin.com"
                            />
                        </div>
                        <InputError :message="form.errors.email" class="mt-1" />
                    </div>

                    <div>
                        <label for="password" class="mb-1.5 block text-sm font-bold text-slate-900">كلمة السر</label>
                        <div class="relative">
                            <button
                                type="button"
                                @click="showPassword = !showPassword"
                                class="absolute inset-y-0 right-3 flex items-center text-slate-400 transition hover:text-slate-900"
                            >
                                <component :is="showPassword ? EyeOff : Eye" class="h-5 w-5" />
                            </button>
                            <input
                                id="password"
                                :type="showPassword ? 'text' : 'password'"
                                required
                                autocomplete="current-password"
                                v-model="form.password"
                                dir="ltr"
                                class="w-full rounded-lg border border-slate-200 bg-slate-50 py-3 pe-10 ps-3 text-left text-sm text-slate-900 placeholder:text-slate-400 focus:border-indigo-500 focus:bg-white focus:outline-none focus:ring-2 focus:ring-indigo-100"
                                placeholder="••••••"
                            />
                        </div>
                        <InputError :message="form.errors.password" class="mt-1" />
                    </div>

                    <div class="flex items-center justify-between pt-1">
                        <label class="flex cursor-pointer items-center gap-2 text-sm text-slate-600">
                            <input
                                type="checkbox"
                                v-model="form.remember"
                                class="h-4 w-4 rounded border-slate-300 text-slate-900 focus:ring-slate-400"
                            />
                            <span>تذكرني دائما</span>
                        </label>
                        <Link
                            v-if="canResetPassword"
                            :href="route('password.request')"
                            class="text-sm font-bold text-slate-900 underline-offset-2 hover:underline"
                        >
                            نسيت كلمة المرور؟
                        </Link>
                    </div>

                    <button
                        type="submit"
                        :disabled="form.processing"
                        class="mt-2 inline-flex w-full items-center justify-center gap-2 rounded-lg brand-gradient py-3 text-base font-bold text-white shadow-sm transition hover:brightness-110 disabled:opacity-60"
                    >
                        <LoaderCircle v-if="form.processing" class="h-4 w-4 animate-spin" />
                        <span>دخول</span>
                    </button>
                </form>

                <!-- حسابات التجربة — تظهر فقط حين تُفعَّل من شاشة الموظفين -->
                <div v-if="demo && demo.accounts.length" class="mt-6 rounded-xl border border-emerald-200 bg-emerald-50/60 p-4">
                    <div class="mb-2.5 flex items-center justify-between gap-2">
                        <span class="text-sm font-extrabold text-emerald-800">حسابات التجربة</span>
                        <span class="rounded-md bg-white px-2 py-0.5 text-[11px] font-bold text-slate-600">
                            كلمة السر: <span dir="ltr">{{ demo.password }}</span>
                        </span>
                    </div>

                    <div class="space-y-1.5">
                        <button
                            v-for="a in demo.accounts"
                            :key="a.email"
                            type="button"
                            :disabled="form.processing"
                            @click="loginAs(a, demo.password)"
                            class="flex w-full items-center gap-2.5 rounded-lg border border-emerald-200 bg-white px-3 py-2 text-right transition hover:border-emerald-400 hover:bg-emerald-50 disabled:opacity-60"
                        >
                            <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-emerald-100 text-emerald-700">
                                <UserRound class="h-4 w-4" />
                            </span>
                            <span class="min-w-0 flex-1">
                                <span class="block truncate text-sm font-bold text-slate-900">{{ a.label }}</span>
                                <span class="block truncate text-[11px] text-slate-500" dir="ltr">{{ a.email }}</span>
                            </span>
                        </button>
                    </div>

                    <p class="mt-2.5 text-[11px] font-medium text-emerald-700">اضغط على أي حساب للدخول به مباشرة.</p>
                </div>

                <!-- Footer credit -->
                <div class="mt-8 flex items-center justify-center gap-2 text-center text-xs text-slate-500">
                    <span>برمجة وتصميم</span>
                    <a href="https://www.const-tech.org/" target="_blank" rel="noopener" class="inline-flex items-center gap-1.5 rounded-md bg-slate-100 px-2 py-1 font-bold text-slate-700 transition hover:bg-emerald-50 hover:text-emerald-700">
                        <span class="flex h-5 w-5 items-center justify-center rounded brand-gradient text-[10px] font-extrabold text-white">ك</span>
                        كواكب التقنية
                    </a>
                </div>
            </div>
        </div>

        <!-- جانب العلامة (يسار في RTL) -->
        <div class="order-1 relative hidden overflow-hidden bg-gradient-to-br from-emerald-600 to-teal-700 lg:order-2 lg:block">
            <div class="relative z-10 flex h-full flex-col items-center justify-center p-10 text-center text-white">
                <span class="mb-6 flex h-20 w-20 items-center justify-center rounded-3xl bg-white/15 shadow-lg backdrop-blur">
                    <LayoutDashboard class="h-10 w-10" />
                </span>
                <h1 class="text-4xl font-extrabold drop-shadow-lg sm:text-5xl">لوحة التحكم</h1>
                <p class="mt-3 text-xl font-bold text-white/90 drop-shadow sm:text-2xl">Admin Dashboard</p>
                <p class="mt-4 max-w-sm text-sm font-medium text-white/80">لوحة تحكم عربية جاهزة لإدارة أي مشروع</p>
            </div>
        </div>
    </div>
</template>
