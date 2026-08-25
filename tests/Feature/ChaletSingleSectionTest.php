<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Client;
use App\Models\Role;
use App\Models\Unit;
use App\Models\User;
use Database\Seeders\AccountsSeeder;
use Database\Seeders\BookingSetupSeeder;
use Database\Seeders\DepartmentsSeeder;
use Database\Seeders\FacilitiesSeeder;
use Database\Seeders\RolesSeeder;
use Database\Seeders\UnitsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * One section per chalet booking.
 *
 * A chalet's «قسم» is a room inside it, not a wing of it: a booking takes one
 * room, and a guest who wants two is sold the chalet whole. The rule is
 * checked on the quote, the diary and the save alike — a shape one of them
 * accepted and another refused would strand the clerk mid-form.
 *
 * A hall is left alone: it genuinely goes out in several sections at once.
 */
class ChaletSingleSectionTest extends TestCase
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

        $this->chalet = Unit::where('code', 'CH-BSR1')->with('sections')->firstOrFail();
    }

    /** @return list<int> */
    private function sectionIds(): array
    {
        return $this->chalet->sections->pluck('id')->all();
    }

    /** @return array<string, mixed> */
    private function stayPayload(array $sectionIds): array
    {
        return [
            'unit_id' => $this->chalet->id,
            'client_id' => Client::first()?->id,
            'scope' => 'sections',
            'section_ids' => $sectionIds,
            'booking_date' => now()->addWeek()->toDateString(),
            'check_out_date' => now()->addWeek()->addDays(2)->toDateString(),
        ];
    }

    public function test_a_stay_takes_one_section(): void
    {
        $this->actingAs($this->owner)
            ->post('/admin/bookings/chalets', $this->stayPayload([$this->sectionIds()[0]]))
            ->assertSessionHasNoErrors();

        $this->assertSame(1, Booking::first()->sections()->count());
    }

    public function test_a_stay_is_refused_two_sections(): void
    {
        $this->actingAs($this->owner)
            ->post('/admin/bookings/chalets', $this->stayPayload($this->sectionIds()))
            ->assertSessionHasErrors('section_ids');

        $this->assertSame(0, Booking::count());
    }

    public function test_the_quote_refuses_what_the_save_would_refuse(): void
    {
        $this->actingAs($this->owner)
            ->postJson('/admin/bookings/chalets/quote', $this->stayPayload($this->sectionIds()))
            ->assertStatus(422)
            ->assertJsonValidationErrors('section_ids');
    }

    public function test_the_diary_refuses_two_sections_as_well(): void
    {
        $this->actingAs($this->owner)
            ->postJson('/admin/bookings/chalets/availability', [
                'unit_id' => $this->chalet->id,
                'scope' => 'sections',
                'section_ids' => $this->sectionIds(),
                'from' => now()->toDateString(),
                'to' => now()->addDays(7)->toDateString(),
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('section_ids');
    }

    /**
     * The scope is not a choice on a chalet — it follows from its rooms.
     */
    public function test_a_chalet_with_rooms_cannot_be_booked_whole(): void
    {
        $this->actingAs($this->owner)
            ->post('/admin/bookings/chalets', [
                ...$this->stayPayload([]),
                'scope' => 'whole',
            ])
            ->assertSessionHasErrors('availability');

        $this->assertSame(0, Booking::count());
    }

    public function test_a_chalet_without_rooms_is_booked_whole(): void
    {
        $this->chalet = $this->chaletLetWhole();

        $this->actingAs($this->owner)
            ->post('/admin/bookings/chalets', [
                ...$this->stayPayload([]),
                'scope' => 'whole',
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame('whole', Booking::firstOrFail()->scope);
    }

    public function test_the_form_says_which_scope_each_chalet_carries(): void
    {
        $roomless = $this->chaletLetWhole('CH-LULU');

        $this->actingAs($this->owner)
            ->get('/admin/bookings/chalets/create')
            ->assertOk()
            ->assertInertia(function ($page) use ($roomless) {
                $units = collect($page->toArray()['props']['units']);

                $withRooms = $units->firstWhere('id', $this->chalet->id);
                $this->assertFalse($withRooms['allows_whole']);
                $this->assertTrue($withRooms['allows_sections']);

                $without = $units->firstWhere('id', $roomless->id);
                $this->assertTrue($without['allows_whole']);
                $this->assertFalse($without['allows_sections']);
            });
    }

    public function test_a_hall_still_takes_several_sections(): void
    {
        $hall = Unit::where('type', 'hall')->with('sections')->firstOrFail();

        $this->actingAs($this->owner)
            ->post('/admin/bookings/halls', [
                'unit_id' => $hall->id,
                'client_id' => Client::first()?->id,
                'scope' => 'sections',
                'section_ids' => $hall->sections->pluck('id')->all(),
                'booking_date' => now()->addWeek()->toDateString(),
                'period' => 'full_day',
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(2, Booking::first()->sections()->count());
    }
}
