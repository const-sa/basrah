<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Booking;
use App\Models\CostCenter;
use App\Models\JournalEntry;
use App\Models\Treasury;
use App\Models\Unit;
use App\Services\Accounting\Ledger;
use App\Services\Accounting\VoucherService;
use App\Services\BookingService;
use Database\Seeders\AccountsSeeder;
use Database\Seeders\BookingSetupSeeder;
use Database\Seeders\RolesSeeder;
use Database\Seeders\UnitsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Tests\TestCase;

/**
 * محرّك القيود، القيود التلقائية من الحجوزات، وربحية مراكز التكلفة.
 */
class AccountingTest extends TestCase
{
    use RefreshDatabase;

    private Ledger $ledger;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        $this->seed([RolesSeeder::class, UnitsSeeder::class, BookingSetupSeeder::class, AccountsSeeder::class]);
        $this->ledger = app(Ledger::class);
    }

    public function test_unbalanced_entry_is_rejected(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('قيد غير متوازن');

        $this->ledger->post('2026-09-01', 'قيد خاطئ', [
            ['account' => Ledger::CASH, 'debit' => 100],
            ['account' => Ledger::SALES_REVENUE, 'credit' => 90],
        ]);
    }

    public function test_balanced_entry_posts_and_updates_account_balance(): void
    {
        $entry = $this->ledger->post('2026-09-01', 'إيداع نقدي', [
            ['account' => Ledger::CASH, 'debit' => 1000],
            ['account' => '3100', 'credit' => 1000],
        ]);

        $this->assertSame('posted', $entry->status);
        $this->assertTrue($entry->isBalanced());
        $this->assertCount(2, $entry->lines);
        $this->assertSame(1000.0, Account::where('code', Ledger::CASH)->first()->balance());
    }

    public function test_draft_entries_do_not_affect_balances(): void
    {
        JournalEntry::create([
            'number' => 'JV-2026-999999',
            'entry_date' => '2026-09-01',
            'description' => 'مسوّدة',
            'status' => 'draft',
            'source' => 'manual',
            'total_debit' => 500,
            'total_credit' => 500,
        ])->lines()->createMany([
            ['account_id' => Account::where('code', Ledger::CASH)->value('id'), 'debit' => 500, 'credit' => 0],
            ['account_id' => Account::where('code', '3100')->value('id'), 'debit' => 0, 'credit' => 500],
        ]);

        $this->assertSame(0.0, Account::where('code', Ledger::CASH)->first()->balance());
    }

    public function test_reversing_an_entry_zeroes_the_balance_and_keeps_the_original(): void
    {
        $entry = $this->ledger->post('2026-09-01', 'قيد للعكس', [
            ['account' => Ledger::CASH, 'debit' => 700],
            ['account' => '3100', 'credit' => 700],
        ]);

        $reversal = $this->ledger->reverse($entry, 'خطأ إدخال');

        $this->assertSame('reversed', $entry->fresh()->status);
        $this->assertSame($reversal->id, $entry->fresh()->reversed_by_entry_id);
        $this->assertSame(0.0, Account::where('code', Ledger::CASH)->first()->balance());
    }

    public function test_an_entry_cannot_be_reversed_twice(): void
    {
        $entry = $this->ledger->post('2026-09-01', 'قيد', [
            ['account' => Ledger::CASH, 'debit' => 100],
            ['account' => '3100', 'credit' => 100],
        ]);

        $this->ledger->reverse($entry);

        $this->expectException(RuntimeException::class);
        $this->ledger->reverse($entry->fresh());
    }

    public function test_booking_deposit_is_booked_as_a_liability_not_revenue(): void
    {
        $booking = $this->makeBooking();

        app(BookingService::class)->recordPayment($booking, [
            'type' => 'deposit',
            'payment_method_id' => $this->paymentMethodId('cash'),
            'amount' => 650,
        ]);

        // العربون التزام (إيراد غير مكتسب) لأن الخدمة لم تُقدَّم بعد.
        $this->assertSame(650.0, Account::where('code', Ledger::UNEARNED_REVENUE)->first()->balance());
        $this->assertSame(650.0, Account::where('code', Ledger::CASH)->first()->balance());
        $this->assertSame(0.0, Account::where('code', Ledger::BOOKING_REVENUE)->first()->balance());
        $this->assertSame(650.0, (float) $booking->fresh()->paid_amount);
    }

    public function test_checking_out_a_booking_recognizes_revenue_and_clears_the_liability(): void
    {
        $booking = $this->makeBooking();
        $service = app(BookingService::class);

        $service->recordPayment($booking, ['type' => 'deposit', 'payment_method_id' => $this->paymentMethodId('cash'), 'amount' => 650]);
        $service->settleInFull($booking->fresh());

        $total = (float) $booking->fresh()->total_amount;

        $this->assertSame($total, Account::where('code', Ledger::BOOKING_REVENUE)->first()->balance());
        $this->assertSame(0.0, Account::where('code', Ledger::UNEARNED_REVENUE)->first()->balance());
        // المتبقي يصير ذمة على العميل
        $this->assertSame(round($total - 650, 2), Account::where('code', Ledger::RECEIVABLES)->first()->balance());
    }

    public function test_revenue_is_never_recognized_twice_for_one_booking(): void
    {
        $booking = $this->makeBooking();
        $service = app(BookingService::class);

        $service->settleInFull($booking);
        $service->settleInFull($booking->fresh());

        $entries = JournalEntry::where('source', 'booking')->where('reference_id', $booking->id)->count();
        $this->assertSame(1, $entries);
    }

    public function test_refund_reduces_the_paid_amount_and_the_liability(): void
    {
        $booking = $this->makeBooking();
        $service = app(BookingService::class);

        $service->recordPayment($booking, ['type' => 'deposit', 'payment_method_id' => $this->paymentMethodId('cash'), 'amount' => 1000]);
        $service->recordPayment($booking->fresh(), ['type' => 'refund', 'payment_method_id' => $this->paymentMethodId('cash'), 'amount' => 400]);

        $this->assertSame(600.0, (float) $booking->fresh()->paid_amount);
        $this->assertSame(600.0, Account::where('code', Ledger::UNEARNED_REVENUE)->first()->balance());
    }

    public function test_refund_cannot_exceed_what_was_paid(): void
    {
        $booking = $this->makeBooking();
        $service = app(BookingService::class);

        $service->recordPayment($booking, ['type' => 'deposit', 'payment_method_id' => $this->paymentMethodId('cash'), 'amount' => 200]);

        $this->expectException(ValidationException::class);
        $service->recordPayment($booking->fresh(), ['type' => 'refund', 'payment_method_id' => $this->paymentMethodId('cash'), 'amount' => 500]);
    }

    public function test_each_unit_has_its_own_cost_center_and_profitability(): void
    {
        $unitA = Unit::where('code', 'HALL-01')->firstOrFail();
        $unitB = Unit::where('code', 'HALL-02')->firstOrFail();

        $service = app(BookingService::class);

        foreach ([[$unitA, '2026-09-10'], [$unitB, '2026-09-11']] as [$unit, $date]) {
            $booking = $service->create([
                'unit_id' => $unit->id,
                'scope' => 'whole',
                'booking_date' => $date,
                'period' => 'full_day',
                'status' => 'deposit_paid',
            ]);
            $service->settleInFull($booking);
        }

        $profitA = CostCenter::forUnit($unitA)->profitability();
        $profitB = CostCenter::forUnit($unitB)->profitability();

        $this->assertGreaterThan(0, $profitA['revenue']);
        $this->assertGreaterThan(0, $profitB['revenue']);
        // إيراد الوحدتين منفصل — هذا هو المقصود من مركز التكلفة لكل وحدة
        $this->assertNotSame($profitA['revenue'], $profitB['revenue']);
    }

    public function test_posting_a_receipt_voucher_moves_the_treasury_balance(): void
    {
        $treasury = Treasury::where('type', 'cash')->firstOrFail();
        $service = app(VoucherService::class);

        $voucher = $service->create([
            'type' => 'receipt',
            'voucher_date' => '2026-09-05',
            'amount' => 1200,
            'treasury_id' => $treasury->id,
            'account_id' => Account::where('code', Ledger::BOOKING_REVENUE)->value('id'),
            'payment_method_id' => $this->paymentMethodId('cash'),
            'description' => 'قبض نقدي',
        ]);

        $this->assertSame('draft', $voucher->status);
        $this->assertSame(0.0, $treasury->fresh()->balance());

        $service->post($voucher);

        $this->assertSame('posted', $voucher->fresh()->status);
        $this->assertSame(1200.0, $treasury->fresh()->balance());
        $this->assertSame(1200.0, Account::where('code', Ledger::CASH)->first()->balance());
    }

    public function test_cancelling_a_posted_voucher_reverses_its_entry(): void
    {
        $treasury = Treasury::where('type', 'cash')->firstOrFail();
        $service = app(VoucherService::class);

        $voucher = $service->create([
            'type' => 'expense',
            'voucher_date' => '2026-09-06',
            'amount' => 500,
            'treasury_id' => $treasury->id,
            'account_id' => Account::where('code', '5320')->value('id'),
        ]);

        $service->post($voucher);
        $this->assertSame(-500.0, $treasury->fresh()->balance());

        $service->cancel($voucher->fresh(), 'سند خاطئ');

        $this->assertSame('cancelled', $voucher->fresh()->status);
        $this->assertSame(0.0, Account::where('code', Ledger::CASH)->first()->balance());
    }

    private function makeBooking(): Booking
    {
        return app(BookingService::class)->create([
            'unit_id' => Unit::where('code', 'HALL-01')->value('id'),
            'scope' => 'whole',
            'booking_date' => '2026-09-10',
            'period' => 'full_day',
            'status' => 'deposit_paid',
        ]);
    }
}
