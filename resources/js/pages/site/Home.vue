<script setup lang="ts">
import type { SiteOrg } from '@/layouts/SiteLayout.vue';
import { whatsappLink } from '@/lib/whatsapp';
import { Head, Link } from '@inertiajs/vue3';
import {
    ArrowLeft,
    Building2,
    CalendarCheck,
    CircleCheck,
    FilePen,
    Headset,
    KeyRound,
    LogIn,
    Mail,
    MapPin,
    MessageCircle,
    Phone,
    ShieldCheck,
    Sparkles,
    TreePalm,
    Waves,
    Wrench,
} from 'lucide-vue-next';
import { computed, onBeforeUnmount, onMounted, ref, type Directive } from 'vue';

/**
 * الواجهة العامة — صفحة هبوط تعريفية بخدمات المنشأة.
 *
 * تعريفٌ لا حجز: لا تعرض وحدات ولا أسعارًا ولا روابط حجز، فالحجز الإلكتروني
 * لم يُفتح للعموم بعد. الطريق الوحيد من هنا إلى المنشأة هو التواصل، ولذلك
 * تُخفى أزراره وقسمه كلّه حين لا يُضبط في الإعدادات ما يُتواصل به.
 *
 * الشعار ثابت في public/images/brand لا من الإعدادات: الصفحة مصمّمة على
 * خلفيته الداكنة وألوانه، وشعارٌ آخر يُرفع للنظام لا يضمن أن يُقرأ عليها.
 */
const props = defineProps<{ org: SiteOrg }>();

const waLink = computed(() => whatsappLink(props.org.whatsapp ?? props.org.phone));
/*
 * بطاقات التواصل من «بيانات النشاط» في الإعدادات العامة، كلٌّ بحقله: ما لم
 * يُضبط لا تظهر بطاقته، ولا يظهر القسم كلّه إن لم يُضبط منها شيء.
 */
const contacts = computed(() =>
    [
        { icon: Phone, label: 'اتصل بنا', value: props.org.phone, href: props.org.phone ? `tel:${props.org.phone.replace(/\s+/g, '')}` : null },
        { icon: MessageCircle, label: 'واتساب', value: props.org.whatsapp ?? props.org.phone, href: waLink.value },
        { icon: Mail, label: 'البريد الإلكتروني', value: props.org.email, href: props.org.email ? `mailto:${props.org.email}` : null },
    ].filter((c): c is typeof c & { value: string; href: string } => Boolean(c.value && c.href)),
);
const hasContact = computed(() => contacts.value.length > 0 || Boolean(props.org.address));

const services = [
    {
        icon: Building2,
        title: 'القاعات',
        text: 'قاعات مناسبات مجهّزة للأفراح والحفلات والملتقيات، بمواعيد واضحة وتجهيزات تليق بمناسبتك.',
    },
    {
        icon: TreePalm,
        title: 'الشاليهات',
        text: 'شاليهات للاستجمام والعطلات العائلية، بخصوصية تامة وحجزٍ مرن بالليلة.',
    },
    {
        icon: KeyRound,
        title: 'الإيجار',
        text: 'تأجير الوحدات وإدارتها من الاستلام حتى التسليم، مع تحصيلٍ منتظم ومتابعةٍ للمستأجرين.',
    },
    {
        icon: Waves,
        title: 'المسابح',
        text: 'تصميم المسابح وتركيبها، ثم عنايةٌ دورية بالتنظيف والتعقيم وجودة المياه.',
    },
    {
        icon: Wrench,
        title: 'الصيانة',
        text: 'فريق صيانة للكهرباء والسباكة والتكييف، يستجيب بسرعة ويُبقي المرافق جاهزةً دائمًا.',
    },
    {
        icon: FilePen,
        title: 'العقود',
        text: 'عقود حجزٍ وإيجار موثّقة وواضحة البنود، تحفظ حقوق الطرفين من أول يوم.',
    },
];

// ما يشمله تركيب المسابح وصيانتها — يُعرض بجوار صورتَي القسم.
const poolWork = [
    'تصميم المسبح وتنفيذه وتبليطه بالمقاس الذي يناسب المكان',
    'تركيب المضخات والفلاتر والإضاءة وأنظمة التدوير',
    'تنظيفٌ وتعقيم دوري وفحصٌ منتظم لجودة المياه',
    'كشف التسريبات وإصلاحها وصيانة المعدات',
];

