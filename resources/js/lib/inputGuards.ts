/**
 * حُرّاس إدخال على مستوى النظام:
 *  - حقول الأرقام (type=number أو inputmode=numeric/decimal أو data-field="number")
 *    تمنع إدخال الحروف تلقائياً.
 *  - حقول الأحرف (data-field="letters" أو class="field-letters")
 *    تمنع إدخال الأرقام.
 *
 * يُركّب مرة واحدة على مستوى المستند، فيغطّي كل النماذج الحالية والمستقبلية
 * دون الحاجة لتعديل كل حقل على حدة.
 */

type Field = HTMLInputElement | HTMLTextAreaElement;

// أرقام عربية/فارسية + لاتينية
const DIGITS = /[0-9٠-٩۰-۹]/;
// أي محرف ليس رقماً لاتينياً أو نقطة عشرية أو سالب
const NON_NUMERIC = /[^0-9.\-]/;

const isNumberField = (el: Field): boolean =>
    el instanceof HTMLInputElement &&
    (el.type === 'number' ||
        el.inputMode === 'numeric' ||
        el.inputMode === 'decimal' ||
        el.dataset.field === 'number');

const isLettersField = (el: Field): boolean =>
    el.dataset.field === 'letters' || el.classList.contains('field-letters');

const editableTarget = (t: EventTarget | null): Field | null => {
    if (t instanceof HTMLInputElement || t instanceof HTMLTextAreaElement) return t;
    return null;
};

export function installInputGuards(): void {
    // منع الكتابة/اللصق غير المسموح (يغطّي الكتابة واللصق والسحب في المتصفحات الحديثة)
    document.addEventListener(
        'beforeinput',
        (e: Event) => {
            const ie = e as InputEvent;
            const el = editableTarget(ie.target);
            if (!el) return;

            const data = ie.data;
            if (data == null || data === '') return; // حذف أو إدخال غير نصي

            if (isNumberField(el)) {
                if (NON_NUMERIC.test(data)) ie.preventDefault();
            } else if (isLettersField(el)) {
                if (DIGITS.test(data)) ie.preventDefault();
            }
        },
        true,
    );

    // احتياط للّصق في المتصفحات التي تُرجع data=null في beforeinput
    document.addEventListener(
        'paste',
        (e: ClipboardEvent) => {
            const el = editableTarget(e.target);
            if (!el) return;

            const text = e.clipboardData?.getData('text') ?? '';
            if (!text) return;

            if (isNumberField(el) && NON_NUMERIC.test(text)) e.preventDefault();
            else if (isLettersField(el) && DIGITS.test(text)) e.preventDefault();
        },
        true,
    );
}
