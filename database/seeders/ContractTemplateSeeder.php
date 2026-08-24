<?php

namespace Database\Seeders;

use App\Models\ContractTemplate;
use App\Support\ChaletContractTemplate;
use App\Support\HallContractTemplate;
use Illuminate\Database\Seeder;

/**
 * The pinned contract templates: the standard one, plus the hall and chalet forms.
 * ⚠️ The legal wording is a draft, reviewed with counsel before use (§4.5).
 */
class ContractTemplateSeeder extends Seeder
{
    public function run(): void
    {
        // نموذج عقد القاعات — نصه في App\Support\HallContractTemplate
        // ليقرأه السيدر وشاشة «نموذج العقد» من مصدر واحد.
        // firstOrCreate لا updateOrCreate: إعادة تشغيل السيدر لا تمحو تعديلات
        // الإدارة على النص، ولاستعادة الأصل زر مستقل في الشاشة.
        ContractTemplate::firstOrCreate(
            ['name' => HallContractTemplate::NAME],
            HallContractTemplate::attributes(),
        );

        // The chalets' daily-rental form. Picked automatically for every chalet
        // booking, so it never has to be promoted to the default template.
        ContractTemplate::firstOrCreate(
            ['name' => ChaletContractTemplate::NAME],
            ChaletContractTemplate::attributes(),
        );

        ContractTemplate::updateOrCreate(
            ['name' => 'عقد حجز قياسي'],
            [
                'description' => 'قالب عقد حجز الوحدات والأقسام',
                'is_default' => true,
                'is_active' => true,
                'body' => <<<'TXT'
عقد حجز رقم: {{contract_number}}
التاريخ: {{contract_date}}

الطرف الأول: {{org_name}}
الطرف الثاني: {{client_name}} — جوال: {{client_mobile}} — الهوية: {{client_id_number}}

اتفق الطرفان على حجز ما يلي:

رقم الحجز: {{booking_reference}}
الوحدة: {{unit_name}}
النطاق المحجوز: {{sections}}
التاريخ: {{booking_date}}
الفترة: {{period}} (من {{starts_at}} إلى {{ends_at}})
عدد الضيوف: {{guests_count}}

القيمة الإجمالية: {{total_amount}} ريال
العربون المدفوع: {{deposit_amount}} ريال
المبلغ المتبقي: {{remaining_amount}} ريال
TXT,
                'terms' => <<<'TXT'
الشروط والأحكام:

1. يُسدَّد العربون عند التوقيع، والمتبقي قبل بداية الفترة المحجوزة.
2. الإلغاء قبل الموعد بمدة كافية يخضع لسياسة الإلغاء المعتمدة لدى الطرف الأول.
3. يلتزم الطرف الثاني بالمحافظة على محتويات الوحدة، وتحمّل قيمة أي تلف يقع خلال فترة الحجز.
4. لا يجوز تجاوز عدد الضيوف المتفق عليه دون موافقة مسبقة من الطرف الأول.
5. يلتزم الطرف الثاني بأنظمة الدولة والذوق العام داخل الوحدة.
6. تسليم الوحدة واستلامها يتم في الوقت المحدد بالعقد.

توقيع الطرف الأول: ........................
توقيع الطرف الثاني: ........................
TXT,
            ],
        );
    }
}