const reasons = [
    { icon: CalendarCheck, title: 'مواعيد مؤكَّدة', text: 'لا تداخل في الحجوزات، وكل موعدٍ محفوظ باسمك.' },
    { icon: ShieldCheck, title: 'حقوق محفوظة', text: 'كل اتفاقٍ مكتوب في عقدٍ واضح قبل أن يبدأ.' },
    { icon: Sparkles, title: 'جاهزية دائمة', text: 'مرافق نظيفة ومصانة تستقبلك كما وُعدت.' },
    { icon: Headset, title: 'متابعة قريبة', text: 'فريقٌ يردّ عليك قبل المناسبة وأثناءها وبعدها.' },
];

const year = new Date().getFullYear();

/* ترويسة تكتسب خلفية بعد أول تمرير — شفافة فوق البطل كي لا تقطعه. */
const scrolled = ref(false);
const onScroll = () => (scrolled.value = window.scrollY > 12);
onMounted(() => {
    onScroll();
    window.addEventListener('scroll', onScroll, { passive: true });
});
onBeforeUnmount(() => window.removeEventListener('scroll', onScroll));

/*
 * v-reveal: يظهر العنصر صاعدًا حين يدخل الشاشة، مرةً واحدة.
 * القيمة تأخيرٌ بالمللي ثانية لتتابع بطاقات الشبكة. من يفضّل تقليل الحركة
 * يراه ظاهرًا من البداية (انظر CSS أدناه).
 */
const vReveal: Directive<HTMLElement, number | undefined> = {
    mounted(el, { value }) {
        el.classList.add('reveal');
        if (value) el.style.transitionDelay = `${value}ms`;
        if (!('IntersectionObserver' in window)) {
            el.classList.add('is-visible');
            return;
        }
        const io = new IntersectionObserver(
            ([entry]) => {
                if (!entry.isIntersecting) return;
                el.classList.add('is-visible');
                io.disconnect();
            },
            { threshold: 0.15, rootMargin: '0px 0px -40px 0px' },
        );
        io.observe(el);
    },
};
</script>

