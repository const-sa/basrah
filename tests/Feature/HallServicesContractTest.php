<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Client;
use App\Models\Contract;
use App\Models\ContractTemplate;
use App\Models\CostCenter;
use App\Models\Role;
use App\Models\Unit;
use App\Models\User;
use App\Services\BookingService;
use App\Services\ContractPdf;
use App\Services\ContractService;
use App\Support\HallRentalContractTemplate;
use App\Support\HallServicesContractTemplate;
use Database\Seeders\AccountsSeeder;
use Database\Seeders\BookingSetupSeeder;
use Database\Seeders\ContractTemplateSeeder;
use Database\Seeders\RolesSeeder;
use Database\Seeders\UnitsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/** The halls' services list — the second paper, written beside the rental pad. */
class HallServicesContractTest extends TestCase
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

    public function test_the_seeder_pins_the_services_list(): void
    {
        $template = $this->template();

        $this->assertTrue($template->is_active);
        // Picked by name beside the rental pad, never promoted to the default.
        $this->assertFalse((bool) $template->is_default);
        $this->assertStringContainsString('{{booking_reference}}', $template->body);
        $this->assertStringContainsString('عشاء الرجال', $template->terms);
    }

    public function test_the_sheet_is_ruled_with_the_printed_services(): void
    {
        $contract = $this->servicesContract();

        $this->assertTrue($contract->isHallServicesForm());
        $this->assertCount(count(HallServicesContractTemplate::SERVICES), $contract->lines());
        $this->assertSame('قهوجي + عدد (صباب)', $contract->lines()[0]['name']);

        // The rental was contracted on the rental pad: this sheet is priced on
        // itself, so its totals start empty rather than repeating that value.
        $this->assertSame('—', $contract->data['total_amount']);
        $this->assertSame('—', $contract->data['deposit_amount']);

        $this->actingAs($this->owner)->get("/admin/contracts/{$contract->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('admin/contracts/Show')
                ->where('contract.is_hall_services_form', true)
                ->where('contract.is_hall_form', false));

        $this->assertStringStartsWith('%PDF-', app(ContractPdf::class)->render($contract));
    }

    public function test_a_hall_booking_takes_both_papers_but_not_twice(): void
    {
        $booking = $this->booking();

        // The booking already carries its rental pad, and the register still
        // offers it — the services list is the paper it is offered for.
        $this->actingAs($this->owner)->get('/admin/contracts')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where(
                'bookings',
                fn ($bookings) => collect($bookings)->contains(fn ($b) => $b['id'] === $booking->id),
            ));

        $this->actingAs($this->owner)->post('/admin/contracts', [
            'booking_id' => $booking->id,
            'contract_template_id' => $this->template()->id,
        ]);

        $this->assertCount(2, $booking->contracts()->get());

        // A second copy of either paper is two live agreements over one event.
        $this->actingAs($this->owner)->post('/admin/contracts', [
            'booking_id' => $booking->id,
            'contract_template_id' => $this->template()->id,
        ])->assertSessionHas('warning');

        $this->actingAs($this->owner)->post('/admin/contracts', [
            'booking_id' => $booking->id,
            'contract_template_id' => ContractTemplate::where('name', HallRentalContractTemplate::NAME)->value('id'),
        ])->assertSessionHas('warning');

        $this->assertCount(2, $booking->contracts()->get());
    }

    public function test_the_grid_is_priced_on_the_sheet_it_prints_on(): void
    {
        $contract = $this->servicesContract();

        $this->actingAs($this->owner)->get("/admin/contracts/{$contract->id}/edit")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('admin/contracts/Edit')
                ->where('contract.is_hall_services_form', true));

        $this->actingAs($this->owner)->post("/admin/contracts/{$contract->id}", [
            'fields' => ['total_amount' => '3200', 'deposit_amount' => '1200', 'remaining_amount' => '2000'],
            'items' => [
                ['name' => 'قهوجي + عدد (صباب)', 'quantity' => '2', 'unit_price' => '400', 'total_price' => '800', 'notes' => 'من بعد العشاء'],
                ['name' => 'كوشة', 'quantity' => '1', 'unit_price' => '2,400.00', 'total_price' => '2,400.00'],
                // A blank row of the pad is not a line the client signed for.
                ['name' => '', 'quantity' => '', 'unit_price' => '', 'total_price' => ''],
            ],
            'body' => $contract->body,
            'terms' => $contract->terms,
        ])->assertRedirect("/admin/contracts/{$contract->id}");

        $contract->refresh();
        $lines = $contract->lines();

        $this->assertCount(2, $lines);
        $this->assertSame('من بعد العشاء', $lines[0]['notes']);
        $this->assertSame('800.00', $lines[0]['total_price']);
        $this->assertSame('3,200.00', $contract->data['total_amount']);
    }

    /** The sheet does its own arithmetic — the employee prices, it multiplies. */
    public function test_the_sheet_totals_itself_from_the_prices_and_counts(): void
    {
        $contract = $this->servicesContract();

        $this->actingAs($this->owner)->post("/admin/contracts/{$contract->id}", [
            // A typed total is not what the sheet is worth: the lines are.
            'fields' => ['total_amount' => '10'],
            'items' => [
                ['name' => 'بوفيه مفتوح (للشخص)', 'quantity' => '120', 'unit_price' => '30', 'total_price' => ''],
                ['name' => 'كوشة', 'quantity' => '1', 'unit_price' => '2,500.00', 'total_price' => ''],
                // Priced in words, so it is left exactly as it was written.
                ['name' => 'ستاير', 'quantity' => '', 'unit_price' => 'حسب الاتفاق', 'total_price' => ''],
            ],
            'body' => $contract->body,
            'terms' => $contract->terms,
        ])->assertRedirect("/admin/contracts/{$contract->id}");

        $contract->refresh();

        $this->assertSame('3,600.00', $contract->lines()[0]['total_price']);
        $this->assertSame('2,500.00', $contract->lines()[1]['total_price']);
        $this->assertSame('', $contract->lines()[2]['total_price']);
        $this->assertSame('6,100.00', $contract->data['total_amount']);
        // And what is left of it, against the receipts written on the contract.
        $this->assertSame('0.00', $contract->data['deposit_amount']);
        $this->assertSame('6,100.00', $contract->data['remaining_amount']);
    }

    /** What is paid for the services is a receipt of its own, and it posts. */
    public function test_the_paid_amount_is_taken_as_a_receipt_and_posted(): void
    {
        $contract = $this->pricedContract();

        $this->assertTrue($contract->takesReceipts());

        $this->actingAs($this->owner)->post("/admin/contracts/{$contract->id}/receipt", [
            'amount' => 1000,
            'voucher_date' => '2026-10-02',
        ])->assertSessionHas('success');

        $voucher = $contract->vouchers()->firstOrFail();

        $this->assertSame('posted', $voucher->status);
        $this->assertSame(1000.0, (float) $voucher->amount);
        // It earns where the hall does, as the booking's own payments do.
        $this->assertSame(
            CostCenter::forUnit($contract->booking->unit)->id,
            $voucher->cost_center_id,
        );

        $contract->refresh();

        $this->assertSame(1000.0, $contract->paidAmount());
        $this->assertSame(2600.0, $contract->remainingAmount());

        // المدفوع والباقي على الورقة هما ما تقوله السندات.
        $this->actingAs($this->owner)->get("/admin/contracts/{$contract->id}")
            ->assertInertia(fn ($page) => $page
                ->where('contract.deposit_amount', '1,000.00')
                ->where('contract.remaining_amount', '2,600.00')
                ->where('contract.takes_receipts', true)
                ->etc());
    }

    /** The halls' own register — both papers of an event, and nothing else. */
    public function test_the_halls_register_holds_both_papers(): void
    {
        $services = $this->servicesContract();
        $rental = $services->booking->contracts()->where('id', '!=', $services->id)->firstOrFail();

        $this->actingAs($this->owner)->get('/admin/halls/contracts')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('admin/contracts/Index')
                ->where('scope', 'hall')
                ->where('contracts.data', fn ($rows) => collect($rows)->pluck('id')->sort()->values()->all()
                    === collect([$services->id, $rental->id])->sort()->values()->all())
                // It offers the halls' own pads, so a sheet drawn here stays here.
                ->where('templates', fn ($templates) => collect($templates)->pluck('name')->sort()->values()->all()
                    === collect([HallRentalContractTemplate::NAME, HallServicesContractTemplate::NAME])->sort()->values()->all())
                ->etc());
    }

    public function test_refreshing_the_wording_keeps_what_the_sheet_was_priced_at(): void
    {
        $contract = $this->servicesContract();

        $this->actingAs($this->owner)->post("/admin/contracts/{$contract->id}", [
            'items' => [['name' => 'كوشة', 'quantity' => '1', 'unit_price' => '2400', 'total_price' => '']],
            'body' => $contract->body,
            'terms' => $contract->terms,
        ]);

        $this->actingAs($this->owner)->post("/admin/contracts/{$contract->id}/refresh")
            ->assertSessionHas('success');

        $contract->refresh();

        // Rebuilt from the services template, not from the booking's rental pad.
        $this->assertTrue($contract->isHallServicesForm());
        $this->assertCount(1, $contract->lines());
        $this->assertSame('2,400.00', $contract->data['total_amount']);
    }

    private function template(): ContractTemplate
    {
        return ContractTemplate::where('name', HallServicesContractTemplate::NAME)->firstOrFail();
    }

    private function booking(): Booking
    {
        $booking = app(BookingService::class)->create([
            'unit_id' => Unit::where('type', 'hall')->firstOrFail()->id,
            'client_id' => Client::create(['name' => 'أم عبدالله', 'mobile' => '0551122334'])->id,
            'scope' => 'whole',
            'booking_date' => '2026-10-05',
            'period' => 'evening',
            'status' => 'deposit_paid',
            'guests_count' => 120,
        ]);

        return $booking->fresh();
    }

    private function servicesContract(): Contract
    {
        return app(ContractService::class)->generate($this->booking(), $this->template());
    }

    /** A services sheet with its lines priced — 3,600 of services. */
    private function pricedContract(): Contract
    {
        $contract = $this->servicesContract();

        return app(ContractService::class)->applyEdit($contract, [
            'items' => [['name' => 'كوشة', 'quantity' => '1', 'unit_price' => '3600', 'total_price' => '']],
            'body' => $contract->body,
        ]);
    }
}
