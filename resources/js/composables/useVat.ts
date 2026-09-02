import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

/**
 * مفتاح الضريبة كما يشاركه HandleInertiaRequests مع كل صفحة.
 *
 * الشاشات كانت تقرأ نسبة الضريبة من الصنف نفسه، والصنف يحملها في جدوله —
 * فيُطفأ المفتاح في الإعدادات وتبقى الأعمدة والسطور تُعرض وتُحتسب. فصار
 * السؤال يُوجَّه هنا: عمودُ ضريبةٍ لا يُرسم إلا و`applies` صحيحة.
 *
 * ملاحظة: هذا للعرض لا للحماية — الخادم يصفّر ما يصله من ضريبة حين تكون
 * مطفأة، فلا تُحفظ ضريبة بشاشةٍ قديمة بقيت مفتوحة.
 */
export function useVat() {
    const page = usePage();

    const vat = computed(() => (page.props.vat ?? { applies: false, rate: 0 }) as { applies: boolean; rate: number });

    /** هل الضريبة سارية؟ التفعيل والرقم الضريبي والنسبة شروطٌ مجتمعة على الخادم. */
    const applies = computed(() => vat.value.applies);

    /** النسبة السارية — صفرٌ حين لا ضريبة. */
    const rate = computed(() => vat.value.rate);

    /**
     * هل تُعرض ضريبة هذه الورقة؟
     *
     * الورقة الصادرة تُقرأ بما حُرِّرت به لا بما في الإعدادات اليوم: فاتورةٌ
     * حُصِّلت ضريبتها تبقى سطورها ظاهرةً بعد إطفاء المفتاح، وإلا أنكر النظام
     * مبلغًا أخذه فعلًا. أما شاشات الإدخال فتتبع المفتاح وحده.
     */
    const shows = (taxAmount: number | null | undefined): boolean => applies.value || (taxAmount ?? 0) > 0;

    return { applies, rate, shows };
}
