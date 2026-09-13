<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Services\WaGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Mockery;
use Mockery\MockInterface;
use Psr\Log\LoggerInterface;
use Tests\TestCase;

/**
 * What the gateway writes to the whatsapp log.
 *
 * Asking the provider why a message never arrived needs the request and its
 * reply, in order, with the body they actually returned.
 */
class WhatsappGatewayLogTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Setting::current()->update([
            'wa_enabled' => true,
            'wa_instance_id' => 'INSTANCE',
            'wa_access_token' => 'SECRET-TOKEN',
        ]);
    }

    public function test_a_sent_message_logs_its_request_and_the_gateways_reply(): void
    {
        Http::fake(['*' => Http::response(['ok' => true, 'message' => 'queued'], 200)]);

        $log = $this->spyWhatsappLog();

        (new WaGateway)->send('0501234567', 'سند قبض عربون');

        $log->shouldHaveReceived('info')
            ->withArgs(fn (string $message, array $context) => str_contains($message, '→ send')
                && $context['number'] === '966501234567'
                && $context['message'] === 'سند قبض عربون');

        $log->shouldHaveReceived('info')
            ->withArgs(fn (string $message, array $context) => str_contains($message, '← send ok')
                && str_contains($context['body'], 'queued'));
    }

    public function test_a_refusal_carrying_http_200_is_logged_as_a_failure_with_its_body(): void
    {
        Http::fake(['*' => Http::response(['ok' => false, 'error' => 'session not connected'], 200)]);

        $log = $this->spyWhatsappLog();

        (new WaGateway)->send('0501234567', 'مرحبًا');

        // The gateway refuses inside a 200 as often as it errors outright.
        $log->shouldHaveReceived('warning')
            ->withArgs(fn (string $message, array $context) => str_contains($message, 'failed')
                && $context['http_status'] === 200
                && str_contains($context['body'], 'session not connected'));
    }

    public function test_an_attachment_is_logged_with_the_link_that_was_sent(): void
    {
        Http::fake(['*' => Http::response(['ok' => true], 200)]);

        $log = $this->spyWhatsappLog();

        (new WaGateway)->sendMedia('0501234567', 'https://example.test/bonds/bond-a-1-2.pdf', ['caption' => 'سند']);

        $log->shouldHaveReceived('info')
            ->withArgs(fn (string $message, array $context) => str_contains($message, '→ send-media')
                && $context['media_url'] === 'https://example.test/bonds/bond-a-1-2.pdf');
    }

    public function test_a_number_the_gateway_would_reject_is_logged_and_never_sent(): void
    {
        Http::fake();

        $log = $this->spyWhatsappLog();

        $result = (new WaGateway)->send('123', 'مرحبًا');

        $this->assertFalse($result['ok']);
        $log->shouldHaveReceived('warning')->withArgs(fn (string $message) => str_contains($message, 'invalid number'));
        Http::assertNothingSent();
    }

    public function test_the_access_token_never_reaches_the_log(): void
    {
        Http::fake(['*' => Http::response(['ok' => true], 200)]);

        $log = $this->spyWhatsappLog();

        (new WaGateway)->send('0501234567', 'مرحبًا');

        // Credentials travel in the query string and must stay there.
        $log->shouldNotHaveReceived('info', [
            Mockery::any(),
            Mockery::on(fn ($context) => str_contains(json_encode($context), 'SECRET-TOKEN')),
        ]);
    }

    private function spyWhatsappLog(): MockInterface
    {
        $spy = Mockery::spy(LoggerInterface::class);

        Log::shouldReceive('channel')->andReturn($spy);

        return $spy;
    }
}
