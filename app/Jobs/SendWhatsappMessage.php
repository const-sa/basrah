<?php

namespace App\Jobs;

use App\Models\WhatsappMessage;
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
    ) {}

    public function handle(WhatsappManager $whatsapp): void
    {
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

        // البوابة ترسل النص مع الوسائط في طلب واحد، فلا تُرسل رسالتان
        // يصل ترتيبهما مقلوبًا إلى العميل.
        $driver = $whatsapp->driver();

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
