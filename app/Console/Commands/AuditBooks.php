<?php

namespace App\Console\Commands;

use App\Models\Account;
use App\Models\Booking;
use App\Models\BookingPayment;
use App\Models\Contract;
use App\Models\CostCenter;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\Voucher;
use App\Services\Accounting\BookingAccounting;
use App\Services\Accounting\ContractAccounting;
use App\Services\Accounting\Ledger;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * Hold every booking and contract up against the ledger, and list what disagrees.
 *
 * The cost centres are read off the ledger and the registers off the documents,
 * so when the two do not agree the operator has no way to tell which one is
 * wrong — and «الحسابات مش مظبوطة» cannot be answered by looking harder at
 * either screen. This reads both, document by document, and names the
 * booking or contract where they part.
 *
 * Read-only: it changes nothing. An error is a figure the books have wrong; a
 * warning is a decision someone still has to take, such as a deposit held on
 * a cancelled booking that was neither refunded nor kept.
 */
class AuditBooks extends Command
{
    protected $signature = 'accounting:audit
        {--errors : List errors only, without the warnings}';

    protected $description = 'Reconcile every booking and contract with the ledger and list the differences';

    /** @var list<array{0: string, 1: string, 2: string, 3: string}> */
    private array $issues = [];

    private int $unearned = 0;

    /** Unearned revenue held by the documents we can name. */
    private float $explained = 0.0;

