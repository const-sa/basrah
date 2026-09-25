<script setup lang="ts">
import type { SharedData } from '@/types';
import { usePage } from '@inertiajs/vue3';
import { watch } from 'vue';

/**
 * «حفظ وطباعة» — يطبع ورقة المستند المحفوظ للتو دون مغادرة الشاشة.
 *
 * الخادم يعيد رابط الورقة في `flash.print`، فتُحمَّل في إطار خفي بـ
 * `?print=1` وتطبع نفسها (useAutoPrint)، ويبقى الموظف حيث هو ليكمل عمله.
 */
const page = usePage<SharedData>();

// الرجوع في سجل المتصفح يعيد خصائص الصفحة كما كانت، ومعها رابط الطباعة —
// فيُحفظ ما طُبع كي لا تخرج الورقة مرتين.
const PRINTED_KEY = 'printed-after-save';

const alreadyPrinted = (url: string): boolean => {
    try {
        return (JSON.parse(sessionStorage.getItem(PRINTED_KEY) ?? '[]') as string[]).includes(url);
    } catch {
        return false;
    }
};

const markPrinted = (url: string) => {
    try {
        const list = (JSON.parse(sessionStorage.getItem(PRINTED_KEY) ?? '[]') as string[]).slice(-20);
        sessionStorage.setItem(PRINTED_KEY, JSON.stringify([...list, url]));
    } catch {
        // بلا تخزين: أسوأ الاحتمالات طباعة مكررة عند الرجوع، لا فقدان الطباعة.
    }
};

const printInFrame = (url: string) => {
    const frame = document.createElement('iframe');
    frame.setAttribute('aria-hidden', 'true');
    // خارج الشاشة لا مخفيًا: بعض المتصفحات لا تطبع إطارًا بـ display:none.
    frame.style.cssText = 'position:fixed;right:-10000px;bottom:0;width:1024px;height:800px;border:0;';

    const cleanup = () => setTimeout(() => frame.remove(), 1000);

    frame.addEventListener('load', () => {
        frame.contentWindow?.addEventListener('afterprint', cleanup, { once: true });
    });
    // احتياط إن لم يصل afterprint (أُغلقت النافذة أو تعذّر التحميل).
    setTimeout(() => frame.isConnected && frame.remove(), 5 * 60 * 1000);

    frame.src = url + (url.includes('?') ? '&' : '?') + 'print=1';
    document.body.appendChild(frame);
};

watch(
    () => page.props.flash?.print,
    (url) => {
        if (!url || alreadyPrinted(url)) return;
        markPrinted(url);
        printInFrame(url);
    },
    { immediate: true },
);
</script>

<template>
    <span class="hidden" />
</template>
