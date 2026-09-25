<?php

namespace App\Support;

use App\Models\Setting;

/**
 * الترويسة التي يُطبع تحتها المستند — هوية الجهة المُصدِرة كاملةً.
 *
 * المسابح (مؤسسة العجلان) جهةٌ مستقلة عن ديوان المسرة: لها اسمها وشعارها
 * وسجلّها ورقمها الضريبي وتوقيعها وختمها. فمستند المسابح لا يحمل شيئًا من
 * بيانات الديوان، ولا يستعير منها ما تُرك فارغًا — الحقل الفارغ يُحذف من
 * الورقة بدل أن يُطبع باسم جهةٍ أخرى. والاسم وحده يرجع إلى اسم المؤسسة
 * الثابت، فلا تخرج ورقةٌ بلا مُصدِر.
 *
 * كل ترويسة في النظام (الفواتير، عروض الأسعار، المشتريات، العقود، ملفات PDF،
 * رمز الزكاة) تُبنى من هنا، فلا يُنسى حقلٌ في شاشةٍ دون أخرى.
 */
class Letterhead
{
    /**
     * بيانات الجهة كما هي في الإعدادات — مسارات الصور على القرص.
     *
     * @return array{name: string, logo_path: ?string, phone: ?string, whatsapp: ?string, email: ?string, address: ?string, tax_number: ?string, commercial_register: ?string, manager_name: ?string, signature_path: ?string, stamp_path: ?string}
     */
    public static function raw(bool $pools, ?Setting $settings = null): array
    {
        $s = $settings ?? Setting::current();

        if ($pools) {
            return [
                'name' => $s->pools_name ?: PoolsLetterhead::NAME,
                'logo_path' => $s->pools_logo_path ?: null,
                'phone' => $s->pools_phone ?: null,
                'whatsapp' => $s->pools_whatsapp ?: null,
                'email' => $s->pools_email ?: null,
                'address' => $s->pools_address ?: null,
                'tax_number' => $s->pools_tax_number ?: null,
                'commercial_register' => $s->pools_commercial_register ?: null,
                'manager_name' => $s->pools_manager_name ?: null,
                'signature_path' => $s->pools_signature_path ?: null,
                'stamp_path' => $s->pools_stamp_path ?: null,
            ];
        }

        return [
            'name' => $s->business_name ?: (string) config('app.name'),
            'logo_path' => $s->logo_path ?: null,
            'phone' => $s->phone ?: null,
            'whatsapp' => $s->whatsapp ?: null,
            'email' => $s->email ?: null,
            'address' => $s->address ?: null,
            'tax_number' => $s->tax_number ?: null,
            'commercial_register' => $s->commercial_register ?: null,
            'manager_name' => $s->manager_name ?: null,
            'signature_path' => $s->manager_signature_path ?: null,
            'stamp_path' => $s->stamp_path ?: null,
        ];
    }

    /**
     * الترويسة كما تصل الشاشة — الصور روابط، والرقم الضريبي لا يُطبع إلا
     * حين تسري الضريبة ($showTax يقرّره المستند بقاعدته).
     *
     * @return array<string, mixed>
     */
    public static function issuer(bool $pools, bool $showTax = true, ?Setting $settings = null): array
    {
        $l = self::raw($pools, $settings);

        return [
            'business_name' => $l['name'],
            'logo_url' => self::url($l['logo_path']),
            'phone' => $l['phone'],
            // الرقم الثاني يُطبع حين يختلف عن الأول فقط.
            'whatsapp' => $l['whatsapp'] !== $l['phone'] ? $l['whatsapp'] : null,
            'email' => $l['email'],
            'address' => $l['address'],
            'tax_number' => $showTax ? $l['tax_number'] : null,
            'commercial_register' => $l['commercial_register'],
            'manager_name' => $l['manager_name'],
            'manager_signature_url' => self::url($l['signature_path']),
            'stamp_url' => self::url($l['stamp_path']),
        ];
    }

    /**
     * ترويسة العقد — كالمستندات الأخرى، إلا أن الاسم المحفوظ في بيانات العقد
     * يسبق: يبقى اسمَ الجهة يوم أُصدر. ما لم يكن اسمَ الديوان على عقد مسابح —
     * ذاك اسمٌ جُمِّد قبل أن يُفصل النشاطان.
     *
     * @return array<string, mixed>
     */
    public static function contract(?string $orgName, bool $pools): array
    {
        $settings = Setting::current();
        $issuer = self::issuer($pools, (bool) $settings->tax_enabled, $settings);

        if ($orgName && ! ($pools && $orgName === self::raw(false, $settings)['name'])) {
            $issuer['business_name'] = $orgName;
        }

        return $issuer;
    }

    private static function url(?string $path): ?string
    {
        return $path ? asset($path) : null;
    }
}
