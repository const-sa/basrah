<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Services\WaGateway;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class WhatsappSettingsController extends Controller
{
    public function edit(): Response
    {
        $settings = Setting::current();

        return Inertia::render('admin/settings/Whatsapp', [
            'settings' => [
                'wa_enabled' => $settings->wa_enabled,
                'wa_instance_id' => $settings->wa_instance_id,
                // لا نُعيد التوكن كاملاً للواجهة؛ نُرسل مؤشراً فقط على وجوده.
                'wa_has_token' => filled($settings->wa_access_token),
                'wa_welcome_enabled' => $settings->wa_welcome_enabled,
                'wa_welcome_template' => $settings->wa_welcome_template
                    ?? "مرحباً {name} 👋\nأهلاً بك في {business_name}. سعداء بانضمامك، ونحن في خدمتك دائماً.",
            ],
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'wa_enabled' => ['boolean'],
            'wa_instance_id' => ['nullable', 'string', 'max:100'],
            // فارغ = إبقاء التوكن الحالي دون تغيير.
            'wa_access_token' => ['nullable', 'string', 'max:255'],
            'wa_welcome_enabled' => ['boolean'],
            'wa_welcome_template' => ['nullable', 'string', 'max:2000'],
        ]);

        $settings = Setting::current();

        $settings->wa_enabled = $data['wa_enabled'] ?? false;
        $settings->wa_instance_id = $data['wa_instance_id'] ?? null;
        if (filled($data['wa_access_token'] ?? null)) {
            $settings->wa_access_token = $data['wa_access_token'];
        }
        $settings->wa_welcome_enabled = $data['wa_welcome_enabled'] ?? false;
        $settings->wa_welcome_template = $data['wa_welcome_template'] ?? null;

        $settings->save();

        return back()->with('success', 'تم حفظ إعدادات الواتساب بنجاح');
    }

    /** التحقق من حالة الاتصال بالجلسة (يُستدعى عبر fetch من الواجهة). */
    public function status(): JsonResponse
    {
        $gateway = new WaGateway();

        if (! $gateway->isConfigured()) {
            return response()->json([
                'ok' => false,
                'configured' => false,
                'message' => 'أدخِل المعرّف والتوكن وفعّل التكامل ثم احفظ قبل التحقق.',
            ]);
        }

        $result = $gateway->status();

        return response()->json([
            'ok' => (bool) ($result['ok'] ?? false),
            'configured' => true,
            'status' => $result['status'] ?? null,
            'phone' => $result['phone'] ?? null,
            'subscription' => $result['subscription'] ?? null,
            'message' => $result['error'] ?? null,
            'raw' => $result,
        ]);
    }

    /** إرسال رسالة تجريبية للتأكد من عمل البوابة. */
    public function test(Request $request): JsonResponse
    {
        $data = $request->validate([
            'number' => ['required', 'string', 'max:30'],
            'message' => ['nullable', 'string', 'max:1000'],
        ]);

        $gateway = new WaGateway();

        if (! $gateway->isConfigured()) {
            return response()->json([
                'ok' => false,
                'message' => 'التكامل غير مفعّل أو المعرّفات ناقصة. احفظ الإعدادات أولاً.',
            ]);
        }

        $result = $gateway->send(
            $data['number'],
            $data['message'] ?? 'رسالة تجريبية من لوحة التحكم ✅'
        );

        return response()->json([
            'ok' => (bool) ($result['ok'] ?? false),
            'message' => ($result['ok'] ?? false)
                ? 'تم إرسال الرسالة التجريبية بنجاح.'
                : ($result['error'] ?? 'تعذّر الإرسال — تحقّق من الرقم وحالة الاتصال.'),
            'raw' => $result,
        ]);
    }
}
