<?php

namespace Tests\Feature;

use App\Services\Whatsapp\WhatsappManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Mockery;
use Mockery\MockInterface;
use Psr\Log\LoggerInterface;
use Tests\TestCase;

/**
 * What the gateway driver writes to the whatsapp log.
 *
 * Asking the provider why a message never arrived needs the call and its reply,
 * with the body they actually returned.
 */
class WhatsappGatewayLogTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('whatsapp.driver', 'cwts');
        config()->set('whatsapp.drivers.cwts.instance_id', 'INSTANCE');
        config()->set('whatsapp.drivers.cwts.access_token', 'SECRET-TOKEN');
    }

    public function test_a_sent_message_logs_the_call_and_the_gateways_reply(): void
    {
        Http::fake(['*' => Http::response(['ok' => true, 'message' => 'queued'], 200)]);

        $log = $this->spyWhatsappLog();

        $this->gateway()->sendText('0501234567', 'سند قبض عربون');

        $log->shouldHaveReceived('info')
            ->withArgs(fn (string $message, array $context) => str_contains($message, 'send text')
                && $context['number'] === '966501234567'
                && $context['status'] === 200
                && str_contains(json_encode($context['response']), 'queued'));
    }

    public function test_a_refusal_carrying_http_200_is_logged_as_a_failure_with_its_body(): void
    {
        Http::fake(['*' => Http::response(['ok' => false, 'error' => 'session not connected'], 200)]);

        $log = $this->spyWhatsappLog();

        $result = $this->gateway()->sendText('0501234567', 'مرحبًا');

        // The gateway refuses inside a 200 as often as it errors outright.
        $this->assertTrue($result->failed());
        $this->assertSame('session not connected', $result->error());

        $log->shouldHaveReceived('warning')
            ->withArgs(fn (string $message, array $context) => str_contains($message, 'send text')
                && $context['status'] === 200
                && str_contains(json_encode($context['response']), 'session not connected'));
    }

    public function test_an_attachment_is_logged_with_the_link_that_was_sent(): void
    {
        Http::fake(['*' => Http::response(['ok' => true], 200)]);

        $log = $this->spyWhatsappLog();

        $this->gateway()->sendMedia('0501234567', 'سند', 'https://example.test/bonds/bond-a-1-2.pdf');

        $log->shouldHaveReceived('info')
            ->withArgs(fn (string $message, array $context) => str_contains($message, 'send media')
                && $context['media_url'] === 'https://example.test/bonds/bond-a-1-2.pdf'
                && $context['type'] === 'document');
    }

    public function test_a_number_the_gateway_would_reject_is_logged_and_never_sent(): void
    {
        Http::fake();

        $log = $this->spyWhatsappLog();

        $result = $this->gateway()->sendText('123', 'مرحبًا');

        $this->assertTrue($result->failed());
        $this->assertSame('invalid_number', $result->error());
        $log->shouldHaveReceived('warning')->withArgs(fn (string $message) => str_contains($message, 'invalid number'));
        Http::assertNothingSent();
    }

    public function test_the_access_token_never_reaches_the_log(): void
    {
        Http::fake(['*' => Http::response(['ok' => true], 200)]);

        $log = $this->spyWhatsappLog();

        $this->gateway()->sendText('0501234567', 'مرحبًا');

        // Credentials travel in the query string and must stay there.
        $log->shouldNotHaveReceived('info', [
            Mockery::any(),
            Mockery::on(fn ($context) => str_contains(json_encode($context), 'SECRET-TOKEN')),
        ]);
    }

    private function gateway()
    {
        return app(WhatsappManager::class)->driver();
    }

    private function spyWhatsappLog(): MockInterface
    {
        $spy = Mockery::spy(LoggerInterface::class);

        Log::shouldReceive('channel')->andReturn($spy);

        return $spy;
    }
}
