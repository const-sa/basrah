<?php

return [

    /*
    |--------------------------------------------------------------------------
    | البوابة الفعّالة
    |--------------------------------------------------------------------------
    |
    | تبديل المزوّد سطرٌ واحد في .env لا تعديل في الكود.
    | المدعوم: "cwts" (c-wts.com) و "waclient" (waclient.com).
    |
    */

    'driver' => env('WHATSAPP_PROVIDER', 'cwts'),

    /*
    |--------------------------------------------------------------------------
    | خيارات مشتركة
    |--------------------------------------------------------------------------
    |
    | country_code يُضاف أمام الأرقام المحلية (05xxxxxxxx / 5xxxxxxxx) قبل
    | الإرسال. اتركه فارغًا لتمرير الأرقام كما هي.
    |
    */

    'enabled' => (bool) env('WHATSAPP_ENABLED', true),

    'country_code' => env('WHATSAPP_COUNTRY_CODE', '966'),

    'timeout' => (int) env('WHATSAPP_TIMEOUT', 30),

    'log_channel' => env('WHATSAPP_LOG_CHANNEL', 'whatsapp'),

    /*
    |--------------------------------------------------------------------------
    | البوابات
    |--------------------------------------------------------------------------
    |
    | معرّفات البوابة تخصّ النشر لا البيانات، فـ .env مصدرها الوحيد: أعمدة
    | wa_instance_id / wa_access_token في جدول الإعدادات لم تعد تُقرأ. ونسخة
    | قاعدة البيانات لا تحمل المفتاح، واستعادتها في نسخة تجريبية لا تجعلها
    | ترسل من رقم العميل.
    |
    */

    'drivers' => [

        // https://www.c-wts.com/docs
        'cwts' => [
            'base_url' => env('CWTS_URL', 'https://www.c-wts.com'),
            'instance_id' => env('CWTS_INSTANCE_ID'),
            'access_token' => env('CWTS_ACCESS_TOKEN'),
            'verify_ssl' => (bool) env('CWTS_VERIFY_SSL', true),
        ],

        // https://waclient.com/docs/whatsapp-web-api
        'waclient' => [
            'base_url' => env('WHATSAPP_API_URL', 'https://api.waclient.com'),
            'instance_id' => env('WHATSAPP_API_INSTANCE_ID'),
            'access_token' => env('WHATSAPP_API_TOKEN'),
            'verify_ssl' => (bool) env('WHATSAPP_API_VERIFY_SSL', true),
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | المفاتيح التي تكتبها شاشة الإعدادات
    |--------------------------------------------------------------------------
    |
    | شاشة «إعدادات الواتساب» تحرّر ملف .env لا جدول الإعدادات. هذه الخريطة
    | تقول أي مفتاح يقابل أي خيار، فإضافة بوابة أعلاه تكفي لظهورها هناك.
    |
    */

    'env_keys' => [

        'enabled' => 'WHATSAPP_ENABLED',
        'driver' => 'WHATSAPP_PROVIDER',
        'country_code' => 'WHATSAPP_COUNTRY_CODE',

        'drivers' => [
            'cwts' => [
                'base_url' => 'CWTS_URL',
                'instance_id' => 'CWTS_INSTANCE_ID',
                'access_token' => 'CWTS_ACCESS_TOKEN',
            ],
            'waclient' => [
                'base_url' => 'WHATSAPP_API_URL',
                'instance_id' => 'WHATSAPP_API_INSTANCE_ID',
                'access_token' => 'WHATSAPP_API_TOKEN',
            ],
        ],

    ],

];
