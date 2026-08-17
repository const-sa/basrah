<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\Unit;
use App\Services\Concerns\BuildsBookings;
use App\Support\StayPeriod;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * إنشاء وتعديل إقامات الشاليهات.
 *
 * الفرق الجوهري عن حجز القاعة: الإقامة تُقاس بالليالي لا بالفترة. لذلك
 * تأخذ هذه الخدمة تاريخي دخول وخروج، تشتق منهما المدى الزمني وعدد الليالي،
 * وتُسعّر كل ليلة بتاريخها. ما عدا ذلك — منع التعارض، ربط الأقسام والخدمات،
 * الترقيم، الدفعات — مشترك مع القاعة ويجري بنفس الأدوات.
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
            $checkOut = $data['check_out_date'];
            $scope = $data['scope'] ?? 'whole';
            $sectionIds = $scope === 'sections' ? array_map('intval', $data['section_ids'] ?? []) : [];

            $nights = $this->guardStay($unit, $scope, $checkIn, $checkOut, $sectionIds, $data['client_id'] ?? null);

            $quote = $this->pricing->quoteStay(
                $unit,
                $checkIn,
                $checkOut,
                $sectionIds,
                $data['addons'] ?? [],
                (float) ($data['discount_amount'] ?? 0),
            );

            [$startsAt, $endsAt] = StayPeriod::range($checkIn, $checkOut);

            $booking = Booking::create([
                'reference' => $this->nextReference(),
                'unit_id' => $unit->id,
                'client_id' => $data['client_id'] ?? null,
                'created_by' => $userId,
                'source' => $data['source'] ?? 'admin',
                'scope' => $scope,
                'period' => StayPeriod::PERIOD,
                'booking_date' => $checkIn,
                'check_out_date' => $checkOut,
                'nights' => $nights,
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
                // الإقامة تُسجَّل مؤكدة كحجز القاعة — راجع BookingService.
                'status' => $data['status'] ?? 'confirmed',
                'base_amount' => $quote['base_amount'],
                'package_amount' => 0,
                'event_fee_amount' => 0,
                'addons_amount' => $quote['addons_amount'],
                'discount_amount' => $quote['discount_amount'],
                'total_amount' => $quote['total_amount'],
                'deposit_amount' => $quote['deposit_amount'],
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
            $checkOut = $data['check_out_date'] ?? $booking->checkOutDate();
            $scope = $data['scope'] ?? $booking->scope;
            $sectionIds = $scope === 'sections'
                ? array_map('intval', $data['section_ids'] ?? $booking->sections->pluck('id')->all())
                : [];
            $clientId = $data['client_id'] ?? $booking->client_id;

            $nights = $this->guardStay($unit, $scope, $checkIn, $checkOut, $sectionIds, $clientId, $booking->id);

            $quote = $this->pricing->quoteStay(
                $unit,
                $checkIn,
                $checkOut,
                $sectionIds,
                $data['addons'] ?? [],
                (float) ($data['discount_amount'] ?? $booking->discount_amount),
            );

            [$startsAt, $endsAt] = StayPeriod::range($checkIn, $checkOut);

            $booking->update([
                'unit_id' => $unit->id,
                'client_id' => $clientId,
                // الإقامة لا تحمل باقة ولا رسم مناسبة: إن جاء الحجز من شاشة
                // القاعة قبل الفصل فهذه القيم تُطرح عنه لا تُترك معلّقة.
                'event_type_id' => null,
                'package_id' => null,
                'scope' => $scope,
                'period' => StayPeriod::PERIOD,
                'booking_date' => $checkIn,
                'check_out_date' => $checkOut,
                'nights' => $nights,
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
                'base_amount' => $quote['base_amount'],
                'package_amount' => 0,
                'event_fee_amount' => 0,
                'addons_amount' => $quote['addons_amount'],
                'discount_amount' => $quote['discount_amount'],
                'total_amount' => $quote['total_amount'],
                'deposit_amount' => $quote['deposit_amount'],
                'guests_count' => $data['guests_count'] ?? $booking->guests_count,
                'notes' => $data['notes'] ?? $booking->notes,
            ]);

            $this->syncSections($booking, $scope, $quote['lines']);
            $this->syncAddons($booking, $quote['lines']);

            return $booking->fresh(['unit', 'client', 'sections', 'addons']);
        });
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

        [$startsAt, $endsAt] = StayPeriod::range($checkIn, $checkOut);

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
