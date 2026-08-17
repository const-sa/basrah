<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * إضافة ترويسات أمان دفاعية على كل الاستجابات.
 * (لم نُضِف CSP صارمة هنا لتفادي كسر Vite/Inertia؛ يمكن إضافتها لاحقاً بعناية.)
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $headers = [
            // منع تخمين نوع المحتوى (يخفّف مخاطر رفع الملفات).
            'X-Content-Type-Options' => 'nosniff',
            // منع تضمين اللوحة داخل إطار (Clickjacking).
            'X-Frame-Options' => 'DENY',
            // تقليل تسريب الـ Referrer إلى مواقع خارجية.
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
            // منع سياسات Flash/PDF عبر النطاقات.
            'X-Permitted-Cross-Domain-Policies' => 'none',
        ];

        foreach ($headers as $key => $value) {
            if (! $response->headers->has($key)) {
                $response->headers->set($key, $value);
            }
        }

        return $response;
    }
}
