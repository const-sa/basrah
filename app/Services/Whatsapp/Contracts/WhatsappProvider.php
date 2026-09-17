<?php

namespace App\Services\Whatsapp\Contracts;

use App\Services\Whatsapp\WhatsappQrCode;
use App\Services\Whatsapp\WhatsappResponse;

/**
 * Contract every WhatsApp gateway driver must satisfy.
 * WhatsappManager resolves one by config('whatsapp.driver').
 */
interface WhatsappProvider
{
    /**
     * Driver key, e.g. "waclient" or "cwts".
     */
    public function name(): string;

    /**
     * Whether the driver has the credentials it needs to talk to the gateway.
     */
    public function configured(): bool;

    /**
     * Send a plain text message.
     */
    public function sendText(string $phone, string $message): WhatsappResponse;

    /**
     * Send a media file (image / video / audio / document) by public URL.
     *
     * @param  string|null  $type  image|video|audio|document, auto-detected when null
     * @param  array  $options  file_name, mimetype
     */
    public function sendMedia(
        string $phone,
        string $message,
        string $mediaUrl,
        ?string $type = null,
        array $options = []
    ): WhatsappResponse;

    /**
     * Session / subscription state of the linked WhatsApp instance.
     *
     * Successful responses always carry a normalised `connected` bool and,
     * when the gateway reports one, the linked `phone`.
     */
    public function status(): WhatsappResponse;

    /**
     * QR code used to link a WhatsApp account to the instance.
     */
    public function qrCode(): WhatsappQrCode;
}
