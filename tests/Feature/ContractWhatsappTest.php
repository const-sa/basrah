<?php

namespace Tests\Feature;

use App\Jobs\SendWhatsappMessage;
use App\Models\Booking;
use App\Models\Client;
use App\Models\Contract;
use App\Models\ContractTemplate;
use App\Models\Unit;
use App\Models\WhatsappMessage;
use App\Services\BookingService;
use App\Services\ContractService;
use App\Services\WhatsappNotifier;
use Database\Seeders\AccountsSeeder;
use Database\Seeders\BookingSetupSeeder;
use Database\Seeders\ContractTemplateSeeder;
use Database\Seeders\RolesSeeder;
use Database\Seeders\UnitsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * توليد العقود من الحجوزات، ورسائل واتساب الخدمية واحتساب المحادثات.
 */
class ContractWhatsappTest extends TestCase
{
    use RefreshDatabase;

    private ContractService $contracts;

    private WhatsappNotifier $whatsapp;

    private Booking $booking;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        $this->seed([RolesSeeder::class, UnitsSeeder::class, BookingSetupSeeder::class, AccountsSeeder::class, ContractTemplateSeeder::class]);

        $this->contracts = app(ContractService::class);
        $this->whatsapp = app(WhatsappNotifier::class);

        $client = Client::create(['name' => 'خالد المطيري', 'mobile' => '0551234567']);

