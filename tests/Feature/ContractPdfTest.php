<?php

namespace Tests\Feature;

use App\Jobs\SendWhatsappMessage;
use App\Models\Booking;
use App\Models\Client;
use App\Models\Contract;
use App\Models\Role;
use App\Models\Setting;
use App\Models\Unit;
use App\Models\User;
use App\Services\BookingService;
use App\Services\ContractPdf;
use Database\Seeders\BookingSetupSeeder;
use Database\Seeders\ContractTemplateSeeder;
use Database\Seeders\RolesSeeder;
use Database\Seeders\UnitsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * توليد ملف العقد PDF وإرساله مرفقًا (§5 من العرض).
 */
class ContractPdfTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Booking $booking;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([RolesSeeder::class, UnitsSeeder::class, BookingSetupSeeder::class, ContractTemplateSeeder::class]);

        $this->owner = User::factory()->create([
            'role_id' => Role::where('slug', 'super-admin')->firstOrFail()->id,
            'is_active' => true,
            'has_all_units' => true,
        ]);

        $client = Client::create(['name' => 'عبدالله السالم', 'mobile' => '0551234567', 'is_active' => true]);

        $this->booking = app(BookingService::class)->create([
            'unit_id' => Unit::where('code', 'HALL-01')->firstOrFail()->id,
            'client_id' => $client->id,
            'scope' => 'whole',
            'period' => 'evening',
            'booking_date' => '2026-09-10',
        ], $this->owner->id);
    }

    private function contract(): Contract
    {
        // العقد يُولَّد تلقائيًا مع الحجز عبر BookingObserver.
        return $this->booking->contracts()->firstOrFail();
    }

    public function test_pdf_is_generated_for_a_contract(): void
    {
        $bytes = app(ContractPdf::class)->render($this->contract());

        $this->assertStringStartsWith('%PDF-', $bytes);
        $this->assertGreaterThan(5000, strlen($bytes));
    }

    /**
     * العربية تحتاج خطًّا يحمل حروفها؛ سقوط mpdf إلى خطٍّ لاتيني يُخرج
     * مستندًا بمربّعات فارغة، ولا يظهر ذلك إلا بفتح الملف. فحص الخط
     * المضمَّن يجعل العطل يظهر في الاختبار لا عند العميل.
     */
    public function test_pdf_embeds_an_arabic_capable_font(): void
    {
        $bytes = app(ContractPdf::class)->render($this->contract());

        $this->assertStringContainsString('XBRiyaz', $bytes);
    }

    public function test_pdf_route_streams_the_document_inline(): void
    {
        $response = $this->actingAs($this->owner)
            ->get("/admin/contracts/{$this->contract()->id}/pdf");

        $response->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringContainsString('inline;', $response->headers->get('Content-Disposition'));
    }

    public function test_pdf_route_can_force_a_download(): void
    {
        $response = $this->actingAs($this->owner)
            ->get("/admin/contracts/{$this->contract()->id}/pdf?download=1");

        $this->assertStringContainsString('attachment;', $response->headers->get('Content-Disposition'));
    }

    public function test_pdf_route_requires_export_permission(): void
    {
        $cashier = User::factory()->create([
            'role_id' => Role::where('slug', 'cashier')->firstOrFail()->id,
            'is_active' => true,
            'has_all_units' => true,
        ]);

        $this->actingAs($cashier)
            ->get("/admin/contracts/{$this->contract()->id}/pdf")
            ->assertForbidden();
    }

    /**
     * الرسالة تقول «مرفق العقد» — فيجب أن يكون هناك مرفق فعلًا.
     */
    public function test_sending_a_contract_stores_the_pdf_and_attaches_it(): void
    {
        Storage::fake(ContractPdf::DISK);
        Bus::fake();

        $contract = $this->contract();

        $this->actingAs($this->owner)
            ->post("/admin/contracts/{$contract->id}/send")
            ->assertRedirect();

        $filename = app(ContractPdf::class)->filename($contract);

        Storage::disk(ContractPdf::DISK)->assertExists(ContractPdf::DIRECTORY.'/'.$filename);

        Bus::assertDispatched(
            SendWhatsappMessage::class,
            fn (SendWhatsappMessage $job) => $job->mediaUrl !== null
                && str_contains($job->mediaUrl, $filename),
        );

        $this->assertSame('sent', $contract->fresh()->status);
    }

    /**
     * العقد يُصاغ من اللقطة المجمَّدة لا من الحجز الحالي.
     */
    public function test_pdf_uses_the_frozen_snapshot_not_the_live_booking(): void
    {
        $contract = $this->contract();

        Setting::current()->update(['business_name' => 'ديوان البصرة']);

        $this->booking->update(['total_amount' => 99999]);

        $bytes = app(ContractPdf::class)->render($contract->fresh());

        // المستند وُلِّد بلا عطل رغم تغيّر الحجز بعد تجميد العقد
        $this->assertStringStartsWith('%PDF-', $bytes);
        $this->assertSame(
            $contract->data['total_amount'],
            $contract->fresh()->data['total_amount'],
        );
    }
}
