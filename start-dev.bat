@echo off
chcp 65001 >nul
title saw3d-trans - Dev Server
echo ============================================
echo   saw3d-trans  ^|  Laravel + Livewire
echo ============================================

REM --- اضافة الادوات الى المسار مؤقتا ---
set "PATH=C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64;C:\laragon\bin\nodejs\node-v22;C:\laragon\bin\composer;C:\laragon\bin\mysql\mysql-8.4.3-winx64\bin;%PATH%"

cd /d "%~dp0"

REM --- تشغيل قاعدة البيانات اذا لم تكن تعمل (Laragon MySQL 8.4) ---
tasklist /FI "IMAGENAME eq mysqld.exe" | find /I "mysqld.exe" >nul
if errorlevel 1 (
  echo [DB] تشغيل MySQL ...
  start "MySQL" /B "C:\laragon\bin\mysql\mysql-8.4.3-winx64\bin\mysqld.exe"
  timeout /t 3 >nul
) else (
  echo [DB] MySQL يعمل بالفعل.
)

REM --- Vite للتطوير الحي ---
echo [Vite] تشغيل خادم الاصول ...
start "Vite" cmd /k "npm run dev"

REM --- عامل الطابور (لإرسال رسائل الواتساب في الخلفية) ---
echo [Queue] تشغيل عامل الطابور ...
start "Queue" cmd /k "php artisan queue:work --tries=3"

REM --- خادم Laravel ---
echo [Laravel] http://127.0.0.1:8000
php artisan serve --host=127.0.0.1 --port=8000
