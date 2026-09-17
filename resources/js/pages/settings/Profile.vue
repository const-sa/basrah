<script setup lang="ts">
import { TransitionRoot } from '@headlessui/vue';
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import { Check, UserRound } from 'lucide-vue-next';

import InputError from '@/components/InputError.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import SettingsLayout from '@/layouts/settings/Layout.vue';
import { type BreadcrumbItem, type SharedData, type User } from '@/types';

interface Props {
    mustVerifyEmail: boolean;
    status?: string;
    className?: string;
}

defineProps<Props>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'لوحة التحكم', href: '/admin' },
    { title: 'الملف الشخصي', href: '/admin/settings/profile' },
];

const page = usePage<SharedData>();
const user = page.props.auth.user as User;

const form = useForm({
    name: user.name,
    email: user.email,
});

const field =
    'w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm focus:border-emerald-400 focus:outline-none focus:ring-2 focus:ring-emerald-100';

const submit = () => {
    form.patch(route('profile.update'), { preserveScroll: true });
};
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head title="الملف الشخصي" />

        <SettingsLayout>
            <div class="mb-5 flex items-center gap-2 border-b border-slate-100 pb-3">
                <UserRound class="h-5 w-5 text-blue-600" />
                <div>
                    <h2 class="text-lg font-bold text-slate-800">بيانات الملف الشخصي</h2>
                    <p class="text-sm font-medium text-slate-600">الاسم والبريد اللذان يظهران لك في اللوحة</p>
                </div>
            </div>

            <form @submit.prevent="submit" class="space-y-5">
                <div>
                    <label for="name" class="mb-1 block text-sm font-bold text-slate-700">الاسم</label>
                    <input id="name" v-model="form.name" type="text" required autocomplete="name" placeholder="الاسم الكامل" :class="field" />
                    <InputError class="mt-1.5" :message="form.errors.name" />
                </div>

                <div>
                    <label for="email" class="mb-1 block text-sm font-bold text-slate-700">البريد الإلكتروني</label>
                    <input
                        id="email"
                        v-model="form.email"
                        type="email"
                        dir="ltr"
                        required
                        autocomplete="username"
                        placeholder="name@example.com"
                        :class="field"
                    />
                    <InputError class="mt-1.5" :message="form.errors.email" />
                </div>

                <div v-if="mustVerifyEmail && !user.email_verified_at" class="rounded-xl bg-amber-50 p-3">
                    <p class="text-sm font-medium text-amber-800">
                        بريدك الإلكتروني غير مُوثَّق.
                        <Link :href="route('verification.send')" method="post" as="button" class="font-bold underline hover:text-amber-900">
                            أعِد إرسال رسالة التوثيق
                        </Link>
                    </p>

                    <p v-if="status === 'verification-link-sent'" class="mt-1.5 text-sm font-bold text-emerald-700">
                        أُرسل رابط توثيق جديد إلى بريدك الإلكتروني.
                    </p>
                </div>

                <div class="flex items-center gap-3 border-t border-slate-100 pt-4">
                    <button
                        type="submit"
                        :disabled="form.processing"
                        class="rounded-xl bg-blue-600 px-5 py-2.5 text-sm font-bold text-white shadow-sm transition hover:bg-blue-700 disabled:opacity-50"
                    >
                        حفظ
                    </button>

                    <TransitionRoot
                        :show="form.recentlySuccessful"
                        enter="transition ease-in-out"
                        enter-from="opacity-0"
                        leave="transition ease-in-out"
                        leave-to="opacity-0"
                    >
                        <p class="inline-flex items-center gap-1 text-sm font-bold text-emerald-700">
                            <Check class="h-4 w-4" />
                            تم الحفظ
                        </p>
                    </TransitionRoot>
                </div>
            </form>
        </SettingsLayout>
    </AppLayout>
</template>
