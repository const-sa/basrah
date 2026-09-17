<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Client;
use App\Models\Contract;
use App\Models\Package;
use App\Models\Unit;
use App\Services\BookingService;
use App\Services\ContractService;
use Database\Seeders\AccountsSeeder;
use Database\Seeders\BookingSetupSeeder;
use Database\Seeders\ContractTemplateSeeder;
use Database\Seeders\PackagesSeeder;
use Database\Seeders\RolesSeeder;
use Database\Seeders\UnitsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Handover cleanup: the trial movement goes, the units and their setup stay.
 */
class PurgeTrialDataTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        $this->seed([
            RolesSeeder::class, UnitsSeeder::class, BookingSetupSeeder::class,
            PackagesSeeder::class, AccountsSeeder::class, ContractTemplateSeeder::class,
        ]);
    }

    public function test_it_clears_the_trial_movement_and_keeps_the_units(): void
    {
        $units = Unit::count();
        $packages = Package::count();
        $this->makeBooking();

        $this->artisan('data:purge-trial --force')->assertSuccessful();

        $this->assertSame(0, Booking::count());
        $this->assertSame(0, Client::count());
        $this->assertSame($units, Unit::count());
        $this->assertSame($packages, Package::count());
    }

    public function test_a_dry_run_deletes_nothing(): void
    {
        $this->makeBooking();

        $this->artisan('data:purge-trial --dry-run')->assertSuccessful();

        $this->assertSame(1, Booking::count());
    }

    public function test_keeping_the_contracts_keeps_the_bookings_and_clients_under_them(): void
    {
        app(ContractService::class)->generate($this->makeBooking());

        $this->artisan('data:purge-trial --keep=contracts --force')->assertSuccessful();

        $this->assertSame(1, Contract::count());
        $this->assertSame(1, Booking::count());
        $this->assertSame(1, Client::count());
    }

    public function test_it_refuses_a_table_it_does_not_know(): void
    {
        $this->artisan('data:purge-trial --keep=ghosts --force')->assertFailed();
    }

    /** Every table must be classified, or the command stops before touching anything. */
    public function test_every_table_in_the_schema_is_classified(): void
    {
        $this->artisan('data:purge-trial --dry-run')
            ->doesntExpectOutputToContain('Unclassified tables:')
            ->assertSuccessful();
    }

    private function makeBooking(): Booking
    {
        $client = Client::create(['name' => 'خالد المطيري', 'mobile' => '0551234567']);

        return app(BookingService::class)->create([
            'unit_id' => $this->chaletLetWhole()->id,
            'client_id' => $client->id,
            'scope' => 'whole',
            'booking_date' => '2026-09-10',
            'period' => 'full_day',
            'status' => 'deposit_paid',
            'guests_count' => 30,
        ]);
    }
}
