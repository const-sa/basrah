<?php

namespace App\Support;

use App\Models\Setting;

/**
 * هوية المنشأة كما تظهر في الواجهة العامة.
 *
 * موضعها هنا لا في متحكّم بعينه لأن صفحات الموقع كلها تعرضها في ترويستها
 * وتذييلها، فنسخُها في كل متحكّم يجعل تعديل حقلٍ فيها تعديلًا في مواضع.
 */
class SiteIdentity
{
    /**
     * الهوية المصغّرة المشتركة مع كل صفحة: الاسم والشعار والأيقونة وحدها.
     *
     * مفصولة عن current() لأنها تُقرأ في *كل* طلب لبناء ترويسة الشريط
     * الجانبي، فلا يصحّ أن تجرّ معها الهاتف والعنوان والبريد بلا حاجة.
     *
     * @return array{name: string, logo_url: string|null, favicon_url: string|null}
     */
    public static function brand(): array
    {
        $settings = Setting::current();

        return [
            'name' => $settings->business_name ?: config('app.name'),
            'logo_url' => self::url($settings->logo_path),
            'favicon_url' => self::url($settings->favicon_path),
        ];
    }

    /**
     * مسار الملف المخزَّن → رابطًا مطلقًا، وnull إن لم يُضبط.
     *
     * يمرّ عليه كل حقول الصور: تُخزَّن نسبيةً إلى public/ فيكفي asset()،
     * لكن تكرار الشرط الثلاثي في كل حقل يُغفل واحدًا عند الإضافة.
     */
    private static function url(?string $path): ?string
    {
        return $path ? asset($path) : null;
    }

    /**
     * @return array<string, mixed>
     */
    public static function current(): array
    {
        $settings = Setting::current();

        return [
            'name' => $settings->business_name ?: config('app.name'),
            'logo_url' => self::url($settings->logo_path),
            'phone' => $settings->phone,
            // رقم الواتساب يرجع إلى الهاتف عند غيابه: زرّ تواصلٍ لا يعمل
            // أسوأ من زرٍّ لا يظهر.
            'whatsapp' => $settings->whatsapp ?: $settings->phone,
            'email' => $settings->email,
            'address' => $settings->address,
        ];
    }
}
