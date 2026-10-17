<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Contract;
use App\Models\ContractTemplate;
use App\Models\Role;
use App\Models\Unit;
use App\Models\User;
use App\Support\HallRentalContractTemplate;
use Database\Seeders\AccountsSeeder;
use Database\Seeders\ContractTemplateSeeder;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Each hall reads its own contracts. A pad written with no booking named no
 * hall, so it showed in the register of every hall — one hall's client sat
 * in the other's list, under the other's supervisor.
 */
class ContractUnitScopeTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Unit $diwan;

    private Unit $yasmin;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        $this->seed([RolesSeeder::class, AccountsSeeder::class, ContractTemplateSeeder::class]);

        $this->owner = User::factory()->create([
            'role_id' => Role::where('slug', 'super-admin')->value('id'),
            'is_active' => true,
            'has_all_units' => true,
        ]);

        $this->diwan = Unit::create(['code' => 'H1', 'name' => 'قاعة ديوان المسرة', 'type' => 'hall']);
        $this->yasmin = Unit::create(['code' => 'H2', 'name' => 'قاعة ياسمين الشام', 'type' => 'hall']);
    }

    public function test_a_bookingless_hall_pad_must_name_its_hall(): void
    {
        $this->actingAs($this->owner)->post('/admin/contracts/direct', [
            'client_id' => $this->client()->id,
            'contract_template_id' => $this->rentalPad()->id,
        ])->assertSessionHasErrors('unit_id');

        $this->assertSame(0, Contract::count());
    }

    public function test_the_pad_is_bound_to_the_hall_it_names(): void
    {
        $this->writeFor($this->yasmin);

        $contract = Contract::firstOrFail();

        $this->assertSame($this->yasmin->id, $contract->unit_id);
        $this->assertSame('قاعة ياسمين الشام', $contract->data['unit_name']);
    }

    public function test_one_halls_supervisor_does_not_see_the_other_halls_contracts(): void
    {
        $this->writeFor($this->yasmin);
        $yasminContract = Contract::latest('id')->firstOrFail();
        $this->writeFor($this->diwan);

        $supervisor = $this->supervisorOf($this->diwan);

        $this->actingAs($supervisor)->get('/admin/halls/contracts')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('contracts.data', 1)
                ->where('contracts.data.0.unit_name', 'قاعة ديوان المسرة'));

        // Nor by typing its address.
        $this->actingAs($supervisor)->get("/admin/contracts/{$yasminContract->id}")->assertForbidden();
    }

    public function test_whoever_oversees_both_halls_filters_them_apart(): void
    {
        $this->writeFor($this->yasmin);
        $this->writeFor($this->diwan);

        $this->actingAs($this->owner)->get('/admin/halls/contracts')
            ->assertInertia(fn ($page) => $page->has('contracts.data', 2)->has('units', 2));

        $this->actingAs($this->owner)->get("/admin/halls/contracts?unit_id={$this->yasmin->id}")
            ->assertInertia(fn ($page) => $page
                ->has('contracts.data', 1)
                ->where('contracts.data.0.unit_name', 'قاعة ياسمين الشام'));
    }

    public function test_a_pad_written_under_the_wrong_hall_is_moved_from_the_edit_screen(): void
    {
        $this->writeFor($this->diwan);
        $contract = Contract::firstOrFail();

        $this->actingAs($this->owner)->post("/admin/contracts/{$contract->id}", [
            'body' => $contract->body,
            'unit_id' => $this->yasmin->id,
        ])->assertSessionHasNoErrors();

        $this->assertSame($this->yasmin->id, $contract->fresh()->unit_id);
    }

    private function writeFor(Unit $hall): void
    {
        $this->actingAs($this->owner)->post('/admin/contracts/direct', [
            'client_id' => $this->client()->id,
            'contract_template_id' => $this->rentalPad()->id,
            'unit_id' => $hall->id,
        ])->assertSessionHasNoErrors();
    }

    private function supervisorOf(Unit $hall): User
    {
        $user = User::factory()->create([
            'role_id' => Role::create([
                'slug' => 'hall-supervisor-'.$hall->id,
                'name' => 'مشرف '.$hall->name,
                'permissions' => ['halls.view', 'hall_contracts.view', 'hall_contracts.create', 'hall_contracts.edit'],
            ])->id,
            'is_active' => true,
            'has_all_units' => false,
        ]);
        $user->units()->sync([$hall->id]);

        return $user;
    }

    private function client(): Client
    {
        return Client::firstOrCreate(['mobile' => '0550000001'], ['name' => 'أبو بكر', 'type' => 'hall']);
    }

    private function rentalPad(): ContractTemplate
    {
        return ContractTemplate::where('name', HallRentalContractTemplate::NAME)->firstOrFail();
    }
}
