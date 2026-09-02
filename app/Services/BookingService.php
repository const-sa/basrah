<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\BookingPayment;
use App\Models\PaymentMethod;
use App\Models\Unit;
use App\Services\Accounting\BookingAccounting;
use App\Services\Concerns\BuildsBookings;
use App\Support\BookingPeriod;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * إنشاء وتعديل حجوزات القاعات، إضافةً إلى ما يشترك فيه النوعان من دفعات
 * وإنهاء وإلغاء.
 *
 * حجز القاعة يقع داخل يوم واحد بفترة معلومة (صباحي/مسائي/يوم كامل)، ويحتمل
 * باقة ونوع مناسبة. أما إقامة الشاليه فتخصّها ChaletBookingService لأنها
 * تُقاس بالليالي وتُسعَّر ليلةً ليلة.
 *
 * الدفعات والإنهاء والإلغاء بقيت هنا لأنها لا تفرّق بين النوعين: الدفعة قيدٌ
 * محاسبي على حجز، ولا شأن لها بكيفية تحديد وقته.
 *
 * الفحص والحفظ يجريان داخل معاملة واحدة مع قفل الوحدة، حتى لا ينجح
 * حجزان متزامنان على نفس الفترة (سباق يحدث فعليًا مع عدة موظفي حجز).
 */
class BookingService
{
    use BuildsBookings;

    public function __construct(
        private readonly BookingAvailability $availability,
        private readonly BookingPricing $pricing,
        private readonly BookingAccounting $accounting,
    ) {}

    /**
     * تسجيل دفعة على حجز مع قيدها المحاسبي.
     *
     * @param  array{type?:string, payment_method_id?:int|null, amount:float, paid_on?:string, reference?:string|null, notes?:string|null}  $data
     */
    public function recordPayment(Booking $booking, array $data, ?int $userId = null): BookingPayment
    {
        $amount = round((float) $data['amount'], 2);

        if ($amount <= 0) {
            throw new RuntimeException('مبلغ الدفعة يجب أن يكون أكبر من صفر.');
        }

        $type = $data['type'] ?? 'payment';

        // الاسترداد لا يتجاوز المسدَّد فعلًا، وإلا صار رصيد العميل سالبًا بلا سبب.
        if ($type === 'refund' && $amount > (float) $booking->paid_amount) {
            throw ValidationException::withMessages([
                'amount' => 'مبلغ الاسترداد يتجاوز المسدَّد على هذا الحجز.',
            ]);
        }

        // Nothing may be released that is not being held. Without this the
        // deposits account goes negative — the books would show the property
        // owing a guest money it never took from them.
        if (in_array($type, BookingPayment::SECURITY_RELEASES, true) && $amount > $booking->securityHeld()) {
            throw ValidationException::withMessages([
                'amount' => 'المبلغ يتجاوز التأمين المحتجز على هذا الحجز.',
            ]);
        }

        // A forfeit moves no cash — it only ends the claim on money already in
        // the till — so it carries no payment method to mislead the reports.
        $methodId = $type === 'security_forfeit'
            ? null
            : ($data['payment_method_id'] ?? PaymentMethod::default()->id);

        return DB::transaction(function () use ($booking, $data, $amount, $type, $userId, $methodId) {
            $payment = $booking->payments()->create([
                'received_by' => $userId,
                'type' => $type,
                'payment_method_id' => $methodId,
                'amount' => $amount,
                'paid_on' => $data['paid_on'] ?? now()->toDateString(),
                'reference' => $data['reference'] ?? null,
                'attachment_path' => $data['attachment_path'] ?? null,
                'notes' => $data['notes'] ?? null,
            ]);

            $booking->recalculatePaidAmount();
            $this->accounting->recordPayment($payment, $userId);

            return $payment;
        });
    }

