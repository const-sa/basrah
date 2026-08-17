/**
 * التاريخ الهجري بجانب الميلادي.
 *
 * يُحسب بـ Intl مع تقويم أم القرى — وهو تقويم السعودية الرسمي وموجود في
 * المتصفحات الحديثة، فلا حاجة لمكتبة تحويل تُصان يدويًا كل سنة.
 */

const HIJRI_LOCALE = 'ar-SA-u-ca-islamic-umalqura-nu-latn';

const longFormatter = new Intl.DateTimeFormat(HIJRI_LOCALE, {
    day: 'numeric',
    month: 'long',
    year: 'numeric',
});

const numericFormatter = new Intl.DateTimeFormat(HIJRI_LOCALE, {
    day: '2-digit',
    month: '2-digit',
    year: 'numeric',
});

const weekdayFormatter = new Intl.DateTimeFormat('ar-SA-u-nu-latn', { weekday: 'long' });

/**
 * التاريخ يُقرأ ظهرًا لا منتصف الليل: إزاحة المنطقة الزمنية عند الحد قد تُرجع
 * اليوم السابق، فيظهر هجري لا يطابق الميلادي المكتوب.
 */
const parse = (date: string | null | undefined): Date | null => {
    if (!date) return null;

    const parsed = new Date(`${date.slice(0, 10)}T12:00:00`);

    return Number.isNaN(parsed.getTime()) ? null : parsed;
};

/** ١٤ رجب ١٤٤٧ هـ */
export const toHijri = (date: string | null | undefined): string => {
    const parsed = parse(date);

    return parsed ? `${longFormatter.format(parsed)} هـ` : '';
};

/** 1447/07/14 هـ */
export const toHijriShort = (date: string | null | undefined): string => {
    const parsed = parse(date);

    return parsed ? `${numericFormatter.format(parsed)} هـ` : '';
};

/** اسم اليوم بالعربية — يوضّح إن كان الحجز في نهاية الأسبوع. */
export const weekdayName = (date: string | null | undefined): string => {
    const parsed = parse(date);

    return parsed ? weekdayFormatter.format(parsed) : '';
};
