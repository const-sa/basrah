<?php

namespace Tests\Feature;

use App\Models\ContractTemplate;
use App\Models\Role;
use App\Models\User;
use App\Support\HallContractTemplate;
use Database\Seeders\ContractTemplateSeeder;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * شاشة «نموذج العقد» داخل القاعات.
 */
class HallContractTemplateTest extends TestCase
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

    public function test_the_seeder_pins_the_hall_contract_template(): void
    {
        $this->seed(ContractTemplateSeeder::class);

        $template = ContractTemplate::where('name', HallContractTemplate::NAME)->first();

        $this->assertNotNull($template);
        $this->assertStringContainsString('{{client_name}}', $template->body);
        $this->assertStringContainsString('مبلغ تأمين', $template->terms);
    }

    public function test_the_screen_renders_the_template_even_before_seeding(): void
    {
        $this->actingAs($this->owner)->get('/admin/units/contract-template')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('admin/units/ContractTemplate')
                ->where('template.name', HallContractTemplate::NAME)
                ->has('placeholders'));

        $this->assertDatabaseHas('contract_templates', ['name' => HallContractTemplate::NAME]);
    }

    public function test_the_route_is_not_swallowed_by_the_units_type_route(): void
    {
        $this->actingAs($this->owner)->get('/admin/units/contract-template')
            ->assertInertia(fn ($page) => $page->component('admin/units/ContractTemplate'));
    }

    public function test_the_template_text_is_editable_and_restorable(): void
    {
        $this->seed(ContractTemplateSeeder::class);

        $this->actingAs($this->owner)->put('/admin/units/contract-template', [
            'body' => 'نص معدّل {{client_name}}',
            'terms' => 'شروط معدّلة',
            'is_default' => false,
            'is_active' => true,
        ])->assertRedirect();

        $this->assertSame('شروط معدّلة', ContractTemplate::where('name', HallContractTemplate::NAME)->value('terms'));

        $this->actingAs($this->owner)->post('/admin/units/contract-template/reset')->assertRedirect();

        $this->assertSame(
            HallContractTemplate::TERMS,
            ContractTemplate::where('name', HallContractTemplate::NAME)->value('terms'),
        );
    }

    public function test_marking_it_default_demotes_the_other_templates(): void
    {
        $this->seed(ContractTemplateSeeder::class);

        $this->actingAs($this->owner)->put('/admin/units/contract-template', [
            'body' => HallContractTemplate::BODY,
            'terms' => HallContractTemplate::TERMS,
            'is_default' => true,
            'is_active' => true,
        ])->assertRedirect();

        $this->assertSame(1, ContractTemplate::where('is_default', true)->count());
        $this->assertTrue(ContractTemplate::where('name', HallContractTemplate::NAME)->value('is_default'));
    }
}
