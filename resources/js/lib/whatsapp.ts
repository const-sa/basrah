/**
 * رابط واتساب بصيغة دولية بلا + أو أصفار — نفس تطبيع البوابة في الخادم.
 *
 * رقمٌ محلي (يبدأ بصفر أو قصير) يُفترض سعوديًا، وnull إن لم يُضبط رقم أصلًا:
 * زرّ تواصلٍ لا يعمل أسوأ من زرٍّ لا يظهر.
 */
export function whatsappLink(raw: string | null | undefined): string | null {
    if (!raw) return null;
    let digits = raw.replace(/\D+/g, '');
    if (!digits) return null;
    if (digits.startsWith('00')) digits = digits.slice(2);
    if (digits.startsWith('0')) digits = `966${digits.slice(1)}`;
    else if (digits.length <= 9) digits = `966${digits}`;
    return `https://wa.me/${digits}`;
}
