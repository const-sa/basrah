<?php

namespace App\Services\Whatsapp;

use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Support\Arr;
use JsonSerializable;

/**
 * Provider-agnostic result of a WhatsApp API call.
 *
 * Every driver normalises its own payload into this object so call sites never
 * have to know which gateway is active.
 */
class WhatsappResponse implements Arrayable, JsonSerializable
{
    public function __construct(
        protected string $driver,
        protected bool $ok,
        protected int $status = 0,
        protected array $data = [],
        protected ?string $error = null,
        protected ?string $messageId = null
    ) {}

    public static function success(string $driver, int $status, array $data = [], ?string $messageId = null): self
    {
        return new self($driver, true, $status, $data, null, $messageId);
    }

    public static function failure(string $driver, string $error, int $status = 0, array $data = []): self
    {
        return new self($driver, false, $status, $data, $error);
    }

    public function driver(): string
    {
        return $this->driver;
    }

    public function ok(): bool
    {
        return $this->ok;
    }

    /**
     * Alias kept for readability at call sites that used to receive an HTTP response.
     */
    public function successful(): bool
    {
        return $this->ok;
    }

    public function failed(): bool
    {
        return ! $this->ok;
    }

    public function status(): int
    {
        return $this->status;
    }

    public function error(): ?string
    {
        return $this->error;
    }

    public function messageId(): ?string
    {
        return $this->messageId;
    }

    public function data(): array
    {
        return $this->data;
    }

    /**
     * Read the raw gateway payload, optionally by "dot" key.
     *
     * @return mixed
     */
    public function json(?string $key = null, $default = null)
    {
        return $key === null ? $this->data : Arr::get($this->data, $key, $default);
    }

    public function toArray(): array
    {
        return [
            'driver' => $this->driver,
            'ok' => $this->ok,
            'status' => $this->status,
            'message_id' => $this->messageId,
            'error' => $this->error,
            'data' => $this->data,
        ];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
