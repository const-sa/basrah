<?php

namespace App\Jobs;

use App\Models\Setting;
use App\Services\WaGateway;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

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
    ) {}

    public function handle(): void
    {
        $gateway = new WaGateway;

        // Said out loud, not returned silently: an unconfigured gateway is the
        // commonest reason a "sent" message never arrives, and an empty log
        // makes it look as though the job never ran at all.
        if (! $gateway->isConfigured()) {
            Log::channel('whatsapp')->warning('SendWhatsappMessage: البوابة غير مهيّأة — لم تُرسل الرسالة', [
                'number' => $this->number,
                'media' => $this->mediaUrl,
                'wa_enabled' => (bool) Setting::current()->wa_enabled,
                'has_credentials' => $gateway->hasCredentials(),
            ]);

            return;
        }

        // البوابة ترسل النص مع الوسائط في طلب واحد، فلا تُرسل رسالتان
        // يصل ترتيبهما مقلوبًا إلى العميل.
        $result = $this->mediaUrl
            ? $gateway->sendMedia($this->number, $this->mediaUrl, ['caption' => $this->message])
            : $gateway->send($this->number, $this->message);

        if (! ($result['ok'] ?? false)) {
            Log::channel('whatsapp')->warning('SendWhatsappMessage: تعذّر إرسال رسالة واتساب', [
                'number' => $this->number,
                'media' => $this->mediaUrl,
                'error' => $result['error'] ?? null,
            ]);
        }
    }
}
