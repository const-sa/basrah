<?php

namespace App\Services\Accounting;

use App\Models\Booking;
use App\Models\BookingPayment;
use App\Models\CostCenter;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Support\Accounting\SectionShares;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * القيود التلقائية من نظام الحجوزات (§الطبقة أ - بند 4).
 *
 * المعالجة المحاسبية المتبعة:
 *  - العربون عند استلامه = التزام (إيراد غير مكتسب) لا إيراد،
 *    لأن الخدمة لم تُقدَّم بعد. إثباته إيرادًا فورًا يضخّم دخل الشهر
 *    ويُنقص دخل شهر التنفيذ.
 *  - عند اكتمال الحجز يُعترف بالإيراد ويُقفل الالتزام.
 */
class BookingAccounting
{
    public function __construct(
        private readonly Ledger $ledger,
        private readonly BookingReceipts $receipts,
        private readonly RevenueAccounts $revenueAccounts,
        private readonly PaymentMethodAccounts $paymentMethodAccounts,
    ) {}

    /**
     * قيد استلام دفعة على حجز.
     * مدين: الخزينة — دائن: إيراد غير مكتسب.
     */
    public function recordPayment(BookingPayment $payment, ?int $userId = null): JournalEntry
    {
        $booking = $payment->booking()->with('unit')->firstOrFail();
        $costCenter = $this->costCenter($booking);

        if ($payment->isSecurity()) {
            return $this->recordSecurityMovement($payment, $booking, $costCenter, $userId);
        }

        $treasuryAccount = $this->treasuryAccount($payment, $booking);

        $amount = (float) $payment->amount;
        $isRefund = $payment->type === 'refund';

        // The cash is the hall's; the deposit is held for the rooms let, so a
        // men's-side booking still ahead of its date reads on «قسم الرجال».
        $lines = $isRefund
            ? [
                ...$this->heldLines($booking, $amount, 'debit'),
                ['account' => $treasuryAccount, 'credit' => $amount, 'cost_center_id' => $costCenter],
            ]
            : [
                ['account' => $treasuryAccount, 'debit' => $amount, 'cost_center_id' => $costCenter],
                ...$this->heldLines($booking, $amount, 'credit'),
            ];

        $label = $isRefund ? 'استرداد' : ($payment->type === 'deposit' ? 'عربون' : 'دفعة');

        $entry = $this->ledger->post(
            $payment->paid_on->toDateString(),
            "{$label} على الحجز {$booking->reference}",
            $lines,
            'payment',
            $payment,
            $userId,
        );

        $this->receipts->mirror($payment, $booking, $entry, $costCenter, $userId);

        return $entry;
    }

    /**
     * A security deposit taken, given back, or kept against damage.
     *
     * None of the three touches unearned revenue: the money is held on the
     * guest's behalf until it goes back to them. Only a forfeit crosses into
     * revenue, and it moves no cash — nothing leaves the till, the claim on it
     * simply ends.
     *
     *  - taken:    Dr treasury      / Cr deposits held
     *  - refunded: Dr deposits held / Cr treasury
     *  - forfeit:  Dr deposits held / Cr booking revenue
     */
    private function recordSecurityMovement(
        BookingPayment $payment,
        Booking $booking,
        ?int $costCenter,
        ?int $userId,
    ): JournalEntry {
        $amount = (float) $payment->amount;

        $lines = match ($payment->type) {
            'security_deposit' => [
                ['account' => $this->treasuryAccount($payment, $booking), 'debit' => $amount, 'cost_center_id' => $costCenter],
                ['account' => Ledger::REFUNDABLE_DEPOSITS, 'credit' => $amount, 'cost_center_id' => $costCenter],
            ],
            'security_refund' => [
                ['account' => Ledger::REFUNDABLE_DEPOSITS, 'debit' => $amount, 'cost_center_id' => $costCenter],
                ['account' => $this->treasuryAccount($payment, $booking), 'credit' => $amount, 'cost_center_id' => $costCenter],
            ],
            default => [
                ['account' => Ledger::REFUNDABLE_DEPOSITS, 'debit' => $amount, 'cost_center_id' => $costCenter],
                // A kept deposit is its own kind of earning, and the operator
                // may want it read apart from the night that was let.
                ...$this->revenueLines($booking, $amount, $costCenter, $this->revenueAccounts->idFor('security_forfeit')),
            ],
        };

        $label = BookingPayment::TYPES[$payment->type] ?? $payment->type;

        $entry = $this->ledger->post(
            $payment->paid_on->toDateString(),
            "{$label} على الحجز {$booking->reference}",
            $lines,
            'payment',
            $payment,
            $userId,
        );

        $this->receipts->mirror($payment, $booking, $entry, $costCenter, $userId);

        return $entry;
    }

