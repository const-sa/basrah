<script setup lang="ts">
import { activeHref, urlBelongsTo, useNavigation } from '@/composables/useNavigation';
import { Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

/**
 * اختصارات شاشات القسم الذي تنتمي إليه الصفحة المفتوحة.
 *
 * تُشتق من شجرة التنقّل لا تُكتب يدويًا في كل شاشة: الشاشة الجديدة تُسجَّل
 * في القائمة مرة واحدة فتظهر اختصارًا هنا من تلقائها، ولا تبقى صفحةٌ
 * باختصارات قديمة نسيها من أضاف الشاشة.
 */
const props = withDefaults(
    defineProps<{
        /** إخفاء مداخل بعينها بمسارها — لما لا يصلح اختصارًا في هذه الشاشة. */
        exclude?: string[];
        /** أقصى عدد يُعرض — الباقي يبقى في القائمة العلوية. */
        limit?: number;
    }>(),
    { exclude: () => [], limit: 0 },
);

const { navItems } = useNavigation();
const page = usePage();

const currentUrl = computed(() => page.url.split('?')[0]);

/** المجموعة التي تحوي الصفحة المفتوحة — أطول مطابقة تفوز حتى لا تُلتقط مجموعة عامة. */
const group = computed(() =>
    navItems.value
        .filter((item) => item.children?.some((c) => urlBelongsTo(currentUrl.value, c.href)))
        .sort((a, b) => {
            const best = (i: typeof a) =>
                Math.max(...(i.children ?? []).filter((c) => urlBelongsTo(currentUrl.value, c.href)).map((c) => c.href.length));
            return best(b) - best(a);
        })[0],
);

/** الصفحة المفتوحة بعينها — لا كل مدخل تطابقه بادئتها. */
const current = computed(() => activeHref(currentUrl.value, (group.value?.children ?? []).map((c) => c.href)));

const shortcuts = computed(() => {
    const items = (group.value?.children ?? [])
        // الصفحة المفتوحة ليست اختصارًا إلى نفسها
        .filter((c) => c.href !== current.value && !props.exclude.includes(c.href));

    return props.limit > 0 ? items.slice(0, props.limit) : items;
});
</script>

<template>
    <div v-if="shortcuts.length" class="flex flex-wrap items-center gap-1.5">
        <Link
            v-for="item in shortcuts"
            :key="item.href"
            :href="item.href"
            class="inline-flex items-center gap-1.5 rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm font-bold text-slate-700 shadow-sm transition hover:border-slate-300 hover:bg-slate-50"
        >
            <component :is="item.icon" class="h-4 w-4 shrink-0 text-slate-500" />
            <span class="whitespace-nowrap">{{ item.title }}</span>
        </Link>
    </div>
</template>
