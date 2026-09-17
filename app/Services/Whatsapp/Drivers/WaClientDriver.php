<?php

namespace App\Services\Whatsapp\Drivers;

use App\Services\Whatsapp\MediaType;
use App\Services\Whatsapp\WhatsappQrCode;
use App\Services\Whatsapp\WhatsappResponse;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

/**
 * waclient.com WhatsApp Web API — https://waclient.com/docs/whatsapp-web-api
 * Its status carries no phone field, so a linked account shows no number here.
 */
class WaClientDriver extends Driver
{
    /**
     * Connection states reported by /instance_status.
     */
    private const STATE_CONNECTED = 'connected';

    /**
     * States where /get_qrcode returns nothing useful and the instance has to be
     * re-logged in instead.
     */
    private const STATES_NEEDING_RELOGIN = ['logged_out', 'disconnected'];

    public function name(): string
    {
        return 'waclient';
    }

    public function sendText(string $phone, string $message): WhatsappResponse
    {
        $number = $this->phone($phone);

        if ($refusal = $this->rejectInvalidNumber($number, 'send text')) {
            return $refusal;
        }

        return $this->call(
            'send text',
            fn () => $this->request()->asJson()->post($this->url('send'), $this->credentials() + [
                'number' => $number,
                'type' => 'text',
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

        $payload = $this->credentials() + [
            'number' => $number,
            // "media" lets the gateway detect image/video/audio from the URL extension.
            'type' => 'media',
            'message' => $this->sanitize($message),
            'media_url' => $mediaUrl,
        ];

        // A filename forces document mode, so only send it for documents.
        if ($type === MediaType::DOCUMENT) {
            $payload['filename'] = $options['file_name'] ?? MediaType::fileName($mediaUrl);
        }

        return $this->call(
            'send media',
            fn () => $this->request()->asJson()->post($this->url('send'), $payload),
            ['number' => $number, 'media_url' => $mediaUrl, 'type' => $type]
        );
    }

    public function status(): WhatsappResponse
    {
        $response = $this->call(
            'status',
            fn () => $this->request()->get($this->url('instance_status'), $this->credentials() + ['live' => 1])
        );

        if ($response->failed()) {
            return $response;
        }

        // pending, linking, connecting, connected, disconnected, logged_out
        $state = (string) $response->json('data.connection_state', '');
        $reloginRequired = (bool) $response->json('data.relogin_required', false);

        return WhatsappResponse::success($this->name(), $response->status(), $response->data() + [
            'connection_state' => $state,
            'relogin_required' => $reloginRequired,
            'connected' => $state === self::STATE_CONNECTED && ! $reloginRequired,
            'phone' => $response->json('data.phone'),
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

        $response = $this->needsRelogin($status) ? $this->reloginQrCode() : $this->newQrCode();

        // Legacy phrasing for an instance that is already paired.
        if (Str::contains(Str::lower((string) $response->json('message', '')), 'instance id has been used')) {
            return WhatsappQrCode::linked($this->name());
        }

        if ($response->failed()) {
            $error = $response->error() ?? 'unknown_error';

            return WhatsappQrCode::failed(
                $this->name(),
                $error,
                $error === 'missing_credentials' ? 'missing_credentials' : null
            );
        }

        $qr = (string) $response->json('base64', '');

        return $qr !== ''
            ? WhatsappQrCode::fromBase64($this->name(), $qr)
            : WhatsappQrCode::failed($this->name(), 'qr_not_ready', 'qr_not_ready');
    }

    protected function newQrCode(): WhatsappResponse
    {
        return $this->call(
            'qrcode',
            fn () => $this->request()->get($this->url('get_qrcode'), $this->credentials())
        );
    }

    /**
     * A logged-out instance needs the relogin endpoint to issue a fresh QR.
     */
    protected function reloginQrCode(): WhatsappResponse
    {
        return $this->call(
            'relogin qrcode',
            fn () => $this->request()->asJson()->post($this->url('relogin_qrcode'), $this->credentials())
        );
    }

    private function needsRelogin(WhatsappResponse $status): bool
    {
        return $status->ok()
            && ($status->json('relogin_required')
                || in_array($status->json('connection_state'), self::STATES_NEEDING_RELOGIN, true));
    }

    /**
     * Older deployments kept the full send endpoint in WHATSAPP_API_URL
     * (https://waclient.com/api/send); accept both that and a plain API root.
     * Only the endpoint is dropped, so a legacy URL keeps its /api prefix.
     */
    protected function baseUrl(): string
    {
        return (string) preg_replace('#/send$#', '', parent::baseUrl());
    }

    protected function isSuccessful(Response $response, array $payload): bool
    {
        if (array_key_exists('status', $payload)) {
            return Str::lower((string) $payload['status']) === 'success';
        }

        return $response->successful();
    }

    protected function errorMessage(Response $response, array $payload): string
    {
        $message = trim((string) ($payload['message'] ?? ''));

        return $message !== '' ? $message : 'HTTP '.$response->status();
    }

    protected function messageId(array $payload): ?string
    {
        $id = Arr::get($payload, 'message_payload.key.id');

        return $id === null ? null : (string) $id;
    }
}
