<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Database\Seeders\AccountsSeeder;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * صفحة قسم المحاسبة — شاشاته مبسوطةً بدل قائمةٍ جانبية تُفتح وتُغلق.
 *
 * الشاشات نفسها تُبنى في الواجهة من شجرة التنقّل، فما يُختبر هنا هو الباب:
 * أنه يُفتح لمن يملك القسم، ويُردّ عمّن لا يملكه، وأنه لا يبتلع مسارات
 * المحاسبة التي تليه.
 */
class AccountingHubTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([RolesSeeder::class, AccountsSeeder::class]);
    }

    private function user(string $slug): User
    {
        return User::factory()->create([
            'role_id' => Role::where('slug', $slug)->firstOrFail()->id,
            'is_active' => true,
            'has_all_units' => true,
        ]);
    }

    public function test_the_accounting_section_opens_as_a_page(): void
    {
        $this->actingAs($this->user('accountant'))
            ->get('/admin/accounting')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('admin/SectionHub')
                ->where('section.key', 'accounting')
                ->where('section.label', 'المحاسبة')
                // الصفحة تجد مجموعتها في شجرة التنقّل بهذا المسار بعينه
                ->where('section.href', '/admin/accounting'));
    }

    /**
     * من لا يملك القسم لا يُفتح له بابه — ولو كان بابًا لا يحمل أرقامًا.
     */
    public function test_a_role_outside_the_section_is_refused(): void
    {
        $this->actingAs($this->user('cashier'))
            ->get('/admin/accounting')
            ->assertForbidden();
    }

    /**
     * مسار القسم مطابقٌ لا بادئة: شاشاته تبقى على حالها خلفه.
     */
    public function test_the_section_route_does_not_swallow_its_screens(): void
    {
        $this->actingAs($this->user('accountant'))
            ->get('/admin/accounting/accounts')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('admin/accounting/Accounts'));
    }

    public function test_a_guest_is_sent_to_the_login_screen(): void
    {
        $this->get('/admin/accounting')->assertRedirect('/admin/login');
    }
}
