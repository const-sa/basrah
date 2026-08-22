<!DOCTYPE html>
<html lang="ar" dir="rtl">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        {{-- هوية المنشأة من الإعدادات. rescue لأن هذا القالب يُصيَّر قبل أن
             يُهيَّأ الجدول في التنصيب الأول، فلا يصحّ أن يُسقط الصفحة كلها. --}}
        @php($brand = rescue(fn () => \App\Support\SiteIdentity::brand(), ['name' => config('app.name'), 'logo_url' => null, 'favicon_url' => null], false))

        <title inertia>{{ $brand['name'] ?: config('app.name', 'ديوان البصرة') }}</title>

        <link rel="icon" href="{{ $brand['favicon_url'] ?? $brand['logo_url'] ?? '/favicon.ico' }}">

        {{-- تطبيق ويب مثبَّت على الجوال (PWA) — لا تطبيق أصلي في النطاق --}}
        <link rel="manifest" href="/manifest.webmanifest">
        <meta name="theme-color" content="#0f766e">
        <meta name="mobile-web-app-capable" content="yes">
        <meta name="apple-mobile-web-app-capable" content="yes">
        <meta name="apple-mobile-web-app-status-bar-style" content="default">
        <meta name="apple-mobile-web-app-title" content="{{ $brand['name'] ?: config('app.name') }}">
        <link rel="apple-touch-icon" href="{{ $brand['logo_url'] ?? '/pwa/icon-192.png' }}">

        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css">

        {{-- اسم كعكة رمز CSRF الخاصة بهذا التطبيق — تقرأه الواجهة لتضبط axios عليه. --}}
        <meta name="xsrf-cookie" content="{{ config('session.xsrf_cookie') }}">

        @routes
        @vite(['resources/js/app.ts'])
        @inertiaHead
    </head>
    <body class="font-sans antialiased">
        @inertia

        {{-- تسجيل عامل الخدمة: يُسرّع الإقلاع ويُظهر صفحة انقطاع مفهومة.
             لا يُخزّن بيانات تشغيلية لأنها تتغيّر لحظيًا. --}}
        <script>
            if ('serviceWorker' in navigator && location.protocol === 'https:' || location.hostname === 'localhost' || location.hostname === '127.0.0.1') {
                window.addEventListener('load', function () {
                    navigator.serviceWorker.register('/sw.js').catch(function () {
                        // فشل التسجيل لا يمنع عمل النظام — يبقى موقعًا عاديًا.
                    });
                });
            }
        </script>
    </body>
</html>
