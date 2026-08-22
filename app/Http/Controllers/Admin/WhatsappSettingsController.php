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
                'wa_number' => $settings->wa_number,
                'wa_connected_at' => $settings->wa_connected_at?->toDateTimeString(),
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
            'wa_number' => ['nullable', 'string', 'max:30'],
            'wa_welcome_enabled' => ['boolean'],
            'wa_welcome_template' => ['nullable', 'string', 'max:2000'],
        ]);

        $settings = Setting::current();

        $settings->wa_enabled = $data['wa_enabled'] ?? false;
        $settings->wa_instance_id = $data['wa_instance_id'] ?? null;
        if (filled($data['wa_access_token'] ?? null)) {
            $settings->wa_access_token = $data['wa_access_token'];
        }
        $settings->wa_number = WaGateway::normalizeNumber($data['wa_number'] ?? null);
        $settings->wa_welcome_enabled = $data['wa_welcome_enabled'] ?? false;
        $settings->wa_welcome_template = $data['wa_welcome_template'] ?? null;

        $settings->save();

        return back()->with('success', 'تم حفظ إعدادات الواتساب بنجاح');
    }

    /** التحقق من حالة الاتصال بالجلسة (يُستدعى عبر fetch من الواجهة). */
    public function status(): JsonResponse
    {
        $settings = Setting::current();
        $gateway = new WaGateway($settings);

        if (! $gateway->hasCredentials()) {
            return response()->json([
                'ok' => false,
                'configured' => false,
                'message' => 'أدخِل معرّف الجهاز ورمز الوصول ثم احفظ قبل التحقق.',
            ]);
        }

        // استعلامٌ محض: التثبيت والتفعيل يجريان في connect وحده لأنه
        // خلف صلاحية التعديل، وهذه الشاشة قد يفتحها من يملك العرض فقط.
        $result = $gateway->status();

        return response()->json([
            'ok' => (bool) ($result['ok'] ?? false),
            'configured' => true,
            'status' => $result['status'] ?? null,
            'phone' => $result['phone'] ?? null,
            'avatar_url' => $result['avatar_url'] ?? null,
            'platform' => $result['platform'] ?? null,
            'subscription' => $result['subscription'] ?? null,
            'message' => $result['error'] ?? null,
            'raw' => $result,
        ]);
    }

    /**
     * نبضة الربط: استعلام واحد تستدعيه الواجهة كل ثوانٍ حتى يكتمل الربط.
     *
     * البوابة تُميّز الحالات برمز HTTP لا بمحتوى الجسم (200 رمز جاهز،
     * 409 مرتبط سلفاً، 202 قيد التوليد، 401/403 اعتماد خاطئ)، فنترجمها
     * هنا إلى حالةٍ واحدة تفهمها الواجهة بدل أن تتعامل مع البوابة مباشرة
     * — الواجهة لا يجوز أن ترى التوكن أصلاً.
     */
    public function connect(): JsonResponse
    {
        $settings = Setting::current();
        $gateway = new WaGateway($settings);

        if (! $gateway->hasCredentials()) {
            return response()->json([
                'state' => 'missing',
                'message' => 'أدخِل معرّف الجهاز ورمز الوصول ثم احفظ الإعدادات لبدء الربط.',
            ]);
        }

        $result = $gateway->qrcode();
        $http = (int) ($result['http_status'] ?? 0);

        // مرتبط سلفاً: البوابة ترفض إصدار رمز جديد، فنقرأ بيانات الجلسة.
        if ($http === 409) {
            return $this->connectedResponse($settings, $gateway);
        }

        if ($http === 401 || $http === 403) {
            return response()->json([
                'state' => 'unauthorized',
                'message' => $result['error'] ?? 'معرّف الجهاز أو رمز الوصول غير صحيح.',
            ]);
        }

        if (($result['ok'] ?? false) && filled($result['qr'] ?? null)) {
            return response()->json([
                'state' => 'qr',
                'qr' => $result['qr'],
                'message' => 'امسح الرمز من واتساب › الأجهزة المرتبطة › ربط جهاز.',
            ]);
        }

        if ($http === 202) {
            return response()->json([
                'state' => ($result['code'] ?? null) === 'connection_problem' ? 'problem' : 'pending',
                'message' => $result['error'] ?? 'جارٍ توليد الرمز… ثوانٍ قليلة.',
                // البوابة تُعيد المحاولة تلقائياً ما لم تقل خلاف ذلك.
                'retrying' => ($result['retrying'] ?? true) !== false,
            ]);
        }

        return response()->json([
            'state' => 'error',
            'message' => $result['error'] ?? 'تعذّر الاتصال بالبوابة — أعد المحاولة بعد قليل.',
        ]);
    }

    /** إرسال رسالة تجريبية للتأكد من عمل البوابة. */
    public function test(Request $request): JsonResponse
    {
        $data = $request->validate([
            'number' => ['required', 'string', 'max:30'],
            'message' => ['nullable', 'string', 'max:1000'],
        ]);

        $gateway = new WaGateway;

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

    /**
     * استجابة «تم الربط» — وهي أيضاً موضع التفعيل التلقائي.
     */
    private function connectedResponse(Setting $settings, WaGateway $gateway): JsonResponse
    {
        $status = $gateway->status();

        $this->rememberConnection($settings, $status);

        $phone = $status['phone'] ?? null;
        $expected = $settings->wa_number;

        return response()->json([
            'state' => 'connected',
            'phone' => $phone,
            'avatar_url' => $status['avatar_url'] ?? null,
            'platform' => $status['platform'] ?? null,
            'subscription' => $status['subscription'] ?? null,
            // الرقم المرتبط قد يخالف الرقم المُدخل إن مُسح الرمز من جهاز آخر.
            'mismatch' => filled($expected) && filled($phone) && $expected !== WaGateway::normalizeNumber($phone),
            'expected_number' => $expected,
            'message' => 'تم الربط بنجاح، والتكامل جاهز للإرسال.',
        ]);
    }

    /**
     * تثبيت أثر الربط الناجح: الرقم وزمنه، وتفعيل التكامل.
     *
     * التفعيل هنا هو معنى «الربط بلا تدخّل»: من أتمّ المسح لا يُطلب منه
     * بعدها فتح الإعدادات ليرفع مفتاحاً كي تبدأ الرسائل بالخروج.
     */
    private function rememberConnection(Setting $settings, array $status): void
    {
        if (($status['status'] ?? null) !== 'connected') {
            return;
        }

        $phone = WaGateway::normalizeNumber($status['phone'] ?? null);

        $settings->wa_connected_at = now();

        if (blank($settings->wa_number) && filled($phone)) {
            $settings->wa_number = $phone;
        }

        $settings->wa_enabled = true;

        $settings->save();
    }
}
