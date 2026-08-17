<?php

use App\Http\Controllers\DeployController;
use Illuminate\Support\Facades\Route;

// عرّف مسارات الـ API الخاصة بمشروعك هنا.

// نقطة النشر التلقائي — يستدعيها زر «تحديث» في لوحة update-hosts.
// مجموعة api لا تخضع لـ CSRF. المسار الناتج: POST /api/deploy
Route::post('/deploy', [DeployController::class, 'deploy']);
