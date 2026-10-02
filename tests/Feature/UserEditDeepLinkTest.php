<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * ?edit=ID على شاشة الموظفين يسلّم المستخدم المطلوب لتُفتح نافذة تعديله —
 * زرّ «ربط حسابي بوحدة» في نموذج المصروف يقود إليه.
 */
class UserEditDeepLinkTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_requested_user_is_handed_to_the_screen(): void
    {
        $this->seed(RolesSeeder::class);

        $admin = User::factory()->create([
            'role_id' => Role::where('slug', 'super-admin')->value('id'),
            'is_active' => true,
        ]);
        $scoped = User::factory()->create(['is_active' => true, 'has_all_units' => false]);

        $this->actingAs($admin)
            ->get('/admin/employees?edit='.$scoped->id)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('editTarget.id', $scoped->id)
                ->where('editTarget.has_all_units', false));

        $this->actingAs($admin)
            ->get('/admin/employees')
            ->assertInertia(fn (Assert $page) => $page->where('editTarget', null));
    }
}
