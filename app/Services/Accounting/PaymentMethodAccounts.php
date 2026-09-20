<?php

namespace App\Services\Accounting;

use App\Models\Account;
use App\Models\Booking;
use App\Models\PaymentMethod;
use App\Models\PaymentMethodAccount;
use App\Support\ActivitySegment;
use Illuminate\Support\Facades\DB;

class PaymentMethodAccounts
{
    /**
     * @var array<string, array<int, int>>|null
     */
    private ?array $resolved = null;

    public function __construct(private readonly ActivitySegment $segments) {}

    public function segmentOfBooking(Booking $booking): string
    {
        $booking->loadMissing('unit');

        return match ($booking->unit?->type) {
            'chalet' => ActivitySegment::CHALETS,
            default => ActivitySegment::HALLS,
        };
    }

    public function segmentOfInvoiceCenter(?int $costCenterId): string
    {
        $segment = $this->segments->of($costCenterId);

        return ActivitySegment::isActivity($segment) ? $segment : ActivitySegment::POOLS;
    }

    public function resolveForBooking(Booking $booking, PaymentMethod $method): ?int
    {
        return $this->resolve($this->segmentOfBooking($booking), $method);
    }

    public function resolveForInvoiceCenter(?int $costCenterId, PaymentMethod $method): ?int
    {
        return $this->resolve($this->segmentOfInvoiceCenter($costCenterId), $method);
    }

    public function resolve(?string $section, PaymentMethod $method): ?int
    {
        if (! ActivitySegment::isActivity($section)) {
            return null;
        }

        return $this->resolved()[$section][$method->id] ?? null;
    }

    /**
     * @return array<string, array<int, int|null>>
     */
    public function stored(): array
    {
        $map = $this->emptyMap();

        PaymentMethodAccount::query()
            ->get(['section', 'payment_method_id', 'account_id'])
            ->each(function (PaymentMethodAccount $row) use (&$map) {
                if (array_key_exists($row->section, $map)) {
                    $map[$row->section][$row->payment_method_id] = (int) $row->account_id;
                }
            });

        return $map;
    }

    /**
     * @return array<string, array<int, int>>
     */
    private function resolved(): array
    {
        if ($this->resolved !== null) {
            return $this->resolved;
        }

        $depositable = $this->depositableIds();
        $map = $this->emptyMap();

        PaymentMethodAccount::query()
            ->whereIn('account_id', $depositable)
            ->get(['section', 'payment_method_id', 'account_id'])
            ->each(function (PaymentMethodAccount $row) use (&$map) {
                if (array_key_exists($row->section, $map)) {
                    $map[$row->section][$row->payment_method_id] = (int) $row->account_id;
                }
            });

        return $this->resolved = $map;
    }

    /**
     * @param  array<string, array<int, int|null>>  $map
     */
    public function save(array $map): void
    {
        DB::transaction(function () use ($map) {
            foreach ($map as $section => $methods) {
                if (! ActivitySegment::isActivity($section)) {
                    continue;
                }

                foreach ($methods as $methodId => $accountId) {
                    if ($accountId === null) {
                        PaymentMethodAccount::where('section', $section)
                            ->where('payment_method_id', $methodId)
                            ->delete();

                        continue;
                    }

                    PaymentMethodAccount::updateOrCreate(
                        ['section' => $section, 'payment_method_id' => $methodId],
                        ['account_id' => $accountId],
                    );
                }
            }
        });

        $this->forget();
    }

    public function forget(): void
    {
        $this->resolved = null;
    }

    /**
     * @return list<int>
     */
    public function depositableIds(): array
    {
        return Account::query()
            ->where('type', 'asset')
            ->postable()
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * @return array<string, array<int, int|null>>
     */
    private function emptyMap(): array
    {
        return collect(ActivitySegment::activities())->mapWithKeys(fn (string $s) => [$s => []])->all();
    }
}
