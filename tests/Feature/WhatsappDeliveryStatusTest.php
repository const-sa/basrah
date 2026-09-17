<?php

namespace Tests\Feature;

use App\Jobs\SendWhatsappMessage;
use App\Models\Client;
use App\Models\Setting;
use App\Models\WhatsappMessage;
use App\Services\WhatsappNotifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * The log says sent only when the gateway said so.
 */
class WhatsappDeliveryStatusTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_queued_message_is_not_marked_sent_before_the_gateway_answers(): void
    {
        Queue::fake();

        $message = app(WhatsappNotifier::class)->send('0551234567', 'مرحبا', 'other');

        $this->assertSame('queued', $message->status);
        $this->assertNull($message->sent_at);
        Queue::assertPushed(SendWhatsappMessage::class, fn ($job) => $job->messageId === $message->id);
    }

    public function test_the_job_marks_the_row_sent_when_the_gateway_accepts_it(): void
    {
        $this->connectWhatsapp();
        Http::fake(['*' => Http::response(['status' => 'success'], 200)]);

        $message = WhatsappMessage::create([
            'to_number' => '966551234567', 'body' => 'مرحبا',
            'category' => 'utility', 'purpose' => 'other', 'status' => 'queued',
        ]);

        (new SendWhatsappMessage('966551234567', 'مرحبا', null, $message->id))->handle();

        $this->assertSame('sent', $message->fresh()->status);
        $this->assertNotNull($message->fresh()->sent_at);
    }

    public function test_an_unconfigured_gateway_fails_the_row_instead_of_leaving_it_sent(): void
    {
        $message = WhatsappMessage::create([
            'to_number' => '966551234567', 'body' => 'مرحبا',
            'category' => 'utility', 'purpose' => 'other', 'status' => 'queued',
        ]);

        (new SendWhatsappMessage('966551234567', 'مرحبا', null, $message->id))->handle();

        $this->assertSame('failed', $message->fresh()->status);
        $this->assertNotNull($message->fresh()->error);
    }

    public function test_a_welcome_message_reaches_the_log(): void
    {
        Queue::fake();
        $this->connectWhatsapp();
        Setting::current()->update(['wa_welcome_enabled' => true]);

        app(WhatsappNotifier::class)->send('0551234567', 'أهلاً', 'welcome', Client::create([
            'name' => 'خالد المطيري', 'mobile' => '0551234567',
        ]));

        $this->assertSame(1, WhatsappMessage::where('purpose', 'welcome')->count());
    }

    private function connectWhatsapp(): void
    {
        Setting::current()->update([
            'wa_enabled' => true,
            'wa_instance_id' => 'instance-1',
            'wa_access_token' => 'token-1',
        ]);
    }
}
