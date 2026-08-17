<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Role;
use App\Models\Unit;
use App\Models\User;
use App\Services\BookingService;
use Database\Seeders\BookingSetupSeeder;
use Database\Seeders\RolesSeeder;
use Database\Seeders\UnitsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * حراسة نظام الحجوزات: الوصول للنظام، صلاحية الإجراء، ونطاق الوحدة.
 */
class BookingRoutesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([RolesSeeder::class, UnitsSeeder::class, BookingSetupSeeder::class]);
    }

    private function userWithRole(string $slug, bool $allUnits = true): User
    {
        return User::factory()->create([
            'role_id' => Role::where('slug', $slug)->firstOrFail()->id,
            'is_active' => true,
            'has_all_units' => $allUnits,
        ]);
    }

    public function test_cashier_cannot_reach_the_bookings_system_at_all(): void
    {
        $this->actingAs($this->userWithRole('cashier'))
            ->get('/admin/bookings/halls')
            ->assertForbidden();

        $this->actingAs($this->userWithRole('cashier'))
            ->get('/admin/calendar/halls')
            ->assertForbidden();
    }

    public function test_unit_supervisor_can_open_bookings_and_calendar(): void
    {
        $supervisor = $this->userWithRole('unit-supervisor');

        $this->actingAs($supervisor)->get('/admin/bookings/halls')->assertOk();
        $this->actingAs($supervisor)->get('/admin/calendar/halls')->assertOk();
    }

    public function test_unit_supervisor_cannot_delete_a_booking(): void
    {
        // مشرف الوحدة يملك bookings.create و bookings.edit دون bookings.delete
        $supervisor = $this->userWithRole('unit-supervisor');
        $unit = Unit::firstOrFail();

        $booking = app(BookingService::class)->create([
            'unit_id' => $unit->id,
            'scope' => 'whole',
            'booking_date' => '2026-10-05',
            'period' => 'full_day',
            'status' => 'confirmed',
        ]);

        $this->actingAs($supervisor)
            ->delete("/admin/bookings/{$booking->id}")
            ->assertForbidden();
    }

    public function test_supervisor_scoped_to_one_unit_cannot_book_another(): void
    {
        $supervisor = $this->userWithRole('unit-supervisor', allUnits: false);
        $mine = Unit::firstOrFail();
        $other = Unit::where('id', '!=', $mine->id)->firstOrFail();

        $supervisor->units()->sync([$mine->id]);

        $this->actingAs($supervisor)->post('/admin/bookings/halls', [
            'unit_id' => $other->id,
            'scope' => 'whole',
            'booking_date' => '2026-10-06',
            'period' => 'full_day',
        ])->assertForbidden();
    }

    public function test_supervisor_can_book_their_own_unit(): void
    {
        $supervisor = $this->userWithRole('unit-supervisor', allUnits: false);
        $mine = Unit::firstOrFail();
        $supervisor->units()->sync([$mine->id]);

        $this->actingAs($supervisor)->post('/admin/bookings/halls', [
            'unit_id' => $mine->id,
            'scope' => 'whole',
            'booking_date' => '2026-10-07',
            'period' => 'full_day',
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('bookings', ['unit_id' => $mine->id, 'booking_date' => '2026-10-07 00:00:00']);
    }

    public function test_bookings_list_hides_units_outside_the_supervisor_scope(): void
    {
        $supervisor = $this->userWithRole('unit-supervisor', allUnits: false);
        $mine = Unit::firstOrFail();
        $other = Unit::where('id', '!=', $mine->id)->firstOrFail();
        $supervisor->units()->sync([$mine->id]);

        $service = app(BookingService::class);
        foreach ([$mine, $other] as $unit) {
            $service->create([
                'unit_id' => $unit->id,
                'scope' => 'whole',
                'booking_date' => '2026-10-08',
                'period' => 'full_day',
                'status' => 'confirmed',
            ]);
        }

        $this->actingAs($supervisor)
            ->get('/admin/bookings/halls')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('bookings.data', 1));
    }

    public function test_quote_endpoint_reports_a_conflict_without_saving(): void
    {
        $owner = $this->userWithRole('super-admin');
        $unit = Unit::firstOrFail();

        app(BookingService::class)->create([
            'unit_id' => $unit->id,
            'scope' => 'whole',
            'booking_date' => '2026-10-09',
            'period' => 'full_day',
            'status' => 'confirmed',
        ]);

        $response = $this->actingAs($owner)->postJson('/admin/bookings/halls/quote', [
            'unit_id' => $unit->id,
            'scope' => 'whole',
            'booking_date' => '2026-10-09',
            'period' => 'full_day',
        ])->assertOk();

        $this->assertFalse($response->json('availability.ok'));
        $this->assertNotNull($response->json('availability.reason'));
        $this->assertGreaterThan(0, $response->json('pricing.total_amount'));
        $this->assertSame(1, Booking::count());
    }

    public function test_booking_with_payment_cannot_be_deleted(): void
    {
        $owner = $this->userWithRole('super-admin');
        $unit = Unit::firstOrFail();

        $booking = app(BookingService::class)->create([
            'unit_id' => $unit->id,
            'scope' => 'whole',
            'booking_date' => '2026-10-10',
            'period' => 'full_day',
            'status' => 'confirmed',
        ]);

        $booking->payments()->create([
            'type' => 'deposit',
            'payment_method_id' => $this->paymentMethodId('cash'),
            'amount' => 500,
            'paid_on' => '2026-10-01',
        ]);
        $booking->recalculatePaidAmount();

        $this->actingAs($owner)
            ->delete("/admin/bookings/{$booking->id}")
            ->assertSessionHas('warning');

        $this->assertNotNull($booking->fresh());
        $this->assertSame(500.0, (float) $booking->fresh()->paid_amount);
    }

    public function test_unit_with_bookings_cannot_be_deleted(): void
    {
        $owner = $this->userWithRole('super-admin');
        $unit = Unit::firstOrFail();

        app(BookingService::class)->create([
            'unit_id' => $unit->id,
            'scope' => 'whole',
            'booking_date' => '2026-10-11',
            'period' => 'full_day',
            'status' => 'confirmed',
        ]);

        $this->actingAs($owner)
            ->delete("/admin/units/{$unit->id}")
            ->assertSessionHas('warning');

        $this->assertNotNull($unit->fresh());
    }
}
