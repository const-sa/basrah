<?php

namespace Tests\Feature;

use App\Services\Whatsapp\MediaType;
use App\Services\Whatsapp\WhatsappManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * المرفق يُعلَن نوعه للبوابة.
 *
 * بلا نوعٍ معلن يعرض واتساب الملف «BIN» ولا يفتحه العميل، ولو كان اسمه ينتهي
 * بـ.pdf — فخادمٌ يقدّمه بترويسة application/octet-stream يكفي لحدوث ذلك،
 * والإعلان الصريح يسبق استنتاج البوابة.
 */
class WhatsappMediaTypeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('whatsapp.driver', 'cwts');
        config()->set('whatsapp.drivers.cwts.base_url', 'https://www.c-wts.com');
        config()->set('whatsapp.drivers.cwts.instance_id', 'INSTANCE');
        config()->set('whatsapp.drivers.cwts.access_token', 'SECRET');
    }

    public function test_a_contract_pdf_is_sent_as_a_pdf(): void
    {
        Http::fake(['*' => Http::response(['ok' => true], 200)]);

        $this->gateway()->sendMedia(
            '0551234567',
            'مرفق عقد إيجار قاعة',
            'https://diwanalmasara.com/storage/contracts/contract-CT-2026-0024.pdf',
        );

        Http::assertSent(function ($request) {
            $body = urldecode((string) $request->body());

            return str_contains($request->url(), '/api/send-media')
                && str_contains($body, 'mimetype=application/pdf')
                && str_contains($body, 'type=document')
                && str_contains($body, 'file_name=contract-CT-2026-0024.pdf');
        });
    }

    /** الصورة تُعلَن صورةً، فلا تصل مرفقًا يُفتح بتطبيق آخر. */
    public function test_an_image_is_sent_as_an_image(): void
    {
        Http::fake(['*' => Http::response(['ok' => true], 200)]);

        $this->gateway()->sendMedia('0551234567', 'شعار', 'https://example.test/logo.png');

        Http::assertSent(function ($request) {
            $body = urldecode((string) $request->body());

            return str_contains($body, 'mimetype=image/png') && str_contains($body, 'type=image');
        });
    }

    /** امتدادٌ مجهول لا يُخترع له نوع: البوابة تستنتجه كما كانت. */
    public function test_an_unknown_extension_declares_nothing(): void
    {
        Http::fake(['*' => Http::response(['ok' => true], 200)]);

        $this->assertNull(MediaType::mimeType('https://example.test/file.xyz'));

        $this->gateway()->sendMedia('0551234567', 'ملف', 'https://example.test/file.xyz');

        Http::assertSent(fn ($request) => ! str_contains(urldecode((string) $request->body()), 'mimetype='));
    }

    private function gateway()
    {
        return app(WhatsappManager::class)->driver('cwts');
    }
}
