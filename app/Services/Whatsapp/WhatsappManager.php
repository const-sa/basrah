<?php

namespace App\Services\Whatsapp;

use App\Services\Whatsapp\Contracts\WhatsappProvider;
use App\Services\Whatsapp\Drivers\CwtsDriver;
use App\Services\Whatsapp\Drivers\WaClientDriver;
use Illuminate\Support\Manager;

/**
 * Resolves the gateway named by config('whatsapp.driver') — WHATSAPP_PROVIDER in .env.
 *
 * @method WhatsappProvider driver(string|null $driver = null)
 */
class WhatsappManager extends Manager
{
    public function getDefaultDriver(): string
    {
        return (string) $this->config->get('whatsapp.driver', 'cwts');
    }

    /** Switch the gateway at runtime — for tinkering and tests. */
    public function setDefaultDriver(string $name): void
    {
        $this->config->set('whatsapp.driver', $name);
    }

    /** @return list<string> */
    public function availableDrivers(): array
    {
        return ['cwts', 'waclient'];
    }

    /** The master switch, separate from whether credentials exist. */
    public function enabled(): bool
    {
        return (bool) $this->config->get('whatsapp.enabled', true);
    }

    /** Enabled and holding credentials — the test before any send. */
    public function isConfigured(): bool
    {
        return $this->enabled() && $this->driver()->configured();
    }

    /**
     * Credentials present, whatever the switch says.
     * The linking screen needs this: linking comes before enabling.
     */
    public function hasCredentials(): bool
    {
        return $this->driver()->configured();
    }

    protected function createCwtsDriver(): WhatsappProvider
    {
        return new CwtsDriver($this->driverConfig('cwts'));
    }

    protected function createWaclientDriver(): WhatsappProvider
    {
        return new WaClientDriver($this->driverConfig('waclient'));
    }

    /** Driver settings merged with the shared ones. */
    protected function driverConfig(string $name): array
    {
        $driver = $this->config->get("whatsapp.drivers.{$name}", []);

        return array_merge([
            'timeout' => $this->config->get('whatsapp.timeout', 30),
            'country_code' => $this->config->get('whatsapp.country_code', '966'),
            'log_channel' => $this->config->get('whatsapp.log_channel', 'whatsapp'),
        ], is_array($driver) ? $driver : []);
    }
}
