<?php

namespace App\Support;

/**
 * The halls' rental pad — «عقد إيجار», the numbered sheet the office writes at
 * the counter and the client signs before entry.
 *
 * A single source for the text, read by the seeder that pins it and by the
 * screen that restores it; once seeded the database row is the reference and is
 * edited from «قوالب العقود».
 *
 * ⚠️ The legal wording is a draft, reviewed with counsel before use.
 */
class HallRentalContractTemplate
{
    /** Template name — the matching key in the database, never changed. */
    public const NAME = 'عقد إيجار القاعة';

    /**
     * The layout the contract is printed on, frozen onto it when it is drawn —
     * see PoolInstallationContractTemplate::FORM.
     */
    public const FORM = 'hall_rental';

    /** The heading already opens with «عقد», so the form names the rest. */
    public const SUBJECT = 'إيجار قاعة';

    public const DESCRIPTION = 'نموذج عقد إيجار القاعة — بيانات المستأجر والمدة والشروط الأربعة عشر';

    public const BODY = <<<'TXT'
عقد إيجار رقم: {{contract_number}}
التاريخ: {{contract_date}}   الموافق: {{contract_date_hijri}} هـ

تم الاتفاق بين {{org_name}} للاحتفالات والمناسبات
والطرف الثاني (المستأجر): {{client_name}}
سجل مدني: {{client_id_number}} — مكان الميلاد: {{client_birth_place}}
جوال رقم: {{client_mobile}}

تاريخ الدخول: {{booking_date}} — الموافق {{booking_date_hijri}} هـ — يوم {{check_in_day}}
قسم رقم: {{sections}}
قيمة الإيجار: {{total_amount}} ريال — واصل: {{deposit_amount}} ريال — باقي: {{remaining_amount}} ريال
TXT;

    /**
     * The fourteen conditions the pad prints, as they stand on the paper.
     */
    public const TERMS = <<<'TXT'
اتفق الطرف الأول مع الطرف الثاني على الشروط التالية:

1 - تعتبر المقدمة جزءًا لا يتجزأ من هذا العقد.
2 - يلتزم الطرف الثاني بالقيم الإسلامية وتعاليم الدين الإسلامي، وعدم إحضار أي منكرات أو اختلاط يخالف الشريعة الإسلامية، وعدم إحضار الأدوات الموسيقية والأورغ والشيشة، ويكون مسؤولًا مسؤولية تامة أمام الجهات المختصة (هيئة الأمر بالمعروف والنهي عن المنكر) والجهات الحكومية الأخرى.
3 - في حالة اتفاق الطرف الثاني مع المكتب على توفير بعض الخدمات للمناسبة فلا بد أن تكون مسجلة ومتفقًا عليها في عقد الخدمات وتحضيرها في الموعد المحدد، وإذا لم تُكتب الخدمات في العقد المخصص لها ويوقّع المستأجر عليها فالقاعة غير مسؤولة عن عدم تحضيرها للمستأجر.
4 - لم يتم تأكيد الحجز إلا بعد دفع نصف المبلغ أو أكثر، ودفع باقي المبلغ قبل الدخول بأسبوع على الأقل مع إحضار أصل العقد.
5 - لا بد أن تكون جميع البيانات المسجلة في هذا العقد مكتوبة بشكل صحيح وكامل من بطاقة الأحوال للمستأجر.
6 - القاعة غير مسؤولة عن بعض الخدمات التي أحضرها معه الطرف الثاني من مطاعم (صحون وأطباق وغيرهما) ومحلات تزيين (طاولات وكراسي وكوشات وديجيه وغيرهما) لأنها ستوضع خارج القاعة بعد خروج المستأجر مباشرة.
7 - يلتزم الطرف الثاني المستأجر بالمحافظة على القاعة والنظافة، وهو مسؤول مسؤولية تامة عما يحدث في القاعة من مشاكل أو حوادث كهرباء تعرّض الأطفال للإصابات، أو إصابات قد تنتج من الطاولات الزجاجية، والطرف الأول غير مسؤول عن أي إصابات داخل القاعة.
8 - إن الطرف الأول والعمال غير مسؤولين تمامًا عن أي مفقودات داخل القاعة وخارجها أو أغراض شخصية بعد خروج المستأجر، وعدم مطالبة الطرف الأول بأي تعويضات عن المفقودات.
9 - في حالة انقطاع الكهرباء من قبل الشركة السعودية للكهرباء فالطرف الأول غير مسؤول عن ذلك نهائيًا.
10 - عند الإخلال بأي شرط من شروط العقد فإن للطرف الأول فسخ العقد وإخراج المستأجر من القاعة، وليس له المطالبة برد الإيجار.
11 - في حالة دخول طقاقة يُدفع مبلغ 500 ريال.
12 - العربون لا يُرد نهائيًا.
13 - الدخول يكون في الساعة الثالثة عصرًا والخروج الساعة الثالثة منتصف الليل، وقت المناسبة 12 ساعة فقط، ويُفصل الكهرباء من العداد الرئيسي بعد الساعة الثالثة، ويُدفع مبلغ 300 ريال تأمين قبل الدخول، وفي حالة تمديد الوقت يُدفع عن كل ساعة مبلغ 200 ريال.
14 - يلتزم المستأجر بدفع 100 ريال في حال تسليم القاعة غير نظيفة.
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
