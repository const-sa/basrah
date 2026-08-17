<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Client;
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
 * الموقع العام والحجز أونلاين (§12 من العرض).
 */
class PublicSiteBookingTest extends TestCase
{
    use RefreshDatabase;

    private Unit $hall;

    private Unit $chalet;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([RolesSeeder::class, UnitsSeeder::class, BookingSetupSeeder::class]);

        $this->hall = Unit::where('code', 'HALL-01')->firstOrFail();
        $this->chalet = Unit::where('code', 'CH-BSR1')->firstOrFail();
    }

    // ── صفحات العرض ──────────────────────────────────────────

    public function test_home_page_is_public_and_lists_units(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('site/Home')
                ->has('halls', 2)
                ->has('chalets', 6)
                ->has('org.name'),
            );
    }

    public function test_unit_page_shows_sections_and_prices(): void
    {
        $this->get("/units/{$this->hall->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('site/Unit')
                ->where('unit.name', 'قاعة المشام')
                ->where('isStay', false)
                ->has('unit.sections', 2)
                ->has('prices', 3)
                ->has('periods', 3),
            );
    }

    public function test_chalet_page_is_presented_as_a_stay(): void
    {
        $this->get("/units/{$this->chalet->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('isStay', true)->has('prices', 1));
    }

    /**
     * الوحدة الموقوفة خارج الخدمة — فلا تُعرض ولا تُحجز.
     */
    public function test_inactive_unit_is_hidden_from_the_public_site(): void
    {
        $this->hall->update(['is_active' => false]);

        $this->get("/units/{$this->hall->id}")->assertNotFound();
        $this->get("/book/{$this->hall->id}")->assertNotFound();

        $this->get('/')->assertInertia(fn ($page) => $page->has('halls', 1));
    }

    public function test_booking_form_is_public(): void
    {
        $this->get("/book/{$this->hall->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('site/Book')
                ->where('unit.id', $this->hall->id)
                ->has('periods', 3),
            );
    }

    // ── التسعيرة ─────────────────────────────────────────────

    public function test_quote_returns_a_price_without_saving_anything(): void
    {
        $response = $this->postJson("/book/{$this->hall->id}/quote", [
            'scope' => 'whole',
            'booking_date' => '2026-09-10',
            'period' => 'evening',
            'days_count' => 1,
        ]);

        $response->assertOk()->assertJsonStructure(['total_amount', 'deposit_amount', 'lines']);

        $this->assertGreaterThan(0, $response->json('total_amount'));
        $this->assertSame(0, Booking::count());
    }

    // ── تسجيل الطلب ──────────────────────────────────────────

    /**
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return [
            'scope' => 'whole',
            'booking_date' => '2026-09-10',
            'period' => 'evening',
            'days_count' => 1,
            'client_name' => 'زائر الموقع',
            'client_mobile' => '0559998888',
            'agreed' => true,
            ...$overrides,
        ];
    }

    public function test_visitor_can_submit_a_booking_request(): void
    {
        $this->post("/book/{$this->hall->id}", $this->payload())
            ->assertRedirect();

        $booking = Booking::firstOrFail();

        $this->assertSame('online', $booking->source);
        // الطلب يحجز الموعد وينتظر العربون — لا يُسجَّل مؤكدًا كحجز الموظف
        $this->assertSame('pending_deposit', $booking->status);
        $this->assertNull($booking->created_by);
        $this->assertTrue($booking->isBlocking());
        $this->assertSame('زائر الموقع', $booking->client->name);
    }

    public function test_confirmation_page_is_reachable_by_reference(): void
    {
        $this->post("/book/{$this->hall->id}", $this->payload());

        $booking = Booking::firstOrFail();

        $this->get("/booking/{$booking->reference}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('site/Confirmation')
                ->where('booking.reference', $booking->reference)
                ->where('booking.client_name', 'زائر الموقع')
                // الصفحة عامة فلا تعرض ما لا يخصّ صاحب الحجز
                ->missing('booking.notes'),
            );
    }

    /**
     * العميل يُطابَق بجواله فلا يتكرر في السجل بعد كل حجز.
     */
    public function test_returning_visitor_is_matched_to_the_existing_client(): void
    {
        $existing = Client::create(['name' => 'عميل قديم', 'mobile' => '0559998888', 'is_active' => true]);

        $this->post("/book/{$this->hall->id}", $this->payload());

        $this->assertSame(1, Client::count());
        $this->assertSame($existing->id, Booking::firstOrFail()->client_id);
    }

    /**
     * القاعدة الجوهرية: الموقع لا يبيع ما باعته الإدارة.
     */
    public function test_online_booking_cannot_double_book_an_occupied_date(): void
    {
        $owner = User::factory()->create([
            'role_id' => Role::where('slug', 'super-admin')->firstOrFail()->id,
            'is_active' => true,
            'has_all_units' => true,
        ]);

        app(BookingService::class)->create([
            'unit_id' => $this->hall->id,
            'scope' => 'whole',
            'period' => 'evening',
            'booking_date' => '2026-09-10',
        ], $owner->id);

        $this->post("/book/{$this->hall->id}", $this->payload())
            ->assertSessionHasErrors('availability');

        $this->assertSame(1, Booking::count());
    }

    public function test_past_dates_are_rejected(): void
    {
        $this->post("/book/{$this->hall->id}", $this->payload(['booking_date' => '2020-01-01']))
            ->assertSessionHasErrors('booking_date');

        $this->assertSame(0, Booking::count());
    }

    public function test_terms_must_be_accepted(): void
    {
        $this->post("/book/{$this->hall->id}", $this->payload(['agreed' => false]))
            ->assertSessionHasErrors('agreed');

        $this->assertSame(0, Booking::count());
    }

    public function test_name_and_mobile_are_required(): void
    {
        $payload = $this->payload();
        unset($payload['client_name'], $payload['client_mobile']);

        $this->post("/book/{$this->hall->id}", $payload)
            ->assertSessionHasErrors(['client_name', 'client_mobile']);
    }

    public function test_sections_must_be_chosen_when_booking_by_section(): void
    {
        $this->post("/book/{$this->hall->id}", $this->payload(['scope' => 'sections']))
            ->assertSessionHasErrors('section_ids');
    }

    public function test_chalet_stay_is_booked_by_nights(): void
    {
        $this->post("/book/{$this->chalet->id}", [
            'scope' => 'whole',
            'booking_date' => '2026-09-10',
            'check_out_date' => '2026-09-13',
            'client_name' => 'ضيف',
            'client_mobile' => '0557776666',
            'agreed' => true,
        ])->assertRedirect();

        $booking = Booking::firstOrFail();

        $this->assertSame(3, $booking->nights);
        $this->assertSame('online', $booking->source);
        $this->assertTrue($booking->isStay());
    }

    public function test_chalet_check_out_must_follow_check_in(): void
    {
        $this->post("/book/{$this->chalet->id}", [
            'scope' => 'whole',
            'booking_date' => '2026-09-10',
            'check_out_date' => '2026-09-10',
            'client_name' => 'ضيف',
            'client_mobile' => '0557776666',
            'agreed' => true,
        ])->assertSessionHasErrors('check_out_date');
    }

    /**
     * الحجز القادم من الموقع يُوسَم في سجل الإدارة ليُتابَع.
     */
    public function test_online_bookings_are_flagged_in_the_admin_register(): void
    {
        $this->post("/book/{$this->hall->id}", $this->payload());

        $owner = User::factory()->create([
            'role_id' => Role::where('slug', 'super-admin')->firstOrFail()->id,
            'is_active' => true,
            'has_all_units' => true,
        ]);

        $this->actingAs($owner)
            ->get('/admin/bookings/halls')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('bookings.data.0.is_online', true));
    }

    public function test_admin_created_bookings_are_not_flagged_as_online(): void
    {
        $owner = User::factory()->create([
            'role_id' => Role::where('slug', 'super-admin')->firstOrFail()->id,
            'is_active' => true,
            'has_all_units' => true,
        ]);

        $booking = app(BookingService::class)->create([
            'unit_id' => $this->hall->id,
            'scope' => 'whole',
            'period' => 'evening',
            'booking_date' => '2026-09-10',
        ], $owner->id);

        $this->assertSame('admin', $booking->source);
        $this->assertFalse($booking->isOnline());
    }
}