        $this->booking = app(BookingService::class)->create([
            'unit_id' => $this->chaletLetWhole('CH-BSR1')->id,
            'client_id' => $client->id,
            'scope' => 'whole',
            'booking_date' => '2026-09-10',
            'period' => 'full_day',
            'status' => 'deposit_paid',
            'guests_count' => 30,
        ]);
    }

    public function test_contract_is_generated_with_placeholders_filled(): void
    {
        $contract = $this->contracts->generate($this->booking);

        $this->assertSame('draft', $contract->status);
        $this->assertStringStartsWith('CT-'.now()->year.'-', $contract->number);

        // لا يبقى أي حقل غير مملوء في النص النهائي
        $this->assertStringNotContainsString('{{', $contract->body);
        $this->assertStringContainsString('خالد المطيري', $contract->body);
        $this->assertStringContainsString($this->booking->reference, $contract->body);
        $this->assertStringContainsString('شاليه البصرة', $contract->body);
        $this->assertStringContainsString('الوحدة كاملة', $contract->body);
    }

    public function test_contract_body_is_frozen_against_later_template_edits(): void
    {
        $contract = $this->contracts->generate($this->booking);
        $original = $contract->body;

        ContractTemplate::first()->update(['body' => 'نص مختلف تمامًا']);

        // العقد الصادر لا يتغيّر بتعديل القالب — هذا شرط سلامة تعاقدية
        $this->assertSame($original, $contract->fresh()->body);
    }

    public function test_section_booking_lists_the_booked_sections_in_the_contract(): void
    {
        $unit = Unit::where('code', 'CH-LULU')->firstOrFail();
        $men = $unit->sections()->where('gender', 'men')->firstOrFail();

        $booking = app(BookingService::class)->create([
            'unit_id' => $unit->id,
            'client_id' => $this->booking->client_id,
            'scope' => 'sections',
            'section_ids' => [$men->id],
            'booking_date' => '2026-09-12',
            'period' => 'evening',
            'status' => 'deposit_paid',
        ]);

        $contract = $this->contracts->generate($booking);

        $this->assertStringContainsString('قسم الرجال', $contract->body);
        $this->assertStringNotContainsString('الوحدة كاملة', $contract->body);
    }

    public function test_unknown_placeholder_is_left_visible_not_silently_dropped(): void
    {
        $rendered = $this->contracts->render(
            'العميل {{client_name}} والحقل {{does_not_exist}}',
            ['client_name' => 'سعد'],
        );

        $this->assertSame('العميل سعد والحقل {{does_not_exist}}', $rendered);
    }

    public function test_contract_numbers_are_sequential(): void
    {
        // الحجز يُولّد عقده تلقائيًا عند إنشائه، فالترقيم هنا يُقاس بالتتابع
        // لا بالبدء من الواحد.
        $first = $this->booking->contracts()->value('number');
        $a = $this->contracts->generate($this->booking);
        $b = $this->contracts->generate($this->booking);

        $year = now()->year;
        $this->assertSame("CT-{$year}-0001", $first);
        $this->assertSame("CT-{$year}-0002", $a->number);
        $this->assertSame("CT-{$year}-0003", $b->number);
    }

    public function test_contract_is_generated_automatically_with_the_booking(): void
    {
        $contract = $this->booking->contracts()->first();

        $this->assertNotNull($contract, 'الحجز يجب أن يخرج ومعه عقده.');
        $this->assertSame('draft', $contract->status);
        $this->assertSame($this->booking->client_id, $contract->client_id);

        // العقد يُبنى بعد ربط الأقسام وتسجيل الدفعة، فالنطاق والمبالغ نهائية
        $this->assertStringNotContainsString('{{', $contract->body);
        $this->assertStringContainsString('الوحدة كاملة', $contract->body);
        $this->assertStringContainsString($this->booking->reference, $contract->body);
    }

    public function test_a_missing_template_does_not_break_booking_creation(): void
    {
        ContractTemplate::query()->delete();

        $booking = app(BookingService::class)->create([
            'unit_id' => $this->chaletLetWhole('CH-BSR1')->id,
            'scope' => 'whole',
            'booking_date' => '2026-11-20',
            'period' => 'full_day',
            'status' => 'deposit_paid',
        ]);

        // الحجز هو الأصل: غياب القالب يمنع العقد ولا يمنع الحجز.
        $this->assertDatabaseHas('bookings', ['id' => $booking->id]);
        $this->assertSame(0, $booking->contracts()->count());
    }

    public function test_generating_without_any_template_fails_clearly(): void
    {
        ContractTemplate::query()->delete();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('لا يوجد قالب عقد فعّال');

        $this->contracts->generate($this->booking);
    }

    public function test_booking_confirmation_message_is_logged_and_queued(): void
    {
        $message = $this->whatsapp->bookingConfirmed($this->booking);

        $this->assertNotNull($message);
        $this->assertSame('utility', $message->category);
        $this->assertSame('booking_confirm', $message->purpose);
        $this->assertStringContainsString($this->booking->reference, $message->body);

        Queue::assertPushed(SendWhatsappMessage::class);
    }

    public function test_saudi_mobile_numbers_are_normalized(): void
    {
        $client = Client::create(['name' => 'تجربة', 'mobile' => '0501112222']);
        $booking = app(BookingService::class)->create([
            'unit_id' => $this->chaletLetWhole('CH-BSR2')->id,
            'client_id' => $client->id,
            'scope' => 'whole',
            'booking_date' => '2026-09-20',
            'period' => 'morning',
        ]);

        $message = $this->whatsapp->bookingConfirmed($booking);

        $this->assertSame('966501112222', $message->to_number);
    }

    public function test_no_message_is_created_for_a_client_without_a_mobile(): void
    {
        $client = Client::create(['name' => 'بلا جوال']);
        $booking = app(BookingService::class)->create([
            'unit_id' => $this->chaletLetWhole('CH-MOON')->id,
            'client_id' => $client->id,
            'scope' => 'whole',
            'booking_date' => '2026-09-21',
            'period' => 'morning',
        ]);

        $this->assertNull($this->whatsapp->bookingConfirmed($booking));
        $this->assertSame(0, WhatsappMessage::count());
    }

    public function test_conversations_are_counted_per_number_per_day_not_per_message(): void
    {
        // Meta تسعّر لكل محادثة 24 ساعة لا لكل رسالة (§3.1) — ثلاث رسائل
        // لنفس الرقم في اليوم نفسه محادثة واحدة.
        $this->whatsapp->bookingConfirmed($this->booking);
        $this->whatsapp->bookingReminder($this->booking);
        $this->whatsapp->paymentReceived($this->booking, 500);

        $this->assertSame(3, WhatsappMessage::count());
        $this->assertSame(1, WhatsappMessage::conversationCount());
    }

    public function test_two_different_numbers_count_as_two_conversations(): void
    {
        $other = Client::create(['name' => 'عميل آخر', 'mobile' => '0559998888']);
        $second = app(BookingService::class)->create([
            'unit_id' => $this->chaletLetWhole('CH-FOUR')->id,
            'client_id' => $other->id,
            'scope' => 'whole',
            'booking_date' => '2026-09-22',
            'period' => 'morning',
        ]);

        $this->whatsapp->bookingConfirmed($this->booking);
        $this->whatsapp->bookingConfirmed($second);

        $this->assertSame(2, WhatsappMessage::conversationCount());
    }

    public function test_contract_message_links_back_to_the_contract(): void
    {
        $contract = $this->contracts->generate($this->booking);
        $message = $this->whatsapp->contract($contract);

        $this->assertSame(Contract::class, $message->related_type);
        $this->assertSame($contract->id, $message->related_id);
        $this->assertStringContainsString($contract->number, $message->body);
    }
}
