<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\Setting;
use App\Models\User;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The social accounts are set in the general settings and stand beside the
 * phone and the mail in the site's contact section.
 */
class SiteSocialAccountsTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([RolesSeeder::class]);

        $this->owner = User::factory()->create([
            'role_id' => Role::where('slug', 'super-admin')->value('id'),
            'is_active' => true,
            'has_all_units' => true,
        ]);
    }

    public function test_the_settings_screen_carries_the_social_accounts(): void
    {
        Setting::current()->update([
            'instagram' => 'diwanalmasara',
            'tiktok' => 'diwanalmasara',
            'snapchat' => 'diwanalmasara',
        ]);

        $this->actingAs($this->owner)->get('/admin/settings/general')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('admin/settings/General')
                ->where('settings.instagram', 'diwanalmasara')
                ->where('settings.tiktok', 'diwanalmasara')
                ->where('settings.snapchat', 'diwanalmasara')
                ->etc());
    }

    public function test_the_accounts_are_saved_as_typed(): void
    {
        $this->actingAs($this->owner)->post('/admin/settings/general', [
            'business_name' => 'ديوان المسرة',
            'instagram' => 'diwanalmasara',
            'tiktok' => '@diwanalmasara',
            'snapchat' => 'https://www.snapchat.com/add/diwanalmasara',
        ])->assertRedirect();

        $settings = Setting::current()->fresh();

        $this->assertSame('diwanalmasara', $settings->instagram);
        $this->assertSame('@diwanalmasara', $settings->tiktok);
        $this->assertSame('https://www.snapchat.com/add/diwanalmasara', $settings->snapchat);
    }

    public function test_the_front_page_carries_the_accounts_to_the_visitor(): void
    {
        Setting::current()->update([
            'instagram' => 'diwanalmasara',
            'tiktok' => '@diwanalmasara',
            'snapchat' => 'https://www.snapchat.com/add/diwanalmasara',
        ]);

        $this->get('/')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('site/Home')
                ->where('org.instagram', 'diwanalmasara')
                ->where('org.tiktok', '@diwanalmasara')
                ->where('org.snapchat', 'https://www.snapchat.com/add/diwanalmasara')
                ->etc());
    }

    /** An account left blank is passed as null, so no card is drawn for it. */
    public function test_an_unset_account_reaches_the_page_as_null(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('org.instagram', null)
                ->where('org.tiktok', null)
                ->where('org.snapchat', null)
                ->etc());
    }
}
