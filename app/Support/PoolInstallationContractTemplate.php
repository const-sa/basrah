<?php

namespace App\Support;

/**
 * The pools' piping-and-installation form — «عقد التمديد والتركيب».
 *
 * A single source for the text, read by the seeder that pins it and by any
 * screen that restores it; once seeded the database row is the reference and
 * is edited from «قوالب العقود».
 *
 * The form is drawn on a quotation, so the equipment it covers (filter, pump,
 * skimmer, lights…) is the quotation's priced lines — the contract prints them
 * as its own table, which is what the paper form's equipment grid was.
 *
 * ⚠️ The legal wording is a draft, reviewed with counsel before use.
 */
class PoolInstallationContractTemplate
{
    /** Template name — the matching key in the database, never changed. */
    public const NAME = 'عقد التمديد والتركيب';

    /**
     * The layout this form is printed on, frozen onto the contract when it is
     * drawn. The key travels with the snapshot rather than being read back off
     * the template, so renaming or deleting the template later cannot change
     * how a contract already issued prints.
     */
    public const FORM = 'pool_installation';

    /**
     * What the printed heading calls the contract. The heading already opens
     * with «عقد», so the form's own name is carried without that word.
     */
    public const SUBJECT = 'التمديد والتركيب';

    public const DESCRIPTION = 'نموذج عقد تمديد وتركيب المسابح — الدفعتان والضمان والتزامات الموقع';

    public const BODY = <<<'TXT'
عقد التمديد والتركيب رقم: {{contract_number}}
التاريخ: {{contract_date}}   الموافق: {{contract_date_hijri}} هـ

الطرف الأول: {{org_name}}
الطرف الثاني (المكرم/السادة): {{client_name}}
جوال: {{client_mobile}} — الهوية / السجل: {{client_id_number}}
عنوان الموقع: {{client_address}}

الموضوع: تنفيذ أعمال تمديد وتركيب مسبح لدى الطرف الثاني، بالمعدات والكميات
المبيّنة في عرض السعر رقم {{quotation_number}} بتاريخ {{quotation_date}}،
وهي نفسها بنود هذا العقد وجدوله.

قيمة العقد الكلية: {{total_amount}} ريال ({{total_amount_words}})
الدفعة الأولى (50% عند التعاقد): {{first_installment}} ريال
الدفعة الثانية (50% عند طلب توريد المعدات): {{second_installment}} ريال

يعتبر العقد ساري المفعول من تاريخ توقيع العقد ولمدة سنة كاملة، وإذا حصل تغيير
بعد عام من تاريخ توقيع العقد بالأسعار يلتزم الطرف الثاني بدفع الفرق.
TXT;

    /**
     * What the form prints in its «ملاحظات أو شروط أخرى» box — the warranty and
     * the site services, as the pad reads. The contract's validity is printed
     * by the footer band, so it is not repeated here.
     */
    public const TERMS = <<<'TXT'
• ضمان لمدة (5) سنوات على جرم الفلتر.
• ضمان لمدة (1) سنة على المضخة.
• الضمان لا يشمل سوء الاستخدام، ولا التشغيل قبل التسليم.

ملاحظة: على الطرف الثاني توفير خط ماء — كهرباء — خط صرف إلى غرفة الفلتر.

• لا تبدأ أعمال التمديد والتركيب إلا بعد سداد الدفعة الأولى ({{first_installment}} ريال)، وتُستحق الدفعة الثانية ({{second_installment}} ريال) عند طلب توريد المعدات.
• مقاسات المسبح والمعدات المثبتة في هذا العقد هي أساس التسعير، وأي تغيير فيها يُعاد تسعيره ويُتفق عليه كتابةً قبل تنفيذه.
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