    public function handle(BookingAccounting $bookings, ContractAccounting $contracts): int
    {
        $this->unearned = (int) Account::where('code', Ledger::UNEARNED_REVENUE)->value('id');

        $this->auditBookings($bookings);
        $this->auditContracts($contracts);
        $this->auditLedger();

        $errors = count(array_filter($this->issues, fn ($i) => $i[0] === 'error'));
        $warnings = count($this->issues) - $errors;
        $shown = $this->option('errors') ? array_filter($this->issues, fn ($i) => $i[0] === 'error') : $this->issues;

        if ($shown !== []) {
            $this->table(['level', 'document', 'check', 'detail'], $shown);
        }

        $this->line("Errors: {$errors}   Warnings: {$warnings}");

        if ($errors === 0) {
            $this->info('Every booking and contract agrees with the ledger.');
        }

        return $errors > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function auditBookings(BookingAccounting $accounting): void
    {
        Booking::query()
            ->with(['unit', 'sections', 'payments'])
            ->orderBy('id')
            ->chunkById(200, function ($chunk) use ($accounting) {
                foreach ($chunk as $booking) {
                    $this->auditBooking($booking, $accounting);
                }
            });
    }

    private function auditBooking(Booking $booking, BookingAccounting $accounting): void
    {
        $doc = "booking {$booking->reference}";
        $money = $booking->payments->reject(fn (BookingPayment $p) => $p->isSecurity());
        $paid = round($money->sum(fn (BookingPayment $p) => $p->type === 'refund' ? -(float) $p->amount : (float) $p->amount), 2);
        $total = round((float) $booking->total_amount, 2);

        if (abs($paid - (float) $booking->paid_amount) > 0.005) {
            $this->flagError($doc, 'paid amount', "booking says {$this->n($booking->paid_amount)}, its payments add up to {$this->n($paid)}");
        }

        foreach ($booking->payments as $payment) {
            $entry = JournalEntry::where('reference_type', BookingPayment::class)
                ->where('reference_id', $payment->id)
                ->where('status', 'posted')
                ->with('lines')
                ->first();

            if (! $entry) {
                $this->flagError($doc, 'payment not posted', "{$payment->type} of {$this->n($payment->amount)} on {$payment->paid_on?->toDateString()} has no entry");

                continue;
            }

            $side = round($entry->lines->sum(fn ($l) => (float) $l->debit), 2);

            if (abs($side - (float) $payment->amount) > 0.005) {
                $this->flagError($doc, 'payment amount', "payment is {$this->n($payment->amount)}, entry {$entry->number} posts {$this->n($side)}");
            }
        }

        $standing = $accounting->standingEntry($booking)?->load('lines.account');
        $held = $this->bookingHeld($booking, $standing);
        $this->explained += $held;

        $blocking = in_array($booking->status, Booking::BLOCKING_STATUSES, true);
        $delivered = $accounting->isDelivered($booking);

        $expected = $standing ? round(max(0, $paid - $total), 2) : $paid;

        if (abs($held - $expected) > 0.005) {
            $this->flagError($doc, 'deposit held', "ledger holds {$this->n($held)}, should hold {$this->n($expected)}");
        }

        if ($standing) {
            $credited = round($standing->lines->filter(fn ($l) => $l->account?->type === 'revenue')->sum(fn ($l) => (float) $l->credit - (float) $l->debit), 2);

            if (abs($credited - $total) > 0.005) {
                $this->flagError($doc, 'revenue amount', "booking is {$this->n($total)}, entry {$standing->number} earns {$this->n($credited)}");
            }

            if (! $delivered) {
                $this->flagError($doc, 'earned too early', "entry {$standing->number} earns it before its date ({$accounting->deliveryDate($booking)})");
            }

            if (! $blocking) {
                $this->flagError($doc, 'earned while '.$booking->status, "entry {$standing->number} still stands");
            }
        } elseif ($blocking && $delivered && $total > 0) {
            $this->flagError($doc, 'not earned', "served on {$accounting->deliveryDate($booking)} and never recognized — run accounting:recognize-delivered");
        }

        if (! $blocking && $held > 0.005) {
            $this->flagWarning($doc, 'money held on '.$booking->status, "{$this->n($held)} is still owed back to the client — refund it, or record it kept");
        }

        if ($booking->scope === 'sections' && $booking->sections->isNotEmpty() && $booking->unit) {
            $unitCentre = CostCenter::forUnit($booking->unit)->id;
            $onUnit = round($this->heldOnCentre($booking, $standing, $unitCentre), 2);

            if (abs($onUnit) > 0.005) {
                $this->flagError($doc, 'deposit on the hall', "{$this->n($onUnit)} sits on the hall, not on the rooms let");
            }
        }
    }

    /** Unearned revenue this booking's standing entries leave behind. */
    private function bookingHeld(Booking $booking, ?JournalEntry $standing): float
    {
        return round($this->bookingUnearnedLines($booking, $standing)->sum(fn ($l) => (float) $l->credit - (float) $l->debit), 2);
    }

    private function heldOnCentre(Booking $booking, ?JournalEntry $standing, int $centre): float
    {
        return $this->bookingUnearnedLines($booking, $standing)
            ->where('cost_center_id', $centre)
            ->sum(fn ($l) => (float) $l->credit - (float) $l->debit);
    }

    /**
     * Posted entries only: a reversed entry and its reversal cancel out, and
     * the reversal carries no reference back to the booking to be found by.
     *
     * @return Collection<int, JournalLine>
     */
    private function bookingUnearnedLines(Booking $booking, ?JournalEntry $standing)
    {
        $payments = JournalLine::query()
            ->where('account_id', $this->unearned)
            ->whereHas('entry', fn ($q) => $q->where('status', 'posted')
                ->where('reference_type', BookingPayment::class)
                ->whereIn('reference_id', $booking->payments->pluck('id')))
            ->get();

        return $standing
            ? $payments->concat($standing->lines->where('account_id', $this->unearned))
            : $payments;
    }

    private function auditContracts(ContractAccounting $accounting): void
    {
        Contract::query()
            ->orderBy('id')
            ->chunkById(200, function ($chunk) use ($accounting) {
                foreach ($chunk as $contract) {
                    if ($contract->takesReceipts()) {
                        $this->auditContract($contract, $accounting);
                    }
                }
            });
    }

    private function auditContract(Contract $contract, ContractAccounting $accounting): void
    {
        $doc = "contract {$contract->number}";
        $vouchers = Voucher::where('contract_id', $contract->id)->where('type', 'receipt')->where('status', 'posted')->get();
        $received = round($vouchers->sum(fn ($v) => (float) $v->amount), 2);

        if (abs($received - $contract->paidAmount()) > 0.005) {
            $this->flagError($doc, 'paid amount', "contract says {$this->n($contract->paidAmount())}, its receipts add up to {$this->n($received)}");
        }

        $entryIds = $vouchers->pluck('journal_entry_id')->filter()->all();
        $entryIds = [...$entryIds, ...JournalEntry::where('status', 'posted')
            ->where('reference_type', Contract::class)
            ->where('reference_id', $contract->id)
            ->pluck('id')->all()];

        $held = round((float) JournalLine::whereIn('journal_entry_id', $entryIds)
            ->whereHas('entry', fn ($q) => $q->where('status', 'posted'))
            ->where('account_id', $this->unearned)
            ->selectRaw('COALESCE(SUM(credit) - SUM(debit), 0) AS held')
            ->value('held'), 2);

        $this->explained += $held;

        if ($contract->isMaintenanceForm()) {
            if ($held > 0.005) {
                $this->flagError($doc, 'maintenance held', "{$this->n($held)} of monthly fees sits in deposits instead of revenue");
            }

            return;
        }

        $recognized = $accounting->recognizedEntry($contract) !== null;
        $invoiced = $accounting->invoiceFor($contract) !== null;

        if ($contract->status === 'cancelled') {
            if ($held > 0.005) {
                $this->flagWarning($doc, 'money held on cancelled', "{$this->n($held)} is still owed back to the client");
            }

            return;
        }

        if ($accounting->isDelivered($contract) && ! $recognized && ! $invoiced && $contract->totalAmount() !== null) {
            $this->flagError($doc, 'not earned', "delivered on {$accounting->deliveryDate($contract)} and never recognized");
        }

        if (! $accounting->isDelivered($contract) && $held > 0.005 && $contract->isInstallationForm()) {
            $this->flagWarning($doc, 'awaiting handover', "{$this->n($held)} held until «تم التنفيذ» is recorded on the contract");
        }
    }

    private function auditLedger(): void
    {
        $unbalanced = JournalEntry::query()
            ->whereIn('status', JournalEntry::EFFECTIVE_STATUSES)
            ->join('journal_lines', 'journal_lines.journal_entry_id', '=', 'journal_entries.id')
            ->groupBy('journal_entries.id', 'journal_entries.number')
            ->havingRaw('ABS(SUM(journal_lines.debit) - SUM(journal_lines.credit)) > 0.005')
            ->pluck('journal_entries.number');

        foreach ($unbalanced as $number) {
            $this->flagError("entry {$number}", 'unbalanced', 'debits and credits differ');
        }

        $orphans = JournalLine::query()
            ->whereNull('journal_lines.cost_center_id')
            ->whereHas('entry', fn ($q) => $q->whereIn('status', JournalEntry::EFFECTIVE_STATUSES))
            ->join('accounts', 'accounts.id', '=', 'journal_lines.account_id')
            ->whereIn('accounts.type', ['revenue', 'expense'])
            ->selectRaw('accounts.code AS code, accounts.name AS name, SUM(journal_lines.debit) AS d, SUM(journal_lines.credit) AS c')
            ->groupBy('accounts.code', 'accounts.name')
            ->get();

        foreach ($orphans as $row) {
            $net = round(abs((float) $row->c - (float) $row->d), 2);

            if ($net > 0.005) {
                $this->flagWarning("account {$row->code}", 'no cost centre', "{$this->n($net)} on «{$row->name}» is on no centre");
            }
        }

        $ledger = round((float) JournalLine::query()
            ->where('account_id', $this->unearned)
            ->whereHas('entry', fn ($q) => $q->whereIn('status', JournalEntry::EFFECTIVE_STATUSES))
            ->selectRaw('COALESCE(SUM(credit) - SUM(debit), 0) AS b')
            ->value('b'), 2);

        $gap = round($ledger - $this->explained, 2);

        if (abs($gap) > 0.005) {
            $this->flagError('account '.Ledger::UNEARNED_REVENUE, 'deposits unexplained', "ledger holds {$this->n($ledger)}, the bookings and contracts account for {$this->n($this->explained)} — {$this->n($gap)} belongs to no document");
        }
    }

    private function flagError(string $doc, string $check, string $detail): void
    {
        $this->issues[] = ['error', $doc, $check, $detail];
    }

    private function flagWarning(string $doc, string $check, string $detail): void
    {
        $this->issues[] = ['warning', $doc, $check, $detail];
    }

    private function n(float|string|null $amount): string
    {
        return number_format((float) $amount, 2);
    }
}
