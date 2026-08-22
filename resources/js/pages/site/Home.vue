<script setup lang="ts">
import type { SiteOrg } from '@/layouts/SiteLayout.vue';
import { Head, Link } from '@inertiajs/vue3';
import { LogIn } from 'lucide-vue-next';

/**
 * الواجهة العامة — وضع «قريبًا».
 *
 * لا تعرض وحدات ولا أسعارًا ولا روابط حجز عمدًا: الموقع لم يُطلق بعد، وعرض
 * نصف الخدمة أسوأ من عدم عرضها — يفتح باب أسئلةٍ واستفساراتٍ لا جواب لها.
 * يبقى المتحكّم يمرّر بقية البيانات، وتُتجاهل هنا حتى يحين وقت الإطلاق.
 */
defineProps<{ org: SiteOrg }>();

const year = new Date().getFullYear();
</script>

<template>
    <Head :title="`${org.name} — قريبًا`" />

    <div class="soon relative flex min-h-screen flex-col overflow-hidden bg-[#040a10] text-white">
        <!-- هالات ضوئية متحركة -->
        <div class="pointer-events-none absolute inset-0">
            <div class="blob blob-a absolute -top-40 right-[-10%] h-[34rem] w-[34rem] rounded-full bg-emerald-500/20 blur-[120px]"></div>
            <div class="blob blob-b absolute -bottom-48 left-[-12%] h-[36rem] w-[36rem] rounded-full bg-teal-400/15 blur-[130px]"></div>
            <div class="blob blob-c absolute left-1/2 top-1/3 h-72 w-72 -translate-x-1/2 rounded-full bg-emerald-300/10 blur-[100px]"></div>
        </div>

        <!-- شبكة نقطية خافتة + تعتيم الأطراف -->
        <div class="dots pointer-events-none absolute inset-0 opacity-[0.14]"></div>
        <div class="pointer-events-none absolute inset-0 bg-[radial-gradient(ellipse_at_center,transparent_35%,#040a10_95%)]"></div>

        <!--
            دخول الإدارة — الرابط الوحيد في الصفحة.
            مُزاح إلى الزاوية وبتباين منخفض: لا يخاطب الزائر، وإنما يوفّر على
            من يدير النظام تذكّر مسار /login طوال فترة «قريبًا».
        -->
        <Link
            :href="route('login')"
            class="group absolute left-4 top-4 z-20 inline-flex items-center gap-2 rounded-full border border-white/10 bg-white/5 px-4 py-2 text-xs font-bold text-slate-400 backdrop-blur transition hover:border-emerald-400/40 hover:bg-emerald-400/10 hover:text-emerald-200 sm:left-6 sm:top-6"
        >
            <LogIn class="h-3.5 w-3.5 transition group-hover:-translate-x-0.5" />
            دخول الإدارة
        </Link>

        <main class="relative z-10 flex flex-1 items-center justify-center px-6">
            <div class="relative w-full max-w-2xl text-center">
                <!-- كلمة SOON الشبحية خلف المحتوى -->
                <span
                    class="ghost pointer-events-none absolute left-1/2 top-1/2 -translate-x-1/2 -translate-y-1/2 select-none text-[26vw] font-black leading-none tracking-[0.08em] sm:text-[13rem]"
                    aria-hidden="true"
                >SOON</span>

                <!-- الشعار / الحرف -->
                <div class="relative mx-auto mb-8 flex h-32 w-32 items-center justify-center">
                    <span class="pulse absolute inset-0 rounded-full border border-emerald-400/30"></span>
                    <span class="pulse pulse-2 absolute inset-0 rounded-full border border-emerald-400/20"></span>
                    <span class="absolute inset-2 rounded-full bg-gradient-to-br from-emerald-500/20 to-teal-400/5 backdrop-blur-sm"></span>
                    <img
                        v-if="org.logo_url"
                        :src="org.logo_url"
                        :alt="org.name"
                        class="relative h-20 w-auto object-contain drop-shadow-[0_0_18px_rgba(16,185,129,0.35)]"
                    />
                    <span v-else class="relative text-3xl font-black text-emerald-300">{{ org.name.trim().charAt(0) }}</span>
                </div>

                <!-- اسم الجهة -->
                <p class="relative text-sm font-bold tracking-[0.35em] text-emerald-300/70">{{ org.name }}</p>

                <!-- العنوان -->
                <h1
                    class="relative mt-4 bg-gradient-to-l from-emerald-200 via-white to-teal-200 bg-clip-text text-6xl font-extrabold text-transparent sm:text-8xl"
                >
                    قريبًا
                </h1>

                <!-- شريط التقدّم المتحرك -->
                <div class="relative mx-auto mt-9 h-px w-56 overflow-hidden bg-white/10">
                    <span class="sweep absolute inset-y-0 left-0 w-1/3 bg-gradient-to-l from-transparent via-emerald-300 to-transparent"></span>
                </div>

                <p class="relative mt-7 text-base font-medium leading-relaxed text-slate-400">
                    نعمل على تجهيز التجربة كاملةً قبل فتحها.
                    <span class="block text-slate-500">ترقّبوا الإطلاق.</span>
                </p>
            </div>
        </main>

        <footer class="relative z-10 pb-8 text-center text-xs font-medium text-slate-600">
            © {{ year }} {{ org.name }}
        </footer>
    </div>
</template>

<style scoped>
/* حرفٌ مفرّغ لا مملوء: يبقى خلفيةً للنص فوقه بدل أن ينافسه */
.ghost {
    color: transparent;
    -webkit-text-stroke: 1px rgba(16, 185, 129, 0.13);
}

.dots {
    background-image: radial-gradient(rgba(148, 163, 184, 0.5) 1px, transparent 1px);
    background-size: 26px 26px;
}

.blob {
    animation: drift 18s ease-in-out infinite;
}
.blob-b {
    animation-duration: 24s;
    animation-direction: reverse;
}
.blob-c {
    animation-duration: 30s;
}

@keyframes drift {
    0%,
    100% {
        transform: translate3d(0, 0, 0) scale(1);
    }
    50% {
        transform: translate3d(-4%, 5%, 0) scale(1.12);
    }
}

.pulse {
    animation: pulse-ring 3.6s cubic-bezier(0.25, 0.8, 0.35, 1) infinite;
}
.pulse-2 {
    animation-delay: 1.8s;
}

@keyframes pulse-ring {
    0% {
        transform: scale(0.9);
        opacity: 0.9;
    }
    70% {
        transform: scale(1.45);
        opacity: 0;
    }
    100% {
        opacity: 0;
    }
}

.sweep {
    animation: sweep 2.6s ease-in-out infinite;
}

@keyframes sweep {
    0% {
        transform: translateX(-100%);
    }
    100% {
        transform: translateX(300%);
    }
}

/* احترام تفضيل تقليل الحركة — الصفحة تبقى مقروءة بلا أي تحريك */
@media (prefers-reduced-motion: reduce) {
    .blob,
    .pulse,
    .sweep {
        animation: none;
    }
    .sweep {
        transform: none;
        width: 100%;
    }
}
</style>
