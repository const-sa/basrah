<?php

namespace App\Console\Commands;

use App\Models\Booking;
use App\Models\Contract;
use App\Models\CostCenter;
use App\Services\Accounting\BookingAccounting;
use App\Services\Accounting\ContractAccounting;
use App\Services\BookingService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Recognize the revenue of work that has been delivered (§الطبقة أ - بند 4).
 *
 * Revenue used to post only when a booking was paid in full, so an event that
 * was held but left a balance never earned anything at all: its money sat in
 * unearned revenue for good and its unit read a loss for a month it worked.
 * Delivery is what earns, not payment — what is past its date posts, and what
 * is still owed becomes a receivable.
 *
 * Contracts are swept the same way: a hall services list is delivered the day
 * its booking runs, and an installation on the handover recorded against it.
 *
 * Dated on the day of delivery, not today, so revenue stays in its own month.
 */
class RecognizeDeliveredRevenue extends Command
{
    protected $signature = 'accounting:recognize-delivered
        {--until= : Recognize what was delivered on or before this date, else today}
        {--pretend : List what would be posted without posting it}';

    protected $description = 'Recognize revenue for bookings and contracts already delivered';

    /** Cost centre name => what is waiting to be recognized on it. */
    private array $byCentre = [];

    private int $posted = 0;

    private int $failed = 0;

    private int $due = 0;

    public function handle(
        BookingAccounting $accounting,
        BookingService $bookings,
        ContractAccounting $contracts,
    ): int {
        $until = $this->option('until') ?: now()->toDateString();

        $this->sweepBookings($accounting, $bookings, $until);
        $this->sweepContracts($contracts, $until);

        if ($this->due === 0) {
            $this->info('Nothing delivered is waiting to be recognized.');

            return self::SUCCESS;
        }

        foreach ($this->byCentre as $centre => $total) {
            $this->line("{$centre}: ".number_format($total, 2));
        }

        $this->info($this->option('pretend')
            ? "Would recognize {$this->due} document(s)."
            : "Recognized {$this->posted} document(s)".($this->failed > 0 ? ", {$this->failed} failed." : '.'));

        return $this->failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function sweepBookings(BookingAccounting $accounting, BookingService $bookings, string $until): void
    {
        Booking::query()
            ->whereIn('status', Booking::BLOCKING_STATUSES)
            ->where('total_amount', '>', 0)
            // ends_at narrows the query; the delivery date decides, so an
            // event that ran past midnight stays in the day it was held.
            ->whereDate('ends_at', '<=', $until)
            ->with(['unit', 'sections'])
            ->orderBy('ends_at')
            ->chunkById(200, function ($chunk) use ($accounting, $bookings, $until) {
                foreach ($chunk as $booking) {
                    if (! $accounting->isDelivered($booking, $until) || $accounting->recognizedEntry($booking)) {
                        continue;
                    }

                    // Paid in full before its day: the payments earned it all.
                    $left = $accounting->leftToEarn($booking);

                    if ($left <= 0) {
                        continue;
                    }

                    $centre = $booking->unit ? CostCenter::forUnit($booking->unit)->name : 'no cost centre';

                    $this->take(
                        $centre,
                        $left,
                        "booking {$booking->reference} — {$accounting->deliveryDate($booking)}",
                        fn () => $bookings->recognizeIfDelivered($booking),
                        "booking {$booking->reference}",
                    );
                }
            });
    }

    private function sweepContracts(ContractAccounting $contracts, string $until): void
    {
        Contract::query()
            ->whereNot('status', 'cancelled')
            ->where(fn ($q) => $q->whereNotNull('completed_at')->orWhereNotNull('booking_id'))
            ->with(['booking.unit', 'quotation.department', 'unit'])
            ->orderBy('id')
            ->chunkById(200, function ($chunk) use ($contracts, $until) {
                foreach ($chunk as $contract) {
                    if ($contracts->refusal($contract) !== null || ! $contracts->isDelivered($contract, $until)) {
                        continue;
                    }

                    $centre = CostCenter::find($contracts->costCenterFor($contract))?->name ?? 'no cost centre';

                    $this->take(
                        $centre,
                        (float) $contract->totalAmount(),
                        "contract {$contract->number} — {$contracts->deliveryDate($contract)}",
                        fn () => $contracts->recognizeRevenue($contract),
                        "contract {$contract->number}",
                    );
                }
            });
    }

    /** Count one document, then post it — unless we were only asked to look. */
    private function take(string $centre, float $amount, string $line, callable $post, string $label): void
    {
        $this->due++;
        $this->byCentre[$centre] = round(($this->byCentre[$centre] ?? 0) + $amount, 2);

        if ($this->option('pretend')) {
            $this->line('  '.$line.' — '.number_format($amount, 2));

            return;
        }

        try {
            $post();
            $this->posted++;
        } catch (Throwable $e) {
            // One document must not take the rest down with it.
            $this->failed++;
            $this->error("Could not recognize {$label}: {$e->getMessage()}");
            report($e);
        }
    }
}
