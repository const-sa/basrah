<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Client;
use App\Models\Contract;
use App\Models\ContractTemplate;
use App\Models\Role;
use App\Models\Unit;
use App\Models\User;
use App\Services\BookingService;
use App\Services\ChaletBookingService;
use App\Services\ContractPdf;
use App\Support\ChaletContractTemplate;
use App\Support\HallRentalContractTemplate;
use Database\Seeders\AccountsSeeder;
use Database\Seeders\BookingSetupSeeder;
use Database\Seeders\ContractTemplateSeeder;
use Database\Seeders\RolesSeeder;
use Database\Seeders\UnitsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/** The chalet contract — the approved daily-rental form. */
class ChaletContractTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();

        $this->seed([
            RolesSeeder::class, UnitsSeeder::class, BookingSetupSeeder::class,
            AccountsSeeder::class, ContractTemplateSeeder::class,
        ]);

        $this->owner = User::factory()->create([
            'role_id' => Role::where('slug', 'super-admin')->firstOrFail()->id,
            'is_active' => true,
            'has_all_units' => true,
        ]);
    }

    private function stay(): Booking
    {
        $client = Client::create(['name' => 'نزيل الشاليه', 'mobile' => '0559876543', 'city' => 'الرياض']);

        return app(ChaletBookingService::class)->create([
            'unit_id' => $this->chaletLetWhole()->id,
            'client_id' => $client->id,
            'scope' => 'whole',
            'booking_date' => '2026-10-05',
            'check_out_date' => '2026-10-08',
            'status' => 'deposit_paid',
        ]);
    }

    private function contractOf(Booking $booking): Contract
    {
        return $booking->contracts()->firstOrFail();
    }

    public function test_the_seeder_pins_the_chalet_contract_form(): void
    {
        $template = ContractTemplate::where('name', ChaletContractTemplate::NAME)->first();

        $this->assertNotNull($template);
        $this->assertStringContainsString('عقد إيجار يومي', $template->body);
        $this->assertStringContainsString('المسبح', $template->terms);
    }

    public function test_a_chalet_booking_is_drawn_on_the_chalet_form(): void
    {
        $contract = $this->contractOf($this->stay());

        $this->assertSame(
            ChaletContractTemplate::NAME,
            ContractTemplate::find($contract->contract_template_id)?->name,
        );
        $this->assertStringContainsString('عقد إيجار يومي', $contract->body);
    }

    private function hallEvening(): Booking
    {
        $client = Client::create(['name' => 'عبدالله السالم', 'mobile' => '0551234567']);

        return app(BookingService::class)->create([
            'unit_id' => Unit::where('code', 'HALL-01')->firstOrFail()->id,
            'client_id' => $client->id,
            'scope' => 'whole',
            'period' => 'evening',
            'booking_date' => '2026-09-10',
        ], $this->owner->id);
    }

    /** A hall is drawn on its own rental pad, not on the chalet's daily form. */
    public function test_a_hall_booking_is_not_drawn_on_the_chalet_form(): void
    {
        $this->assertSame(
            HallRentalContractTemplate::NAME,
            ContractTemplate::find($this->contractOf($this->hallEvening())->contract_template_id)?->name,
        );
    }

    public function test_the_chalets_register_shows_their_own_contracts_alone(): void
    {
        // Each booking draws its contract as it is created — no second call.
        $this->stay();
        $this->hallEvening();

        // A sheet written on the chalet form with no booking is theirs too:
        // there is no booking behind it to place it anywhere else.
        $this->actingAs($this->owner)->post('/admin/contracts/direct', [
            'client_id' => Client::create(['name' => 'ضيف بلا حجز', 'mobile' => '0553334444', 'type' => 'chalet'])->id,
            'contract_template_id' => ContractTemplate::where('name', ChaletContractTemplate::NAME)->value('id'),
            'total_amount' => 1500,
        ])->assertRedirect();

        $this->actingAs($this->owner)->get('/admin/chalets/contracts')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('admin/contracts/Index')
                ->where('scope', 'chalet')
                ->has('contracts.data', 2)
                // Their own pad alone, and no quotation to draw from: a sheet
                // drawn either way would fall outside this register.
                ->has('templates', 1)
                ->where('templates.0.name', ChaletContractTemplate::NAME)
                ->has('quotations', 0));

        // The overseeing register still sees the hall's contract with them.
        $this->actingAs($this->owner)->get('/admin/contracts')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('scope', 'all')->has('contracts.data', 3));
    }

    public function test_the_snapshot_carries_the_fields_the_form_prints(): void
    {
        $data = $this->contractOf($this->stay())->data;

        $this->assertSame('2026-10-05', $data['booking_date']);
        $this->assertSame('2026-10-08', $data['last_day_date']);
        $this->assertSame('الاثنين', $data['check_in_day']);
        $this->assertSame('الخميس', $data['check_out_day']);
        $this->assertStringContainsString('هـ', $data['contract_date_hijri']);
        $this->assertStringContainsString('هـ', $data['booking_date_hijri']);
        $this->assertStringContainsString('فقط', $data['total_amount_words']);
        $this->assertNotEmpty($data['check_in_time']);
        $this->assertNotEmpty($data['check_out_time']);
    }

    /** A draft issued before the chalet form existed adopts it when refreshed. */
    public function test_refreshing_an_older_draft_moves_it_onto_the_chalet_form(): void
    {
        $contract = $this->contractOf($this->stay());

        $contract->update([
            'contract_template_id' => ContractTemplate::where('name', 'عقد حجز قياسي')->value('id'),
            'terms' => 'شروط قديمة',
            'data' => collect($contract->data)->except(['check_in_day', 'contract_date_hijri'])->all(),
        ]);

        $this->actingAs($this->owner)->post("/admin/contracts/{$contract->id}/refresh")->assertRedirect();

        $fresh = $contract->fresh();

        $this->assertSame(ChaletContractTemplate::NAME, $fresh->template->name);
        $this->assertStringContainsString('المسبح', $fresh->terms);
        $this->assertSame('الاثنين', $fresh->data['check_in_day']);
    }

    public function test_the_pdf_renders_on_the_daily_rental_layout(): void
    {
        $bytes = app(ContractPdf::class)->render($this->contractOf($this->stay()));

        $this->assertStringStartsWith('%PDF-', $bytes);
        $this->assertGreaterThan(5000, strlen($bytes));
    }

    public function test_the_contract_screen_shows_the_stay_fields(): void
    {
        $contract = $this->contractOf($this->stay());

        $this->actingAs($this->owner)->get("/admin/contracts/{$contract->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('admin/contracts/Show')
                ->where('contract.unit_type', 'chalet')
                ->has('contract.check_in_day')
                ->has('contract.check_out_time')
                ->has('contract.booking_date_hijri')
                ->has('contract.total_amount_words')
                ->etc());
    }

    /** A chalet let through a booking takes its money there, not on the contract. */
    public function test_a_booked_chalet_contract_carries_no_receipt_book(): void
    {
        $contract = $this->contractOf($this->stay());

        $this->assertTrue($contract->isChaletRentalForm());
        $this->assertFalse($contract->takesReceipts());

        $this->actingAs($this->owner)->post("/admin/contracts/{$contract->id}/receipt", ['amount' => 500])
            ->assertNotFound();

        $this->actingAs($this->owner)->get("/admin/contracts/{$contract->id}")
            ->assertInertia(fn ($page) => $page->where('contract.takes_receipts', false)->etc());
    }

    public function test_the_form_screen_renders_and_is_restorable(): void
    {
        $this->actingAs($this->owner)->get('/admin/units/chalet-contract-template')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('admin/units/ContractTemplate')
                ->where('template.name', ChaletContractTemplate::NAME)
                ->where('screen.endpoint', '/admin/units/chalet-contract-template')
                ->has('placeholders'));

        $this->actingAs($this->owner)->put('/admin/units/chalet-contract-template', [
            'body' => 'نص معدّل {{client_name}}',
            'terms' => 'شروط معدّلة',
            'is_default' => false,
            'is_active' => true,
        ])->assertRedirect();

        $this->assertSame('شروط معدّلة', ContractTemplate::where('name', ChaletContractTemplate::NAME)->value('terms'));

        $this->actingAs($this->owner)->post('/admin/units/chalet-contract-template/reset')->assertRedirect();

        $this->assertSame(
            ChaletContractTemplate::TERMS,
            ContractTemplate::where('name', ChaletContractTemplate::NAME)->value('terms'),
        );
    }
}
