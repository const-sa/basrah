<?php

namespace Tests\Feature;

use App\Jobs\SendWhatsappMessage;
use App\Models\Client;
use App\Models\Role;
use App\Models\Sale;
use App\Models\User;
use App\Services\SalePdf;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * إرسال فاتورة المبيعات على واتساب.
 *
 * العميل الذي يسحب طلباته شهريًا يُحاسَب على ورقة لا على سطور رسالة، فالرسالة
 * تقول «مرفق فاتورتكم» ويجب أن يكون هناك مرفق فعلًا.
 */
class SaleInvoiceWhatsappTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesSeeder::class);

        $this->owner = User::factory()->create([
            'role_id' => Role::where('slug', 'super-admin')->firstOrFail()->id,
            'is_active' => true,
            'has_all_units' => true,
        ]);

        config()->set('whatsapp.enabled', true);
        config()->set('whatsapp.driver', 'cwts');
        config()->set('whatsapp.drivers.cwts.instance_id', 'INSTANCE');
        config()->set('whatsapp.drivers.cwts.access_token', 'SECRET');
    }

    public function test_the_invoice_is_stored_and_attached_to_the_message(): void
    {
        Storage::fake(SalePdf::DISK);
        Bus::fake();

        $sale = $this->sale('0551234567');

        $this->actingAs($this->owner)
            ->post("/admin/sales/{$sale->id}/send")
            ->assertRedirect();

        // المرفق موجود على القرص قبل أن تُرسل الرسالة التي تَعِد به.
        $files = Storage::disk(SalePdf::DISK)->files(SalePdf::DIRECTORY);
        $this->assertCount(1, $files);

        Bus::assertDispatched(
            SendWhatsappMessage::class,
            fn (SendWhatsappMessage $job) => $job->mediaUrl !== null
                && str_contains($job->mediaUrl, basename($files[0]))
                && str_contains($job->message, $sale->number),
        );
    }

    /** عميلٌ بلا جوال لا تُرسل له فاتورة، ويُقال السبب بدل الصمت. */
    public function test_a_client_without_a_mobile_is_refused_with_a_reason(): void
    {
        Storage::fake(SalePdf::DISK);
        Bus::fake();

        $sale = $this->sale(null);

        $this->actingAs($this->owner)
            ->post("/admin/sales/{$sale->id}/send")
            ->assertSessionHas('warning', 'لا يوجد رقم جوال للعميل — لا يمكن الإرسال.');

        Bus::assertNotDispatched(SendWhatsappMessage::class);
    }

    public function test_the_invoice_sheet_opens_as_a_pdf(): void
    {
        $sale = $this->sale('0551234567');

        $response = $this->actingAs($this->owner)->get("/admin/sales/{$sale->id}/pdf");

        $response->assertOk();
        $response->assertHeader('content-type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', $response->getContent());
    }

    private function sale(?string $mobile): Sale
    {
        $client = Client::create([
            'name' => 'عميل الصيانة الشهرية',
            'mobile' => $mobile,
            'is_active' => true,
        ]);

        return Sale::create([
            'number' => 'SL-2026-00042',
            'client_id' => $client->id,
            'user_id' => $this->owner->id,
            'type' => 'sale',
            'subtotal' => 130,
            'discount_amount' => 0,
            'tax_amount' => 0,
            'is_taxable' => false,
            'total_amount' => 130,
            'paid_amount' => 130,
        ]);
    }
}
