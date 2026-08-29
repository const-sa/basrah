<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Database\Seeders\AdminUserSeeder;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * حساب المدير الأول: يُنشأ ليُفتح به النظام، ولا يُكتب فوقه بعد ذلك.
 */
class AdminUserSeederTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesSeeder::class);
    }

    public function test_it_creates_the_first_administrator(): void
    {
        $this->seed(AdminUserSeeder::class);

        $admin = User::where('email', AdminUserSeeder::DEFAULT_EMAIL)->firstOrFail();

        $this->assertTrue(Hash::check(AdminUserSeeder::DEFAULT_PASSWORD, $admin->password));
        $this->assertTrue($admin->is_active);
        $this->assertTrue($admin->has_all_units);
        $this->assertSame(Role::where('slug', 'super-admin')->value('id'), $admin->role_id);
    }

    public function test_the_login_works_with_the_seeded_credentials(): void
    {
        $this->seed(AdminUserSeeder::class);

        $this->post('/admin/login', [
            'email' => AdminUserSeeder::DEFAULT_EMAIL,
            'password' => AdminUserSeeder::DEFAULT_PASSWORD,
        ])->assertRedirect();

        $this->assertAuthenticated();
    }

    /**
     * المدير الذي بدّل كلمته فعل ذلك عن قصد، وإعادةُ البذر لا تُرجعه إلى
     * الكلمة المنشورة في الشيفرة من حيث لا يدري.
     */
    public function test_re_seeding_does_not_reset_a_changed_password(): void
    {
        $this->seed(AdminUserSeeder::class);

        $admin = User::where('email', AdminUserSeeder::DEFAULT_EMAIL)->firstOrFail();
        $admin->update(['password' => Hash::make('a-password-of-my-own')]);

        $this->seed(AdminUserSeeder::class);

        $admin->refresh();

        $this->assertTrue(Hash::check('a-password-of-my-own', $admin->password));
        $this->assertFalse(Hash::check(AdminUserSeeder::DEFAULT_PASSWORD, $admin->password));
        $this->assertSame(1, User::where('email', AdminUserSeeder::DEFAULT_EMAIL)->count());
    }

    /**
     * ولا يُنشئ نظيرًا لحسابٍ محذوف حذفًا ناعمًا: إعادتُه من سلة المحذوفات
     * تُوقع بريدين متطابقين.
     */
    public function test_a_soft_deleted_administrator_is_not_recreated(): void
    {
        $this->seed(AdminUserSeeder::class);

        User::where('email', AdminUserSeeder::DEFAULT_EMAIL)->firstOrFail()->delete();

        $this->seed(AdminUserSeeder::class);

        $this->assertSame(0, User::where('email', AdminUserSeeder::DEFAULT_EMAIL)->count());
        $this->assertSame(1, User::withTrashed()->where('email', AdminUserSeeder::DEFAULT_EMAIL)->count());
    }

    /**
     * الخادم يُركَّب بحسابٍ خاصٍّ به حين تُكتب بياناته في البيئة.
     */
    public function test_the_environment_may_name_another_administrator(): void
    {
        config()->set('app.env', 'local');
        putenv('ADMIN_EMAIL=owner@example.com');
        putenv('ADMIN_PASSWORD=a-stronger-one');

        try {
            $this->seed(AdminUserSeeder::class);
        } finally {
            putenv('ADMIN_EMAIL');
            putenv('ADMIN_PASSWORD');
        }

        $admin = User::where('email', 'owner@example.com')->firstOrFail();

        $this->assertTrue(Hash::check('a-stronger-one', $admin->password));
        $this->assertSame(0, User::where('email', AdminUserSeeder::DEFAULT_EMAIL)->count());
    }
}
