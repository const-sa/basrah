import { nextTick, onMounted, watch, type WatchSource } from 'vue';

/**
 * هل فُتحت الصفحة لتُطبع؟ — «حفظ وطباعة» يحمّل ورقة المستند بـ `?print=1`
 * داخل إطار خفي، فتطبع نفسها ما إن تكتمل.
 */
export const wantsPrint = (): boolean => new URLSearchParams(window.location.search).get('print') === '1';

/** الطباعة بعد اكتمال الصور (الشعار ورمز QR) والخطوط — وإلا خرجت الورقة ناقصة. */
const printWhenLoaded = async () => {
    await nextTick();
    await Promise.all(
        Array.from(document.images)
            .filter((img) => !img.complete)
            .map(
                (img) =>
                    new Promise((resolve) => {
                        img.addEventListener('load', resolve, { once: true });
                        img.addEventListener('error', resolve, { once: true });
                    }),
            ),
    );
    await document.fonts?.ready;
    window.print();
};

/**
 * يطبع الورقة تلقائيًا حين تُفتح بـ `?print=1`.
 *
 * `ready` للصفحات التي تجلب محتواها بعد التحميل (كنافذة الفاتورة في سجل
 * المبيعات): تُطبع حين يصير صحيحًا، لا عند التركيب.
 */
export function useAutoPrint(ready?: WatchSource<boolean>) {
    if (!wantsPrint()) return;

    if (!ready) {
        onMounted(printWhenLoaded);
        return;
    }

    // مرة واحدة: إعادة جلب المحتوى لاحقًا (بعد سند قبض مثلًا) لا تعيد الطباعة.
    let printed = false;

    watch(
        ready,
        (isReady) => {
            if (!isReady || printed) return;
            printed = true;
            printWhenLoaded();
        },
        { immediate: true },
    );
}