    /**
     * Revenue recognized when the service is delivered.
     * Dr unearned revenue (what was collected) + receivables (the rest),
     * Cr booking revenue (the total).
     *
     * Dated on the day it was served, not today: an event held last month
     * earns in that month however late the entry is written.
     */
    public function recognizeRevenue(Booking $booking, ?int $userId = null, ?string $date = null): ?JournalEntry
    {
        $total = (float) $booking->total_amount;

        if ($total <= 0) {
            return null;
        }

        // Never recognized twice for one booking.
        if ($this->recognizedEntry($booking)) {
            return null;
        }

        return $this->postRevenue($booking, $date ?? $this->deliveryDate($booking), $userId);
    }

    /**
     * The revenue this booking is recognized by right now — the standing entry.
     *
     * A reversed entry does not count. Every path that reverses one either
     * takes the booking out of play (a cancellation, a postponement — neither
     * is swept again) or reposts at once (a revision). The one that does not
     * is revenue posted before the event: it is reversed so the booking can
     * earn on its date, and counting the reversal would have kept it from ever
     * earning at all.
     */
    public function recognizedEntry(Booking $booking): ?JournalEntry
    {
        return $this->standingEntry($booking);
    }

    /** The entry that still stands, the one a revision or a cancellation reverses. */
    public function standingEntry(Booking $booking): ?JournalEntry
    {
        return $this->revenueEntries($booking)->where('status', 'posted')->latest('id')->first();
    }

    /**
     * The day the booking was served — the month its revenue belongs to.
     *
     * A stay ends when the guest leaves; everything else ends on its last day,
     * which keeps an evening that runs past midnight in the day it was held.
     */
    public function deliveryDate(Booking $booking): string
    {
        return $booking->isStay() ? $booking->checkOutDate() : $booking->lastDayDate();
    }

    /**
     * Undo a cancelled booking's revenue with a counter-entry, not by deleting it.
     *
     * Without this the hall keeps earning an event it never held, and the
     * client keeps owing a balance they no longer owe.
     */
    public function reverseRevenue(Booking $booking, string $reason, ?int $userId = null): ?JournalEntry
    {
        $entry = $this->standingEntry($booking);

        return $entry ? $this->ledger->reverse($entry, $reason, $userId) : null;
    }

    /** Has the service been delivered? */
    public function isDelivered(Booking $booking, ?string $asOf = null): bool
    {
        return $this->deliveryDate($booking) <= ($asOf ?? now()->toDateString());
    }

    private function revenueEntries(Booking $booking): Builder
    {
        return JournalEntry::where('source', 'booking')
            ->where('reference_type', Booking::class)
            ->where('reference_id', $booking->id);
    }

    /**
     * إعادة إثبات إيراد حجزٍ مقفَل تغيّر مبلغه أو ما سُدِّد عليه بعد إقفاله.
     *
     * قيد الإيراد يُكتب مرة عند الإقفال، فإذا رُفع إجمالي الحجز بعدها بقي
     * الإيراد على المبلغ القديم وعلق الفرق في العرابين غير المكتسبة (حجز a-51:
     * 9,000 مسدَّدة و8,000 إيراد). فيُعكس القيد القديم ويُكتب بديله بتاريخه
     * نفسه، كي لا ينتقل إيراد الحجز إلى شهرٍ غير شهره.
     */
    public function reviseRevenue(Booking $booking, ?int $userId = null): ?JournalEntry
    {
        $entry = $this->standingEntry($booking)?->load('lines');

        if (! $entry) {
            return null;
        }

        // Revenue posted before the event — the old rule earned a booking the
        // day it was paid in full, and a date can be moved later. It is held
        // again until its day comes, when the sweep recognizes it.
        if (! $this->isDelivered($booking)) {
            $this->ledger->reverse($entry, "الحجز {$booking->reference} لم يُقدَّم بعد", $userId);

            return null;
        }

        $expected = (float) $booking->total_amount > 0 ? $this->revenueEntryLines($booking) : [];

        if ($this->signature($entry->lines->map(fn ($l) => [
            'account' => $l->account_id,
            'debit' => (float) $l->debit,
            'credit' => (float) $l->credit,
            'cost_center_id' => $l->cost_center_id,
        ])->all()) === $this->signature($expected)) {
            return null;
        }

        $date = $entry->entry_date->toDateString();
        $this->ledger->reverse($entry, "تعديل الحجز {$booking->reference}", $userId);

        return $expected ? $this->postRevenue($booking, $date, $userId) : null;
    }

