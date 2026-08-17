<script setup lang="ts">
import { useLocale } from '@/composables/useLocale';
import { activeHref, urlBelongsTo, useNavigation } from '@/composables/useNavigation';
import { Link, usePage } from '@inertiajs/vue3';
import { ChevronDown, ExternalLink, Menu, X } from 'lucide-vue-next';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';

const { t } = useLocale();
const { navItems } = useNavigation();
const page = usePage();

/** المجموعة المفتوحة — واحدة في كل مرة، فالقوائم لا تتزاحم فوق بعضها. */
const openKey = ref<string | null>(null);
const mobileOpen = ref(false);

const toggle = (key: string) => (openKey.value = openKey.value === key ? null : key);

/**
 * المسار الحالي — يُبرز المجموعة التي تحوي الصفحة المفتوحة، فيعرف الموظف
 * أين هو دون أن يفتح القوائم واحدة واحدة.
 */
const currentUrl = computed(() => page.url.split('?')[0]);

/**
 * المدخل المضاء — واحدٌ لا اثنان: «تقويم القاعات» بادئةٌ لـ«التقويم الشهري»،
 * فالمطابقة بالبادئة وحدها كانت تُضيء الاثنين معًا.
 */
const activeChild = computed(() =>
    activeHref(
        currentUrl.value,
        navItems.value.flatMap((i) => (i.children ? i.children.map((c) => c.href) : [i.href])),
    ),
);

const isActive = (href: string) => activeChild.value === href;

const groupActive = (item: { href: string; children?: { href: string }[] }) =>
    item.children ? item.children.some((c) => urlBelongsTo(currentUrl.value, c.href)) : isActive(item.href);

// الانتقال إلى صفحة يُغلق ما فُتح: بقاء القائمة مفتوحة فوق الصفحة الجديدة
// يحجب أول ما يريد الموظف رؤيته.
watch(currentUrl, () => {
    openKey.value = null;
    mobileOpen.value = false;
});

// النقر خارج الشريط يُغلق المنسدلة — وكذلك Escape للوحة المفاتيح.
const root = ref<HTMLElement | null>(null);

const onPointerDown = (e: PointerEvent) => {
    if (root.value && !root.value.contains(e.target as Node)) openKey.value = null;
};

const onKeydown = (e: KeyboardEvent) => {
    if (e.key === 'Escape') openKey.value = null;
};

onMounted(() => {
    document.addEventListener('pointerdown', onPointerDown);
    document.addEventListener('keydown', onKeydown);
});

onBeforeUnmount(() => {
    document.removeEventListener('pointerdown', onPointerDown);
    document.removeEventListener('keydown', onKeydown);
});
</script>

