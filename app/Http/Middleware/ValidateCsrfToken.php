<?php

namespace App\Http\Middleware;

use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken as Middleware;
use Symfony\Component\HttpFoundation\Cookie;

/**
 * تحقّق CSRF مع كعكة خاصة بهذا التطبيق.
 *
 * الاسم الافتراضي "XSRF-TOKEN" مشترك بين كل تطبيقات لارافيل، والمتصفح يتجاهل
 * المنفذ عند مطابقة الكعكات، فتشغيل أكثر من تطبيق على 127.0.0.1 يجعل آخر
 * تطبيق ردّ هو صاحب الكعكة. عندها يرسل هذا التطبيق رمزًا مشفّرًا بمفتاح غيره
 * فيفشل فكّه ويُرفض الطلب بخطأ 419. اسمٌ خاص يقطع هذا التداخل من جذره.
 *
 * ملاحظة: اسم الترويسة (X-XSRF-TOKEN) يبقى كما هو لأنه يُرسل مع كل طلب على
 * حدة ولا يُخزَّن في المتصفح، فلا تداخل فيه. الواجهة تقرأ اسم الكعكة من وسم
 * <meta name="xsrf-cookie"> في resources/views/app.blade.php.
 */
class ValidateCsrfToken extends Middleware
{
    /**
     * إنشاء كعكة رمز CSRF باسم هذا التطبيق بدل الاسم الموحّد.
     */
    protected function newCookie($request, $config)
    {
        return new Cookie(
            config('session.xsrf_cookie', 'XSRF-TOKEN'),
            $request->session()->token(),
            $this->availableAt(60 * $config['lifetime']),
            $config['path'],
            $config['domain'],
            $config['secure'],
            false,
            false,
            $config['same_site'] ?? null,
            $config['partitioned'] ?? false
        );
    }
}
