<?php

namespace App\Services\Accounting;

use App\Models\Account;
use App\Models\Booking;
use App\Models\RevenueAccount;
use App\Support\ActivitySegment;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Where each stream of income is credited — the setting behind every revenue line.
 *
 * The posting services used to name their account outright: a booking credited
 * Ledger::BOOKING_REVENUE, an invoice Ledger::SALES_REVENUE. That is one
 * account for three activities, so a chart of accounts split into «إيرادات
 * القاعات» and «إيرادات الشاليهات» could be drawn but never used — the tree
 * showed the split and the ledger ignored it.
 *
 * Here the operator chooses and the code asks. The default remains the account
 * the stream has always used, so an untouched system posts exactly as before.
 *
 * The same question is asked of the other side of the entry. Where the money
 * lands used to be two constants — cash at 1110, everything else at 1120 — so
 * a business banking the halls with one bank and the pools with another had
 * nowhere to say so. Each stream may now name the asset account its takings
 * are deposited to; left unset, it follows the payment method as it always has.
 *
 * Resolution is deliberately forgiving: a mapping to an account that was
 * deleted, deactivated, turned into a group, or moved out of the revenue family
 * falls back to that default rather than failing. A mis-set option must not be
 * able to stop a sale from being rung up — the cost of a wrong account is a
 * reclassification entry, the cost of a refused posting is a customer standing
 * at the till with no invoice.
 */
class RevenueAccounts
{
    /**
     * The streams of income the business earns, as the operator names them.
     *
     * @var array<string, array{label: string, hint: string, default: string}>
     */
    public const STREAMS = [
        'hall_bookings' => [
            'label' => 'حجوزات القاعات',
            'hint' => 'إيراد كل حجز قاعة عند إثباته — ويبقى موزّعًا على مراكز تكلفة القاعات كما هو.',
            'default' => Ledger::BOOKING_REVENUE,
        ],
        'chalet_bookings' => [
            'label' => 'حجوزات الشاليهات',
            'hint' => 'إيراد إقامات الشاليهات، سواء حُجز الشاليه كاملًا أو بغرفه.',
            'default' => Ledger::BOOKING_REVENUE,
        ],
        'pool_revenue' => [
            'label' => 'المسابح',
            'hint' => 'فواتير التركيب والصيانة المحرَّرة على قسم المسابح.',
            'default' => Ledger::SALES_REVENUE,
        ],
        'sales' => [
            'label' => 'المبيعات ونقاط البيع',
            'hint' => 'فواتير الكاشير من الأصناف والخدمات — ومرتجعاتها تُردّ على الحساب نفسه.',
            'default' => Ledger::SALES_REVENUE,
        ],
        'security_forfeit' => [
            'label' => 'التأمينات المحتجزة',
            'hint' => 'مبلغ التأمين الذي لا يُردّ للضيف فيصير إيرادًا للمؤسسة.',
            'default' => Ledger::BOOKING_REVENUE,
        ],
    ];

    /**
     * Stream → the account it actually posts to, memoised for the request.
     *
     * @var array<string, int>|null
     */
    private ?array $resolved = null;

    /**
     * Stream → the asset account its takings land on, memoised for the request.
     *
     * @var array<string, int>|null
     */
    private ?array $resolvedDeposits = null;

    public function __construct(private readonly ActivitySegment $segments) {}

    public static function isStream(?string $stream): bool
    {
        return $stream !== null && array_key_exists($stream, self::STREAMS);
    }

    public static function label(string $stream): string
    {
        return self::STREAMS[$stream]['label'] ?? $stream;
    }

    /**
     * The account this stream credits — the chosen one, else its default.
     */
    public function idFor(string $stream): int
    {
        return $this->resolved()[$stream]
            ?? throw new RuntimeException("حساب إيراد «{$stream}» غير موجود في شجرة الحسابات.");
    }

    /**
     * The stream a booking earns on — its unit's activity.
     *
     * A booking with no unit behind it is rare but possible, and it earns on
     * the halls account: that is the account every booking credited before
     * this setting existed, so the odd one out does not move.
     */
    public function streamOfBooking(Booking $booking): string
    {
        $booking->loadMissing('unit');

        return match ($booking->unit?->type) {
            'chalet' => 'chalet_bookings',
            default => 'hall_bookings',
        };
    }

    public function forBooking(Booking $booking): int
    {
        return $this->idFor($this->streamOfBooking($booking));
    }

    /**
     * An invoice's stream, read from the centre it earns on: the pools have
     * their own account, everything else is a sale.
     */
    public function streamOfInvoiceCenter(?int $costCenterId): string
    {
        return $this->segments->of($costCenterId) === ActivitySegment::POOLS ? 'pool_revenue' : 'sales';
    }

    public function forInvoiceCenter(?int $costCenterId): int
    {
        return $this->idFor($this->streamOfInvoiceCenter($costCenterId));
    }

