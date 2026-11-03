<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Role;
use App\Models\Sale;
use App\Models\User;
use Database\Seeders\AccountsSeeder;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * شاشتا ذمم العملاء: قائمة المديونيات وكشف الحساب التفصيلي.
 */
class ReceivablesTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([RolesSeeder::class, AccountsSeeder::class]);

        $this->owner = User::factory()->create([
            'role_id' => Role::where('slug', 'super-admin')->firstOrFail()->id,
            'is_active' => true,
            'has_all_units' => true,
        ]);
    }

    public function test_index_lists_clients_with_their_outstanding_balance(): void
    {
        $client = Client::create(['name' => 'عميل الذمم']);

        Sale::create([
            'number' => 'S-REC-1', 'client_id' => $client->id, 'type' => 'sale',
            'subtotal' => 500, 'tax_amount' => 0, 'total_amount' => 500, 'paid_amount' => 200,
        ]);

        $this->actingAs($this->owner)
            ->get('/admin/accounting/receivables')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('admin/accounting/Receivables')
                ->where('clients.data.0.name', 'عميل الذمم')
                ->where('clients.data.0.outstanding', 300),
            );
    }

    /** «كل العملاء داخلين ف بعض»: each activity's clients can be read on their own. */
    public function test_index_filters_by_activity(): void
    {
        Client::create(['name' => 'عميل قاعة', 'type' => 'hall']);
        Client::create(['name' => 'عميل شاليه', 'type' => 'chalet']);
        Client::create(['name' => 'عميل مسبح', 'type' => 'pool']);

        $this->actingAs($this->owner)
            ->get('/admin/accounting/receivables?type=hall')
            ->assertInertia(fn ($page) => $page
                ->where('filters.type', 'hall')
                ->has('clients.data', 1)
                ->where('clients.data.0.name', 'عميل قاعة'),
            );

        $this->actingAs($this->owner)
            ->get('/admin/accounting/receivables?type=pool')
            ->assertInertia(fn ($page) => $page
                ->has('clients.data', 1)
                ->where('clients.data.0.name', 'عميل مسبح'),
            );

        // An unknown activity shows everyone rather than no one.
        $this->actingAs($this->owner)
            ->get('/admin/accounting/receivables?type=nonsense')
            ->assertInertia(fn ($page) => $page
                ->where('filters.type', null)
                ->has('clients.data', 3),
            );
    }

    public function test_show_renders_the_client_statement(): void
    {
        $client = Client::create(['name' => 'عميل الكشف']);

        Sale::create([
            'number' => 'S-REC-2', 'client_id' => $client->id, 'type' => 'sale',
            'subtotal' => 900, 'tax_amount' => 0, 'total_amount' => 900, 'paid_amount' => 0,
        ]);

        $this->actingAs($this->owner)
            ->get("/admin/accounting/receivables/{$client->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('admin/accounting/ReceivableStatement')
                ->where('statement.closing_balance', 900),
            );
    }

    public function test_export_streams_a_csv(): void
    {
        $client = Client::create(['name' => 'عميل التصدير']);

        $this->actingAs($this->owner)
            ->get("/admin/accounting/receivables/{$client->id}/export")
            ->assertOk()
            ->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
    }

    public function test_a_cashier_without_the_permission_is_forbidden(): void
    {
        $cashier = User::factory()->create([
            'role_id' => Role::where('slug', 'cashier')->firstOrFail()->id,
            'is_active' => true,
            'has_all_units' => true,
        ]);

        $this->actingAs($cashier)->get('/admin/accounting/receivables')->assertForbidden();
    }
}
