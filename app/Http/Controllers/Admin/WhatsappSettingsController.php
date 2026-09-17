<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Services\Whatsapp\EnvFile;
use App\Services\Whatsapp\PhoneNumber;
use App\Services\Whatsapp\WhatsappManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * شاشة بوابة الواتساب — المعرّفات في .env، والترحيب ورقم العمل في الإعدادات.
 */
class WhatsappSettingsController extends Controller
{
    public function __construct(private readonly WhatsappManager $whatsapp) {}

    public function edit(): Response
    {
        $settings = Setting::current();
        $driver = $this->whatsapp->getDefaultDriver();

        return Inertia::render('admin/settings/Whatsapp', [
            'settings' => [
                'wa_enabled' => $this->whatsapp->enabled(),
                'wa_instance_id' => $this->driverConfig($driver, 'instance_id'),
                // التوكن لا يعود للواجهة كاملًا — مؤشّر وجوده يكفي.
                'wa_has_token' => filled($this->driverConfig($driver, 'access_token')),
                'wa_number' => $settings->wa_number,
                'wa_connected_at' => $settings->wa_connected_at?->toDateTimeString(),
                'wa_welcome_enabled' => $settings->wa_welcome_enabled,
                'wa_welcome_template' => $settings->wa_welcome_template
                    ?? "مرحباً {name} 👋\nأهلاً بك في {business_name}. سعداء بانضمامك، ونحن في خدمتك دائماً.",
            ],
            'gateway' => [
                'driver' => $driver,
                'drivers' => $this->whatsapp->availableDrivers(),
                'country_code' => (string) config('whatsapp.country_code', '966'),
                // .env غير قابل للكتابة يعني شاشةً تعرض ولا تحفظ، فيُقال ذلك قبل المحاولة.
                'env_writable' => EnvFile::make()->writable(),
            ],
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'wa_enabled' => ['boolean'],
            'wa_driver' => ['nullable', Rule::in($this->whatsapp->availableDrivers())],
            'wa_instance_id' => ['nullable', 'string', 'max:100'],
            // فارغ = إبقاء التوكن الحالي دون تغيير.
            'wa_access_token' => ['nullable', 'string', 'max:255'],
            'wa_country_code' => ['nullable', 'string', 'max:5'],
            'wa_number' => ['nullable', 'string', 'max:30'],
            'wa_welcome_enabled' => ['boolean'],
            'wa_welcome_template' => ['nullable', 'string', 'max:2000'],
        ]);

        $env = EnvFile::make();

        if (! $env->writable()) {
            return back()->with('warning', 'ملف .env غير قابل للكتابة على الخادم — تعذّر حفظ معرّفات البوابة.');
        }

        $driver = $data['wa_driver'] ?? $this->whatsapp->getDefaultDriver();
        $keys = (array) config('whatsapp.env_keys');

        $values = [
            $keys['enabled'] => ($data['wa_enabled'] ?? false) ? 'true' : 'false',
            $keys['driver'] => $driver,
        ];

        if (filled($data['wa_country_code'] ?? null)) {
            $values[$keys['country_code']] = $data['wa_country_code'];
        }

        $values[$keys['drivers'][$driver]['instance_id']] = (string) ($data['wa_instance_id'] ?? '');

        if (filled($data['wa_access_token'] ?? null)) {
            $values[$keys['drivers'][$driver]['access_token']] = $data['wa_access_token'];
        }

        $env->set($values);
        $this->refreshConfig();

        $settings = Setting::current();
        $settings->wa_number = PhoneNumber::normalizeOrNull($data['wa_number'] ?? null);
        $settings->wa_welcome_enabled = $data['wa_welcome_enabled'] ?? false;
        $settings->wa_welcome_template = $data['wa_welcome_template'] ?? null;
        $settings->save();

        return back()->with('success', 'تم حفظ إعدادات الواتساب بنجاح');
    }