    private function postRevenue(Booking $booking, string $date, ?int $userId): JournalEntry
    {
        return $this->ledger->post(
            $date,
            "إثبات إيراد الحجز {$booking->reference}",
            $this->revenueEntryLines($booking),
            'booking',
            $booking,
            $userId,
        );
    }

    /** @return list<array<string, mixed>> */
    private function revenueEntryLines(Booking $booking): array
    {
        $booking->loadMissing('unit');
        $costCenter = $this->costCenter($booking);

        $total = (float) $booking->total_amount;
        $collected = round(min((float) $booking->paid_amount, $total), 2);
        $outstanding = round($total - $collected, 2);

        return [
            ...$this->releaseLines($booking, $collected),
            ['account' => Ledger::RECEIVABLES, 'debit' => $outstanding, 'cost_center_id' => $costCenter],
            ...$this->revenueLines($booking, $total, $costCenter),
        ];
    }

    /**
     * The unearned-revenue debit that clears what was collected, taken from
     * the centres actually holding it.
     *
     * Splitting the total afresh would leave a halala on one room and owe it
     * on the other wherever each payment was split on its own. Reading back
     * what the payments left on each centre clears every one to zero. Only
     * where they do not add up to what is being cleared — more was paid than
     * the booking is worth — is the amount split by the rooms again.
     *
     * @return list<array<string, mixed>>
     */
    private function releaseLines(Booking $booking, float $collected): array
    {
        $held = $this->heldByCentre($booking);

        if ($held === [] || min($held) < 0 || abs(array_sum($held) - $collected) > 0.005) {
            return $this->heldLines($booking, $collected, 'debit');
        }

        $lines = [];

        foreach ($held as $centre => $amount) {
            $lines[] = ['account' => Ledger::UNEARNED_REVENUE, 'debit' => $amount, 'cost_center_id' => $centre ?: null];
        }

        return $lines;
    }

    /**
     * What this booking's payments hold in unearned revenue, centre by centre.
     *
     * @return array<int, float> centre id (0 for none) => balance held
     */
    private function heldByCentre(Booking $booking): array
    {
        $paymentIds = $booking->payments()->whereNotIn('type', BookingPayment::SECURITY_TYPES)->pluck('id');

        if ($paymentIds->isEmpty()) {
            return [];
        }

        return JournalLine::query()
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->where('journal_entries.status', 'posted')
            ->where('journal_entries.reference_type', BookingPayment::class)
            ->whereIn('journal_entries.reference_id', $paymentIds)
            ->where('journal_lines.account_id', $this->ledger->accountId(Ledger::UNEARNED_REVENUE))
            ->groupBy('journal_lines.cost_center_id')
            ->selectRaw('journal_lines.cost_center_id AS centre, SUM(journal_lines.credit) - SUM(journal_lines.debit) AS held')
            ->get()
            ->mapWithKeys(fn ($row) => [(int) $row->centre => round((float) $row->held, 2)])
            ->filter(fn (float $held) => abs($held) >= 0.005)
            ->all();
    }

    /**
     * An amount on unearned revenue, spread over the centres the booking is
     * held on: one line on the unit when it is taken whole, one per room
     * when it is taken by the room, split as its revenue will be.
     *
     * @return list<array<string, mixed>>
     */
    private function heldLines(Booking $booking, float $amount, string $side): array
    {
        $lines = [];

        foreach (SectionShares::split($amount, $this->centres($booking)) as $centre => $share) {
            $lines[] = ['account' => Ledger::UNEARNED_REVENUE, $side => $share, 'cost_center_id' => $centre ?: null];
        }

        return $lines;
    }

