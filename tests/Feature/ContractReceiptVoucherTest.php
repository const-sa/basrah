<?php

namespace Tests\Feature;

use App\Jobs\SendWhatsappMessage;
use App\Models\Client;
use App\Models\Contract;
use App\Models\ContractTemplate;
use App\Models\Role;
use App\Models\User;
use App\Models\Voucher;
use App\Models\WhatsappAccount;
use App\Models\WhatsappMessage;
use App\Services\ContractReceiptPdf;
use App\Services\Whatsapp\WhatsappAccounts;
use App\Support\PoolMaintenanceContractTemplate;
use Database\Seeders\AccountsSeeder;
use Database\Seeders\ContractTemplateSeeder;
use Database\Seeders\PaymentMethodsSeeder;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * سندات القبض على العقد — تُعرض في سجلّ العقود، وتُطبع، وتُرسل على واتساب.
 */
class ContractReceiptVoucherTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Contract $contract;

    private Voucher $voucher;

    protected function setUp(): void
    {
        parent::setUp();

        Bus::fake();
        $this->seed([RolesSeeder::class, ContractTemplateSeeder::class, AccountsSeeder::class, PaymentMethodsSeeder::class]);

        $this->owner = User::factory()->create([
            'role_id' => Role::where('slug', 'super-admin')->value('id'),
            'is_active' => true,
            'has_all_units' => true,
        ]);

        $client = Client::create(['name' => 'المدرسة الرقمية', 'mobile' => '0551508655', 'type' => 'pool']);

        $this->actingAs($this->owner)->post('/admin/contracts/direct', [
            'client_id' => $client->id,
            'contract_template_id' => ContractTemplate::where('name', PoolMaintenanceContractTemplate::NAME)->value('id'),
            'total_amount' => 800,
            'deposit_amount' => 500,
        ])->assertRedirect();

        $this->contract = Contract::latest('id')->firstOrFail();
        $this->voucher = Voucher::where('contract_id', $this->contract->id)->firstOrFail();
    }

    public function test_the_register_lists_the_receipts_on_each_contract(): void
    {
        $this->actingAs($this->owner)
            ->get('/admin/pools/contracts')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('contracts.data.0.receipts.0.number', $this->voucher->number)
                ->where('contracts.data.0.receipts.0.amount', '500.00'));
    }

    public function test_the_receipt_prints_as_a_pdf(): void
    {
        $response = $this->actingAs($this->owner)
            ->get("/admin/contracts/{$this->contract->id}/receipts/{$this->voucher->id}/pdf")
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');

        $this->assertStringStartsWith('%PDF', $response->getContent());
    }

    public function test_a_voucher_of_another_contract_is_not_printed_under_this_one(): void
    {
        $this->actingAs($this->owner)->post('/admin/contracts/direct', [
            'client_id' => $this->contract->client_id,
            'contract_template_id' => $this->contract->contract_template_id,
            'total_amount' => 100,
        ])->assertRedirect();

        $other = Contract::latest('id')->firstOrFail();

        $this->actingAs($this->owner)
            ->get("/admin/contracts/{$other->id}/receipts/{$this->voucher->id}/pdf")
            ->assertNotFound();
    }

    public function test_sending_the_receipt_attaches_its_pdf(): void
    {
        Storage::fake(ContractReceiptPdf::DISK);
        $this->linkWhatsapp();

        $this->actingAs($this->owner)
            ->post("/admin/contracts/{$this->contract->id}/receipts/{$this->voucher->id}/send")
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertCount(1, Storage::disk(ContractReceiptPdf::DISK)->files(ContractReceiptPdf::DIRECTORY));

        $message = WhatsappMessage::where('purpose', 'receipt')->latest('id')->firstOrFail();
        $this->assertStringContainsString($this->voucher->number, $message->body);
        $this->assertStringContainsString('المتبقي: 300.00', $message->body);

        Bus::assertDispatched(SendWhatsappMessage::class, fn (SendWhatsappMessage $job) => $job->mediaUrl !== null
            && str_contains($job->mediaUrl, ContractReceiptPdf::DIRECTORY.'/'));
    }

    private function linkWhatsapp(): void
    {
        // السند يخرج من رقم المسابح، كعقده.
        WhatsappAccount::updateOrCreate(
            ['section' => 'pools', 'unit_id' => null],
            ['name' => 'مؤسسة العجلان لبرك السباحه', 'driver' => 'cwts', 'is_active' => true,
                'instance_id' => 'TEST-INSTANCE', 'access_token' => 'test-token'],
        );
        app(WhatsappAccounts::class)->forget();
    }
}
