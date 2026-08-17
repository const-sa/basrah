import { computed, ref } from 'vue';
import { messages, type Locale } from '@/i18n/messages';

const STORAGE_KEY = 'locale';

function getStored(): Locale {
    if (typeof window === 'undefined') return 'ar';
    const v = localStorage.getItem(STORAGE_KEY);
    return v === 'en' || v === 'ar' ? v : 'ar';
}

// حالة مشتركة على مستوى التطبيق (module-level) لتبقى متزامنة بين كل المكوّنات.
const locale = ref<Locale>(getStored());

function apply(l: Locale): void {
    if (typeof document === 'undefined') return;
    const el = document.documentElement;
    el.setAttribute('lang', l);
    el.setAttribute('dir', l === 'ar' ? 'rtl' : 'ltr');
}

// يُستدعى مرة واحدة عند الإقلاع (قبل تركيب Vue) لتطبيق الاتجاه المحفوظ.
export function initLocale(): void {
    apply(locale.value);
}

export function useLocale() {
    const direction = computed<'rtl' | 'ltr'>(() => (locale.value === 'ar' ? 'rtl' : 'ltr'));
    const isRtl = computed(() => locale.value === 'ar');

    const setLocale = (l: Locale): void => {
        locale.value = l;
        if (typeof window !== 'undefined') localStorage.setItem(STORAGE_KEY, l);
        apply(l);
    };

    const toggle = (): void => setLocale(locale.value === 'ar' ? 'en' : 'ar');

    // t('key') — يرجع الترجمة حسب اللغة الحالية، مع الرجوع للعربية ثم المفتاح نفسه.
    const t = (key: string): string => messages[locale.value][key] ?? messages.ar[key] ?? key;

    return { locale, direction, isRtl, setLocale, toggle, t };
}
