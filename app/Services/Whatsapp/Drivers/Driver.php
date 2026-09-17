<?php

namespace App\Services\Whatsapp\Drivers;

use App\Services\Whatsapp\Contracts\WhatsappProvider;
use App\Services\Whatsapp\PhoneNumber;
use App\Services\Whatsapp\WhatsappResponse;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Shared plumbing for gateway drivers: config, HTTP, logging, and one WhatsappResponse out.
 * Sends are never retried — the gateways are not idempotent, so a retry can deliver twice.
 */
abstract class Driver implements WhatsappProvider
{
    /** Shorter than this and no gateway can deliver it. */
    private const MIN_PHONE_LENGTH = 10;

    public function __construct(protected array $config = []) {}

    /**
     * Did the gateway consider the call successful?
     */
    abstract protected function isSuccessful(Response $response, array $payload): bool;

    /**
     * Human readable reason a call failed.
     */
    abstract protected function errorMessage(Response $response, array $payload): string;

    /**
     * Gateway side identifier of the sent message, when it exposes one.
     */
    protected function messageId(array $payload): ?string
    {
        return null;
    }

    public function configured(): bool
    {
        return $this->instanceId() !== '' && $this->accessToken() !== '';
    }

    protected function instanceId(): string
    {
        return trim((string) $this->config('instance_id'));
    }

    protected function accessToken(): string
    {
        return trim((string) $this->config('access_token'));
    }

    /**
     * Credentials the gateways expect on every call.
     */
    protected function credentials(): array
    {
        return [
            'instance_id' => $this->instanceId(),
            'access_token' => $this->accessToken(),
        ];
    }

    /**
     * @return mixed
     */
    protected function config(string $key, $default = null)
    {
        return Arr::get($this->config, $key, $default);
    }

    protected function baseUrl(): string
    {
        return rtrim((string) $this->config('base_url'), '/');
    }

    protected function url(string $endpoint, array $query = []): string
    {
        $url = $this->baseUrl().'/'.ltrim($endpoint, '/');

        return $query === [] ? $url : $url.'?'.http_build_query($query);
    }

    protected function request(): PendingRequest
    {
        return Http::timeout((int) $this->config('timeout', 30))
            ->acceptJson()
            ->withOptions(['verify' => (bool) $this->config('verify_ssl', true)]);
    }

    protected function phone($phone): string
    {
        return PhoneNumber::normalize($phone, $this->config('country_code', '966'));
    }

    /** Refused here rather than at the gateway, which bills the attempt either way. */
    protected function rejectInvalidNumber(string $number, string $action): ?WhatsappResponse
    {
        if (strlen($number) >= self::MIN_PHONE_LENGTH) {
            return null;
        }

        $this->log('warning', $action.': invalid number', ['number' => $number]);

        return WhatsappResponse::failure($this->name(), 'invalid_number');
    }

    /**
     * Square brackets crash the message parser of both gateways, so strip them.
     */
    protected function sanitize(string $message): string
    {
        return str_replace(['[', ']'], '', $message);
    }

    /**
     * Perform a gateway call and normalise whatever comes back - including
     * transport failures - into a WhatsappResponse.
     *
     * @param  callable(): Response  $callback
     */
    protected function call(string $action, callable $callback, array $context = []): WhatsappResponse
    {
        if (! $this->configured()) {
            $this->log('warning', $action.': missing credentials', $context);

            return WhatsappResponse::failure($this->name(), 'missing_credentials');
        }

        try {
            $response = $callback();
        } catch (Throwable $e) {
            $this->log('error', $action.': request failed', $context + ['exception' => $e->getMessage()]);

            return WhatsappResponse::failure($this->name(), $e->getMessage());
        }

        $payload = $this->payload($response);
        $ok = $this->isSuccessful($response, $payload);

        $this->log($ok ? 'info' : 'warning', $action, $context + [
            'status' => $response->status(),
            'response' => $payload ?: $response->body(),
        ]);

        return $ok
            ? WhatsappResponse::success($this->name(), $response->status(), $payload, $this->messageId($payload))
            : WhatsappResponse::failure(
                $this->name(),
                $this->errorMessage($response, $payload),
                $response->status(),
                $payload
            );
    }

    /**
     * Decoded JSON body, or an empty array when the gateway answers with something else.
     */
    protected function payload(Response $response): array
    {
        $payload = $response->json();

        return is_array($payload) ? $payload : [];
    }

    protected function log(string $level, string $message, array $context = []): void
    {
        Log::channel((string) $this->config('log_channel', 'whatsapp'))
            ->{$level}('[whatsapp:'.$this->name().'] '.$message, $context);
    }
}
