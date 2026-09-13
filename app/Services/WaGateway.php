<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Psr\Log\LoggerInterface;

/**
 * بوابة إرسال رسائل واتساب عبر مكتبة c-wts.com.
 *
 * المعرّفات (instance_id / access_token) تُقرأ من صفّ الإعدادات، والرابط الأساسي من config/services.php.
 * التوثيق: https://c-wts.com/docs
 */
class WaGateway
{
    private string $baseUrl;

    private ?string $instanceId;

    private ?string $accessToken;

    private bool $enabled;

    public function __construct(?Setting $settings = null)
    {
        $settings ??= Setting::current();

        $this->baseUrl = rtrim((string) config('services.c_wts.base_url', 'https://c-wts.com'), '/');
        $this->instanceId = $settings->wa_instance_id;
        $this->accessToken = $settings->wa_access_token;
        $this->enabled = (bool) $settings->wa_enabled;
    }

    /** هل التكامل مفعّل ومكتمل المعرّفات؟ */
    public function isConfigured(): bool
    {
        return $this->enabled && $this->hasCredentials();
    }

    /**
     * هل المعرّفات موجودة (بصرف النظر عن التفعيل)؟
     *
     * شاشة الربط تحتاج هذا لا isConfigured: الربط يسبق التفعيل عادةً،
     * فلو اشترطنا التفعيل لتعذّر جلب رمز QR أصلاً وبقي المستخدم في حلقة
     * «فعّل لتربط، واربط لتفعّل».
     */
    public function hasCredentials(): bool
    {
        return filled($this->instanceId) && filled($this->accessToken);
    }

    /**
     * حالة الجلسة والاشتراك.
     * التوثيق يمرر المعرّفات في الـ query string لتفادي فقدانها عند إعادة التوجيه HTTP→HTTPS.
     */
    public function status(): array
    {
        $response = Http::acceptJson()
            ->timeout(20)
            ->get("{$this->baseUrl}/api/status", $this->credentials());

        return $this->normalize($response);
    }

    /** رمز QR (base64 PNG) لربط الجهاز. */
    public function qrcode(): array
    {
        $response = Http::acceptJson()
            ->timeout(20)
            ->get("{$this->baseUrl}/api/qrcode", $this->credentials());

        return $this->normalize($response);
    }

    /** إرسال رسالة نصية. الرقم يُطبّع تلقائياً إلى الصيغة الدولية دون + أو 00. */
    public function send(string $number, string $message): array
    {
        $normalized = static::normalizeNumber($number);

        if ($normalized === null) {
            return $this->invalidNumber('send', $number);
        }

        $payload = ['number' => $normalized, 'message' => $message];

        $this->logRequest('send', $payload);

        $response = Http::acceptJson()
            ->timeout(30)
            ->asForm()
            ->post("{$this->baseUrl}/api/send?".http_build_query($this->credentials()), $payload);

        return $this->logResponse('send', $normalized, $this->normalize($response), $response);
    }

    /** إرسال وسائط عبر رابط مباشر. */
    public function sendMedia(string $number, string $mediaUrl, array $options = []): array
    {
        $normalized = static::normalizeNumber($number);

        if ($normalized === null) {
            return $this->invalidNumber('send-media', $number);
        }

        $payload = array_merge(['number' => $normalized, 'media_url' => $mediaUrl], array_filter($options, fn ($v) => $v !== null));

        $this->logRequest('send-media', $payload);

        $response = Http::acceptJson()
            ->timeout(30)
            ->asForm()
            ->post("{$this->baseUrl}/api/send-media?".http_build_query($this->credentials()), $payload);

        return $this->logResponse('send-media', $normalized, $this->normalize($response), $response);
    }

    /**
     * دمج المتغيّرات في قالب النص.
     * المتغيّرات المدعومة: {name} {business_name} — بصيغة {key}.
     */
    public static function renderTemplate(string $template, array $vars): string
    {
        $replacements = [];
        foreach ($vars as $key => $value) {
            $replacements['{'.$key.'}'] = (string) $value;
        }

        return strtr($template, $replacements);
    }

