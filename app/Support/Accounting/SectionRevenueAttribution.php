<?php

namespace App\Support\Accounting;

use App\Models\Account;
use App\Models\Booking;
use App\Models\CostCenter;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Services\Accounting\Ledger;
use Illuminate\Support\Facades\DB;

/**
 * Revenue already posted for a unit let by the room, moved onto the rooms.
 *
 * Booking revenue has always been credited to the unit's cost centre in one
 * line. From now on a booking by rooms credits each room, but everything posted
 * before that stays on the chalet — so the screen would answer «كم دخل من شاليه
 * ٢» only for stays sold after the change, and the months the operator actually
 * wants to look at would read as they always did.
 *
 * This re-attributes them. It is deliberately narrow: one credit line on the
 * booking-revenue account is replaced by one line per room, splitting the same
 * amount by the price each room was let at. No account changes, no entry gains
 * or loses a riyal, no balance moves — only which centre carries a figure that
 * was already there. The debits are left where they are: receivables and
 * unearned revenue are claims on the guest, not earnings of a room.
 */
class SectionRevenueAttribution
{
    /**
     * @return array{entries: int, lines: int}
     */
    public function apply(): array
    {
        $revenueAccountId = Account::where('code', Ledger::BOOKING_REVENUE)->value('id');

        if (! $revenueAccountId) {
            return ['entries' => 0, 'lines' => 0];
        }

        $entries = 0;
        $lines = 0;

        JournalEntry::query()
            ->where('reference_type', Booking::class)
            ->whereNotNull('reference_id')
            ->orderBy('id')
            ->chunkById(200, function ($chunk) use ($revenueAccountId, &$entries, &$lines) {
                foreach ($chunk as $entry) {
                    $moved = $this->split($entry, $revenueAccountId);

                    if ($moved > 0) {
                        $entries++;
                        $lines += $moved;
                    }
                }
            });

        return ['entries' => $entries, 'lines' => $lines];
    }

    /**
     * The number of room lines written in place of one unit line, or zero when
     * the entry is left alone.
     */
    private function split(JournalEntry $entry, int $revenueAccountId): int
    {
        $booking = Booking::with('sections')->find($entry->reference_id);

        if (! $booking || $booking->scope !== 'sections' || $booking->sections->isEmpty()) {
            return 0;
        }

        // More than one already means it has been split — running twice must
        // not shred a line into ever smaller pieces.
        $credits = $entry->lines()
            ->where('account_id', $revenueAccountId)
            ->where('credit', '>', 0)
            ->get();

        if ($credits->count() !== 1) {
            return 0;
        }

        /** @var JournalLine $line */
        $line = $credits->first();
        $amount = round((float) $line->credit, 2);

        if ($amount <= 0) {
            return 0;
        }

        $sections = $booking->sections->values();
        $base = round($sections->sum(fn ($s) => (float) ($s->pivot->price ?? 0)), 2);
        $last = $sections->count() - 1;
        $left = $amount;
        $rows = [];

        foreach ($sections as $i => $section) {
            $share = $i === $last
                ? $left
                : round($base > 0 ? $amount * ((float) $section->pivot->price / $base) : $amount / $sections->count(), 2);

            $left = round($left - $share, 2);

            if ($share <= 0) {
                continue;
            }

            $rows[] = [
                'journal_entry_id' => $entry->id,
                'account_id' => $revenueAccountId,
                'cost_center_id' => CostCenter::forSection($section)->id,
                'debit' => 0,
                'credit' => $share,
                'description' => $section->name,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        if ($rows === []) {
            return 0;
        }

        // A stay in a single room still moves — off the chalet and onto the
        // room that was let, which is the whole question being asked. Only a
        // line already sitting on the centre it belongs to is left alone,
        // which is what makes a second run a no-op.
        if (count($rows) === 1 && (int) $rows[0]['cost_center_id'] === (int) $line->cost_center_id) {
            return 0;
        }

        DB::transaction(function () use ($line, $rows) {
            $line->delete();
            JournalLine::insert($rows);
        });

        return count($rows);
    }
}
