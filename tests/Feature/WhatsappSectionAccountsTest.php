<?php

namespace Tests\Feature;

use App\Jobs\SendWhatsappMessage;
use App\Models\Client;
use App\Models\Unit;
use App\Models\WhatsappAccount;
use App\Models\WhatsappMessage;
use App\Services\Whatsapp\WhatsappAccounts;
use App\Services\WhatsappNotifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Each section speaks from its own number: a unit's account first, then its
 * section's, then the shared gateway.
 */
class WhatsappSectionAccountsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // The migration seeds the business's own numbers; each test sets its own.
        WhatsappAccount::query()->delete();
    }

    public function test_a_unit_account_wins_over_its_section_account(): void
    {
        $hall = $this->unit('hall');
        $sectionWide = $this->account(['name' => 'القاعات', 'section' => 'halls']);
        $own = $this->account(['name' => 'قاعة ديوان المسره', 'section' => 'halls', 'unit_id' => $hall->id]);

        $accounts = app(WhatsappAccounts::class);

        $this->assertSame($own->id, $accounts->resolve($hall->id, 'halls')?->id);
        $this->assertSame($sectionWide->id, $accounts->resolve($this->unit('hall')->id, 'halls')?->id);
        $this->assertNull($accounts->resolve(null, 'chalets'));
    }

    public function test_a_pool_client_is_sent_to_from_the_pools_number_and_signed_with_its_name(): void
    {
        Queue::fake();
        $pools = $this->account(['name' => 'مؤسسة العجلان لبرك السباحه', 'section' => 'pools']);
        $client = Client::create(['name' => 'سالم', 'mobile' => '0551234567', 'type' => 'pool']);

        $message = app(WhatsappNotifier::class)->send($client->mobile, 'أهلاً', 'welcome', $client);

        $this->assertSame($pools->id, $message->whatsapp_account_id);
        $this->assertSame('مؤسسة العجلان لبرك السباحه', app(WhatsappAccounts::class)->senderName($client));
        Queue::assertPushed(SendWhatsappMessage::class, fn ($job) => $job->accountId === $pools->id);
    }

    public function test_an_inactive_account_falls_back_to_the_shared_gateway(): void
    {
        Queue::fake();
        $this->account(['name' => 'المسابح', 'section' => 'pools', 'is_active' => false]);
        $client = Client::create(['name' => 'سالم', 'mobile' => '0551234567', 'type' => 'pool']);

        $message = app(WhatsappNotifier::class)->send($client->mobile, 'أهلاً', 'other', $client);

        $this->assertNull($message->whatsapp_account_id);
    }

    public function test_the_job_sends_with_the_account_credentials(): void
    {
        Http::fake(['*' => Http::response(['ok' => true], 200)]);
        $account = $this->account([
            'name' => 'قاعة ياسمين الشام', 'section' => 'halls',
            'instance_id' => 'hall-instance', 'access_token' => 'hall-token',
        ]);
        $message = $this->logRow($account);

        (new SendWhatsappMessage('966551234567', 'مرحبا', null, $message->id, $account->id))->handle();

        $this->assertSame('sent', $message->fresh()->status);
        Http::assertSent(fn (Request $request) => str_contains($request->url(), 'instance_id=hall-instance')
            && str_contains($request->url(), 'access_token=hall-token'));
    }

    public function test_an_unlinked_account_fails_its_message_rather_than_borrowing_the_shared_number(): void
    {
        Http::fake();
        config(['whatsapp.drivers.cwts.instance_id' => 'shared', 'whatsapp.drivers.cwts.access_token' => 'shared']);
        $account = $this->account(['name' => 'المسابح', 'section' => 'pools']);
        $message = $this->logRow($account);

        (new SendWhatsappMessage('966551234567', 'مرحبا', null, $message->id, $account->id))->handle();

        $this->assertSame('failed', $message->fresh()->status);
        $this->assertSame('رقم القسم غير مربوط', $message->fresh()->error);
        Http::assertNothingSent();
    }

    public function test_the_token_is_stored_encrypted(): void
    {
        $account = $this->account(['name' => 'المسابح', 'section' => 'pools', 'access_token' => 'secret-token']);

        $this->assertNotSame('secret-token', $account->getRawOriginal('access_token'));
        $this->assertSame('secret-token', $account->fresh()->access_token);
    }

    private function account(array $attributes): WhatsappAccount
    {
        return WhatsappAccount::create($attributes + ['driver' => 'cwts', 'is_active' => true]);
    }

    private function unit(string $type): Unit
    {
        static $n = 0;
        $n++;

        return Unit::create(['code' => "U{$n}", 'name' => "وحدة {$n}", 'type' => $type]);
    }

    private function logRow(WhatsappAccount $account): WhatsappMessage
    {
        return WhatsappMessage::create([
            'to_number' => '966551234567', 'body' => 'مرحبا', 'category' => 'utility',
            'purpose' => 'other', 'status' => 'queued', 'whatsapp_account_id' => $account->id,
        ]);
    }
}
