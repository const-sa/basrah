<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\Setting;
use App\Models\User;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * إعدادات الضريبة بعد انتقالها من الإعدادات العامة إلى المحاسبة.
 *
 * الانتقال ليس نقل ملف: الشاشة العامة ما عادت تكتب الضريبة، والشاشة الجديدة
 * وحدها تكتبها. ولو بقيت الأولى تكتبها لعادت النسبة إلى ١٥٪ كلما حُفظ الشعار.
 */
class TaxSettingsScreenTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([RolesSeeder::class]);

        $this->owner = User::factory()->create([
            'role_id' => Role::where('slug', 'super-admin')->firstOrFail()->id,
            'is_active' => true,
            'has_all_units' => true,
        ]);
    }

    public function test_the_tax_screen_opens_under_accounting(): void
    {
        Setting::current()->fill(['tax_enabled' => true, 'tax_rate' => 15, 'tax_number' => '300000000000003'])->save();

        $this->actingAs($this->owner)
            ->get('/admin/accounting/tax')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('admin/accounting/TaxSettings')
                ->where('settings.tax_enabled', true)
                ->where('settings.tax_number', '300000000000003'));
    }

    public function test_the_rate_is_saved_from_the_new_screen(): void
    {
        $this->actingAs($this->owner)
            ->post('/admin/accounting/tax', ['tax_enabled' => true, 'tax_number' => '310000000000003', 'tax_rate' => 5])
            ->assertSessionHasNoErrors();

        $settings = Setting::current()->fresh();

        $this->assertTrue((bool) $settings->tax_enabled);
        $this->assertSame('5.00', (string) $settings->tax_rate);
        $this->assertSame('310000000000003', $settings->tax_number);
    }

    /**
     * حفظ الهوية لا يمسّ الضريبة — وإلا أعاد كل حفظٍ للشعار النسبةَ إلى افتراضها.
     */
    public function test_saving_the_general_settings_leaves_the_tax_alone(): void
    {
        Setting::current()->fill(['tax_enabled' => true, 'tax_rate' => 5, 'tax_number' => '310000000000003'])->save();

        $this->actingAs($this->owner)
            ->post('/admin/settings/general', ['business_name' => 'ديوان المسرّة'])
            ->assertSessionHasNoErrors();

        $settings = Setting::current()->fresh();

        $this->assertSame('ديوان المسرّة', $settings->business_name);
        $this->assertTrue((bool) $settings->tax_enabled);
        $this->assertSame('5.00', (string) $settings->tax_rate);
        $this->assertSame('310000000000003', $settings->tax_number);
    }

    /**
     * النسبة الفارغة تعود إلى ١٥٪ لا إلى صفر: الصفر يُقصد إليه، والفراغ سهو.
     */
    public function test_a_blank_rate_falls_back_to_the_standard(): void
    {
        $this->actingAs($this->owner)
            ->post('/admin/accounting/tax', ['tax_enabled' => true, 'tax_rate' => null])
            ->assertSessionHasNoErrors();

        $this->assertSame('15.00', (string) Setting::current()->fresh()->tax_rate);
    }

    public function test_a_rate_above_a_hundred_is_refused(): void
    {
        $this->actingAs($this->owner)
            ->post('/admin/accounting/tax', ['tax_enabled' => true, 'tax_rate' => 140])
            ->assertSessionHasErrors('tax_rate');
    }
}
