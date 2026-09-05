/**
 * ألوان حالات الحجز الأربع — مصدر واحد لكل الشاشات.
 *
 * المفاتيح هي القيم القادمة من `Booking::STATUS_COLORS` في الخادم، والصيغ
 * الثلاث هي أشكال العرض المستعملة: حبّة في الجداول، شريط في التقويم، ونقطة
 * في دليل الألوان. كانت مكرّرة في ستّ شاشات فتُنسى إحداها عند إضافة حالة.
 *
 * Tailwind يقرأ أصناف الأنماط نصًّا، فتُكتب كاملة لا مركّبة.
 */

/** حبّة الحالة في الجداول — خلفية فاتحة ونص غامق. */
export const statusChipClass = (color: string): string =>
    ({
        amber: 'bg-amber-100 text-amber-700',
        emerald: 'bg-emerald-100 text-emerald-700',
        violet: 'bg-violet-100 text-violet-700',
        red: 'bg-red-100 text-red-700',
    })[color] ?? 'bg-slate-100 text-slate-700';

/** حبّة الحالة بحلقة — في التقويم الشهري حيث الخلية ملوّنة أصلًا. */
export const statusRingChipClass = (color: string): string =>
    ({
        amber: 'bg-amber-100 text-amber-800 ring-amber-200',
        emerald: 'bg-emerald-100 text-emerald-800 ring-emerald-200',
        violet: 'bg-violet-100 text-violet-800 ring-violet-200',
        red: 'bg-red-100 text-red-800 ring-red-200',
    })[color] ?? 'bg-slate-100 text-slate-700 ring-slate-200';

/** شريط الحجز في التقويم — لون صلب يُقرأ من بعيد. */
export const statusBarClass = (color: string): string =>
    ({
        amber: 'bg-amber-400 text-amber-950',
        emerald: 'bg-emerald-500 text-white',
        violet: 'bg-violet-500 text-white',
        red: 'bg-red-500 text-white',
    })[color] ?? 'bg-slate-300 text-slate-800';

/** نقطة دليل الألوان. */
export const statusDotClass = (color: string): string =>
    ({
        amber: 'bg-amber-400',
        emerald: 'bg-emerald-500',
        violet: 'bg-violet-500',
        red: 'bg-red-500',
    })[color] ?? 'bg-slate-300';

/** نقطة أغمق — في مساحة عمل الوحدة على خلفية فاتحة. */
export const statusSolidClass = (color: string): string =>
    ({
        amber: 'bg-amber-500',
        emerald: 'bg-emerald-600',
        violet: 'bg-violet-600',
        red: 'bg-red-600',
    })[color] ?? 'bg-slate-400';

/**
 * الحالات النهائية — تطابق `Booking::CLOSED_STATUSES`. لا تذكير عليها
 * ولا انتقال بعدها.
 */
export const CLOSED_STATUSES = ['postponed', 'cancelled'];

export const isClosedStatus = (status: string): boolean => CLOSED_STATUSES.includes(status);
