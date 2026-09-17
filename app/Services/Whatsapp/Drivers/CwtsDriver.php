<?php

namespace App\Services\Whatsapp\Drivers;

use App\Services\Whatsapp\MediaType;
use App\Services\Whatsapp\WhatsappQrCode;
use App\Services\Whatsapp\WhatsappResponse;
use Illuminate\Http\Client\Response;

/**
 * c-wts.com gateway — https://www.c-wts.com/docs
 * Credentials go in the query string: an HTTP→HTTPS redirect drops the POST body.
 */
class CwtsDriver extends Driver
{
    public function name(): string
    {
        return 'cwts';
    }

    public function sendText(string $phone, string $message): WhatsappResponse
    {
        $number = $this->phone($phone);

        if ($refusal = $this->rejectInvalidNumber($number, 'send text')) {
            return $refusal;
        }

        return $this->call(
            'send text',
            fn () => $this->request()->asForm()->post($this->url('api/send', $this->credentials()), [
                'number' => $number,
                'message' => $this->sanitize($message),
            ]),
            ['number' => $number]
        );
    }

    public function sendMedia(
        string $phone,
        string $message,
        string $mediaUrl,
        ?string $type = null,
        array $options = []
    ): WhatsappResponse {
        $number = $this->phone($phone);

        if ($refusal = $this->rejectInvalidNumber($number, 'send media')) {
            return $refusal;
        }

        $type = $type ?: MediaType::detect($mediaUrl);

        if ($type === null) {
            // Not fatal: the gateway sniffs the type itself, but it is worth knowing about.
            $this->log('warning', 'send media: unrecognised file type', [
                'number' => $number,
                'media_url' => $mediaUrl,
            ]);
        }

        $payload = array_filter([
            'number' => $number,
            'media_url' => $mediaUrl,
            'type' => $type,
            'caption' => $this->sanitize($message),
            'file_name' => $options['file_name']
                ?? ($type === MediaType::DOCUMENT ? MediaType::fileName($mediaUrl) : null),
            'mimetype' => $options['mimetype'] ?? null,
        ], fn ($value) => $value !== null && $value !== '');

        return $this->call(
            'send media',
            fn () => $this->request()->asForm()->post($this->url('api/send-media', $this->credentials()), $payload),
            ['number' => $number, 'media_url' => $mediaUrl, 'type' => $type]
        );
    }

    public function status(): WhatsappResponse
    {
        $response = $this->call(
            'status',
            fn () => $this->request()->get($this->url('api/status', $this->credentials()))
        );

        if ($response->failed()) {
            return $response;
        }

        return WhatsappResponse::success($this->name(), $response->status(), $response->data() + [
            'connected' => $response->json('status') === 'connected',
        ]);
    }

    public function qrCode(): WhatsappQrCode
    {
        $status = $this->status();

        if ($status->error() === 'missing_credentials') {
            return WhatsappQrCode::failed($this->name(), 'missing_credentials', 'missing_credentials');
        }

        // A linked instance has no QR code to hand out.
        if ($status->ok() && $status->json('connected')) {
            return WhatsappQrCode::linked($this->name(), $status->json('phone'));
        }

        $response = $this->call(
            'qrcode',
            fn () => $this->request()->get($this->url('api/qrcode', $this->credentials()))
        );

        if ($response->ok()) {
            $qr = (string) $response->json('qr', '');

            return $qr !== ''
                ? WhatsappQrCode::fromBase64($this->name(), $qr)
                : WhatsappQrCode::failed($this->name(), 'qr_not_ready', 'qr_not_ready');
        }

        $code = (string) $response->json('code', '');

        // 409 already_connected: the instance is paired, no QR to show.
        if ($code === 'already_connected' || $response->status() === 409) {
            return WhatsappQrCode::linked($this->name());
        }

        $error = $response->error() ?? 'unknown_error';

        return WhatsappQrCode::failed($this->name(), $error, $code ?: ($error === 'missing_credentials' ? $error : null));
    }

    protected function isSuccessful(Response $response, array $payload): bool
    {
        if (array_key_exists('ok', $payload)) {
            return (bool) $payload['ok'];
        }

        return $response->successful();
    }

    protected function errorMessage(Response $response, array $payload): string
    {
        foreach (['error', 'code', 'message'] as $key) {
            $value = trim((string) ($payload[$key] ?? ''));

            if ($value !== '') {
                return $value;
            }
        }

        return 'HTTP '.$response->status();
    }

    protected function messageId(array $payload): ?string
    {
        $id = $payload['message_id'] ?? null;

        return $id === null ? null : (string) $id;
    }
}
