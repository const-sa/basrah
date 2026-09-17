<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import SettingsLayout from '@/layouts/settings/Layout.vue';
import { TransitionRoot } from '@headlessui/vue';
import { Head, useForm } from '@inertiajs/vue3';
import { Check, KeyRound } from 'lucide-vue-next';
import { ref } from 'vue';

import { type BreadcrumbItem } from '@/types';

interface Props {
    className?: string;
}

defineProps<Props>();

const breadcrumbItems: BreadcrumbItem[] = [
    { title: 'لوحة التحكم', href: '/admin' },
    { title: 'كلمة المرور', href: '/admin/settings/password' },
];

const passwordInput = ref<HTMLInputElement>();
const currentPasswordInput = ref<HTMLInputElement>();

const field =
    'w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm focus:border-emerald-400 focus:outline-none focus:ring-2 focus:ring-emerald-100';

const form = useForm({
    current_password: '',
    password: '',
    password_confirmation: '',
});

const updatePassword = () => {
    form.put(route('password.update'), {
        preserveScroll: true,
        onSuccess: () => form.reset(),
        onError: (errors: any) => {
            if (errors.password) {
                form.reset('password', 'password_confirmation');
                if (passwordInput.value instanceof HTMLInputElement) {
                    passwordInput.value.focus();
                }
            }

            if (errors.current_password) {
                form.reset('current_password');
                if (currentPasswordInput.value instanceof HTMLInputElement) {
                    currentPasswordInput.value.focus();
                }
            }
        },
    });
};
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbItems">
        <Head title="كلمة المرور" />

        <SettingsLayout>
            <div class="mb-5 flex items-center gap-2 border-b border-slate-100 pb-3">
                <KeyRound class="h-5 w-5 text-blue-600" />
                <div>
                    <h2 class="text-lg font-bold text-slate-800">تغيير كلمة المرور</h2>
                    <p class="text-sm font-medium text-slate-600">اجعلها طويلة وغير متوقَّعة ليبقى حسابك محميًا</p>
                </div>
            </div>

            <form @submit.prevent="updatePassword" class="space-y-5">
                <div>
                    <label for="current_password" class="mb-1 block text-sm font-bold text-slate-700">كلمة المرور الحالية</label>
                    <input
                        id="current_password"
                        ref="currentPasswordInput"
                        v-model="form.current_password"
                        type="password"
                        autocomplete="current-password"
                        placeholder="كلمة المرور الحالية"
                        :class="field"
                    />
                    <InputError class="mt-1.5" :message="form.errors.current_password" />
                </div>

                <div class="border-t border-slate-100 pt-5">
                    <label for="password" class="mb-1 block text-sm font-bold text-slate-700">كلمة المرور الجديدة</label>
                    <input
                        id="password"
                        ref="passwordInput"
                        v-model="form.password"
                        type="password"
                        autocomplete="new-password"
                        placeholder="٨ أحرف على الأقل"
                        :class="field"
                    />
                    <InputError class="mt-1.5" :message="form.errors.password" />
                </div>

                <div>
                    <label for="password_confirmation" class="mb-1 block text-sm font-bold text-slate-700">تأكيد كلمة المرور</label>
                    <input
                        id="password_confirmation"
                        v-model="form.password_confirmation"
                        type="password"
                        autocomplete="new-password"
                        placeholder="أعِد كتابتها"
                        :class="field"
                    />
                    <InputError class="mt-1.5" :message="form.errors.password_confirmation" />
                </div>

                <div class="flex items-center gap-3 border-t border-slate-100 pt-4">
                    <button
                        type="submit"
                        :disabled="form.processing"
                        class="rounded-xl bg-blue-600 px-5 py-2.5 text-sm font-bold text-white shadow-sm transition hover:bg-blue-700 disabled:opacity-50"
                    >
                        حفظ كلمة المرور
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
