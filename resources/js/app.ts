import '../css/app.css';

import { createInertiaApp, router } from '@inertiajs/vue3';
import axios from 'axios';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import type { DefineComponent } from 'vue';
import { createApp, h } from 'vue';
import { ZiggyVue } from '../../vendor/tightenco/ziggy';
import { initializeTheme } from './composables/useAppearance';
import { initLocale } from './composables/useLocale';
import { installInputGuards } from './lib/inputGuards';

// تطبيق اللغة/الاتجاه المحفوظ قبل تركيب التطبيق لتفادي وميض الاتجاه.
initLocale();

// رمز CSRF يُقرأ من كعكة باسم خاص بهذا التطبيق (يضبطه ValidateCsrfToken في الخادم)،
// لأن الاسم الافتراضي XSRF-TOKEN مشترك بين تطبيقات لارافيل جميعها والمتصفح لا يفرّق
// بين المنافذ، فتشغيل تطبيق آخر على 127.0.0.1 كان يدهس الرمز ويسبب خطأ 419 المتكرر.
const xsrfCookie = document.querySelector('meta[name="xsrf-cookie"]')?.getAttribute('content');
if (xsrfCookie) {
    axios.defaults.xsrfCookieName = xsrfCookie;
}

// Extend ImportMeta interface for Vite...
declare module 'vite/client' {
    interface ImportMetaEnv {
        readonly VITE_APP_NAME: string;
        [key: string]: string | boolean | undefined;
    }

    interface ImportMeta {
        readonly env: ImportMetaEnv;
        readonly glob: <T>(pattern: string) => Record<string, () => Promise<T>>;
    }
}

const appName = import.meta.env.VITE_APP_NAME || 'Laravel';

createInertiaApp({
    title: (title) => `${title} - ${appName}`,
    resolve: (name) => resolvePageComponent(`./pages/${name}.vue`, import.meta.glob<DefineComponent>('./pages/**/*.vue')),
    setup({ el, App, props, plugin }) {
        createApp({ render: () => h(App, props) })
            .use(plugin)
            .use(ZiggyVue)
            .mount(el);
    },
    progress: {
        color: '#10b981',
        showSpinner: true,
        delay: 100,
    },
});

// معالجة انتهاء صلاحية الجلسة (419): بدلاً من ترك المستخدم على صفحة «انتهت صلاحية الجلسة»،
// نُعلمه ثم نعيد تحميل الصفحة الحالية لاستعادة رمز CSRF صالح ليتمكن من إعادة المحاولة مباشرة.
router.on('invalid', (event) => {
    if (event.detail.response?.status === 419) {
        event.preventDefault();
        alert('انتهت صلاحية الجلسة. سيتم تحديث الصفحة، ثم أعد المحاولة.');
        window.location.reload();
    }
});

// This will set light / dark mode on page load...
initializeTheme();

// حُرّاس الإدخال: حقول الأرقام تمنع الحروف، وحقول الأحرف (data-field="letters") تمنع الأرقام.
installInputGuards();
