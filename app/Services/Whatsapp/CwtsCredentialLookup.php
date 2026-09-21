<?php

namespace App\Services\Whatsapp;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * يجلب معرّفات البوابة من منصّة c-wts بالرقم وحده، فلا ينسخ أحدٌ معرّف جهاز
 * ورمز وصول بيده.
 *
 * تجيب المنصّة على هذا النداء بمفتاح مشترك (CWTS_LOOKUP_KEY) لا برمز الوصول،
 * لسببٍ ظاهر: رمز الوصول هو ما نسأل عنه. وبلا مفتاح يبقى الجلب معطّلاً
 * وتُدخَل المعرّفات يدوياً كما كان.
 */
final class CwtsCredentialLookup
{
    private ?string $error = null;

    public function __construct(private array $config = []) {}

    public static function make(): self
    {
        return new self((array) config('whatsapp.drivers.cwts', []));
    }

    /** هل سلّمتنا المنصّة مفتاح جلبٍ نستعمله؟ */
    public function enabled(): bool
    {
        return $this->key() !== '' && $this->baseUrl() !== '';
    }

    /**
     * العميل المسجَّل في المنصّة تحت هذا الرقم، أو لا شيء.
     *
     * @return array{instance_id: string, access_token: string, name: string, status: ?string, days_remaining: ?int}|null
     */
    public function find(string $phone): ?array
    {
        $this->error = null;

        $number = PhoneNumber::normalize($phone, config('whatsapp.country_code'));

        if (! $this->enabled() || $number === '') {
            $this->error = 'lookup_disabled';

            return null;
        }

        try {
            $response = Http::timeout((int) Arr::get($this->config, 'timeout', config('whatsapp.timeout', 30)))
                ->acceptJson()
                ->withOptions(['verify' => (bool) Arr::get($this->config, 'verify_ssl', true)])
                ->asForm()
                // المفتاح في رابط الطلب: تحويل http إلى https يُسقط جسم POST.
                ->post($this->baseUrl().'/api/lookup?'.http_build_query(['key' => $this->key()]), [
                    'phone' => $number,
                ]);
        } catch (Throwable $e) {
            return $this->fail($number, $e->getMessage());
        }

        $payload = is_array($response->json()) ? $response->json() : [];

        if (! ($payload['ok'] ?? false)) {
            return $this->fail(
                $number,
                (string) ($payload['code'] ?? $payload['error'] ?? 'HTTP '.$response->status())
            );
        }

        $client = (array) ($payload['client'] ?? []);
        $instanceId = trim((string) ($client['instance_id'] ?? ''));
        $accessToken = trim((string) ($client['access_token'] ?? ''));

        if ($instanceId === '' || $accessToken === '') {
            return $this->fail($number, 'incomplete_client');
        }

        $this->log('info', 'lookup: found client', ['phone' => $number, 'instance_id' => $instanceId]);

        return [
            'instance_id' => $instanceId,
            'access_token' => $accessToken,
            'name' => (string) ($client['name'] ?? ''),
            'status' => $client['status'] ?? null,
            'days_remaining' => Arr::get($client, 'subscription.days_remaining'),
        ];
    }

    /** سبب عودة آخر جلبٍ فارغاً، مثل client_not_found أو invalid_lookup_key. */
    public function error(): ?string
    {
        return $this->error;
    }

    private function fail(string $number, string $error): null
    {
        $this->error = $error;
        $this->log('warning', 'lookup failed', ['phone' => $number, 'error' => $error]);

        return null;
    }

    private function key(): string
    {
        return trim((string) Arr::get($this->config, 'lookup_key', ''));
    }

    private function baseUrl(): string
    {
        return rtrim((string) Arr::get($this->config, 'base_url', ''), '/');
    }

    private function log(string $level, string $message, array $context = []): void
    {
        Log::channel((string) config('whatsapp.log_channel', 'whatsapp'))
            ->{$level}('[whatsapp:cwts] '.$message, $context);
    }
}
