<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { LogIn, Mail, MapPin, MessageCircle, Phone } from 'lucide-vue-next';
import { computed } from 'vue';

export interface SiteOrg {
    name: string;
    logo_url: string | null;
    phone: string | null;
    whatsapp: string | null;
    email: string | null;
    address: string | null;
}

const props = defineProps<{ org: SiteOrg }>();

// رابط واتساب بصيغة دولية بلا + أو أصفار — نفس تطبيع البوابة في الخادم.
const waLink = computed(() => {
    const raw = props.org.whatsapp ?? props.org.phone;
    if (!raw) return null;
    let digits = raw.replace(/\D+/g, '');
    if (digits.startsWith('00')) digits = digits.slice(2);
    if (digits.startsWith('0')) digits = `966${digits.slice(1)}`;
    else if (digits.length <= 9) digits = `966${digits}`;
    return `https://wa.me/${digits}`;
});

const year = new Date().getFullYear();
</script>

<template>
    <div class="flex min-h-screen flex-col bg-slate-50">
        <header class="sticky top-0 z-20 border-b border-slate-200 bg-white/90 backdrop-blur">
            <div class="mx-auto flex max-w-6xl items-center justify-between gap-3 px-4 py-3">
                <Link href="/" class="flex items-center gap-2.5">
                    <img v-if="org.logo_url" :src="org.logo_url" :alt="org.name" class="h-10 w-auto object-contain" />
                    <span class="text-lg font-extrabold text-slate-900">{{ org.name }}</span>
                </Link>

                <nav class="flex items-center gap-2">
                    <a
                        v-if="waLink"
                        :href="waLink"
                        target="_blank"
                        rel="noopener"
                        class="inline-flex items-center gap-1.5 rounded-lg bg-emerald-600 px-3 py-2 text-sm font-bold text-white hover:bg-emerald-700"
                    >
                        <MessageCircle class="h-4 w-4" />
                        <span class="hidden sm:inline">تواصل معنا</span>
                    </a>
                    <Link
                        :href="route('login')"
                        class="inline-flex items-center gap-1.5 rounded-lg border border-slate-200 px-3 py-2 text-sm font-bold text-slate-600 hover:bg-slate-50"
                    >
                        <LogIn class="h-4 w-4" />
                        <span class="hidden sm:inline">دخول الإدارة</span>
                    </Link>
                </nav>
            </div>
        </header>

        <main class="flex-1">
            <slot />
        </main>

        <footer class="mt-10 border-t border-slate-200 bg-white">
            <div class="mx-auto grid max-w-6xl gap-4 px-4 py-8 sm:grid-cols-3">
                <div>
                    <div class="text-base font-extrabold text-slate-900">{{ org.name }}</div>
                    <p v-if="org.address" class="mt-1 flex items-start gap-1.5 text-sm text-slate-600">
                        <MapPin class="mt-0.5 h-4 w-4 shrink-0" /> {{ org.address }}
                    </p>
                </div>
                <div class="space-y-1.5 text-sm text-slate-600">
                    <p v-if="org.phone" class="flex items-center gap-1.5">
                        <Phone class="h-4 w-4" /> <span dir="ltr">{{ org.phone }}</span>
                    </p>
                    <p v-if="org.email" class="flex items-center gap-1.5">
                        <Mail class="h-4 w-4" /> <span dir="ltr">{{ org.email }}</span>
                    </p>
                </div>
                <div class="text-sm text-slate-500 sm:text-left">
                    © {{ year }} {{ org.name }} — جميع الحقوق محفوظة
                </div>
            </div>
        </footer>
    </div>
</template>
