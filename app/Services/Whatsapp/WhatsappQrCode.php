<?php

namespace App\Services\Whatsapp;

/**
 * Result of a "link this instance" QR request, normalised across drivers.
 */
class WhatsappQrCode
{
    public function __construct(
        protected string $driver,
        protected bool $connected = false,
        protected ?string $image = null,
        protected ?string $error = null,
        protected ?string $code = null,
        protected ?string $phone = null
    ) {}

    /**
     * The instance is already linked to a WhatsApp account, no QR needed.
     */
    public static function linked(string $driver, ?string $phone = null): self
    {
        return new self($driver, true, null, null, null, $phone);
    }

    /**
     * @param  string  $image  Base64 payload, with or without the data URI prefix.
     */
    public static function fromBase64(string $driver, string $image): self
    {
        return new self($driver, false, self::asDataUri($image));
    }

    public static function failed(string $driver, string $error, ?string $code = null): self
    {
        return new self($driver, false, null, $error, $code);
    }

    public function connected(): bool
    {
        return $this->connected;
    }

    /**
     * Data URI ready to drop into an <img src="...">.
     */
    public function image(): ?string
    {
        return $this->image;
    }

    public function error(): ?string
    {
        return $this->error;
    }

    /**
     * Machine readable error code, e.g. missing_credentials, qr_not_ready.
     */
    public function code(): ?string
    {
        return $this->code;
    }

    /**
     * Number of the linked account, when the gateway reports one. waclient does
     * not return it, so expect null there.
     */
    public function phone(): ?string
    {
        return $this->phone;
    }

    public function driver(): string
    {
        return $this->driver;
    }

    private static function asDataUri(string $image): string
    {
        return str_starts_with($image, 'data:') ? $image : 'data:image/png;base64,'.$image;
    }
}
