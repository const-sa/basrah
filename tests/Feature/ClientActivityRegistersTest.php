<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Role;
use App\Models\User;
use App\Support\ClientType;
use Database\Seeders\BookingSetupSeeder;
use Database\Seeders\DepartmentsSeeder;
use Database\Seeders\RolesSeeder;
use Database\Seeders\UnitsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * سجلّ عملاء لكل نشاط: المسابح والقاعات والشاليهات لا يتشاركون قائمةً واحدة.
 */
class ClientActivityRegistersTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([RolesSeeder::class, DepartmentsSeeder::class, UnitsSeeder::class, BookingSetupSeeder::class]);

        $this->owner = User::factory()->create([
            'role_id' => Role::where('slug', 'super-admin')->value('id'),
            'is_active' => true,
            'has_all_units' => true,
        ]);

        foreach (ClientType::keys() as $type) {
            Client::create(['name' => "عميل {$type}", 'type' => $type, 'is_active' => true]);
        }
    }

    public function test_the_invoice_screen_offers_the_pools_register_and_the_walk_in_client(): void
    {
        $expected = Client::where('type', ClientType::POOL)->orWhere('is_walk_in', true)->pluck('name')->sort()->values();

        $this->actingAs($this->owner)
            ->get('/admin/pos')
            ->assertOk()
            ->assertInertia(fn ($p) => $p
                ->where('clientType', ClientType::POOL)
                ->where('clients', fn ($clients) => collect($clients)->pluck('name')->sort()->values()->all() === $expected->all()),
            );
    }

    public function test_a_hall_booking_form_offers_hall_clients_and_not_chalet_guests(): void
    {
        $this->actingAs($this->owner)
            ->get('/admin/bookings/halls/create')
            ->assertOk()
            ->assertInertia(fn ($p) => $p
                ->where('clients', fn ($clients) => collect($clients)->pluck('name')->contains('عميل hall')
                    && ! collect($clients)->pluck('name')->contains('عميل chalet')
                    && ! collect($clients)->pluck('name')->contains('عميل pool')),
            );
    }

    public function test_a_chalet_booking_form_offers_chalet_guests_and_not_hall_clients(): void
    {
        $this->actingAs($this->owner)
            ->get('/admin/bookings/chalets/create')
            ->assertOk()
            ->assertInertia(fn ($p) => $p
                ->where('clients', fn ($clients) => collect($clients)->pluck('name')->contains('عميل chalet')
                    && ! collect($clients)->pluck('name')->contains('عميل hall')),
            );
    }

    public function test_a_client_added_from_the_invoice_screen_joins_the_pools_register(): void
    {
        $this->actingAs($this->owner)
            ->postJson('/admin/clients/quick', ['name' => 'مشترٍ جديد', 'type' => ClientType::POOL])
            ->assertCreated();

        $this->assertSame(ClientType::POOL, Client::where('name', 'مشترٍ جديد')->value('type'));
    }

    public function test_the_directory_filters_by_activity(): void
    {
        $this->actingAs($this->owner)
            ->get('/admin/clients?type='.ClientType::CHALET)
            ->assertOk()
            ->assertInertia(fn ($p) => $p
                ->where('filters.type', ClientType::CHALET)
                ->has('clients.data', 1)
                ->where('clients.data.0.name', 'عميل chalet'),
            );
    }

    public function test_editing_a_client_without_sending_the_activity_leaves_it_alone(): void
    {
        $client = Client::where('type', ClientType::HALL)->firstOrFail();

        $this->actingAs($this->owner)
            ->put("/admin/clients/{$client->id}", ['name' => 'اسمٌ محدَّث', 'is_active' => true])
            ->assertRedirect();

        $this->assertSame(ClientType::HALL, $client->fresh()->type);
    }
}