    /**
     * The asset account this stream's takings are deposited into, or null when
     * the operator has named none — then the payment method decides, as it
     * always did.
     *
     * Never throws. A stream with no deposit account is the ordinary case, not
     * an error: the caller falls back to the method's own cash or bank account.
     */
    public function depositIdFor(string $stream): ?int
    {
        return $this->resolvedDeposits()[$stream] ?? null;
    }

    public function depositForBooking(Booking $booking): ?int
    {
        return $this->depositIdFor($this->streamOfBooking($booking));
    }

    public function depositForInvoiceCenter(?int $costCenterId): ?int
    {
        return $this->depositIdFor($this->streamOfInvoiceCenter($costCenterId));
    }

    /**
     * What is stored, untouched — null where nothing was chosen.
     *
     * @return array<string, int|null>
     */
    public function stored(): array
    {
        return $this->storedColumn('account_id');
    }

    /**
     * The deposit account chosen for each stream, untouched — null where the
     * stream still follows the payment method.
     *
     * @return array<string, int|null>
     */
    public function storedDeposits(): array
    {
        return $this->storedColumn('deposit_account_id');
    }

    /**
     * @return array<string, int|null>
     */
    private function storedColumn(string $column): array
    {
        $rows = RevenueAccount::pluck($column, 'stream');

        return collect(self::STREAMS)
            ->map(fn (array $meta, string $stream) => ($rows[$stream] ?? null) !== null ? (int) $rows[$stream] : null)
            ->all();
    }

    /**
     * Stream → account id after defaults and validity are applied.
     *
     * @return array<string, int>
     */
    public function resolved(): array
    {
        if ($this->resolved !== null) {
            return $this->resolved;
        }

        $postable = $this->postableIds();
        $stored = $this->stored();
        $map = [];

        foreach (array_keys(self::STREAMS) as $stream) {
            $chosen = $stored[$stream];
            $id = ($chosen !== null && in_array($chosen, $postable, true)) ? $chosen : $this->defaultId($stream);

            if ($id !== null) {
                $map[$stream] = $id;
            }
        }

        return $this->resolved = $map;
    }

    /**
     * Stream → deposit account, with invalid choices dropped.
     *
     * Unlike the revenue side there is no default to fall back on: a stream
     * that has chosen nothing, or has chosen an account since deleted,
     * deactivated, turned into a group or moved out of the asset family,
     * simply does not appear here — and the payment method answers instead.
     *
     * @return array<string, int>
     */
    public function resolvedDeposits(): array
    {
        if ($this->resolvedDeposits !== null) {
            return $this->resolvedDeposits;
        }

        $depositable = $this->depositableIds();
        $map = [];

        foreach ($this->storedDeposits() as $stream => $chosen) {
            if ($chosen !== null && in_array($chosen, $depositable, true)) {
                $map[$stream] = $chosen;
            }
        }

        return $this->resolvedDeposits = $map;
    }

    /**
     * The streams crediting one account — «هذا الحساب يستقبل: القاعات والمبيعات».
     *
     * @return list<string>
     */
    public function streamsOn(int $accountId): array
    {
        return array_values(array_keys(array_filter(
            $this->resolved(),
            fn (int $id) => $id === $accountId,
        )));
    }

    /**
     * Save the operator's choice. A stream left blank is cleared rather than
     * dropped, so it keeps falling back to its default and stays on the screen.
     *
     * Only the sides actually submitted are written: a caller that sends the
     * revenue account alone must not silently wipe the deposit account, and
     * the row holds both.
     *
     * @param  array<string, array{account_id?: int|null, deposit_account_id?: int|null}>  $map
     */
    public function save(array $map): void
    {
        DB::transaction(function () use ($map) {
            foreach (array_keys(self::STREAMS) as $stream) {
                if (! array_key_exists($stream, $map)) {
                    continue;
                }

                $values = [];

                foreach (['account_id', 'deposit_account_id'] as $column) {
                    if (array_key_exists($column, $map[$stream])) {
                        $values[$column] = $map[$stream][$column] ?: null;
                    }
                }

                if ($values === []) {
                    continue;
                }

                RevenueAccount::updateOrCreate(['stream' => $stream], $values);
            }
        });

        $this->forget();
    }

    public function forget(): void
    {
        $this->resolved = null;
        $this->resolvedDeposits = null;
    }

    /**
     * The revenue accounts an entry may actually be posted to.
     *
     * @return list<int>
     */
    public function postableIds(): array
    {
        return Account::query()
            ->where('type', 'revenue')
            ->postable()
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * The accounts money may actually be deposited into.
     *
     * Every postable asset account, not only the two under the cash family: a
     * second bank is opened where the accountant opens it, and a list drawn
     * tight around one parent code would refuse the very account the operator
     * had just created. The screen still shows the cash family first, because
     * that is where a deposit account nearly always belongs.
     *
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

    private function defaultId(string $stream): ?int
    {
        $code = self::STREAMS[$stream]['default'] ?? null;

        if ($code === null) {
            return null;
        }

        $id = Account::where('code', $code)->value('id');

        return $id !== null ? (int) $id : null;
    }
}