<template>
    <Head :title="org.name" />

    <div class="landing relative min-h-screen overflow-x-hidden bg-[#120428] text-white">
        <!-- هالات ضوئية بطيئة الحركة -->
        <div class="pointer-events-none absolute inset-x-0 top-0 h-[120vh] overflow-hidden" aria-hidden="true">
            <div class="blob absolute -top-40 right-[-15%] h-[36rem] w-[36rem] rounded-full bg-[#f0b275]/15 blur-[130px]"></div>
            <div class="blob blob-b absolute left-[-20%] top-1/3 h-[34rem] w-[34rem] rounded-full bg-violet-600/25 blur-[140px]"></div>
            <div class="dots absolute inset-0 opacity-[0.12]"></div>
        </div>

        <!-- الترويسة -->
        <header
            class="fixed inset-x-0 top-0 z-30 transition-all duration-300"
            :class="scrolled ? 'border-b border-white/10 bg-[#120428]/85 backdrop-blur-md' : 'bg-transparent'"
        >
            <div class="mx-auto flex max-w-6xl items-center justify-between gap-3 px-4 py-3 sm:px-6">
                <a href="#top" class="flex items-center gap-2.5">
                    <img src="/images/brand/masarra-mark.png" :alt="org.name" class="h-10 w-10 object-contain" />
                    <span class="text-base font-extrabold sm:text-lg">{{ org.name }}</span>
                </a>

                <nav class="flex items-center gap-1 text-sm font-bold sm:gap-2">
                    <a href="#services" class="hidden rounded-full px-3 py-2 text-white/70 transition hover:text-white md:inline">خدماتنا</a>
                    <a href="#pools" class="hidden rounded-full px-3 py-2 text-white/70 transition hover:text-white md:inline">المسابح</a>
                    <a href="#why" class="hidden rounded-full px-3 py-2 text-white/70 transition hover:text-white md:inline">لماذا نحن</a>
                    <a v-if="hasContact" href="#contact" class="hidden rounded-full px-3 py-2 text-white/70 transition hover:text-white md:inline"
                        >تواصل معنا</a
                    >
                    <Link
                        :href="route('login')"
                        class="group inline-flex items-center gap-1.5 rounded-full border border-white/15 bg-white/5 px-3.5 py-2 text-xs text-white/70 backdrop-blur transition hover:border-[#f0b275]/50 hover:text-[#f6cfa3]"
                    >
                        <LogIn class="h-3.5 w-3.5 transition group-hover:-translate-x-0.5" />
                        دخول الإدارة
                    </Link>
                </nav>
            </div>
        </header>

        <!-- البطل -->
        <section
            id="top"
            class="relative z-10 mx-auto grid min-h-[100svh] max-w-6xl items-center gap-10 px-4 pb-16 pt-28 sm:px-6 lg:grid-cols-2 lg:gap-6"
        >
            <div class="text-center lg:text-right">
                <span
                    class="rise inline-flex items-center gap-2 rounded-full border border-[#f0b275]/25 bg-[#f0b275]/10 px-4 py-1.5 text-xs font-bold text-[#f6cfa3]"
                >
                    <span class="h-1.5 w-1.5 rounded-full bg-[#f0b275]"></span>
                    قاعات · شاليهات · إيجار · مسابح · صيانة · عقود
                </span>

                <h1 class="rise mt-6 text-4xl font-black leading-[1.35] sm:text-5xl lg:text-6xl" style="animation-delay: 120ms">
                    مناسباتك وأملاكك
                    <span class="shimmer block bg-clip-text pb-3 text-transparent">بين أيدٍ أمينة</span>
                </h1>

                <p class="rise mx-auto mt-6 max-w-xl text-base leading-loose text-white/65 sm:text-lg lg:mx-0" style="animation-delay: 240ms">
                    من حجز القاعة لليلة العمر، إلى شاليه العطلة، وإيجار الوحدات وصيانتها وتوثيق عقودها — نتولّى التفاصيل لتتفرّغ أنت لما يهمّك.
                </p>

                <div class="rise mt-9 flex flex-wrap items-center justify-center gap-3 lg:justify-start" style="animation-delay: 360ms">
                    <a
                        href="#services"
                        class="group inline-flex items-center gap-2 rounded-full bg-[#f0b275] px-6 py-3 text-sm font-extrabold text-[#120428] shadow-[0_10px_30px_-10px_rgba(240,178,117,0.7)] transition hover:-translate-y-0.5 hover:bg-[#f6c48f]"
                    >
                        تعرّف على خدماتنا
                        <ArrowLeft class="h-4 w-4 transition group-hover:-translate-x-1" />
                    </a>
                    <a
                        v-if="waLink"
                        :href="waLink"
                        target="_blank"
                        rel="noopener"
                        class="inline-flex items-center gap-2 rounded-full border border-white/20 px-6 py-3 text-sm font-bold text-white transition hover:-translate-y-0.5 hover:border-white/40 hover:bg-white/5"
                    >
                        <MessageCircle class="h-4 w-4" />
                        تواصل عبر واتساب
                    </a>
                </div>
            </div>

            <!-- الشعار -->
            <div
                class="rise relative mx-auto flex aspect-square w-full max-w-[22rem] items-center justify-center sm:max-w-md"
                style="animation-delay: 200ms"
            >
                <span class="ring-spin absolute inset-0 rounded-full border border-dashed border-[#f0b275]/30"></span>
                <span class="ring-spin ring-reverse absolute inset-8 rounded-full border border-white/10"></span>
                <span class="absolute inset-16 rounded-full bg-[radial-gradient(circle,rgba(240,178,117,0.18),transparent_70%)]"></span>
                <img
                    src="/images/brand/masarra-logo.png"
                    :alt="org.name"
                    class="float relative w-[72%] object-contain drop-shadow-[0_20px_40px_rgba(0,0,0,0.45)]"
                />
            </div>
        </section>

        <!-- الخدمات -->
        <section id="services" class="relative z-10 mx-auto max-w-6xl scroll-mt-20 px-4 py-24 sm:px-6">
            <div v-reveal class="mx-auto max-w-2xl text-center">
                <p class="text-sm font-bold tracking-widest text-[#f0b275]">خدماتنا</p>
                <h2 class="mt-3 text-3xl font-black sm:text-4xl">كل ما تحتاجه تحت سقفٍ واحد</h2>
                <p class="mt-4 leading-loose text-white/60">ستّ خدماتٍ متكاملة يديرها فريقٌ واحد، فلا تتنقّل بين جهاتٍ متعدّدة.</p>
            </div>

            <div class="mt-14 grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
                <div v-for="(s, i) in services" :key="s.title" v-reveal="i * 90">
                    <article
                        class="group relative h-full overflow-hidden rounded-3xl border border-white/10 bg-white/[0.04] p-7 transition duration-300 hover:-translate-y-1.5 hover:border-[#f0b275]/40 hover:bg-white/[0.07]"
                    >
                        <span
                            class="pointer-events-none absolute -left-16 -top-16 h-40 w-40 rounded-full bg-[#f0b275]/0 blur-3xl transition duration-500 group-hover:bg-[#f0b275]/20"
                        ></span>
                        <span
                            class="relative flex h-14 w-14 items-center justify-center rounded-2xl bg-gradient-to-br from-[#f0b275] to-[#d9894a] text-[#120428] shadow-lg shadow-[#f0b275]/20 transition duration-300 group-hover:rotate-6 group-hover:scale-110"
                        >
                            <component :is="s.icon" class="h-7 w-7" />
                        </span>
                        <h3 class="relative mt-6 text-xl font-extrabold">{{ s.title }}</h3>
                        <p class="relative mt-3 text-sm leading-loose text-white/60">{{ s.text }}</p>
                    </article>
                </div>
            </div>
        </section>

        <!--
            تركيب المسابح وصيانتها. الصورتان من Unsplash (ترخيصٌ يُجيز الاستخدام
            التجاري بلا نسبة) ومحفوظتان محليًا في public/images/site:
            التركيب KH9mddFZ7eE، والصيانة LDNTUB4fjCQ.
        -->
        <section id="pools" class="relative z-10 mx-auto max-w-6xl scroll-mt-20 px-4 pb-24 sm:px-6">
            <div class="grid items-center gap-12 lg:grid-cols-2">
                <div v-reveal>
                    <p class="text-sm font-bold tracking-widest text-[#f0b275]">المسابح</p>
                    <h2 class="mt-3 text-3xl font-black leading-snug sm:text-4xl">تركيب المسابح وصيانتها</h2>
                    <p class="mt-4 leading-loose text-white/60">
                        من أول حفرٍ حتى أول سباحة، ثم نبقى معك بعدها: فريقٌ متخصّص يبني المسبح ويجهّز معدّاته، ويعود دوريًا ليبقى الماء صافيًا
                        والمعدّات تعمل كما يجب.
                    </p>
                    <ul class="mt-8 space-y-4">
                        <li v-for="(w, i) in poolWork" :key="w" v-reveal="i * 90" class="flex items-start gap-3">
                            <CircleCheck class="mt-0.5 h-5 w-5 shrink-0 text-[#f0b275]" />
                            <span class="text-sm leading-relaxed text-white/80">{{ w }}</span>
                        </li>
                    </ul>
                </div>

                <div class="grid grid-cols-2 items-start gap-4 sm:gap-5">
                    <figure v-reveal="100" class="group relative overflow-hidden rounded-3xl border border-white/10">
                        <img
                            src="/images/site/pool-installation.webp"
                            alt="مسبح قيد التركيب والتبليط"
                            loading="lazy"
                            class="aspect-[3/4] w-full object-cover object-[20%_100%] transition duration-700 group-hover:scale-105"
                        />
                        <figcaption
                            class="absolute inset-x-0 bottom-0 bg-gradient-to-t from-[#120428]/90 to-transparent px-4 pb-4 pt-10 text-sm font-extrabold"
                        >
                            التركيب
                        </figcaption>
                    </figure>
                    <figure v-reveal="220" class="group relative mt-12 overflow-hidden rounded-3xl border border-white/10">
                        <img
                            src="/images/site/pool-maintenance.webp"
                            alt="فنيّ يُنظّف مسبحًا"
                            loading="lazy"
                            class="aspect-[3/4] w-full object-cover transition duration-700 group-hover:scale-105"
                        />
                        <figcaption
                            class="absolute inset-x-0 bottom-0 bg-gradient-to-t from-[#120428]/90 to-transparent px-4 pb-4 pt-10 text-sm font-extrabold"
                        >
                            الصيانة
                        </figcaption>
                    </figure>
                </div>
            </div>
        </section>

        <!-- لماذا نحن -->
        <section id="why" class="relative z-10 scroll-mt-20 border-t border-white/10 bg-black/20">
            <div class="mx-auto max-w-6xl px-4 py-24 sm:px-6">
                <div v-reveal class="mx-auto max-w-2xl text-center">
                    <p class="text-sm font-bold tracking-widest text-[#f0b275]">لماذا نحن</p>
                    <h2 class="mt-3 text-3xl font-black sm:text-4xl">راحتك تبدأ من التنظيم</h2>
                </div>

                <div class="mt-14 grid gap-8 sm:grid-cols-2 lg:grid-cols-4">
                    <div v-for="(r, i) in reasons" :key="r.title" v-reveal="i * 110" class="text-center">
                        <span class="mx-auto flex h-16 w-16 items-center justify-center rounded-full border border-[#f0b275]/30 text-[#f0b275]">
                            <component :is="r.icon" class="h-7 w-7" />
                        </span>
                        <h3 class="mt-5 text-lg font-extrabold">{{ r.title }}</h3>
                        <p class="mt-2 text-sm leading-loose text-white/55">{{ r.text }}</p>
                    </div>
                </div>
            </div>
        </section>

        <!-- التواصل: يُخفى كلّه حين لا تُضبط أي وسيلة تواصل في الإعدادات -->
        <section v-if="hasContact" id="contact" class="relative z-10 mx-auto max-w-6xl scroll-mt-20 px-4 py-24 sm:px-6">
            <div
                v-reveal
                class="relative overflow-hidden rounded-[2rem] border border-[#f0b275]/25 bg-gradient-to-br from-[#2a0f4d] via-[#1b0838] to-[#120428] px-6 py-14 text-center sm:px-12"
            >
                <span class="blob pointer-events-none absolute -right-20 -top-20 h-64 w-64 rounded-full bg-[#f0b275]/20 blur-3xl"></span>
                <p class="relative text-sm font-bold tracking-widest text-[#f0b275]">بيانات التواصل</p>
                <h2 class="relative mt-3 text-3xl font-black sm:text-4xl">جاهزون لخدمتك</h2>
                <p class="relative mx-auto mt-4 max-w-xl leading-loose text-white/65">
                    أخبرنا بما تحتاجه — مناسبة أو إقامة أو وحدة للإيجار — ونعود إليك بالتفاصيل والمواعيد المتاحة.
                </p>

                <div class="relative mx-auto mt-10 grid max-w-4xl gap-4 sm:grid-cols-3">
                    <a
                        v-for="c in contacts"
                        :key="c.label"
                        :href="c.href"
                        :target="c.href.startsWith('http') ? '_blank' : undefined"
                        rel="noopener"
                        class="group flex flex-col items-center gap-3 rounded-2xl border border-white/10 bg-white/[0.04] px-4 py-6 transition duration-300 hover:-translate-y-1 hover:border-[#f0b275]/40 hover:bg-white/[0.08]"
                    >
                        <span
                            class="flex h-12 w-12 items-center justify-center rounded-full bg-gradient-to-br from-[#f0b275] to-[#d9894a] text-[#120428] transition duration-300 group-hover:scale-110"
                        >
                            <component :is="c.icon" class="h-5 w-5" />
                        </span>
                        <span class="text-sm font-bold text-white/60">{{ c.label }}</span>
                        <span dir="ltr" class="break-all text-base font-extrabold text-white">{{ c.value }}</span>
                    </a>
                </div>

                <p v-if="org.address" class="relative mt-8 inline-flex items-center gap-1.5 text-sm text-white/55">
                    <MapPin class="h-4 w-4 shrink-0" />
                    {{ org.address }}
                </p>
            </div>
        </section>

        <footer class="relative z-10 border-t border-white/10">
            <div class="mx-auto flex max-w-6xl flex-col items-center justify-between gap-3 px-4 py-8 text-xs text-white/40 sm:flex-row sm:px-6">
                <div class="flex items-center gap-2">
                    <img src="/images/brand/masarra-mark.png" alt="" class="h-7 w-7 object-contain opacity-70" />
                    <span class="font-bold text-white/60">{{ org.name }}</span>
                </div>
                <span>© {{ year }} {{ org.name }} — جميع الحقوق محفوظة</span>
            </div>

            <!-- توقيع المطوّر: شعار كواكب التقنية مرسومٌ هنا لا صورة، فيبقى حادًّا بأي حجم -->
            <div class="border-t border-white/5 py-4 text-center">
                <a
                    href="https://www.const-tech.org/"
                    target="_blank"
                    rel="noopener"
                    class="group inline-flex items-center gap-2 text-xs text-white/40 transition hover:text-white/70"
                >
                    تصميم وبرمجة
                    <svg viewBox="0 0 290 330" class="h-5 w-auto" aria-hidden="true" stroke-linecap="round" fill="none">
                        <line x1="35" y1="148" x2="192" y2="35" stroke="#4285f4" stroke-width="56" />
                        <line x1="105" y1="192" x2="178" y2="141" stroke="#0bd44a" stroke-width="54" />
                        <line x1="95" y1="298" x2="252" y2="185" stroke="#fbbc05" stroke-width="56" />
                    </svg>
                    <span class="font-bold text-white/70 transition group-hover:text-white">كواكب التقنية</span>
                </a>
            </div>
        </footer>
    </div>
