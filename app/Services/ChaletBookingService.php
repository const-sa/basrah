<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\Unit;
use App\Services\Concerns\BuildsBookings;
use App\Support\BookingPeriod;
use App\Support\StayPeriod;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * إنشاء وتعديل حجوزات الشاليهات.
 *
 * A chalet is sold two ways, and period says which: 'overnight' is a stay
 * measured in nights between a check-in and a check-out date, and each night
 * is priced by its own date. Any other period is a day-use booking — a
 * morning, an evening or a full day — measured the way a hall is, over one or
 * more days. See plan() for how each resolves.
 *
 * Everything past that point is shared with the hall: both shapes reduce to a
 * starts_at → ends_at range, so conflict detection, section and addon
 * linking, numbering and payments run through the same tools.
 *
 * الباقات ورسوم المناسبات لا مكان لها هنا: الشاليه يُؤجَّر للإقامة، وليس
 * فيه مناسبة تُسعَّر ولا ضيافة تُباع بالباقة.
 */
class ChaletBookingService
{
    use BuildsBookings;

    public function __construct(
        private readonly BookingAvailability $availability,
        private readonly BookingPricing $pricing,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException عند وجود تعارض أو مدى غير صالح
     */
    public function create(array $data, ?int $userId = null): Booking
    {
        return DB::transaction(function () use ($data, $userId) {
            $unit = Unit::lockForUpdate()->findOrFail($data['unit_id']);

            $checkIn = $data['booking_date'];
            $scope = $data['scope'] ?? 'whole';
            $sectionIds = $scope === 'sections' ? array_map('intval', $data['section_ids'] ?? []) : [];

            $plan = $this->plan(
                $unit,
                $data['period'] ?? StayPeriod::PERIOD,
                $scope,
                $checkIn,
                $data['check_out_date'] ?? null,
                (int) ($data['days_count'] ?? 1),
                $sectionIds,
                $data['addons'] ?? [],
                (float) ($data['discount_amount'] ?? 0),
                $data['client_id'] ?? null,
            );

            $quote = $plan['quote'];

            $booking = Booking::create([
                'reference' => $this->nextReference(),
                'unit_id' => $unit->id,
                'client_id' => $data['client_id'] ?? null,
                'created_by' => $userId,
                'source' => $data['source'] ?? 'admin',
                'scope' => $scope,
                'period' => $plan['period'],
                'booking_date' => $checkIn,
                'check_out_date' => $plan['check_out_date'],
                'nights' => $plan['nights'],
                'days_count' => $plan['days_count'],
                'starts_at' => $plan['starts_at'],
                'ends_at' => $plan['ends_at'],
                // الإقامة تُسجَّل مؤكدة كحجز القاعة — راجع BookingService.
                'status' => $data['status'] ?? 'confirmed',
                'base_amount' => $quote['base_amount'],
                'package_amount' => 0,
                'event_fee_amount' => 0,
                'addons_amount' => $quote['addons_amount'],
                'discount_amount' => $quote['discount_amount'],
                'total_amount' => $quote['total_amount'],
                'deposit_amount' => $quote['deposit_amount'],
                // The security deposit is the chalet's usual one unless the
                // form said otherwise. It is stored beside the total, never
                // inside it: it is held, not charged.
                'security_deposit_amount' => $this->securityDeposit($unit, $data),
                'paid_amount' => 0,
                'guests_count' => $data['guests_count'] ?? null,
                'notes' => $data['notes'] ?? null,
            ]);

            $this->syncSections($booking, $scope, $quote['lines']);
            $this->syncAddons($booking, $quote['lines']);

            return $booking->load(['unit', 'client', 'sections', 'addons']);
        });
    }

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException
     */
    public function update(Booking $booking, array $data): Booking
    {
        return DB::transaction(function () use ($booking, $data) {
            $unit = Unit::lockForUpdate()->findOrFail($data['unit_id'] ?? $booking->unit_id);

            $checkIn = $data['booking_date'] ?? $booking->booking_date->toDateString();
            $scope = $data['scope'] ?? $booking->scope;
            $sectionIds = $scope === 'sections'
                ? array_map('intval', $data['section_ids'] ?? $booking->sections->pluck('id')->all())
                : [];
            $clientId = $data['client_id'] ?? $booking->client_id;

            $plan = $this->plan(
                $unit,
                $data['period'] ?? $booking->period,
                $scope,
                $checkIn,
                $data['check_out_date'] ?? $booking->checkOutDate(),
                (int) ($data['days_count'] ?? $booking->days_count ?? 1),
                $sectionIds,
                $data['addons'] ?? [],
                (float) ($data['discount_amount'] ?? $booking->discount_amount),
                $clientId,
                $booking->id,
            );

            $quote = $plan['quote'];

            $booking->update([
                'unit_id' => $unit->id,
                'client_id' => $clientId,
                // الإقامة لا تحمل باقة ولا رسم مناسبة: إن جاء الحجز من شاشة
                // القاعة قبل الفصل فهذه القيم تُطرح عنه لا تُترك معلّقة.
                'event_type_id' => null,
                'package_id' => null,
                'scope' => $scope,
                'period' => $plan['period'],
                'booking_date' => $checkIn,
                'check_out_date' => $plan['check_out_date'],
                'nights' => $plan['nights'],
                'days_count' => $plan['days_count'],
                'starts_at' => $plan['starts_at'],
                'ends_at' => $plan['ends_at'],
                'base_amount' => $quote['base_amount'],
                'package_amount' => 0,
                'event_fee_amount' => 0,
                'addons_amount' => $quote['addons_amount'],
                'discount_amount' => $quote['discount_amount'],
                'total_amount' => $quote['total_amount'],
                'deposit_amount' => $quote['deposit_amount'],
                // An edit keeps what was agreed unless it is being changed:
                // falling back to the chalet's usual amount here would undo a
                // waiver every time the dates were touched.
                'security_deposit_amount' => array_key_exists('security_deposit_amount', $data)
                    && $data['security_deposit_amount'] !== null
                        ? round((float) $data['security_deposit_amount'], 2)
                        : (float) $booking->security_deposit_amount,
                // A cleared count is a change, not an omission: ?? would put
                // back the number the operator had just deleted.
                'guests_count' => array_key_exists('guests_count', $data)
                    ? $data['guests_count']
                    : $booking->guests_count,
                'notes' => $data['notes'] ?? $booking->notes,
            ]);

            $this->syncSections($booking, $scope, $quote['lines']);
            $this->syncAddons($booking, $quote['lines']);

            return $booking->fresh(['unit', 'client', 'sections', 'addons']);
        });
    }

    /**
     * The security deposit this booking asks for.
     *
     * The chalet's usual amount is a default, not a rule — a returning guest
     * may be let off it, and a group taking three chalets is rarely charged
     * three times over. A number sent from the form therefore wins, including
     * a zero, which is why the key is tested for rather than coalesced.
     *
     * @param  array<string, mixed>  $data
     */
    private function securityDeposit(Unit $unit, array $data): float
    {
        return array_key_exists('security_deposit_amount', $data) && $data['security_deposit_amount'] !== null
            ? round((float) $data['security_deposit_amount'], 2)
            : $unit->securityDeposit();
    }

    /**
     * Resolve one chalet booking into the values every write needs.
     *
     * A chalet is sold two ways. An overnight stay spans two dates and is
     * measured in nights; a day-use booking sits inside its own day (or a run
     * of days) and is measured in day periods, exactly as a hall is. Both
     * collapse to the same starts_at → ends_at range, so conflict detection,
     * the calendars and the reports keep reading one thing, and a day-use
     * booking correctly blocks a stay that overlaps it.
     *
     * period is the discriminator — 'overnight' means a stay, anything else
     * means day use. That is already the column the booking stores, so no
     * extra flag has to be kept in step with it.
     *
     * @param  list<int>  $sectionIds
     * @param  array<int, int>  $addons
     * @return array{
     *     quote: array<string, mixed>, period: string,
     *     starts_at: CarbonImmutable, ends_at: CarbonImmutable,
     *     check_out_date: string|null, nights: int|null, days_count: int|null
     * }
     *
     * @throws ValidationException
     */
    private function plan(
        Unit $unit,
        string $period,
        string $scope,
        string $date,
        ?string $checkOut,
        int $days,
        array $sectionIds,
        array $addons,
        float $discount,
        ?int $clientId,
        ?int $ignoreId = null,
    ): array {
        if ($period === StayPeriod::PERIOD) {
            $nights = $this->guardStay($unit, $scope, $date, (string) $checkOut, $sectionIds, $clientId, $ignoreId);
            [$startsAt, $endsAt] = StayPeriod::range($date, (string) $checkOut, $unit);

            return [
                'quote' => $this->pricing->quoteStay($unit, $date, (string) $checkOut, $sectionIds, $addons, $discount),
                'period' => StayPeriod::PERIOD,
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
                'check_out_date' => $checkOut,
                'nights' => $nights,
                // nights and days_count do not combine — see the migration
                // that added days_count.
                'days_count' => null,
            ];
        }

        $days = BookingPeriod::days($days);

        $this->guardDayUse($unit, $scope, $date, $period, $days, $sectionIds, $clientId, $ignoreId);
        [$startsAt, $endsAt] = BookingPeriod::range($date, $period, $days, $unit);

        return [
            // The hall quote already prices a period across days; a chalet
            // simply passes no package and no event type, which are hall
            // tools and default to null anyway.
            'quote' => $this->pricing->quote(
                $unit, $scope, $date, $period, $sectionIds, $addons, $discount, null, null, $days,
            ),
            'period' => $period,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            // A day-use booking ends inside its own last day, so there is no
            // check-out date to record.
            'check_out_date' => null,
            'nights' => null,
            'days_count' => $days,
        ];
    }

    /**
     * Guard a day-use booking: the period must be priced on this chalet, and
     * the resulting range must be free.
     *
     * @param  list<int>  $sectionIds
     *
     * @throws ValidationException
     */
    private function guardDayUse(
        Unit $unit,
        string $scope,
        string $date,
        string $period,
        int $days,
        array $sectionIds,
        ?int $clientId,
        ?int $ignoreId = null,
    ): void {
        // Pricing is what opens a period for booking, so an unpriced period
        // is refused here rather than quoted at zero.
        if (! in_array($period, $unit->dayUsePeriods(), true)) {
            throw ValidationException::withMessages([
                'period' => 'هذه الفترة غير مسعَّرة لهذا الشاليه — أضف سعرها من شاشة الأسعار أولًا.',
            ]);
        }

        [$startsAt, $endsAt] = BookingPeriod::range($date, $period, $days, $unit);

        $result = $this->availability->checkRange(
            $unit,
            $scope,
            $startsAt,
            $endsAt,
            $sectionIds,
            $clientId !== null ? (int) $clientId : null,
            $ignoreId,
        );

        if (! $result['ok']) {
            throw ValidationException::withMessages(['availability' => $result['reason']]);
        }
    }

    /**
     * التحقق من صحة الإقامة ومن خلوّ الشاليه فيها.
     *
     * @param  list<int>  $sectionIds
     * @return int عدد الليالي
     *
     * @throws ValidationException
     */
    private function guardStay(
        Unit $unit,
        string $scope,
        string $checkIn,
        string $checkOut,
        array $sectionIds,
        ?int $clientId,
        ?int $ignoreId = null,
    ): int {
        $nights = StayPeriod::nights($checkIn, $checkOut);

        if ($nights < 1) {
            throw ValidationException::withMessages([
                'check_out_date' => 'تاريخ الخروج يجب أن يكون بعد تاريخ الدخول بليلة على الأقل.',
            ]);
        }

        // السقف يحمي من خطأ إدخال يحجز الشاليه أشهرًا بضغطة واحدة.
        if ($nights > StayPeriod::maxNights()) {
            throw ValidationException::withMessages([
                'check_out_date' => 'أقصى مدة إقامة '.StayPeriod::maxNights()." ليلة، والمطلوب {$nights}.",
            ]);
        }

        [$startsAt, $endsAt] = StayPeriod::range($checkIn, $checkOut, $unit);

        $result = $this->availability->checkRange(
            $unit,
            $scope,
            $startsAt,
            $endsAt,
            $sectionIds,
            $clientId !== null ? (int) $clientId : null,
            $ignoreId,
        );

        if (! $result['ok']) {
            throw ValidationException::withMessages(['availability' => $result['reason']]);
        }

        return $nights;
    }
}
