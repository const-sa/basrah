<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\ContractTemplate;
use App\Models\Department;
use App\Models\Quotation;
use App\Models\Role;
use App\Models\Setting;
use App\Models\User;
use App\Services\ContractService;
use App\Support\PoolMaintenanceContractTemplate;
use App\Support\PoolsLetterhead;
use Database\Seeders\ContractTemplateSeeder;
use Database\Seeders\RolesSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * The pools activity trades under its own name, logo and phone, and its paper
 * is headed with them rather than with the business's.
 */
class PoolsLetterheadTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        $this->seed([RolesSeeder::class, ContractTemplateSeeder::class]);

        $this->owner = User::factory()->create([
            'role_id' => Role::where('slug', 'super-admin')->value('id'),
            'is_active' => true,
            'has_all_units' => true,
        ]);

        $this->client = Client::create(['name' => 'فهد الدوسري', 'mobile' => '0556677889', 'type' => 'pool']);

        Setting::current()->update([
            'business_name' => 'ديوان المسرة',
            'phone' => '0500000000',
            'logo_path' => 'uploads/business-logo.png',
        ]);
    }

    public function test_the_settings_screen_carries_the_pools_identity(): void
    {
        $this->actingAs($this->owner)->get('/admin/settings/general')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('admin/settings/General')
                ->has('settings.pools_name')
                ->has('settings.pools_phone')
                ->has('settings.pools_logo_url')
                ->etc());
    }

    public function test_the_pools_name_logo_and_phone_are_saved(): void
    {
        $this->actingAs($this->owner)->post('/admin/settings/general', [
            'business_name' => 'ديوان المسرة',
            'pools_name' => PoolsLetterhead::NAME,
            'pools_phone' => PoolsLetterhead::PHONE,
            'pools_logo' => UploadedFile::fake()->image('pools.png'),
        ])->assertRedirect();

        $settings = Setting::current()->fresh();

        $this->assertSame(PoolsLetterhead::NAME, $settings->pools_name);
        $this->assertSame(PoolsLetterhead::PHONE, $settings->pools_phone);
        $this->assertStringStartsWith('uploads/pools-logo-', $settings->pools_logo_path);
        $this->assertFileExists(public_path($settings->pools_logo_path));

        @unlink(public_path($settings->pools_logo_path));
    }

    /** The activity's identity is pinned on a fresh install, not left to be typed. */
    public function test_the_seeder_pins_the_pools_name_and_phone(): void
    {
        Setting::current()->update(['pools_name' => null, 'pools_phone' => null]);

        $this->seed(SettingsSeeder::class);

        $this->assertSame(PoolsLetterhead::NAME, Setting::current()->fresh()->pools_name);
        $this->assertSame(PoolsLetterhead::PHONE, Setting::current()->fresh()->pools_phone);
    }

    public function test_a_pools_sheet_is_drawn_and_headed_under_the_pools_identity(): void
    {
        $this->setPoolsIdentity();

        $contract = app(ContractService::class)->generateDirect($this->client, $this->maintenanceForm(), 900);

        $this->assertSame(PoolsLetterhead::NAME, $contract->data['org_name']);

        $this->actingAs($this->owner)->get("/admin/contracts/{$contract->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('issuer.business_name', PoolsLetterhead::NAME)
                ->where('issuer.phone', PoolsLetterhead::PHONE)
                ->where('issuer.logo_url', asset('uploads/pools-logo.png'))
                ->etc());
    }

    /** A sheet that is not the pools' keeps the business's letterhead. */
    public function test_a_standard_sheet_keeps_the_business_letterhead(): void
    {
        $this->setPoolsIdentity();

        $contract = app(ContractService::class)->generateDirect(
            $this->client,
            ContractTemplate::where('is_default', true)->firstOrFail(),
            900,
        );

        $this->assertSame('ديوان المسرة', $contract->data['org_name']);

        $this->actingAs($this->owner)->get("/admin/contracts/{$contract->id}")
            ->assertInertia(fn ($page) => $page
                ->where('issuer.business_name', 'ديوان المسرة')
                ->where('issuer.logo_url', asset('uploads/business-logo.png'))
                ->etc());
    }

    public function test_each_register_is_headed_by_the_letterhead_its_contracts_are_drawn_under(): void
    {
        $this->setPoolsIdentity();

        $this->actingAs($this->owner)->get('/admin/pools/contracts')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('letterhead.business_name', PoolsLetterhead::NAME)
                ->where('letterhead.phone', PoolsLetterhead::PHONE)
                ->where('letterhead.logo_url', asset('uploads/pools-logo.png'))
                ->etc());

        $this->actingAs($this->owner)->get('/admin/contracts')
            ->assertInertia(fn ($page) => $page
                ->where('letterhead.business_name', 'ديوان المسرة')
                ->where('letterhead.phone', '0500000000')
                ->etc());
    }

    /**
     * A sheet drawn before the activity was named keeps what it was frozen
     * with — the letterhead is part of the paper — until it is refreshed.
     */
    public function test_an_older_draft_adopts_the_letterhead_when_refreshed(): void
    {
        $contract = app(ContractService::class)->generateDirect($this->client, $this->maintenanceForm(), 900);

        $this->assertSame('ديوان المسرة', $contract->data['org_name']);

        $this->setPoolsIdentity();

        // Still the name it was drawn under: the settings do not rewrite paper.
        $this->assertSame('ديوان المسرة', $contract->fresh()->data['org_name']);

        $this->actingAs($this->owner)->post("/admin/contracts/{$contract->id}/refresh")->assertRedirect();

        $this->assertSame(PoolsLetterhead::NAME, $contract->fresh()->data['org_name']);

        $this->actingAs($this->owner)->get("/admin/contracts/{$contract->id}")
            ->assertInertia(fn ($page) => $page->where('issuer.business_name', PoolsLetterhead::NAME)->etc());
    }

    /**
     * A quotation contract on the standard sheet is the pools' too: the
     * activity that quoted the job is the one that signs it.
     */
    public function test_a_pools_quotation_is_headed_by_the_pools_letterhead(): void
    {
        $this->setPoolsIdentity();

        $contract = app(ContractService::class)->generateFromQuotation($this->poolsQuotation());

        $this->assertSame(PoolsLetterhead::NAME, $contract->data['org_name']);

        $this->actingAs($this->owner)->get("/admin/contracts/{$contract->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('issuer.business_name', PoolsLetterhead::NAME)
                ->where('issuer.logo_url', asset('uploads/pools-logo.png'))
                ->where('issuer.phone', PoolsLetterhead::PHONE)
                ->etc());
    }

    /** The old sheets are repaired where the letterhead never reached them. */
    public function test_an_old_pools_contract_is_repaired_to_the_pools_letterhead(): void
    {
        $contract = app(ContractService::class)->generateFromQuotation($this->poolsQuotation());

        // Drawn before the activity was named — the business's name is frozen
        // onto the paper, and the letterhead alone does not reach it.
        $this->assertSame('ديوان المسرة', $contract->data['org_name']);

        $this->setPoolsIdentity();
        $this->repairLetterheads();

        $contract->refresh();

        $this->assertSame(PoolsLetterhead::NAME, $contract->data['org_name']);
        $this->assertStringContainsString(PoolsLetterhead::NAME, $contract->body);
        $this->assertStringNotContainsString('ديوان المسرة', $contract->body);

        $this->actingAs($this->owner)->get("/admin/contracts/{$contract->id}")
            ->assertInertia(fn ($page) => $page
                ->where('issuer.business_name', PoolsLetterhead::NAME)
                ->where('issuer.logo_url', asset('uploads/pools-logo.png'))
                ->etc());
    }

    /** A hall's sheet is none of the activity's business. */
    public function test_the_repair_leaves_a_booking_contract_alone(): void
    {
        $this->setPoolsIdentity();

        $contract = app(ContractService::class)->generateDirect(
            $this->client,
            ContractTemplate::where('is_default', true)->firstOrFail(),
            900,
        );

        $this->repairLetterheads();

        $this->assertSame('ديوان المسرة', $contract->fresh()->data['org_name']);
    }

    /** Left blank, the activity follows the business it belongs to. */
    public function test_an_unset_pools_identity_falls_back_to_the_business(): void
    {
        Setting::current()->update(['pools_name' => null, 'pools_logo_path' => null, 'pools_phone' => null]);

        $contract = app(ContractService::class)->generateDirect($this->client, $this->maintenanceForm(), 900);

        $this->assertSame('ديوان المسرة', $contract->data['org_name']);

        $this->actingAs($this->owner)->get('/admin/pools/contracts')
            ->assertInertia(fn ($page) => $page
                ->where('letterhead.business_name', 'ديوان المسرة')
                ->where('letterhead.logo_url', asset('uploads/business-logo.png'))
                ->etc());
    }

    private function setPoolsIdentity(): void
    {
        Setting::current()->update([
            'pools_name' => PoolsLetterhead::NAME,
            'pools_phone' => PoolsLetterhead::PHONE,
            'pools_logo_path' => 'uploads/pools-logo.png',
        ]);
    }

    private function maintenanceForm(): ContractTemplate
    {
        return ContractTemplate::where('name', PoolMaintenanceContractTemplate::NAME)->firstOrFail();
    }

    /** A quotation from the pools department — the activity that quoted it. */
    private function poolsQuotation(): Quotation
    {
        $department = Department::firstOrCreate(
            ['code' => 'POOLS'],
            ['name' => 'المسابح', 'sells' => true, 'is_active' => true, 'sort_order' => 1],
        );

        return Quotation::create([
            'number' => 'QT-000001',
            'client_id' => $this->client->id,
            'user_id' => $this->owner->id,
            'department_id' => $department->id,
            'status' => 'pending',
            'subtotal' => 900,
            'total_amount' => 900,
        ]);
    }

    /** The data repair itself, as the deployed install runs it. */
    private function repairLetterheads(): void
    {
        (require database_path('migrations/2026_10_01_100001_head_old_pools_contracts_with_their_own_letterhead.php'))->up();
    }
}