    /**
     * تسجيل دخول العميل — الوحدة صارت مشغولة فعلًا.
     *
     * لا أثر محاسبي هنا: الإيراد يُعترف به عند الخروج، والعربون يبقى التزامًا
     * حتى تُستهلك الخدمة.
     */
    public function checkIn(Booking $booking): Booking
    {
        $booking->update(['status' => 'checked_in']);

        return $booking->fresh();
    }

    /**
     * تسجيل خروج العميل — نهاية الخدمة والاعتراف بإيرادها.
     */
    public function checkOut(Booking $booking, ?int $userId = null): Booking
    {
        return DB::transaction(function () use ($booking, $userId) {
            $booking->update(['status' => 'checked_out']);
            $this->accounting->recognizeRevenue($booking->fresh(), $userId);

            return $booking->fresh();
        });
    }

    /**
     * تأجيل الحجز — يحرّر الفترة لأن المؤجل خارج BLOCKING_STATUSES، فيُعاد
     * بيع التاريخ ويُنشأ للعميل حجز جديد بموعده الجديد.
     */
    public function postpone(Booking $booking, ?string $reason = null): Booking
    {
        $booking->update([
            'status' => 'postponed',
            'cancellation_reason' => $reason,
        ]);

        return $booking->fresh();
    }

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException عند وجود تعارض
     */
    public function create(array $data, ?int $userId = null): Booking
    {
        return DB::transaction(function () use ($data, $userId) {
            $unit = Unit::lockForUpdate()->findOrFail($data['unit_id']);

            $scope = $data['scope'];
            $sectionIds = $scope === 'sections' ? array_map('intval', $data['section_ids'] ?? []) : [];
            $days = BookingPeriod::days($data['days_count'] ?? null);

            $this->guardAvailability($unit, $scope, [...$data, 'days_count' => $days], $sectionIds);

            // With tax unless told otherwise, which is the common case. A booking
            // coming from the public site is never asked, so it takes the common
            // case along with the rest of its defaults.
            $taxable = (bool) ($data['is_taxable'] ?? true);

            $quote = $this->pricing->quote(
                $unit,
                $scope,
                $data['booking_date'],
                $data['period'],
                $sectionIds,
                $data['addons'] ?? [],
                (float) ($data['discount_amount'] ?? 0),
                isset($data['package_id']) ? (int) $data['package_id'] : null,
                isset($data['event_type_id']) ? (int) $data['event_type_id'] : null,
                $days,
                $taxable,
            );

            [$startsAt, $endsAt] = BookingPeriod::range($data['booking_date'], $data['period'], $days, $unit);

            $booking = Booking::create([
                'reference' => $this->nextReference(),
                'unit_id' => $unit->id,
                'client_id' => $data['client_id'] ?? null,
                // الباقة تُخزَّن كما سعّرتها الخدمة: إن كانت لقاعة أخرى فقد أسقطتها
                'event_type_id' => $quote['event_type']['id'] ?? null,
                'package_id' => $quote['package']['id'] ?? null,
                'created_by' => $userId,
                // الموقع العام يمرّر 'online'؛ شاشات الإدارة لا تمرّر شيئًا.
                'source' => $data['source'] ?? 'admin',
                'scope' => $scope,
                'period' => $data['period'],
                'booking_date' => $data['booking_date'],
                'days_count' => $days,
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
                // الحجز يُسجَّل مؤكدًا: الموظف لا يفتح الشاشة إلا وقد اتفق مع
                // العميل، فجعله مبدئيًا كان يفرض خطوة تأكيدٍ ثانية على كل حجز.
                // و«مبدئي» يبقى في قائمة الحالات لمن يحتاجه فعلًا.
                'status' => $data['status'] ?? 'confirmed',
                'base_amount' => $quote['base_amount'],
                'package_amount' => $quote['package_amount'],
                'event_fee_amount' => $quote['event_fee_amount'],
                'addons_amount' => $quote['addons_amount'],
                'discount_amount' => $quote['discount_amount'],
                'total_amount' => $quote['total_amount'],
                // The answer is stored with the booking rather than inferred from its
                // tax being zero: the invoice reads it back, and the edit screen
                // opens on it.
                'is_taxable' => $taxable,
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
     * تعديل حجز قائم مع إعادة فحص التعارض (متجاهلًا الحجز نفسه).
     *
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException
     */
    public function update(Booking $booking, array $data): Booking
    {
        return DB::transaction(function () use ($booking, $data) {
            $unit = Unit::lockForUpdate()->findOrFail($data['unit_id'] ?? $booking->unit_id);

            $scope = $data['scope'] ?? $booking->scope;
            $sectionIds = $scope === 'sections'
                ? array_map('intval', $data['section_ids'] ?? $booking->sections->pluck('id')->all())
                : [];

            $payload = [
                'booking_date' => $data['booking_date'] ?? $booking->booking_date->toDateString(),
                'period' => $data['period'] ?? $booking->period,
                'days_count' => BookingPeriod::days($data['days_count'] ?? $booking->days_count),
                'client_id' => $data['client_id'] ?? $booking->client_id,
                'event_type_id' => array_key_exists('event_type_id', $data) ? $data['event_type_id'] : $booking->event_type_id,
                'package_id' => array_key_exists('package_id', $data) ? $data['package_id'] : $booking->package_id,
                // An edit keeps what was agreed unless it is being changed: moving a
                // date must not put back tax waived for an exempt body.
                'is_taxable' => (bool) ($data['is_taxable'] ?? $booking->is_taxable),
            ];

            $this->guardAvailability($unit, $scope, $payload, $sectionIds, $booking->id);

            $quote = $this->pricing->quote(
                $unit,
                $scope,
                $payload['booking_date'],
                $payload['period'],
                $sectionIds,
                $data['addons'] ?? [],
                (float) ($data['discount_amount'] ?? $booking->discount_amount),
                $payload['package_id'] ? (int) $payload['package_id'] : null,
                $payload['event_type_id'] ? (int) $payload['event_type_id'] : null,
                $payload['days_count'],
                $payload['is_taxable'],
            );

            [$startsAt, $endsAt] = BookingPeriod::range(
                $payload['booking_date'],
                $payload['period'],
                $payload['days_count'],
                $unit,
            );

            $booking->update([
                'unit_id' => $unit->id,
                'client_id' => $payload['client_id'],
                'event_type_id' => $quote['event_type']['id'] ?? null,
                'package_id' => $quote['package']['id'] ?? null,
                'scope' => $scope,
                'period' => $payload['period'],
                'booking_date' => $payload['booking_date'],
                'days_count' => $payload['days_count'],
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
                'base_amount' => $quote['base_amount'],
                'package_amount' => $quote['package_amount'],
                'event_fee_amount' => $quote['event_fee_amount'],
                'addons_amount' => $quote['addons_amount'],
                'discount_amount' => $quote['discount_amount'],
                'total_amount' => $quote['total_amount'],
                'is_taxable' => $payload['is_taxable'],
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
     * إلغاء حجز — لا يُحذف حتى يبقى في سجل التدقيق، وتتحرر الفترة تلقائيًا
     * لأن الحالة الملغاة خارج BLOCKING_STATUSES.
     */
    public function cancel(Booking $booking, ?string $reason = null): Booking
    {
        $booking->update([
            'status' => 'cancelled',
            'cancelled_at' => now(),
            'cancellation_reason' => $reason,
        ]);

        return $booking;
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<int>  $sectionIds
     *
     * @throws ValidationException
     */
    private function guardAvailability(Unit $unit, string $scope, array $data, array $sectionIds, ?int $ignoreId = null): void
    {
        $result = $this->availability->check(
            $unit,
            $scope,
            $data['booking_date'],
            $data['period'],
            $sectionIds,
            isset($data['client_id']) ? (int) $data['client_id'] : null,
            $ignoreId,
            BookingPeriod::days($data['days_count'] ?? null),
        );

        if (! $result['ok']) {
            throw ValidationException::withMessages([
                'availability' => $result['reason'],
            ]);
        }
    }
}
