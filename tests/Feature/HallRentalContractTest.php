<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Contract;
use App\Models\ContractTemplate;
use App\Models\Role;
use App\Models\Unit;
use App\Models\User;
use App\Services\BookingService;
use App\Services\ContractPdf;
use App\Services\ContractService;
use App\Support\HallRentalContractTemplate;
use Database\Seeders\AccountsSeeder;
use Database\Seeders\BookingSetupSeeder;
use Database\Seeders\ContractTemplateSeeder;
use Database\Seeders\RolesSeeder;
use Database\Seeders\UnitsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * The halls' numbered rental pad — «عقد إيجار».
 */
class HallRentalContractTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        $this->seed([RolesSeeder::class, UnitsSeeder::class, BookingSetupSeeder::class, AccountsSeeder::class, ContractTemplateSeeder::class]);

        $this->owner = User::factory()->create([
            'role_id' => Role::where('slug', 'super-admin')->value('id'),
            'is_active' => true,
            'has_all_units' => true,
        ]);
    }

    public function test_the_seeder_pins_the_rental_pad(): void
    {
        $template = ContractTemplate::where('name', HallRentalContractTemplate::NAME)->first();

        $this->assertNotNull($template);
        $this->assertTrue($template->is_active);
        // The halls' pad is picked by activity, never promoted to the default.
        $this->assertFalse((bool) $template->is_default);
        $this->assertStringContainsString('{{client_birth_place}}', $template->body);
        $this->assertStringContainsString('العربون لا يُرد نهائيًا', $template->terms);
    }

    public function test_a_hall_booking_is_contracted_on_its_own_pad(): void
    {
        $contract = $this->contractFor($this->hall());

        $this->assertTrue($contract->isHallRentalForm());
        $this->assertSame(HallRentalContractTemplate::NAME, $contract->template->name);
        $this->assertStringContainsString('تم الاتفاق بين', $contract->body);

        $this->actingAs($this->owner)->get("/admin/contracts/{$contract->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('admin/contracts/Show')
                ->where('contract.is_hall_form', true));

        $this->assertStringStartsWith('%PDF-', app(ContractPdf::class)->render($contract));
    }

    public function test_a_chalet_keeps_its_own_daily_rental_form(): void
    {
        $this->assertFalse($this->contractFor($this->chaletLetWhole())->isHallRentalForm());
    }

    public function test_the_pad_is_edited_on_the_sheet_it_prints_on(): void
    {
        $contract = $this->contractFor($this->hall());

        $this->actingAs($this->owner)->get("/admin/contracts/{$contract->id}/edit")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('admin/contracts/Edit')
                ->where('contract.is_hall_form', true));

        $this->actingAs($this->owner)->put("/admin/contracts/{$contract->id}", [
            'fields' => [
                // The pad copies the tenant's card, birthplace included — the
                // system holds no such field, so it is written on the contract.
                'client_birth_place' => 'الرياض',
                'client_id_number' => '1098765432',
                'sections' => '2',
                'total_amount' => '4500',
            ],
            'body' => $contract->body,
            'terms' => $contract->terms,
        ])->assertRedirect("/admin/contracts/{$contract->id}");

        $contract->refresh();

        $this->assertSame('الرياض', $contract->data['client_birth_place']);
        $this->assertSame('2', $contract->data['sections']);
        $this->assertSame('4,500.00', $contract->data['total_amount']);
        $this->assertStringContainsString('الرياض', $contract->body);
    }

    public function test_the_edit_is_saved_by_post_as_well_as_by_put(): void
    {
        $contract = $this->contractFor($this->hall());

        // Shared hosting refuses PUT before the request reaches the app, so the
        // sheet posts and the route answers to both.
        $this->actingAs($this->owner)->post("/admin/contracts/{$contract->id}", [
            'fields' => ['client_birth_place' => 'جدة'],
            'body' => $contract->body,
            'terms' => $contract->terms,
        ])->assertRedirect("/admin/contracts/{$contract->id}");

        $this->assertSame('جدة', $contract->fresh()->data['client_birth_place']);
    }

    public function test_a_sheet_with_no_grid_does_not_erase_the_lines(): void
    {
        $contract = $this->contractFor($this->hall());
        $contract->update(['data' => [...$contract->data, 'items' => [
            ['name' => 'ضيافة', 'code' => null, 'quantity' => 1, 'unit_price' => '', 'total_price' => ''],
        ]]]);

        // The halls' pad posts no lines at all, and an absent grid is not an
        // instruction to empty one.
        $this->actingAs($this->owner)->put("/admin/contracts/{$contract->id}", [
            'fields' => ['sections' => '1'],
            'body' => $contract->body,
            'terms' => $contract->terms,
        ]);

        $this->assertCount(1, $contract->fresh()->lines());
    }

    private function hall(): Unit
    {
        return Unit::where('type', 'hall')->firstOrFail();
    }

    private function contractFor(Unit $unit): Contract
    {
        $booking = app(BookingService::class)->create([
            'unit_id' => $unit->id,
            'client_id' => Client::create(['name' => 'أم عبدالله', 'mobile' => '0551122334'])->id,
            'scope' => 'whole',
            'booking_date' => '2026-10-05',
            'period' => $unit->type === 'chalet' ? 'full_day' : 'evening',
            'status' => 'deposit_paid',
            'guests_count' => 120,
        ]);

        // A booking draws its contract as it is created; otherwise one is made.
        return $booking->contracts()->first()
            ?? app(ContractService::class)->generate($booking->fresh());
    }
}
