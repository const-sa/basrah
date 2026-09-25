<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\ContractTemplate;
use App\Models\Department;
use App\Models\Purchase;
use App\Models\Quotation;
use App\Models\Role;
use App\Models\Setting;
use App\Models\Supplier;
use App\Models\User;
use App\Models\WhatsappAccount;
use App\Models\WhatsappMessage;
use App\Services\ContractService;
use App\Services\QuotationPdf;
use App\Services\Whatsapp\WhatsappAccounts;
use App\Support\PoolMaintenanceContractTemplate;
use App\Support\PoolsLetterhead;
use Database\Seeders\ContractTemplateSeeder;
use Database\Seeders\RolesSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
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
        Setting::current()->update(['pools_name' => 'الاسم السابق للمسابح']);

        $contract = app(ContractService::class)->generateDirect($this->client, $this->maintenanceForm(), 900);

        $this->assertSame('الاسم السابق للمسابح', $contract->data['org_name']);

        $this->setPoolsIdentity();

        // Still the name it was drawn under: the settings do not rewrite paper.
        $this->assertSame('الاسم السابق للمسابح', $contract->fresh()->data['org_name']);

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
        $contract->update(['data' => ['org_name' => 'ديوان المسرة'] + $contract->data]);
        $this->assertSame('ديوان المسرة', $contract->fresh()->data['org_name']);

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

    /**
     * Left blank, the activity borrows nothing from the Diwan — it is a
     * separate business. Its registered name heads the sheet, and the blank
     * fields are simply left off.
     */
    public function test_an_unset_pools_identity_borrows_nothing_from_the_diwan(): void
    {
        Setting::current()->update(['pools_name' => null, 'pools_logo_path' => null, 'pools_phone' => null]);

        $contract = app(ContractService::class)->generateDirect($this->client, $this->maintenanceForm(), 900);

        $this->assertSame(PoolsLetterhead::NAME, $contract->data['org_name']);

        $this->actingAs($this->owner)->get('/admin/pools/contracts')
            ->assertInertia(fn ($page) => $page
                ->where('letterhead.business_name', PoolsLetterhead::NAME)
                ->where('letterhead.logo_url', null)
                ->where('letterhead.phone', null)
                ->etc());
    }

    /**
     * The pools are a separate business: their quotation carries their own
     * address and tax number, and whatever they have not been given is left
     * off — never filled in from the Diwan's.
     */
    public function test_a_pools_quotation_carries_nothing_of_the_diwan(): void
    {
        $this->setDiwanRegistration();
        $this->setPoolsIdentity();
        Setting::current()->update(['pools_address' => 'الرياض — حي النرجس', 'pools_tax_number' => '311111111111113']);

        $issuer = $this->actingAs($this->owner)
            ->getJson('/admin/quotations/'.$this->poolsQuotation()->id)
            ->assertOk()
            ->json('issuer');

        $this->assertSame(PoolsLetterhead::NAME, $issuer['business_name']);
        $this->assertSame('الرياض — حي النرجس', $issuer['address']);
        $this->assertSame('311111111111113', $issuer['tax_number']);
        $this->assertNull($issuer['commercial_register']);
        $this->assertNull($issuer['email']);
    }

    /** The quotation PDF is the pools' own too — and renders with tax on. */
    public function test_the_pools_quotation_pdf_is_headed_by_the_pools(): void
    {
        $this->setDiwanRegistration();
        $this->setPoolsIdentity();

        $quotation = $this->poolsQuotation();

        $this->actingAs($this->owner)->get("/admin/quotations/{$quotation->id}/pdf")
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');

        $html = view('pdf.quotation', (fn () => $this->viewData($quotation))->call(app(QuotationPdf::class)))->render();

        $this->assertStringContainsString(PoolsLetterhead::NAME, $html);
        $this->assertStringNotContainsString('ديوان المسرة', $html);
        $this->assertStringNotContainsString('399999999999993', $html);
        $this->assertStringNotContainsString('البصرة — شارع الكورنيش', $html);
    }

    /** A pools purchase is the pools' paper, not the Diwan's. */
    public function test_a_pools_purchase_is_headed_by_the_pools(): void
    {
        $this->setDiwanRegistration();
        $this->setPoolsIdentity();

        $purchase = Purchase::create([
            'number' => 'PUR-000001',
            'supplier_id' => Supplier::create(['name' => 'مورد المضخات'])->id,
            'user_id' => $this->owner->id,
            'department_id' => $this->poolsQuotation()->department_id,
            'subtotal' => 100,
            'total_amount' => 100,
            'is_taxable' => false,
            'paid_amount' => 0,
        ]);

        $issuer = $this->actingAs($this->owner)
            ->getJson("/admin/purchases/{$purchase->id}")
            ->assertOk()
            ->json('issuer');

        $this->assertSame(PoolsLetterhead::NAME, $issuer['business_name']);
        $this->assertNull($issuer['address']);
        $this->assertNull($issuer['tax_number']);
        $this->assertNull($issuer['commercial_register']);
    }

    /** The pools contract is signed and stamped by the pools' own manager. */
    public function test_a_pools_contract_is_signed_and_stamped_by_the_pools(): void
    {
        $this->setDiwanRegistration();
        $this->setPoolsIdentity();
        Setting::current()->update([
            'manager_name' => 'مدير الديوان',
            'manager_signature_path' => 'uploads/diwan-signature.png',
            'stamp_path' => 'uploads/diwan-stamp.png',
            'pools_manager_name' => 'إبراهيم العجلان',
            'pools_stamp_path' => 'uploads/pools-stamp.png',
        ]);

        $contract = app(ContractService::class)->generateFromQuotation($this->poolsQuotation());

        $this->actingAs($this->owner)->get("/admin/contracts/{$contract->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('issuer.business_name', PoolsLetterhead::NAME)
                ->where('issuer.manager_name', 'إبراهيم العجلان')
                ->where('issuer.stamp_url', asset('uploads/pools-stamp.png'))
                ->where('issuer.manager_signature_url', null)
                ->where('issuer.address', null)
                ->where('issuer.tax_number', null)
                ->where('issuer.commercial_register', null)
                ->etc());
    }

    /** A pools message is signed with the pools' name, not the Diwan's. */
    public function test_a_pools_contract_message_is_signed_by_the_pools(): void
    {
        $this->setPoolsIdentity();

        $contract = app(ContractService::class)->generateFromQuotation($this->poolsQuotation());
        $accounts = app(WhatsappAccounts::class);

        // With the pools' own number, the message goes out under its name.
        $poolsAccount = WhatsappAccount::active()->get()
            ->first(fn (WhatsappAccount $a) => $accounts->for($contract)?->is($a));
        $this->assertNotNull($poolsAccount, 'the pools contract is sent from the pools number');
        $this->assertNotSame('ديوان المسرة', $accounts->senderName($contract));

        // Without one, it is signed with the pools' letterhead — still not the Diwan's.
        WhatsappAccount::query()->update(['is_active' => false]);
        $accounts->forget();

        $this->assertSame(PoolsLetterhead::NAME, $accounts->senderName($contract));
        $this->assertSame(PoolsLetterhead::NAME, $accounts->senderName($this->client));
    }

    /** The pools quotation goes out on WhatsApp with its PDF, from the pools. */
    public function test_a_pools_quotation_is_sent_on_whatsapp_with_its_pdf(): void
    {
        Storage::fake('public');
        $this->setPoolsIdentity();

        $quotation = $this->poolsQuotation();

        $this->actingAs($this->owner)->post("/admin/quotations/{$quotation->id}/send")
            ->assertRedirect()
            ->assertSessionHas('success');

        $files = Storage::disk('public')->files('quotations');
        $this->assertCount(1, $files);
        $this->assertStringStartsWith('quotations/quotation-QT-000001-', $files[0]);

        $message = WhatsappMessage::latest('id')->firstOrFail();
        $this->assertSame('quotation', $message->purpose);
        $this->assertSame(Quotation::class, $message->related_type);
        $this->assertSame($quotation->id, $message->related_id);
        $this->assertStringContainsString('QT-000001', $message->body);
        $this->assertStringNotContainsString('ديوان المسرة', $message->body);
        // من رقم المسابح لا من رقم الديوان.
        $this->assertSame(app(WhatsappAccounts::class)->for($quotation)?->id, $message->whatsapp_account_id);
    }

    public function test_a_quotation_without_a_mobile_is_not_sent(): void
    {
        Storage::fake('public');
        $this->client->update(['mobile' => null]);

        $this->actingAs($this->owner)->post('/admin/quotations/'.$this->poolsQuotation()->id.'/send')
            ->assertSessionHas('warning');

        $this->assertSame(0, WhatsappMessage::count());
        $this->assertSame([], Storage::disk('public')->files('quotations'));
    }

    private function setDiwanRegistration(): void
    {
        Setting::current()->update([
            'address' => 'البصرة — شارع الكورنيش',
            'email' => 'info@diwan.test',
            'tax_enabled' => true,
            'tax_rate' => 15,
            'tax_number' => '399999999999993',
            'commercial_register' => '1010999999',
        ]);
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