    /**
     * The centres a booking's money is held and earned on, each weighted by
     * the price its room was let at.
     *
     * @return array<int, float> centre id (0 for none) => weight
     */
    private function centres(Booking $booking): array
    {
        $booking->loadMissing(['unit', 'sections']);

        if ($booking->scope !== 'sections' || $booking->sections->isEmpty()) {
            return [($this->costCenter($booking) ?? 0) => 1.0];
        }

        $weights = [];

        foreach ($booking->sections->values() as $section) {
            $weights[CostCenter::forSection($section)->id] = (float) ($section->pivot->price ?? 0);
        }

        return $weights;
    }

    /**
     * Move the deposits this booking holds onto the rooms it is let by now.
     *
     * A payment is held on the rooms booked the day it was taken. Change the
     * booking from the men's side to the women's and that money would stay on
     * «قسم الرجال» while the revenue it pays for lands on «قسم النساء» — one
     * room holding a deposit for an event it will never host, the other
     * clearing a deposit it never held.
     *
     * Only the centre moves: no amount, no account, no date. Returns the number
     * of entries rewritten.
     */
    public function realignHeld(Booking $booking): int
    {
        $booking->unsetRelation('sections')->loadMissing(['unit', 'sections']);

        $unearned = $this->ledger->accountId(Ledger::UNEARNED_REVENUE);
        $moved = 0;

        $entries = JournalEntry::query()
            ->where('status', 'posted')
            ->where('reference_type', BookingPayment::class)
            ->whereIn('reference_id', $booking->payments()->whereNotIn('type', BookingPayment::SECURITY_TYPES)->select('id'))
            ->with('lines')
            ->get();

        foreach ($entries as $entry) {
            $lines = $entry->lines->where('account_id', $unearned);
            $credit = round($lines->sum(fn ($l) => (float) $l->credit - (float) $l->debit), 2);

            if (abs($credit) < 0.005) {
                continue;
            }

            $expected = $this->heldLines($booking, abs($credit), $credit > 0 ? 'credit' : 'debit');

            if ($this->rewrite($entry, $lines, $expected, $unearned)) {
                $moved++;
            }
        }

        return $moved;
    }

    /**
     * Put the standing revenue entry's unearned debit on the centres that hold
     * the money, in place — for entries written before deposits were held by
     * the room. Left alone when the amounts no longer agree: that entry is
     * stale, and reviseRevenue() is what replaces it.
     */
    public function realignRecognized(Booking $booking): bool
    {
        $entry = $this->standingEntry($booking)?->load('lines');

        if (! $entry) {
            return false;
        }

        $unearned = $this->ledger->accountId(Ledger::UNEARNED_REVENUE);
        $lines = $entry->lines->where('account_id', $unearned);
        $debit = round($lines->sum(fn ($l) => (float) $l->debit - (float) $l->credit), 2);

        if ($debit <= 0) {
            return false;
        }

        return $this->rewrite($entry, $lines, $this->releaseLines($booking, $debit), $unearned);
    }