<template>
    <nav ref="root" class="sticky top-[58px] z-20 border-b border-slate-200 bg-white shadow-sm print:hidden">
        <div class="flex items-center gap-1 px-2 md:px-4">
            <!-- زرّ القائمة في الشاشات الضيقة: الشريط الأفقي لا يتّسع للهاتف -->
            <button
                type="button"
                @click="mobileOpen = !mobileOpen"
                class="my-1.5 inline-flex items-center gap-1.5 rounded-lg bg-slate-100 px-3 py-2 text-sm font-extrabold text-slate-700 lg:hidden"
            >
                <component :is="mobileOpen ? X : Menu" class="h-4 w-4" />
                {{ t('nav.menu') }}
            </button>

            <!-- الشريط الأفقي -->
            <ul class="hidden flex-1 flex-wrap items-center gap-0.5 lg:flex">
                <li v-for="item in navItems" :key="item.href" class="relative">
                    <!-- مجموعة لها أبناء -->
                    <button
                        v-if="item.children"
                        type="button"
                        @click="toggle(item.href)"
                        class="inline-flex items-center gap-1.5 rounded-lg px-3 py-2.5 text-sm font-extrabold transition"
                        :class="
                            groupActive(item) || openKey === item.href
                                ? 'bg-slate-900 text-white'
                                : 'text-slate-700 hover:bg-slate-100'
                        "
                    >
                        <component :is="item.icon" class="h-4 w-4 shrink-0" />
                        <span class="whitespace-nowrap">{{ item.title }}</span>
                        <ChevronDown class="h-3.5 w-3.5 shrink-0 transition" :class="openKey === item.href ? 'rotate-180' : ''" />
                    </button>

                    <!-- مدخل مفرد -->
                    <Link
                        v-else
                        :href="item.href"
                        class="inline-flex items-center gap-1.5 rounded-lg px-3 py-2.5 text-sm font-extrabold transition"
                        :class="isActive(item.href) ? 'bg-slate-900 text-white' : 'text-slate-700 hover:bg-slate-100'"
                    >
                        <component :is="item.icon" class="h-4 w-4 shrink-0" />
                        <span class="whitespace-nowrap">{{ item.title }}</span>
                    </Link>

                    <!-- المنسدلة -->
                    <div
                        v-if="item.children && openKey === item.href"
                        class="absolute top-full z-30 mt-1 min-w-[240px] overflow-hidden rounded-xl border border-slate-200 bg-white py-1 shadow-lg ltr:left-0 rtl:right-0"
                    >
                        <Link
                            v-for="child in item.children"
                            :key="child.href"
                            :href="child.href"
                            class="flex items-center gap-2 px-3 py-2 text-sm font-bold transition"
                            :class="isActive(child.href) ? 'bg-slate-900 text-white' : 'text-slate-700 hover:bg-slate-100'"
                        >
                            <component :is="child.icon" class="h-4 w-4 shrink-0 opacity-70" />
                            <span class="truncate">{{ child.title }}</span>
                        </Link>
                    </div>
                </li>
            </ul>

            <a
                href="/"
                target="_blank"
                rel="noopener"
                class="brand-gradient my-1.5 hidden shrink-0 items-center gap-1.5 rounded-lg px-3 py-2 text-sm font-extrabold text-white hover:brightness-110 lg:inline-flex"
            >
                <ExternalLink class="h-4 w-4" />
                {{ t('nav.visit_site') }}
            </a>
        </div>

        <!-- قائمة الشاشات الضيقة: المجموعات مفرودة رأسيًا بلا انسدال -->
        <div v-if="mobileOpen" class="max-h-[70vh] overflow-y-auto border-t border-slate-200 px-2 py-2 lg:hidden">
            <div v-for="item in navItems" :key="item.href" class="mb-2 last:mb-0">
                <Link
                    v-if="!item.children"
                    :href="item.href"
                    class="flex items-center gap-2 rounded-lg px-3 py-2 text-sm font-extrabold"
                    :class="isActive(item.href) ? 'bg-slate-900 text-white' : 'text-slate-700 hover:bg-slate-100'"
                >
                    <component :is="item.icon" class="h-4 w-4" />
                    {{ item.title }}
                </Link>

                <template v-else>
                    <div class="flex items-center gap-2 px-3 py-1.5 text-xs font-extrabold text-slate-500">
                        <component :is="item.icon" class="h-3.5 w-3.5" />
                        {{ item.title }}
                    </div>
                    <Link
                        v-for="child in item.children"
                        :key="child.href"
                        :href="child.href"
                        class="flex items-center gap-2 rounded-lg px-3 py-2 text-sm font-bold ltr:ml-3 rtl:mr-3"
                        :class="isActive(child.href) ? 'bg-slate-900 text-white' : 'text-slate-700 hover:bg-slate-100'"
                    >
                        <component :is="child.icon" class="h-4 w-4 opacity-70" />
                        {{ child.title }}
                    </Link>
                </template>
            </div>

            <a href="/" target="_blank" rel="noopener" class="brand-gradient mt-2 flex items-center gap-2 rounded-lg px-3 py-2 text-sm font-extrabold text-white">
                <ExternalLink class="h-4 w-4" />
                {{ t('nav.visit_site') }}
            </a>
        </div>
    </nav>
</template>
