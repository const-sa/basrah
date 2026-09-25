<?php

return [

    /*
    |--------------------------------------------------------------------------
    | مستودع التحديث
    |--------------------------------------------------------------------------
    |
    | من أين يسحب زرّ «تحديث النظام» الكود. تُكتب من شاشة الإعدادات إلى .env
    | ولا تُخزَّن في قاعدة البيانات: رمز GitHub مفتاحٌ للكود كله، ونسخة
    | القاعدة تُنقل وتُستعاد حيث لا ينبغي أن يسافر.
    |
    */

    'owner' => env('GITHUB_OWNER', 'const-sa'),

    'repo' => env('GITHUB_REPO', 'basrah'),

    'branch' => env('GITHUB_BRANCH', 'main'),

    'token' => env('GITHUB_TOKEN'),

    // ثوانٍ لكل خطوة — البناء على استضافةٍ مشتركة بطيء.
    'timeout' => (int) env('DEPLOY_STEP_TIMEOUT', 600),

    'env_keys' => [
        'owner' => 'GITHUB_OWNER',
        'repo' => 'GITHUB_REPO',
        'branch' => 'GITHUB_BRANCH',
        'token' => 'GITHUB_TOKEN',
    ],

];
