<?php

namespace App\Support;

/**
 * The pools' monthly-maintenance sheet — «عرض سعر صيانة مسابح شهريًا».
 *
 * A single source for the text, read by the seeder that pins it and by any
 * screen that restores it; once seeded the database row is the reference and
 * is edited from «قوالب العقود».
 *
 * A different paper from the installation pad: no equipment grid and no two
 * payments, but a priced line table with a discount under it and the visit
 * schedule in its notes — maintenance is billed monthly, not on delivery.
 *
 * ⚠️ The legal wording is a draft, reviewed with counsel before use.
 */
class PoolMaintenanceContractTemplate
{
    /** Template name — the matching key in the database, never changed. */
    public const NAME = 'عرض سعر الصيانة الشهرية';

    /**
     * The layout this sheet is printed on, frozen onto the contract when it is
     * drawn — see PoolInstallationContractTemplate::FORM.
     */
    public const FORM = 'pool_maintenance';

    /** What the register calls it, and what the standard sheet would head it. */
    public const SUBJECT = 'صيانة مسابح شهريًا';

    /** The heading the sheet itself prints, as the paper reads. */
    public const HEADING = 'عرض سعر صيانة مسابح شهريًا';

    public const DESCRIPTION = 'نموذج عرض سعر صيانة المسابح الشهرية — البنود والخصم والزيارات';

    public const BODY = <<<'TXT'
عرض سعر صيانة مسابح شهريًا رقم: {{contract_number}}
التاريخ: {{contract_date}}   الموافق: {{contract_date_hijri}} هـ

إلى السادة: {{client_name}}
جوال: {{client_mobile}} — الهوية / السجل: {{client_id_number}}
عنوان الموقع: {{client_address}}

الموضوع: تنظيف وصيانة المسبح شهريًا شامل المواد، بالبنود والكميات المبيّنة
في جدول هذا العرض.

الإجمالي: {{subtotal}} ريال
الخصم: {{discount_amount}} ريال
الإجمالي المستحق: {{total_amount}} ريال ({{total_amount_words}})
TXT;

    /**
     * What the sheet prints in its «ملاحظات» box — when the month is paid for,
     * what the visit covers, and how often it comes.
     */
    public const TERMS = <<<'TXT'
• يدفع المبلغ بداية كل شهر ميلادي.
• الصيانة والتنظيف تشمل المواد الكيماوية والتعقيم.
• الزيارات 8 مرات في الشهر يومي السبت والثلاثاء.
TXT;

    /**
     * The attributes the seeder pins, or that restore the row if it is gone.
     *
     * @return array<string, mixed>
     */
    public static function attributes(): array
    {
        return [
            'description' => self::DESCRIPTION,
            'body' => self::BODY,
            'terms' => self::TERMS,
            'is_active' => true,
        ];
    }
}
