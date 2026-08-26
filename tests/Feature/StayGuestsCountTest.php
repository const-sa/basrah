<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Client;
use App\Models\Role;
use App\Models\Unit;
use App\Models\User;
use Database\Seeders\AccountsSeeder;
use Database\Seeders\BookingSetupSeeder;
use Database\Seeders\ContractTemplateSeeder;
use Database\Seeders\DepartmentsSeeder;
use Database\Seeders\FacilitiesSeeder;
use Database\Seeders\RolesSeeder;
use Database\Seeders\UnitsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * «عدد الضيوف» يُكتب على الإقامة ويُطبع في عقدها — ولا يُطلب: الإقامة
 * تُحجز بالهاتف قبل أن يُعرف من سيأتي.
 */
class StayGuestsCountTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Unit $chalet;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([
            RolesSeeder::class, FacilitiesSeeder::class, DepartmentsSeeder::class,
            UnitsSeeder::class, BookingSetupSeeder::class, AccountsSeeder::class,
        ]);

        $this->owner = User::factory()->create([
            'role_id' => Role::where('slug', 'super-admin')->value('id'),
            'is_active' => true,
            'has_all_units' => true,
        ]);

        $this->chalet = $this->chaletLetWhole();
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function book(array $extra = []): Booking
    {
        $this->actingAs($this->owner)->post('/admin/bookings/chalets', [
            'unit_id' => $this->chalet->id,
            'client_id' => Client::first()?->id,
            'scope' => 'whole',
            'booking_date' => '2027-05-10',
            'check_out_date' => '2027-05-12',
            ...$extra,
        ])->assertRedirect();

        return Booking::latest('id')->firstOrFail();
    }

    public function test_the_count_is_written_on_the_stay_and_reaches_its_contract(): void
    {
        $booking = $this->book(['guests_count' => 6]);

        $this->assertSame(6, $booking->guests_count);

        $this->actingAs($this->owner)
            ->get("/admin/bookings/chalets/{$booking->id}/edit")
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('booking.guests_count', 6)->etc());

        // «عدد الضيوف:» كان يُطبع فارغًا لأن لا حقل يملؤه، لا لأن العقد
        // لا يقرؤه.
        $this->seed(ContractTemplateSeeder::class);

        $this->actingAs($this->owner)
            ->post('/admin/contracts', ['booking_id' => $booking->id])
            ->assertRedirect();

        $this->assertSame('6', $booking->contracts()->firstOrFail()->data['guests_count']);
    }

    public function test_a_stay_is_booked_without_it(): void
    {
        $booking = $this->book();

        $this->assertNull($booking->guests_count);

        // The empty field posts an empty string, which is a count nobody gave
        // rather than a validation failure.
        $again = $this->book(['booking_date' => '2027-06-10', 'check_out_date' => '2027-06-12', 'guests_count' => '']);

        $this->assertNull($again->guests_count);
    }

    public function test_clearing_the_count_on_an_edit_actually_clears_it(): void
    {
        $booking = $this->book(['guests_count' => 4]);

        $this->actingAs($this->owner)->put("/admin/bookings/chalets/{$booking->id}", [
            'unit_id' => $this->chalet->id,
            'client_id' => $booking->client_id,
            'scope' => 'whole',
            'booking_date' => '2027-05-10',
            'check_out_date' => '2027-05-12',
            'guests_count' => null,
        ])->assertRedirect();

        $this->assertNull($booking->fresh()->guests_count);
    }

    public function test_a_count_below_one_is_refused(): void
    {
        $this->actingAs($this->owner)->post('/admin/bookings/chalets', [
            'unit_id' => $this->chalet->id,
            'scope' => 'whole',
            'booking_date' => '2027-07-10',
            'check_out_date' => '2027-07-12',
            'guests_count' => 0,
        ])->assertSessionHasErrors('guests_count');
    }
}
