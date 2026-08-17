<?php

namespace App\Jobs;

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

        if (! $gateway->isConfigured()) {
            return;
        }

        // البوابة ترسل النص مع الوسائط في طلب واحد، فلا تُرسل رسالتان
        // يصل ترتيبهما مقلوبًا إلى العميل.
        $result = $this->mediaUrl
            ? $gateway->sendMedia($this->number, $this->mediaUrl, ['caption' => $this->message])
            : $gateway->send($this->number, $this->message);

        if (! ($result['ok'] ?? false)) {
            Log::warning('SendWhatsappMessage: تعذّر إرسال رسالة واتساب', [
                'number' => $this->number,
                'media' => $this->mediaUrl,
                'error' => $result['error'] ?? null,
            ]);
        }
    }
}
