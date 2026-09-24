<?php

namespace App\Jobs;

use App\Models\WhatsappAccount;
use App\Models\WhatsappMessage;
use App\Services\Whatsapp\Contracts\WhatsappProvider;
use App\Services\Whatsapp\WhatsappManager;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * إرسال رسالة واتساب واحدة عبر البوابة في الخلفية.
 * نقل الإرسال إلى الطابور يمنع تعليق طلبات الويب وإنهاك عمّال PHP
 * عند الإرسال الجماعي لعدد كبير من العملاء.
 */
class SendWhatsappMessage implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** عدد المحاولات قبل اعتبار المهمة فاشلة. */
    public int $tries = 3;

    /** الانتظار (ثوانٍ) بين المحاولات. */
    public int $backoff = 30;

    public function __construct(
        public string $number,
        public string $message,
        /** رابط عام لمرفق (عقد PDF مثلًا) — الرسالة نصية بدونه. */
        public ?string $mediaUrl = null,
        /** The log row this job answers for — its status follows the gateway, not the dispatch. */
        public ?int $messageId = null,
        /** رقم القسم الذي تخرج منه الرسالة — فارغ للبوابة العامة. */
        public ?int $accountId = null,
    ) {}

    public function handle(?WhatsappManager $whatsapp = null): void
    {
        $whatsapp ??= app(WhatsappManager::class);

        if ($this->accountId) {
            $this->sendFromAccount($whatsapp);

            return;
        }

        // An unconfigured gateway is the commonest reason a message never arrives.
        if (! $whatsapp->isConfigured()) {
            Log::channel('whatsapp')->warning('SendWhatsappMessage: البوابة غير مهيّأة — لم تُرسل الرسالة', [
                'number' => $this->number,
                'media' => $this->mediaUrl,
                'enabled' => $whatsapp->enabled(),
                'has_credentials' => $whatsapp->hasCredentials(),
            ]);

            // Not retried: no number of attempts will configure the gateway.
            $this->markFailed('البوابة غير مهيّأة');

            return;
        }

        $this->deliver($whatsapp->driver());
    }

    /**
     * رسالة القسم تخرج من رقمه أو لا تخرج: البوابة العامة رقمٌ آخر باسمٍ آخر،
     * والعميل الذي يردّ عليها يصل إلى غير من راسله.
     */
    private function sendFromAccount(WhatsappManager $whatsapp): void
    {
        $account = WhatsappAccount::find($this->accountId);

        $problem = match (true) {
            $account === null => 'رقم القسم محذوف',
            ! $account->is_active => 'رقم القسم موقوف',
            ! $account->hasCredentials() => 'رقم القسم غير مربوط',
            default => null,
        };

        if ($problem) {
            Log::channel('whatsapp')->warning('SendWhatsappMessage: '.$problem.' — لم تُرسل الرسالة', [
                'number' => $this->number,
                'account' => $this->accountId,
            ]);
            $this->markFailed($problem);

            return;
        }

        $this->deliver($whatsapp->forAccount($account));
    }

    private function deliver(WhatsappProvider $driver): void
    {
        // البوابة ترسل النص مع الوسائط في طلب واحد، فلا تُرسل رسالتان
        // يصل ترتيبهما مقلوبًا إلى العميل.
        $result = $this->mediaUrl
            ? $driver->sendMedia($this->number, $this->message, $this->mediaUrl)
            : $driver->sendText($this->number, $this->message);

        if ($result->failed()) {
            // Not retried: the gateway is not idempotent, so a second attempt can send twice.
            $this->markFailed($result->error() ?? 'تعذّر الإرسال');

            return;
        }

        $this->logRow()?->update(['status' => 'sent', 'sent_at' => now(), 'error' => null]);
    }

    /** The last attempt is what settles the log — earlier ones stay queued. */
    public function failed(?Throwable $e): void
    {
        $this->markFailed($e?->getMessage());
    }

    private function markFailed(?string $error): void
    {
        $this->logRow()?->update(['status' => 'failed', 'error' => $error]);
    }

    private function logRow(): ?WhatsappMessage
    {
        return $this->messageId ? WhatsappMessage::find($this->messageId) : null;
    }
}