    /** التحقق من حالة الاتصال بالجلسة (يُستدعى عبر fetch من الواجهة). */
    public function status(): JsonResponse
    {
        if (! $this->whatsapp->hasCredentials()) {
            return response()->json([
                'ok' => false,
                'configured' => false,
                'message' => 'أدخِل معرّف الجهاز ورمز الوصول ثم احفظ قبل التحقق.',
            ]);
        }

        // استعلامٌ محض: التثبيت يجري في connect وحده لأنه خلف صلاحية التعديل.
        $result = $this->whatsapp->driver()->status();

        return response()->json([
            'ok' => $result->ok(),
            'configured' => true,
            'status' => $result->json('status'),
            'phone' => $result->json('phone'),
            'avatar_url' => $result->json('avatar_url'),
            'platform' => $result->json('platform'),
            'subscription' => $result->json('subscription'),
            'message' => $result->error(),
            'raw' => $result->toArray(),
        ]);
    }

    /**
     * نبضة الربط: استعلام واحد تستدعيه الواجهة كل ثوانٍ حتى يكتمل الربط.
     * الواجهة لا ترى التوكن — تقرأ حالةً واحدة مترجمة عن البوابة.
     */
    public function connect(): JsonResponse
    {
        if (! $this->whatsapp->hasCredentials()) {
            return response()->json([
                'state' => 'missing',
                'message' => 'أدخِل معرّف الجهاز ورمز الوصول ثم احفظ الإعدادات لبدء الربط.',
            ]);
        }

        $qr = $this->whatsapp->driver()->qrCode();

        if ($qr->connected()) {
            return $this->connectedResponse($qr->phone());
        }

        if ($qr->image() !== null) {
            return response()->json([
                'state' => 'qr',
                'qr' => $qr->image(),
                'message' => 'امسح الرمز من واتساب › الأجهزة المرتبطة › ربط جهاز.',
            ]);
        }

        return response()->json(match ($qr->code()) {
            'missing_credentials' => ['state' => 'unauthorized', 'message' => 'معرّف الجهاز أو رمز الوصول غير صحيح.'],
            'qr_not_ready' => ['state' => 'pending', 'message' => 'جارٍ توليد الرمز… ثوانٍ قليلة.', 'retrying' => true],
            default => ['state' => 'error', 'message' => $qr->error() ?? 'تعذّر الاتصال بالبوابة — أعد المحاولة بعد قليل.'],
        });
    }

    /** إرسال رسالة تجريبية للتأكد من عمل البوابة. */
    public function test(Request $request): JsonResponse
    {
        $data = $request->validate([
            'number' => ['required', 'string', 'max:30'],
            'message' => ['nullable', 'string', 'max:1000'],
        ]);

        if (! $this->whatsapp->isConfigured()) {
            return response()->json([
                'ok' => false,
                'message' => 'التكامل غير مفعّل أو المعرّفات ناقصة. احفظ الإعدادات أولاً.',
            ]);
        }

        $result = $this->whatsapp->driver()->sendText(
            $data['number'],
            $data['message'] ?? 'رسالة تجريبية من لوحة التحكم ✅'
        );

        return response()->json([
            'ok' => $result->ok(),
            'message' => $result->ok()
                ? 'تم إرسال الرسالة التجريبية بنجاح.'
                : ($result->error() ?? 'تعذّر الإرسال — تحقّق من الرقم وحالة الاتصال.'),
            'raw' => $result->toArray(),
        ]);
    }

    /** استجابة «تم الربط» — وهي موضع تثبيت أثر الربط. */
    private function connectedResponse(?string $phone): JsonResponse
    {
        $settings = Setting::current();
        $expected = $settings->wa_number;

        $settings->wa_connected_at = now();

        if (blank($expected) && filled($phone)) {
            $settings->wa_number = PhoneNumber::normalizeOrNull($phone);
        }

        $settings->save();

        return response()->json([
            'state' => 'connected',
            'phone' => $phone,
            // الرقم المرتبط قد يخالف المُدخل إن مُسح الرمز من جهاز آخر.
            'mismatch' => filled($expected) && filled($phone) && $expected !== PhoneNumber::normalizeOrNull($phone),
            'expected_number' => $expected,
            'message' => 'تم الربط بنجاح، والتكامل جاهز للإرسال.',
        ]);
    }

    private function driverConfig(string $driver, string $key): ?string
    {
        $value = config("whatsapp.drivers.{$driver}.{$key}");

        return $value === null ? null : (string) $value;
    }

    /** بلا هذا يبقى env() يعيد القيمة القديمة المخبوزة في الكاش. */
    private function refreshConfig(): void
    {
        if (app()->configurationIsCached()) {
            rescue(fn () => Artisan::call('config:clear'), null, false);
        }
    }
}