</template>

<style scoped>
.landing {
    scroll-behavior: smooth;
}

.dots {
    background-image: radial-gradient(rgba(255, 255, 255, 0.55) 1px, transparent 1px);
    background-size: 28px 28px;
    mask-image: linear-gradient(to bottom, black 20%, transparent 90%);
}

/* لمعةٌ ذهبية تعبر العنوان ببطء — بلون الخط في الشعار */
.shimmer {
    background-image: linear-gradient(100deg, #f0b275 20%, #fde3c4 40%, #f0b275 60%);
    background-size: 250% 100%;
    animation: shimmer 6s ease-in-out infinite;
}
@keyframes shimmer {
    0%,
    100% {
        background-position: 100% 0;
    }
    50% {
        background-position: 0 0;
    }
}

/* دخول عناصر البطل عند التحميل */
.rise {
    opacity: 0;
    animation: rise 0.9s cubic-bezier(0.2, 0.7, 0.2, 1) forwards;
}
@keyframes rise {
    from {
        opacity: 0;
        transform: translateY(24px);
    }
    to {
        opacity: 1;
        transform: none;
    }
}

.float {
    animation: float 6s ease-in-out infinite;
}
@keyframes float {
    0%,
    100% {
        transform: translateY(0);
    }
    50% {
        transform: translateY(-14px);
    }
}

.ring-spin {
    animation: spin 40s linear infinite;
}
.ring-reverse {
    animation-duration: 60s;
    animation-direction: reverse;
}
@keyframes spin {
    to {
        transform: rotate(360deg);
    }
}

.blob {
    animation: drift 20s ease-in-out infinite;
}
.blob-b {
    animation-duration: 26s;
    animation-direction: reverse;
}
@keyframes drift {
    0%,
    100% {
        transform: translate3d(0, 0, 0) scale(1);
    }
    50% {
        transform: translate3d(-5%, 6%, 0) scale(1.1);
    }
}

/* الظهور عند التمرير (v-reveal) */
.reveal {
    opacity: 0;
    transform: translateY(28px);
    transition:
        opacity 0.8s ease,
        transform 0.8s cubic-bezier(0.2, 0.7, 0.2, 1);
}
.reveal.is-visible {
    opacity: 1;
    transform: none;
}

/* احترام تفضيل تقليل الحركة: كل شيء ظاهر وثابت */
@media (prefers-reduced-motion: reduce) {
    .shimmer,
    .rise,
    .float,
    .ring-spin,
    .blob {
        animation: none;
    }
    .rise,
    .reveal {
        opacity: 1;
        transform: none;
        transition: none;
    }
}
</style>
