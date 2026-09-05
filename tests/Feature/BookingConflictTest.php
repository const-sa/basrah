<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Client;
use App\Models\Unit;
use App\Services\BookingService;
use Database\Seeders\RolesSeeder;
use Database\Seeders\UnitsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * اختبارات منع التعارض الهرمي وقاعدة الخصوصية (§1.1).
 */
class BookingConflictTest extends TestCase
{
    use RefreshDatabase;

    private BookingService $service;

    private Unit $unit;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([RolesSeeder::class, UnitsSeeder::class]);
        $this->service = app(BookingService::class);
        // A hall, not a chalet: these are the whole-versus-section rules, and
        // a chalet no longer holds both — it is let by the room when it has
        // rooms and whole when it has none (Unit::allowsWholeBooking).
        $this->unit = Unit::where('code', 'HALL-02')->firstOrFail();
    }

    private function client(string $name): Client
    {
        return Client::create(['name' => $name, 'mobile' => '05'.random_int(10000000, 99999999)]);
    }

    /** @param list<int> $sectionIds */
    private function book(string $scope, array $sectionIds = [], ?int $clientId = null, string $date = '2026-09-10', string $period = 'full_day'): Booking
    {
        return $this->service->create([
            'unit_id' => $this->unit->id,
            'client_id' => $clientId,
            'scope' => $scope,
            'section_ids' => $sectionIds,
            'booking_date' => $date,
            'period' => $period,
            'status' => 'deposit_paid',
        ]);
    }

    public function test_whole_unit_booking_locks_all_its_sections(): void
    {
        $this->book('whole');

        $this->expectException(ValidationException::class);
        $this->book('sections', [$this->unit->sections()->first()->id]);
    }

    public function test_booked_section_prevents_booking_the_whole_unit(): void
    {
        $men = $this->unit->sections()->where('gender', 'men')->firstOrFail();
        $this->book('sections', [$men->id]);

        try {
            $this->book('whole');
            $this->fail('كان يجب رفض حجز الوحدة كاملة.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('أقسام محجوزة', $e->errors()['availability'][0]);
        }
    }

    public function test_same_section_cannot_be_double_booked(): void
    {
        $men = $this->unit->sections()->where('gender', 'men')->firstOrFail();
        $this->book('sections', [$men->id]);

        $this->expectException(ValidationException::class);
        $this->book('sections', [$men->id]);
    }

    public function test_exclusive_unit_blocks_other_client_from_the_second_section(): void
    {
        $this->unit->update(['privacy_mode' => 'exclusive']);
        $men = $this->unit->sections()->where('gender', 'men')->firstOrFail();
        $women = $this->unit->sections()->where('gender', 'women')->firstOrFail();

        $this->book('sections', [$women->id], $this->client('عائلة أ')->id);

        try {
            $this->book('sections', [$men->id], $this->client('عميل ب')->id);
            $this->fail('كان يجب رفض الحجز بسبب قاعدة الخصوصية.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('خصوصية الوحدة', $e->errors()['availability'][0]);
        }
    }

    public function test_exclusive_unit_still_allows_the_same_client_to_take_both_sections(): void
    {
        $this->unit->update(['privacy_mode' => 'exclusive']);
        $family = $this->client('عائلة واحدة');
        $men = $this->unit->sections()->where('gender', 'men')->firstOrFail();
        $women = $this->unit->sections()->where('gender', 'women')->firstOrFail();

        $this->book('sections', [$women->id], $family->id);
        $second = $this->book('sections', [$men->id], $family->id);

        $this->assertSame('sections', $second->scope);
        $this->assertCount(2, Booking::where('unit_id', $this->unit->id)->get());
    }

    public function test_open_unit_allows_two_different_clients_in_separate_sections(): void
    {
        $this->unit->update(['privacy_mode' => 'open']);
        $men = $this->unit->sections()->where('gender', 'men')->firstOrFail();
        $women = $this->unit->sections()->where('gender', 'women')->firstOrFail();

        $this->book('sections', [$women->id], $this->client('عميل أ')->id);
        $second = $this->book('sections', [$men->id], $this->client('عميل ب')->id);

        $this->assertNotNull($second->id);
    }

    public function test_cancelled_booking_frees_the_slot(): void
    {
        $booking = $this->book('whole');
        $this->service->cancel($booking, 'اعتذر العميل');

        $again = $this->book('whole');

        $this->assertSame('deposit_paid', $again->status);
        $this->assertSame('cancelled', $booking->fresh()->status);
    }

    public function test_postponed_booking_frees_the_slot(): void
    {
        $booking = $this->book('whole');
        $this->service->postpone($booking, 'أجّل العميل المناسبة');

        $again = $this->book('whole');

        $this->assertSame('deposit_paid', $again->status);
        $this->assertSame('postponed', $booking->fresh()->status);
    }

    /**
     * «مدفوع العربون» يحجز التاريخ كالمسدَّد كاملًا، وإلا بيع اليوم مرتين
     * قبل اكتمال المبلغ.
     */
    public function test_booking_paid_only_a_deposit_still_blocks_the_slot(): void
    {
        $this->service->create([
            'unit_id' => $this->unit->id,
            'scope' => 'whole',
            'booking_date' => '2026-09-10',
            'period' => 'full_day',
            'status' => 'deposit_paid',
        ]);

        $this->expectException(ValidationException::class);
        $this->book('whole');
    }

    /**
     * اكتمال المبلغ لا يحرّر الفترة — الحجز قائم وقد سُدِّد، لا مُلغى.
     */
    public function test_fully_settled_booking_still_blocks_the_slot(): void
    {
        $booking = $this->book('whole');
        $this->service->settleInFull($booking);

        $this->assertTrue($booking->fresh()->isBlocking());

        $this->expectException(ValidationException::class);
        $this->book('whole');
    }

    public function test_morning_and_evening_on_same_day_do_not_conflict(): void
    {
        $this->book('whole', period: 'morning');
        $evening = $this->book('whole', period: 'evening');

        $this->assertSame('evening', $evening->period);
    }

    public function test_full_day_conflicts_with_morning_on_same_day(): void
    {
        $this->book('whole', period: 'morning');

        $this->expectException(ValidationException::class);
        $this->book('whole', period: 'full_day');
    }

    public function test_different_days_never_conflict(): void
    {
        $this->book('whole', date: '2026-09-10', period: 'morning');
        $next = $this->book('whole', date: '2026-09-11', period: 'morning');

        $this->assertNotNull($next->id);
    }

    public function test_editing_a_booking_ignores_its_own_slot(): void
    {
        $booking = $this->book('whole', date: '2026-09-10', period: 'full_day');

        $updated = $this->service->update($booking, [
            'unit_id' => $this->unit->id,
            'scope' => 'whole',
            'booking_date' => '2026-09-10',
            'period' => 'full_day',
            'guests_count' => 40,
        ]);

        $this->assertSame(40, $updated->guests_count);
    }

    public function test_booking_reference_is_sequential_and_unique(): void
    {
        $a = $this->book('whole', date: '2026-09-10');
        $b = $this->book('whole', date: '2026-09-12');

        $this->assertSame('a-1', $a->reference);
        $this->assertSame('a-2', $b->reference);
    }

    /**
     * العدّاد لا يُصفَّر بدخول سنة جديدة: العمود فريد، وتصفيره يعيد
     * إصدار رقمٍ خرج على عقد العام الماضي فيصطدم بالقيد.
     */
    public function test_the_reference_counter_does_not_reset_with_the_year(): void
    {
        $a = $this->book('whole', date: '2026-09-10');

        $this->travel(2)->years();

        $b = $this->book('whole', date: now()->addMonth()->toDateString());

        $this->assertSame('a-1', $a->reference);
        $this->assertSame('a-2', $b->reference);
    }

    /**
     * الرقم العاشر أطول نصًّا من التاسع، والترتيب النصّي وحده يجعل a-9
     * أكبر من a-10 فيتكرر الرقم. المولّد يرتّب بالطول أولًا لهذا.
     */
    public function test_the_counter_passes_nine_without_repeating(): void
    {
        $last = null;

        for ($i = 1; $i <= 11; $i++) {
            $last = $this->book('whole', date: '2026-09-'.str_pad((string) (9 + $i), 2, '0', STR_PAD_LEFT));
        }

        $this->assertSame('a-11', $last->reference);
        $this->assertSame(11, Booking::whereIn('reference', ['a-10', 'a-11'])->count() + 9);
    }

    /* ── الحجز في الماضي ───────────────────────────────────────────────── */

    public function test_a_booking_in_a_past_date_is_rejected(): void
    {
        try {
            $this->book('whole', date: now()->subDay()->toDateString());
            $this->fail('كان يجب رفض الحجز في تاريخ مضى.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('تاريخ مضى', $e->errors()['availability'][0]);
        }
    }

    public function test_today_is_still_bookable(): void
    {
        // الماضي هو ما قبل اليوم لا ما قبل اللحظة: الحجز صباحًا لمساء اليوم
        // نفسه أمر يومي في القاعات، ورفضه يمنع بيعًا قائمًا.
        $booking = $this->book('whole', date: now()->toDateString());

        $this->assertNotNull($booking->id);
    }

    public function test_a_past_booking_can_still_be_edited_on_its_own_date(): void
    {
        // الحجز يُنشأ حين كان تاريخه مستقبلًا، ثم يمضي اليوم.
        $booking = $this->book('whole', date: now()->addDay()->toDateString());
        $date = $booking->booking_date->toDateString();

        $this->travel(3)->days();

        $updated = $this->service->update($booking, [
            'unit_id' => $this->unit->id,
            'scope' => 'whole',
            'booking_date' => $date,
            'period' => 'full_day',
            'guests_count' => 90,
        ]);

        $this->assertSame(90, $updated->guests_count);
    }

    public function test_an_existing_booking_cannot_be_moved_into_the_past(): void
    {
        $booking = $this->book('whole', date: '2026-09-10');

        $this->expectException(ValidationException::class);

        $this->service->update($booking, [
            'unit_id' => $this->unit->id,
            'scope' => 'whole',
            'booking_date' => now()->subWeek()->toDateString(),
            'period' => 'full_day',
        ]);
    }
}