    /**
     * Replace one account's lines on an entry with the expected ones, unless
     * they already are. Same account, same total — only the centres differ.
     *
     * @param  Collection<int, JournalLine>  $lines
     * @param  list<array<string, mixed>>  $expected
     */
    private function rewrite(JournalEntry $entry, $lines, array $expected, int $accountId): bool
    {
        $current = $lines->map(fn ($l) => [
            'account' => $accountId,
            'debit' => (float) $l->debit,
            'credit' => (float) $l->credit,
            'cost_center_id' => $l->cost_center_id,
        ])->values()->all();

        if ($this->signature($current) === $this->signature($expected)) {
            return false;
        }

        $rows = [];

        foreach ($expected as $line) {
            $debit = round((float) ($line['debit'] ?? 0), 2);
            $credit = round((float) ($line['credit'] ?? 0), 2);

            if ($debit <= 0 && $credit <= 0) {
                continue;
            }

            $rows[] = [
                'journal_entry_id' => $entry->id,
                'account_id' => $accountId,
                'cost_center_id' => $line['cost_center_id'] ?? null,
                'debit' => $debit,
                'credit' => $credit,
                'description' => $lines->first()?->description,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        DB::transaction(function () use ($lines, $rows) {
            JournalLine::whereIn('id', $lines->pluck('id'))->delete();
            JournalLine::insert($rows);
        });

        return true;
    }

    /**
     * The entry's lines as a comparable fingerprint: account codes resolved to
     * ids and zero lines dropped, the way the ledger stores them.
     *
     * @param  list<array<string, mixed>>  $lines
     */
    private function signature(array $lines): string
    {
        $rows = [];

        foreach ($lines as $line) {
            $debit = round((float) ($line['debit'] ?? 0), 2);
            $credit = round((float) ($line['credit'] ?? 0), 2);

            if ($debit <= 0 && $credit <= 0) {
                continue;
            }

            $rows[] = implode('|', [$this->ledger->accountId($line['account']), (int) ($line['cost_center_id'] ?? 0), number_format($debit, 2, '.', ''), number_format($credit, 2, '.', '')]);
        }

        sort($rows);

        return implode(';', $rows);
    }

    /**
     * The till or bank this booking's money moves through.
     *
     * The stream may name its own account — «مقبوض القاعات يودع في البنك
     * الأهلي» — and it then answers for every movement on the booking: a
     * payment in, a refund out, a security deposit held and given back. Money
     * must leave through the door it came in by, or the account it came in on
     * keeps a balance that was long since paid away.
     *
     * Where no account was named, the payment method decides as it always did:
     * cash at the till, everything else at the bank. The method carries its own
     * account, so no translation is left in the code to land on the till by
     * default.
     */
    private function treasuryAccount(BookingPayment $payment, Booking $booking): string|int
    {
        $method = $payment->paymentMethod()->firstOrFail();

        return $this->paymentMethodAccounts->resolveForBooking($booking, $method)
            ?? $this->revenueAccounts->depositForBooking($booking)
            ?? $method->ledgerAccount();
    }

    /**
     * مركز تكلفة الحجز = مركز تكلفة وحدته.
     */
    private function costCenter(Booking $booking): ?int
    {
        return $booking->unit ? CostCenter::forUnit($booking->unit)->id : null;
    }

    /**
     * The revenue credit, carried by the rooms that earned it.
     *
     * A unit taken whole earned it whole, and stays one line on the unit. A
     * unit taken by the room is a different sale in each room, and one line
     * on the unit makes «كم دخل من شاليه ٢» unanswerable — the figure exists
     * in the books but not separably. So the credit is apportioned by the
     * price each room was let at, frozen on the booking the day it was made,
     * which is the only per-room number the booking actually holds: the
     * total also carries addons, a discount and tax, and none of those are
     * recorded room by room.
     *
     * The split itself is SectionShares: weighted by those prices only when
     * every room carries one, so a room the price list forgot still gets its
     * share instead of dropping out of the books while its neighbour takes
     * the lot. The last room takes the remainder, so the credit adds up exactly.
     *
     * The account is the one the operator has put this activity's income on —
     * halls and chalets may be kept apart, or share one line as they always
     * have. Which room earned it and which account it lands on are separate
     * questions, so the split below is unchanged by the choice.
     *
     * @return list<array<string, mixed>>
     */
    private function revenueLines(Booking $booking, float $amount, ?int $unitCenter, ?int $account = null): array
    {
        $account ??= $this->revenueAccounts->forBooking($booking);

        $booking->loadMissing('sections');
        $sections = $booking->sections;

        if ($booking->scope !== 'sections' || $sections->isEmpty()) {
            return [['account' => $account, 'credit' => $amount, 'cost_center_id' => $unitCenter]];
        }

        $ordered = $sections->values();
        $shares = SectionShares::split($amount, $ordered->map(fn ($s) => (float) ($s->pivot->price ?? 0))->all());

        $lines = [];

        foreach ($ordered as $i => $section) {
            $lines[] = [
                'account' => $account,
                'credit' => $shares[$i],
                'cost_center_id' => CostCenter::forSection($section)->id,
                'description' => $section->name,
            ];
        }

        return $lines;
    }
}
