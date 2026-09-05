<?php

namespace Tests\Feature;

use App\Models\Addon;
use App\Models\Booking;
use App\Models\BookingPayment;
use App\Models\Client;
use App\Models\Role;
use App\Models\Unit;
use App\Models\User;
use Database\Seeders\BookingSetupSeeder;
use Database\Seeders\RolesSeeder;
use Database\Seeders\UnitsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ملف العميل المتكامل (§5): من هذا، وكم تعامل، وكم عليه، وأين عقده.
 */
class ClientProfileTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Client $client;

    private Unit $unit;

    private int $sequence = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([RolesSeeder::class, UnitsSeeder::class, BookingSetupSeeder::class]);

        $this->owner = User::factory()->create([
            'name' => 'مالك النظام',
            'role_id' => Role::where('slug', 'super-admin')->firstOrFail()->id,
            'is_active' => true,
            'has_all_units' => true,
        ]);

        $this->unit = Unit::where('code', 'HALL-01')->firstOrFail();
        $this->client = Client::create(['name' => 'أبو محمد', 'mobile' => '0551112222', 'is_active' => true]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function booking(array $attributes = []): Booking
    {
        return Booking::create([
            'reference' => 'a-'.(++$this->sequence),
            'unit_id' => $this->unit->id,
            'client_id' => $this->client->id,
            'created_by' => $this->owner->id,
            'scope' => 'whole',
            'period' => 'evening',
            'booking_date' => '2026-08-16',
            'starts_at' => '2026-08-16 18:00:00',
            'ends_at' => '2026-08-16 23:00:00',
            'status' => 'deposit_paid',
            'base_amount' => 1000,
            'total_amount' => 1000,
            'paid_amount' => 400,
            ...$attributes,
        ]);
    }

    public function test_the_profile_shows_the_client_with_his_bookings(): void
    {
        $this->booking();
        $this->booking(['total_amount' => 500, 'paid_amount' => 500]);

        $this->actingAs($this->owner)
            ->get("/admin/clients/{$this->client->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('admin/clients/Show')
                ->where('client.name', 'أبو محمد')
                ->where('client.mobile', '0551112222')
                ->has('bookings', 2)
                ->where('bookings.0.unit', $this->unit->name),
            );
    }

    /**
     * المدفوع والمتبقي هما أول ما يُسأل عنه — والملغى لا يُطالَب به.
     */
    public function test_the_profile_totals_what_was_paid_and_what_remains(): void
    {
        $this->booking();                                                   // 1000 دفع 400
        $this->booking(['total_amount' => 500, 'paid_amount' => 500]);      // مسدَّد
        $this->booking(['status' => 'cancelled', 'total_amount' => 900, 'paid_amount' => 0]);

        $this->actingAs($this->owner)
            ->get("/admin/clients/{$this->client->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('stats.bookings_count', 2)
                ->where('stats.cancelled_count', 1)
                ->where('stats.bookings_value', 1500)
                ->where('stats.paid', 900)
                ->where('stats.remaining', 600),
            );
    }

    public function test_the_transactions_tab_lists_payments_with_refunds_signed(): void
    {
        $booking = $this->booking();

        BookingPayment::create([
            'booking_id' => $booking->id,
            'type' => 'deposit',
            'payment_method_id' => $this->paymentMethodId(),
            'amount' => 400,
            'paid_on' => '2026-08-10',
        ]);

        BookingPayment::create([
            'booking_id' => $booking->id,
            'type' => 'refund',
            'payment_method_id' => $this->paymentMethodId(),
            'amount' => 100,
            'paid_on' => '2026-08-12',
        ]);

        $this->actingAs($this->owner)
            ->get("/admin/clients/{$this->client->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('payments', 2)
                // الأحدث أولًا، والاسترداد بإشارة سالبة لأنه خروجٌ لا دخول.
                ->where('payments.0.amount', -100)
                ->where('payments.1.amount', 400)
                ->where('payments.1.reference', $booking->reference),
            );
    }

    /**
     * دفعات عميلٍ آخر لا تظهر في ملفه — المرور عبر الحجز يحكم الانتماء.
     */
    public function test_another_clients_records_do_not_leak_into_the_profile(): void
    {
        $other = Client::create(['name' => 'عميل آخر', 'is_active' => true]);

        $mine = $this->booking();
        $theirs = $this->booking(['client_id' => $other->id, 'booking_date' => '2026-08-18']);

        BookingPayment::create([
            'booking_id' => $theirs->id,
            'type' => 'deposit',
            'payment_method_id' => $this->paymentMethodId(),
            'amount' => 700,
            'paid_on' => '2026-08-18',
        ]);

        $this->actingAs($this->owner)
            ->get("/admin/clients/{$this->client->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('bookings', 1)
                ->where('bookings.0.reference', $mine->reference)
                ->has('payments', 0),
            );
    }

    public function test_notes_are_saved_from_the_profile(): void
    {
        $this->actingAs($this->owner)
            ->put("/admin/clients/{$this->client->id}", [
                'name' => $this->client->name,
                'mobile' => $this->client->mobile,
                'is_active' => true,
                'notes' => 'يفضّل القاعة مساءً — ولا يُقبل منه شيك.',
            ])
            ->assertRedirect();

        $this->assertSame('يفضّل القاعة مساءً — ولا يُقبل منه شيك.', $this->client->fresh()->notes);
    }

    /**
     * نموذج القائمة لا يرسل الملاحظات، فلا يجوز أن يمحوها بحفظٍ عادي.
     */
    public function test_editing_from_the_list_does_not_wipe_the_notes(): void
    {
        $this->client->update(['notes' => 'ملاحظة قديمة']);

        $this->actingAs($this->owner)
            ->put("/admin/clients/{$this->client->id}", [
                'name' => 'أبو محمد المحدَّث',
                'mobile' => '0559998888',
                'is_active' => true,
            ])
            ->assertRedirect();

        $client = $this->client->fresh();

        $this->assertSame('أبو محمد المحدَّث', $client->name);
        $this->assertSame('ملاحظة قديمة', $client->notes);
    }

    public function test_previous_services_are_summarised(): void
    {
        $booking = $this->booking();
        $addon = Addon::first();

        if ($addon) {
            $booking->addons()->attach($addon->id, ['quantity' => 1, 'unit_price' => 100, 'total' => 100]);
        }

        $this->actingAs($this->owner)
            ->get("/admin/clients/{$this->client->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('services'));
    }

    public function test_the_profile_is_guarded_by_permission(): void
    {
        $role = Role::create(['name' => 'بلا صلاحيات', 'slug' => 'no-access', 'permissions' => []]);

        $stranger = User::factory()->create([
            'role_id' => $role->id,
            'is_active' => true,
            'has_all_units' => true,
        ]);

        $this->actingAs($stranger)->get("/admin/clients/{$this->client->id}")->assertForbidden();
    }

    /**
     * «export» مسارٌ ثابت لا معرّف عميل — والترتيب في الملف يحفظ ذلك.
     */
    public function test_the_export_route_is_not_swallowed_by_the_profile_route(): void
    {
        $this->actingAs($this->owner)
            ->get('/admin/clients/export')
            ->assertOk()
            ->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
    }
}
