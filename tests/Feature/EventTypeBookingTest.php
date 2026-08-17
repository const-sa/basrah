<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\EventType;
use App\Models\Package;
use App\Models\Role;
use App\Models\Unit;
use App\Models\User;
use Database\Seeders\AccountsSeeder;
use Database\Seeders\BookingSetupSeeder;
use Database\Seeders\RolesSeeder;
use Database\Seeders\UnitsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * نوع المناسبة والباقة في حجز القاعة، والسداد لحظة الإنشاء.
 *
 * النوع يتبع قاعته ويحمل سعرها: اختياره يملأ السعر الأساسي بدل تسعيرة اليوم.
 */
class EventTypeBookingTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Unit $hall;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        $this->seed([RolesSeeder::class, UnitsSeeder::class, BookingSetupSeeder::class, AccountsSeeder::class]);

        $this->owner = User::factory()->create([
            'role_id' => Role::where('slug', 'super-admin')->value('id'),
            'is_active' => true,
            'has_all_units' => true,
        ]);

        $this->hall = Unit::where('type', 'hall')->firstOrFail();
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return [
            'unit_id' => $this->hall->id,
            'scope' => 'whole',
            'booking_date' => '2026-10-15',
            'period' => 'evening',
            'status' => 'confirmed',
            ...$overrides,
        ];
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function typeFor(Unit $unit, array $attributes): EventType
    {
        return EventType::create(['unit_id' => $unit->id, ...$attributes]);
    }

    // ── إدارة الأنواع ────────────────────────────────────────

    public function test_an_event_type_is_created_under_its_hall_with_a_price(): void
    {
        $this->actingAs($this->owner)->post('/admin/event-types', [
            'unit_id' => $this->hall->id,
            'name' => 'زواج',
            'color' => 'rose',
            'price' => 15000,
            'is_active' => true,
        ])->assertSessionHasNoErrors();

        $type = EventType::firstWhere('name', 'زواج');

        $this->assertNotNull($type);
        $this->assertSame($this->hall->id, $type->unit_id);
        $this->assertSame(15000.0, (float) $type->price);

        $this->actingAs($this->owner)->get('/admin/event-types')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('admin/bookings/EventTypes')
                ->has('types', 1)
                ->where('types.0.name', 'زواج')
                ->where('types.0.unit_id', $this->hall->id)
                ->where('stats.priced', 1));
    }

    /**
     * الاسم يتكرر بين القاعات ولا يتكرر داخل القاعة: «زواج» في كل قاعة
     * سجلٌّ مستقل بسعره، و«زواج» مرتين في قاعة واحدة خطأ إدخال.
     */
    public function test_a_name_repeats_across_halls_but_not_within_one(): void
    {
        $otherHall = Unit::where('type', 'hall')->where('id', '!=', $this->hall->id)->firstOrFail();

        $this->typeFor($this->hall, ['name' => 'ملكة', 'color' => 'violet', 'price' => 9000]);

        $this->actingAs($this->owner)
            ->post('/admin/event-types', ['unit_id' => $this->hall->id, 'name' => 'ملكة', 'color' => 'sky'])
            ->assertSessionHasErrors('name');

        $this->actingAs($this->owner)
            ->post('/admin/event-types', ['unit_id' => $otherHall->id, 'name' => 'ملكة', 'color' => 'sky', 'price' => 7000])
            ->assertSessionHasNoErrors();

        $this->assertSame(2, EventType::where('name', 'ملكة')->count());
    }

    public function test_a_chalet_cannot_carry_event_types(): void
    {
        $chalet = Unit::where('type', 'chalet')->firstOrFail();

        $this->actingAs($this->owner)
            ->post('/admin/event-types', ['unit_id' => $chalet->id, 'name' => 'زواج', 'color' => 'rose'])
            ->assertStatus(422);
    }

    public function test_a_used_event_type_is_disabled_not_deleted(): void
    {
        $type = $this->typeFor($this->hall, ['name' => 'تخرّج', 'color' => 'sky']);

        $this->actingAs($this->owner)
            ->post('/admin/bookings/halls', $this->payload(['event_type_id' => $type->id]))
            ->assertSessionHasNoErrors();

        $this->actingAs($this->owner)->delete("/admin/event-types/{$type->id}");

        $this->assertModelExists($type);

        $this->actingAs($this->owner)->patch("/admin/event-types/{$type->id}/toggle");

        $this->assertFalse($type->fresh()->is_active);
    }

    // ── أثر النوع على التسعيرة ───────────────────────────────

    /**
     * سعر النوع يحل محل تسعيرة القاعة لا يُضاف إليها.
     */
    public function test_the_event_type_price_replaces_the_hall_rate(): void
    {
        $type = $this->typeFor($this->hall, ['name' => 'زواج', 'color' => 'rose', 'price' => 15000]);

        $plain = $this->actingAs($this->owner)
            ->postJson('/admin/bookings/halls/quote', $this->payload())
            ->json('pricing');

        $withType = $this->actingAs($this->owner)
            ->postJson('/admin/bookings/halls/quote', $this->payload(['event_type_id' => $type->id]))
            ->json('pricing');

        // السعر جاء من النوع، لا من تسعيرة اليوم، ولا جُمع الاثنان.
        $this->assertTrue($withType['priced_by_event']);
        $this->assertSame(15000.0, (float) $withType['base_amount']);
        $this->assertSame(15000.0, (float) $withType['total_amount']);
        $this->assertSame(0.0, (float) $withType['event_fee_amount']);

        $this->assertFalse($plain['priced_by_event']);
        $this->assertNotSame((float) $plain['base_amount'], (float) $withType['base_amount']);
    }

    /**
     * النوع بلا سعر تصنيفٌ فقط: الحجز يبقى على تسعيرة القاعة.
     */
    public function test_a_type_without_a_price_leaves_the_hall_rate_alone(): void
    {
        $type = $this->typeFor($this->hall, ['name' => 'اجتماع', 'color' => 'slate', 'price' => 0]);

        $plain = $this->actingAs($this->owner)
            ->postJson('/admin/bookings/halls/quote', $this->payload())
            ->json('pricing');

        $withType = $this->actingAs($this->owner)
            ->postJson('/admin/bookings/halls/quote', $this->payload(['event_type_id' => $type->id]))
            ->json('pricing');

        $this->assertFalse($withType['priced_by_event']);
        $this->assertSame((float) $plain['total_amount'], (float) $withType['total_amount']);
    }

    /**
     * سعر النوع ثمن القاعة كاملة، فحجز قسم منها يبقى على تسعيرة الأقسام.
     */
    public function test_the_type_price_does_not_apply_to_a_section_booking(): void
    {
        $type = $this->typeFor($this->hall, ['name' => 'زواج', 'color' => 'rose', 'price' => 15000]);
        $section = $this->hall->sections()->firstOrFail();

        $quote = $this->actingAs($this->owner)
            ->postJson('/admin/bookings/halls/quote', $this->payload([
                'scope' => 'sections',
                'section_ids' => [$section->id],
                'event_type_id' => $type->id,
            ]))
            ->json('pricing');

        $this->assertFalse($quote['priced_by_event']);
        $this->assertNotSame(15000.0, (float) $quote['base_amount']);
    }

    /**
     * نوع قاعة أخرى يُرفض عند الحفظ: سعره ثمن تلك القاعة لا هذه.
     */
    public function test_an_event_type_of_another_hall_is_rejected(): void
    {
        $otherHall = Unit::where('type', 'hall')->where('id', '!=', $this->hall->id)->firstOrFail();
        $type = $this->typeFor($otherHall, ['name' => 'زواج', 'color' => 'rose', 'price' => 12000]);

        $this->actingAs($this->owner)
            ->post('/admin/bookings/halls', $this->payload(['event_type_id' => $type->id]))
            ->assertSessionHasErrors('event_type_id');

        $this->assertSame(0, Booking::count());
    }

    public function test_the_package_price_lands_in_the_total(): void
    {
        $package = Package::create(['name' => 'الباقة الفضية', 'unit_id' => $this->hall->id, 'price' => 12000]);

        $plain = $this->actingAs($this->owner)
            ->postJson('/admin/bookings/halls/quote', $this->payload())
            ->json('pricing');

        $withPackage = $this->actingAs($this->owner)
            ->postJson('/admin/bookings/halls/quote', $this->payload(['package_id' => $package->id]))
            ->json('pricing');

        $this->assertSame(12000.0, (float) $withPackage['package_amount']);
        $this->assertSame(
            round($plain['total_amount'] + 12000, 2),
            round($withPackage['total_amount'], 2),
        );
    }

    /**
     * الباقة تُضاف فوق سعر النوع: النوع ثمن القاعة، والباقة ضيافةٌ تُباع معها.
     */
    public function test_a_package_adds_on_top_of_the_event_type_price(): void
    {
        $type = $this->typeFor($this->hall, ['name' => 'زواج', 'color' => 'rose', 'price' => 15000]);
        $package = Package::create(['name' => 'الباقة الفضية', 'unit_id' => $this->hall->id, 'price' => 12000]);

        $quote = $this->actingAs($this->owner)
            ->postJson('/admin/bookings/halls/quote', $this->payload([
                'event_type_id' => $type->id,
                'package_id' => $package->id,
            ]))
            ->json('pricing');

        $this->assertSame(15000.0, (float) $quote['base_amount']);
        $this->assertSame(12000.0, (float) $quote['package_amount']);
        $this->assertSame(27000.0, (float) $quote['total_amount']);
    }

    public function test_a_package_of_another_hall_is_dropped_instead_of_priced(): void
    {
        $otherHall = Unit::where('type', 'hall')->where('id', '!=', $this->hall->id)->firstOrFail();
        $package = Package::create(['name' => 'باقة قاعة أخرى', 'unit_id' => $otherHall->id, 'price' => 9000]);

        $this->actingAs($this->owner)
            ->post('/admin/bookings/halls', $this->payload(['package_id' => $package->id]))
            ->assertSessionHasNoErrors();

        $booking = Booking::latest('id')->firstOrFail();

        $this->assertNull($booking->package_id);
        $this->assertSame(0.0, (float) $booking->package_amount);
    }

    public function test_the_booking_keeps_its_event_type_and_package(): void
    {
        $type = $this->typeFor($this->hall, ['name' => 'عقيقة', 'color' => 'emerald', 'price' => 4000]);
        $package = Package::create(['name' => 'باقة عامة', 'price' => 5000]);

        $this->actingAs($this->owner)->post('/admin/bookings/halls', $this->payload([
            'event_type_id' => $type->id,
            'package_id' => $package->id,
        ]))->assertSessionHasNoErrors();

        $booking = Booking::latest('id')->firstOrFail();

        $this->assertSame($type->id, $booking->event_type_id);
        $this->assertSame($package->id, $booking->package_id);
        $this->assertSame(4000.0, (float) $booking->base_amount);
        $this->assertSame(5000.0, (float) $booking->package_amount);
    }

    // ── السداد عند الإنشاء ───────────────────────────────────

    public function test_a_booking_saved_without_a_payment_stays_unpaid(): void
    {
        $this->actingAs($this->owner)
            ->post('/admin/bookings/halls', $this->payload(['payment_amount' => 0]))
            ->assertSessionHasNoErrors();

        $booking = Booking::latest('id')->firstOrFail();

        $this->assertSame(0.0, (float) $booking->paid_amount);
        $this->assertCount(0, $booking->payments);
        $this->assertFalse($booking->isFullyPaid());
    }

    public function test_paying_in_full_at_creation_settles_the_booking(): void
    {
        $quote = $this->actingAs($this->owner)
            ->postJson('/admin/bookings/halls/quote', $this->payload())
            ->json('pricing');

        $this->actingAs($this->owner)->post('/admin/bookings/halls', $this->payload([
            'payment_amount' => $quote['total_amount'],
            'payment_type' => 'payment',
            'payment_method_id' => $this->paymentMethodId('transfer'),
        ]))->assertSessionHasNoErrors();

        $booking = Booking::latest('id')->firstOrFail();

        $this->assertTrue($booking->isFullyPaid());
        $this->assertCount(1, $booking->payments);
        $this->assertSame('transfer', $booking->payments->first()->paymentMethod->code);
    }

    public function test_a_deposit_at_creation_leaves_the_rest_owed(): void
    {
        $quote = $this->actingAs($this->owner)
            ->postJson('/admin/bookings/halls/quote', $this->payload())
            ->json('pricing');

        $this->actingAs($this->owner)->post('/admin/bookings/halls', $this->payload([
            'payment_amount' => $quote['deposit_amount'],
            'payment_type' => 'deposit',
        ]))->assertSessionHasNoErrors();

        $booking = Booking::latest('id')->firstOrFail();

        $this->assertTrue($booking->isDepositSettled());
        $this->assertFalse($booking->isFullyPaid());
        $this->assertSame(
            round($quote['total_amount'] - $quote['deposit_amount'], 2),
            $booking->remainingAmount(),
        );
    }

    public function test_a_payment_above_the_total_saves_neither_booking_nor_payment(): void
    {
        $this->actingAs($this->owner)
            ->post('/admin/bookings/halls', $this->payload(['payment_amount' => 999999]))
            ->assertSessionHasErrors('payment_amount');

        $this->assertSame(0, Booking::count());
    }
}
