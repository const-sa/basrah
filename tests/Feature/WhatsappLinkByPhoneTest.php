<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\Setting;
use App\Models\User;
use App\Services\Whatsapp\WhatsappManager;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * الربط على بوابة الشركة يبدأ من الرقم: المنصّة تسلّم معرّفات صاحبه، فلا ينسخ
 * أحدٌ معرّف جهاز ورمز وصول بيده، ولا يُعرض رمزٌ لرقمٍ خارج واتساب أصلاً.
 */
class WhatsappLinkByPhoneTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private string $envPath = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([RolesSeeder::class]);

        $this->owner = User::factory()->create([
            'role_id' => Role::where('slug', 'super-admin')->firstOrFail()->id,
            'is_active' => true,
        ]);

        config()->set('whatsapp.driver', 'cwts');
        config()->set('whatsapp.drivers.cwts.base_url', 'https://www.c-wts.com');
        config()->set('whatsapp.drivers.cwts.instance_id', null);
        config()->set('whatsapp.drivers.cwts.access_token', null);
        config()->set('whatsapp.drivers.cwts.lookup_key', 'LOOKUP-KEY');

        // الجلب يكتب المعرّفات في .env، فيُوجَّه إلى ملفٍ مؤقّت لا إلى ملف المشروع.
        $this->useTemporaryEnvFile();
    }

    protected function tearDown(): void
    {
        if (is_file($this->envPath)) {
            unlink($this->envPath);
        }

        parent::tearDown();
    }

    public function test_the_credentials_are_fetched_from_the_platform_by_the_saved_number(): void
    {
        $this->settings(['wa_number' => '966551234567']);

        Http::fake([
            '*/api/lookup*' => Http::response([
                'ok' => true,
                'client' => ['instance_id' => 'INSTANCE', 'access_token' => 'SECRET', 'name' => 'شاليهات البصرة'],
            ], 200),
            '*/api/check-number*' => Http::response(['ok' => true, 'exists' => true], 200),
            '*/api/status*' => Http::response(['ok' => true, 'status' => 'disconnected'], 200),
            '*/api/qrcode*' => Http::response(['ok' => true, 'qr' => 'BASE64PAYLOAD'], 200),
        ]);

        $response = $this->actingAs($this->owner)->getJson('/admin/settings/whatsapp/connect?check=1');

        $response->assertOk()
            ->assertJsonPath('state', 'qr')
            ->assertJsonPath('fetched_for', 'شاليهات البصرة');

        // المعرّفات المجلوبة هي ما تُسأل به البوابة في هذا الطلب نفسه.
        Http::assertSent(fn ($request) => str_contains($request->url(), '/api/qrcode')
            && str_contains($request->url(), 'instance_id=INSTANCE'));

        // وتُحفظ في .env، فالزيارة القادمة لا تسأل المنصّة من جديد.
        $env = file_get_contents($this->envPath);
        $this->assertStringContainsString('CWTS_INSTANCE_ID=INSTANCE', $env);
        $this->assertStringContainsString('CWTS_ACCESS_TOKEN=SECRET', $env);
    }

    public function test_a_number_the_platform_does_not_know_is_told_not_shown_a_code(): void
    {
        $this->settings(['wa_number' => '966551234567']);

        Http::fake(['*/api/lookup*' => Http::response(['ok' => false, 'code' => 'client_not_found'], 200)]);

        $this->actingAs($this->owner)
            ->getJson('/admin/settings/whatsapp/connect')
            ->assertOk()
            ->assertJsonPath('state', 'missing')
            ->assertJsonPath('message', 'لا يوجد عميل مسجَّل في المنصّة بهذا الرقم — تأكّد منه أو راجع الدعم.');

        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/api/qrcode'));
    }

    public function test_linking_does_not_start_before_a_number_is_saved(): void
    {
        $this->settings(['wa_number' => null]);

        Http::fake();

        $this->actingAs($this->owner)
            ->getJson('/admin/settings/whatsapp/connect')
            ->assertOk()
            ->assertJsonPath('state', 'missing_number');

        Http::assertNothingSent();
    }

    public function test_a_number_outside_whatsapp_is_refused_before_any_code_is_drawn(): void
    {
        $this->settings(['wa_number' => '966551234567']);

        Http::fake([
            '*/api/lookup*' => Http::response([
                'ok' => true,
                'client' => ['instance_id' => 'INSTANCE', 'access_token' => 'SECRET', 'name' => 'شاليهات البصرة'],
            ], 200),
            '*/api/check-number*' => Http::response(['ok' => true, 'exists' => false], 200),
        ]);

        $this->actingAs($this->owner)
            ->getJson('/admin/settings/whatsapp/connect?check=1')
            ->assertOk()
            ->assertJsonPath('state', 'not_registered');

        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/api/qrcode'));
    }

    /** الفحص حصّةٌ عند البوابة، فلا يتكرّر مع كل نبضة من الواجهة. */
    public function test_the_number_is_only_checked_on_the_first_beat(): void
    {
        $this->settings(['wa_number' => '966551234567']);

        config()->set('whatsapp.drivers.cwts.instance_id', 'INSTANCE');
        config()->set('whatsapp.drivers.cwts.access_token', 'SECRET');
        app()->forgetInstance(WhatsappManager::class);

        Http::fake([
            '*/api/status*' => Http::response(['ok' => true, 'status' => 'disconnected'], 200),
            '*/api/qrcode*' => Http::response(['ok' => true, 'qr' => 'BASE64PAYLOAD'], 200),
        ]);

        $this->actingAs($this->owner)
            ->getJson('/admin/settings/whatsapp/connect')
            ->assertOk()
            ->assertJsonPath('state', 'qr');

        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/api/check-number'));
    }

    public function test_saving_writes_every_gateways_credentials_and_keeps_an_untouched_token(): void
    {
        config()->set('whatsapp.drivers.cwts.access_token', 'OLD-CWTS-TOKEN');

        $this->actingAs($this->owner)->post('/admin/settings/whatsapp', [
            'wa_enabled' => true,
            'wa_driver' => 'cwts',
            'wa_country_code' => '965',
            'credentials' => [
                // توكن فارغ = إبقاء المحفوظ.
                'cwts' => ['base_url' => 'https://www.c-wts.com', 'instance_id' => 'CW-1', 'access_token' => ''],
                'waclient' => ['base_url' => 'https://api.waclient.com', 'instance_id' => 'WA-1', 'access_token' => 'WA-TOKEN'],
            ],
            'wa_number' => '0551234567',
            'wa_welcome_enabled' => false,
        ])->assertRedirect();

        $env = file_get_contents($this->envPath);

        $this->assertStringContainsString('WHATSAPP_PROVIDER=cwts', $env);
        $this->assertStringContainsString('WHATSAPP_COUNTRY_CODE=965', $env);
        $this->assertStringContainsString('CWTS_INSTANCE_ID=CW-1', $env);
        $this->assertStringContainsString('CWTS_ACCESS_TOKEN=OLD-CWTS-TOKEN', $env);
        // بوابةٌ غير مفعّلة تبقى بياناتها محفوظة، فتبديلها لا يعيد إدخالها.
        $this->assertStringContainsString('WHATSAPP_API_INSTANCE_ID=WA-1', $env);
        $this->assertStringContainsString('WHATSAPP_API_TOKEN=WA-TOKEN', $env);

        $this->assertSame('966551234567', Setting::current()->fresh()->wa_number);
    }

    public function test_the_screen_never_hands_the_browser_a_stored_token(): void
    {
        config()->set('whatsapp.drivers.cwts.access_token', 'SUPER-SECRET-TOKEN-VALUE');

        $response = $this->actingAs($this->owner)->get('/admin/settings/whatsapp');

        $response->assertOk();
        $response->assertDontSee('SUPER-SECRET-TOKEN-VALUE', false);

        $credentials = $response->viewData('page')['props']['gateway']['credentials'];
        $this->assertSame('SUPE••••••ALUE', $credentials['cwts']['saved_token']);
    }

    /** ملف .env مؤقّت: الاختبار لا يعبث بملف المطوّر. */
    private function useTemporaryEnvFile(): void
    {
        $this->envPath = sys_get_temp_dir().'/.env.whatsapp-link-'.uniqid();
        file_put_contents($this->envPath, "CWTS_INSTANCE_ID=\nCWTS_ACCESS_TOKEN=\n");

        $this->app->useEnvironmentPath(dirname($this->envPath));
        $this->app->loadEnvironmentFrom(basename($this->envPath));
    }

    private function settings(array $attributes): void
    {
        $settings = Setting::current();
        $settings->forceFill($attributes)->save();
    }
}
