<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| المهام المجدولة (§14 و§18)
|--------------------------------------------------------------------------
| تحتاج سطرًا واحدًا في cron على الخادم:
|
|   * * * * * cd /path-to-project && php artisan schedule:run >> /dev/null 2>&1
|
| بدونه لا يمشي شيء من هذا — والشاشة تُنبّه إن تأخّرت النسخة، فيُعرف أن
| السطر ناقص قبل أن يُحتاج إلى النسخة.
*/

// النسخة الليلية: الثانية فجرًا — بعد إقفال الحركة وقبل دوام الصباح.
Schedule::command('backup:run --trigger=schedule')
    ->dailyAt('02:00')
    ->withoutOverlapping()
    ->onFailure(fn () => logger()->error('فشلت مهمة النسخ الاحتياطي المجدولة'));

// تذكير الحجوزات: التاسعة صباحًا — ساعةٌ يُقرأ فيها الواتساب ولا تُزعج.
Schedule::command('bookings:send-reminders')
    ->dailyAt('09:00')
    ->withoutOverlapping();
