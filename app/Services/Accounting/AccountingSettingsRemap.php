<?php

namespace App\Services\Accounting;

use App\Models\Account;
use App\Models\BookingPayment;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\PaymentMethod;
use App\Models\Sale;
use App\Support\ActivitySegment;
use Illuminate\Support\Facades\DB;

class AccountingSettingsRemap
{
    private const EXCLUDED_ASSET_CODES = [Ledger::RECEIVABLES, Ledger::INVENTORY];

    public function __construct(
        private readonly RevenueAccounts $revenueAccounts,
        private readonly PaymentMethodAccounts $paymentMethodAccounts,
        private readonly ActivitySegment $segments,
    ) {}

    public function remapAll(): int
    {
        return array_sum(array_map(fn (string $section) => $this->remap($section), ActivitySegment::activities()));
    }

    public function remap(string $section): int
    {
        if (! ActivitySegment::isActivity($section)) {
            return 0;
        }

        return DB::transaction(fn () => $this->remapRevenue($section) + $this->remapTreasury($section));
    }

    private function remapRevenue(string $section): int
    {
        $centerIds = $this->segments->centerIds($section);

        if ($centerIds === []) {
            return 0;
        }

        $target = $this->revenueAccounts->idFor(RevenueAccounts::SECTION_STREAMS[$section]);

        return JournalLine::query()
            ->whereIn('cost_center_id', $centerIds)
            ->whereHas('account', fn ($q) => $q->where('type', 'revenue'))
            ->whereHas('entry', fn ($q) => $q->where('status', 'posted'))
            ->where('account_id', '!=', $target)
            ->update(['account_id' => $target]);
    }

    private function remapTreasury(string $section): int
    {
        $centerIds = $this->segments->centerIds($section);

        if ($centerIds === []) {
            return 0;
        }

        $excludedIds = Account::whereIn('code', self::EXCLUDED_ASSET_CODES)->pluck('id')->all();
        $updated = 0;

        JournalEntry::query()
            ->where('status', 'posted')
            ->whereIn('source', ['payment', 'sale'])
            ->whereHas('lines', fn ($q) => $q->whereIn('cost_center_id', $centerIds))
            ->with(['lines.account', 'reference'])
            ->chunkById(200, function ($entries) use (&$updated, $section, $centerIds, $excludedIds) {
                foreach ($entries as $entry) {
                    $method = $this->paymentMethodOf($entry);
                    $target = $method ? $this->treasuryTarget($section, $method) : null;

                    if ($target === null) {
                        continue;
                    }

                    foreach ($entry->lines as $line) {
                        if (! in_array($line->cost_center_id, $centerIds, true)) {
                            continue;
                        }

                        if ($line->account?->type !== 'asset' || in_array($line->account_id, $excludedIds, true)) {
                            continue;
                        }

                        if ((int) $line->account_id === $target) {
                            continue;
                        }

                        $line->update(['account_id' => $target]);
                        $updated++;
                    }
                }
            });

        return $updated;
    }

    private function treasuryTarget(string $section, PaymentMethod $method): ?int
    {
        $accountId = $this->paymentMethodAccounts->resolve($section, $method)
            ?? $this->revenueAccounts->depositIdFor(RevenueAccounts::SECTION_STREAMS[$section]);

        if ($accountId !== null) {
            return $accountId;
        }

        $code = $method->ledgerAccount();

        $id = Account::where('code', $code)->value('id');

        return $id !== null ? (int) $id : null;
    }

    private function paymentMethodOf(JournalEntry $entry): ?PaymentMethod
    {
        $reference = $entry->reference;

        return match (true) {
            $reference instanceof BookingPayment => $reference->paymentMethod,
            $reference instanceof Sale => $reference->paymentMethod,
            default => null,
        };
    }
}
