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
     * @return array<string, mixed>
     */
    public static function current(): array
    {
        $settings = Setting::current();

        return [
            'name' => $settings->business_name ?: config('app.name'),
            'logo_url' => $settings->logo_path ? asset($settings->logo_path) : null,
            'phone' => $settings->phone,
            // رقم الواتساب يرجع إلى الهاتف عند غيابه: زرّ تواصلٍ لا يعمل
            // أسوأ من زرٍّ لا يظهر.
            'whatsapp' => $settings->whatsapp ?: $settings->phone,
            'email' => $settings->email,
            'address' => $settings->address,
        ];
    }
}
