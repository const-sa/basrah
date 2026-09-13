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

/** ١٤ رجب ١٤٤٧ هـ — Intl prints the era, so never append it again. */
export const toHijri = (date: string | null | undefined): string => {
    const parsed = parse(date);

    return parsed ? longFormatter.format(parsed) : '';
};

/** 1447/07/14 هـ */
export const toHijriShort = (date: string | null | undefined): string => {
    const parsed = parse(date);

    return parsed ? numericFormatter.format(parsed) : '';
};

/** اسم اليوم بالعربية — يوضّح إن كان الحجز في نهاية الأسبوع. */
export const weekdayName = (date: string | null | undefined): string => {
    const parsed = parse(date);

    return parsed ? weekdayFormatter.format(parsed) : '';
};

// ── Picking a date in Hijri ─────────────────────────────────

const MS_DAY = 86_400_000;

/** Month names in order — index 0 is Muharram. */
export const HIJRI_MONTHS = [
    'محرم', 'صفر', 'ربيع الأول', 'ربيع الآخر', 'جمادى الأولى', 'جمادى الآخرة',
    'رجب', 'شعبان', 'رمضان', 'شوال', 'ذو القعدة', 'ذو الحجة',
];

export interface HijriDate {
    year: number;
    month: number;
    day: number;
}

// Latin digits read in UTC: this is arithmetic, not display, and a timezone
// offset would shift the day.
const partsFormatter = new Intl.DateTimeFormat('en-US-u-ca-islamic-umalqura-nu-latn', {
    day: 'numeric',
    month: 'numeric',
    year: 'numeric',
    timeZone: 'UTC',
});

/** UTC noon — either edge of the day lands on another one under an offset. */
const noonOf = (date: string): number => {
    const [year, month, day] = date.slice(0, 10).split('-').map(Number);

    return Date.UTC(year, month - 1, day, 12);
};

const partsAt = (ms: number): HijriDate => {
    const out: HijriDate = { year: 0, month: 0, day: 0 };

    for (const part of partsFormatter.formatToParts(new Date(ms))) {
        if (part.type === 'year' || part.type === 'month' || part.type === 'day') {
            out[part.type] = Number(part.value);
        }
    }

    return out;
};

/** The Hijri parts of a Gregorian date. */
export const hijriOf = (date: string | null | undefined): HijriDate | null =>
    date ? partsAt(noonOf(date)) : null;

// Mean lengths, used only to seed the search below.
const MEAN_YEAR = 354.367;
const MEAN_MONTH = 29.5305;

/** 1 Muharram 1 AH in Gregorian. */
const EPOCH = Date.UTC(622, 6, 19, 12);

/** A rising key — a day never exceeds 30, so months cannot overlap. */
const rank = (h: HijriDate): number => (h.year * 12 + h.month) * 32 + h.day;

/**
 * The Gregorian date for a Hijri day. Umm al-Qura month lengths are observed,
 * not computed, so the day is estimated and then binary-searched for exactly.
 */
export const hijriToDate = (year: number, month: number, day: number): string => {
    const target = rank({ year, month, day });
    const seed = EPOCH
        + (Math.floor((year - 1) * MEAN_YEAR) + Math.floor((month - 1) * MEAN_MONTH) + day) * MS_DAY;

    let low = seed - 90 * MS_DAY;
    let high = seed + 90 * MS_DAY;

    while (low < high) {
        const mid = low + Math.floor((high - low) / MS_DAY / 2) * MS_DAY;

        if (rank(partsAt(mid)) < target) {
            low = mid + MS_DAY;
        } else {
            high = mid;
        }
    }

    return new Date(low).toISOString().slice(0, 10);
};

/** Days in a Hijri month — 29 or 30, and no rule decides which. */
export const hijriMonthLength = (year: number, month: number): number => {
    const start = hijriToDate(year, month, 1);
    const next = month === 12 ? hijriToDate(year + 1, 1, 1) : hijriToDate(year, month + 1, 1);

    return Math.round((noonOf(next) - noonOf(start)) / MS_DAY);
};