    /**
     * تطبيع رقم الجوال إلى الصيغة الدولية دون + أو 00 (افتراض السعودية 966 عند غياب رمز الدولة).
     * يُرجع null إذا كان الرقم غير صالح.
     */
    public static function normalizeNumber(?string $number, string $defaultCountry = '966'): ?string
    {
        if ($number === null) {
            return null;
        }

        // إبقاء الأرقام فقط
        $digits = preg_replace('/\D+/', '', $number) ?? '';

        if ($digits === '') {
            return null;
        }

        // إزالة بادئة 00 الدولية
        if (str_starts_with($digits, '00')) {
            $digits = substr($digits, 2);
        }

        // رقم محلي يبدأ بـ 0 (مثل 05xxxxxxxx) → استبدال الصفر برمز الدولة
        if (str_starts_with($digits, '0')) {
            $digits = $defaultCountry.substr($digits, 1);
        } elseif (! str_starts_with($digits, $defaultCountry) && strlen($digits) <= 9) {
            // رقم بدون رمز دولة ولا صفر (مثل 5xxxxxxxx) → إضافة رمز الدولة
            $digits = $defaultCountry.$digits;
        }

        return strlen($digits) >= 10 ? $digits : null;
    }

    private function credentials(): array
    {
        return [
            'instance_id' => $this->instanceId,
            'access_token' => $this->accessToken,
        ];
    }

    /** The WhatsApp channel, or the default one when it is not configured. */
    private function log(): LoggerInterface
    {
        return Log::channel(config('logging.channels.whatsapp') ? 'whatsapp' : config('logging.default'));
    }

    /**
     * The outgoing request — body and all.
     *
     * The text is logged whole: when a client says the message read wrong,
     * the answer is what we actually sent, not its length. Credentials never
     * enter the log; they are in the query string, and stay there.
     */
    private function logRequest(string $endpoint, array $payload): void
    {
        $this->log()->info('WaGateway → '.$endpoint, [
            'number' => $payload['number'] ?? null,
            'message' => $payload['message'] ?? ($payload['caption'] ?? null),
            'media_url' => $payload['media_url'] ?? null,
        ]);
    }

    /**
     * The gateway's reply, kept whole and raw.
     *
     * A refusal arrives as a 200 with «ok: false» just as often as an HTTP
     * error, so the body is logged either way — a failure line that says only
     * "failed" cannot be taken to the provider.
     */
    private function logResponse(string $endpoint, string $number, array $result, Response $response): array
    {
        $context = [
            'number' => $number,
            'http_status' => $response->status(),
            'body' => $response->body(),
        ];

        if ($result['ok'] ?? false) {
            $this->log()->info('WaGateway ← '.$endpoint.' ok', $context);
        } else {
            $this->log()->warning('WaGateway ← '.$endpoint.' failed', $context);
        }

        return $result;
    }

    /** A number the gateway would reject — logged, never sent. */
    private function invalidNumber(string $endpoint, string $number): array
    {
        $this->log()->warning('WaGateway ✗ '.$endpoint.' invalid number', ['number' => $number]);

        return ['ok' => false, 'code' => 'invalid_number', 'error' => 'رقم الجوال غير صالح'];
    }

    /** توحيد شكل الاستجابة إلى مصفوفة تحوي على الأقل ok/status. */
    private function normalize(Response $response): array
    {
        $data = $response->json();

        if (! is_array($data)) {
            $this->log()->warning('WaGateway: استجابة غير متوقعة', ['status' => $response->status(), 'body' => $response->body()]);

            return [
                'ok' => false,
                'code' => 'bad_response',
                'error' => 'استجابة غير متوقعة من الخادم (رمز '.$response->status().')',
                'http_status' => $response->status(),
            ];
        }

        $data['ok'] = $data['ok'] ?? $response->successful();
        $data['http_status'] = $response->status();

        return $data;
    }
}
