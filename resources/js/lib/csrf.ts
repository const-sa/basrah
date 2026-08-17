/**
 * رمز CSRF لطلبات fetch اليدوية.
 *
 * اسم الكعكة خاص بهذا التطبيق لا "XSRF-TOKEN" الموحّد (انظر
 * App\Http\Middleware\ValidateCsrfToken)، ويصل الواجهةَ من وسم
 * <meta name="xsrf-cookie">. قراءته من مكان واحد تمنع تكرار الاسم في نداءات
 * fetch المتفرقة — وتكراره هناك كان يعني رمزًا فارغًا وردًّا بخطأ 419.
 *
 * أما axios فيضبطه app.ts مرة واحدة عبر xsrfCookieName ولا يحتاج هذا.
 */
const cookieName = () =>
    document.querySelector('meta[name="xsrf-cookie"]')?.getAttribute('content') ?? 'XSRF-TOKEN';

export const csrfToken = (): string => {
    const name = cookieName().replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
    const match = document.cookie.match(new RegExp(`(?:^|;\\s*)${name}=([^;]*)`));

    return match ? decodeURIComponent(match[1]) : '';
};

/** ترويسات طلب JSON مصادَق عليه — النمط المتكرر في نداءات fetch. */
export const jsonHeaders = (): Record<string, string> => ({
    'Content-Type': 'application/json',
    Accept: 'application/json',
    'X-Requested-With': 'XMLHttpRequest',
    'X-XSRF-TOKEN': csrfToken(),
});
