<?php

namespace App\Support;

/**
 * The halls' services list — the second paper of an event, priced line by line,
 * required by the rental pad's third condition. Legal wording is a draft.
 */
class HallServicesContractTemplate
{
    /** Template name — the matching key in the database, never changed. */
    public const NAME = 'عقد وقائمة الخدمات للمناسبة';

    /** The layout it prints on — see PoolInstallationContractTemplate::FORM. */
    public const FORM = 'hall_services';

    /** The heading already opens with «عقد», so the form names the rest. */
    public const SUBJECT = 'خدمات المناسبة';

    public const DESCRIPTION = 'نموذج عقد وقائمة الخدمات للمناسبة — الخدمات المطبوعة بأسعارها وأعدادها';

    /**
     * The services the pad prints, in the order it rules them; a unit the paper
     * names beside a line is kept with it, so the price is read against it.
     */
    public const SERVICES = [
        'قهوجي + عدد (صباب)',
        'قهوجية + عدد (صبابة)',
        'مشرفة عبايات',
        'عاملة نظافة',
        'عاملة تقديم حلويات',
        'مشرفة استقبال وترحيب بالضيوف',
        'مشرفة منصة',
        'مشرفة جوالات',
        'مشرفة لفستان العروسة',
        'مصورة فوتوغرافية',
        'مصور فوتوغرافي',
        'دي جي مع المشرفة',
        'دخول طقاقة في القاعة',
        'جهاز بخار',
        'جهاز متابعة العروسة',
        'كوشة',
        'تزيين طاولات',
        'ممر بأنواعه زجاجي أو عادي',
        'شمعدان',
        'فطاير وحلويات',
        'بوفيه مفتوح (للشخص)',
        'إضافة بوفيه (للشخص)',
        'حارس لبوابة الحرم',
        'تزيين الدرج',
        'كراسي بأنواعها',
        'بروجكتور مع الشاشة',
        'ستاير',
        'تلبيسات',
        'سماعات',
        'طاولات استقبال',
    ];

    public const BODY = <<<'TXT'
عقد وقائمة الخدمات للمناسبة رقم: {{contract_number}}
التاريخ: {{contract_date}}   الموافق: {{contract_date_hijri}} هـ

رقم عقد التأجير: {{booking_reference}} — قسم رقم: {{sections}}
اليوم: {{check_in_day}} — تاريخ المناسبة: {{booking_date}}

الطرف الأول (المؤجر): {{org_name}}
الطرف الثاني (المستأجر): {{client_name}} — جوال: {{client_mobile}}

اتفق الطرفان على تقديم الخدمات المبيّنة في جدول هذا العقد بأعدادها وأسعارها.

الإجمالي: {{total_amount}} ريال — المدفوع: {{deposit_amount}} ريال — الباقي: {{remaining_amount}} ريال
TXT;

    /** What the sheet prints in its «ملحوظة» box, as the paper reads. */
    public const TERMS = <<<'TXT'
ملحوظة:
• ممنوع دخول طقٍّ عن الرجال.
• عشاء الرجال كامل من خارج القاعة.
TXT;

    /**
     * The printed list as contract lines, counted and priced beside each.
     *
     * @return list<array<string, mixed>>
     */
    public static function lines(): array
    {
        return array_map(fn (string $name) => [
            'name' => $name,
            'code' => null,
            'quantity' => '',
            'unit_price' => '',
            'total_price' => '',
            'notes' => null,
        ], self::SERVICES);
    }

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
