<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\Unit;
use App\Support\BookingPeriod;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * منع التعارض الهرمي بين الوحدة وأقسامها (§1.1 من وثيقة النطاق).
 *
 * القواعد المطبَّقة:
 *  1. حجز الوحدة كاملة يقفل جميع أقسامها.
 *  2. وجود أي قسم محجوز يمنع حجز الوحدة كاملة، مع بيان سبب الرفض.
 *  3. القسم نفسه لا يُحجز مرتين في مدى متقاطع.
 *  4. قاعدة الخصوصية:
 *     - privacy_mode = exclusive → حجز أي قسم يحجب بقية الأقسام عن عميل آخر،
 *       ويبقى مسموحًا لنفس العميل (عائلة تحجز القسمين معًا).
 *     - privacy_mode = open      → الأقسام مستقلة تمامًا.
 */
class BookingAvailability
{
    /**
     * نتيجة الفحص: هل الحجز ممكن، ولماذا لا.
     *
     * @param  list<int>  $sectionIds  الأقسام المطلوبة (فارغة إذا كان النطاق whole)
     * @param  int  $days  عدد أيام المناسبة — المدى يغطيها كلها فتُقفل جميعًا
     * @return array{ok: bool, reason: string|null, conflicts: list<array<string, mixed>>}
     */
    public function check(
        Unit $unit,
        string $scope,
        string $date,
        string $period,
        array $sectionIds = [],
        ?int $clientId = null,
        ?int $ignoreBookingId = null,
        int $days = 1,
    ): array {
        [$startsAt, $endsAt] = BookingPeriod::range($date, $period, $days);

        return $this->checkRange($unit, $scope, $startsAt, $endsAt, $sectionIds, $clientId, $ignoreBookingId);
    }

    /**
     * فحص الإتاحة على مدى زمني صريح.
     *
     * الحجز بالفترة والحجز بالليالي يختلفان في كيفية اشتقاق المدى فقط؛ أما
     * قواعد التعارض الهرمي والخصوصية فواحدة. لذلك يشتق كل نوع مداه ثم يستدعي
     * هذه الدالة، فتبقى القواعد في موضع واحد لا موضعين يتباعدان مع الوقت.
     *
     * @param  list<int>  $sectionIds
     * @return array{ok: bool, reason: string|null, conflicts: list<array<string, mixed>>}
     */
    public function checkRange(
        Unit $unit,
        string $scope,
        CarbonInterface $startsAt,
        CarbonInterface $endsAt,
        array $sectionIds = [],
        ?int $clientId = null,
        ?int $ignoreBookingId = null,
    ): array {
        // مدى غير صالح: الخروج قبل الدخول أو مساوٍ له. بلا هذا الفحص يمرّ
        // المدى الفارغ دون تعارض ظاهر لأنه لا يتقاطع مع شيء.
        if ($endsAt->lessThanOrEqualTo($startsAt)) {
            return $this->fail('مدى الحجز غير صالح: تاريخ النهاية يجب أن يتجاوز تاريخ البداية.');
        }

        // الحجز لا يقع في يوم مضى: القاعة لا تُباع بأثر رجعي، والتاريخ الماضي
        // في نموذج حجز جديد خطأ إدخال لا نية. اليوم نفسه مسموح — الحجز في
        // صباح اليوم لمسائه أمر عادي، والماضي هو ما قبل اليوم لا ما قبل اللحظة.
        if ($this->startsInThePast($startsAt, $ignoreBookingId)) {
            return $this->fail('لا يمكن الحجز في تاريخ مضى — اختر اليوم أو تاريخًا لاحقًا.');
        }

        // الوحدة المعطَّلة لا تُحجز — الإيقاف وسيلة إخراجها من الخدمة
        // (صيانة أو إغلاق موسمي)، وبلا هذا الفحص يبقى الإيقاف شكليًا.
        if (! $unit->is_active) {
            return $this->fail("الوحدة «{$unit->name}» موقوفة عن الحجز حاليًا.");
        }

        // فحص نمط الحجز المسموح للوحدة قبل أي شيء.
        if ($scope === 'whole' && ! $unit->allowsWholeBooking()) {
            return $this->fail('هذه الوحدة لا تُحجز كاملة، الحجز فيها بالأقسام فقط.');
        }

        if ($scope === 'sections') {
            if (! $unit->allowsSectionBooking()) {
                return $this->fail('هذه الوحدة تُحجز كاملة فقط ولا تقبل حجز قسم منفرد.');
            }

            if (empty($sectionIds)) {
                return $this->fail('يجب اختيار قسم واحد على الأقل.');
            }

            $valid = $unit->sections()->whereIn('id', $sectionIds)->pluck('id')->all();
            if (count($valid) !== count(array_unique($sectionIds))) {
                return $this->fail('أحد الأقسام المختارة لا يتبع هذه الوحدة.');
            }

            // القسم الموقوف لا يُحجز ولو كانت وحدته فعّالة.
            $inactive = $unit->sections()->whereIn('id', $sectionIds)->where('is_active', false)->pluck('name');
            if ($inactive->isNotEmpty()) {
                return $this->fail('القسم موقوف عن الحجز: '.$inactive->implode('، ').'.');
            }
        }

        $existing = $this->blockingBookings($unit, $startsAt, $endsAt, $ignoreBookingId);

        if ($existing->isEmpty()) {
            return ['ok' => true, 'reason' => null, 'conflicts' => []];
        }

        return $scope === 'whole'
            ? $this->checkWhole($existing)
            : $this->checkSections($unit, $existing, $sectionIds, $clientId);
    }

    /**
     * Many candidate ranges answered at once, from a single read of the unit's
     * diary.
     *
     * The booking form's calendar asks about a whole month before a date is
     * picked — a night per day, and each day period beside it. Calling
     * checkRange() for every one of those would be a hundred queries for one
     * month, so the diary is read once and the same conflict rules are applied
     * in memory.
     *
     * This answers "is it free" and nothing else. The reason a range fails
     * still comes from checkRange() on the single range being saved, which
     * stays the authority — a day the map shows free is checked again when it
     * is chosen.
     *
     * @param  list<array{key: string, starts_at: CarbonInterface, ends_at: CarbonInterface}>  $ranges
     * @param  list<int>  $sectionIds
     * @return array<string, bool> range key → is it free
     */
    public function freeRanges(
        Unit $unit,
        string $scope,
        array $ranges,
        array $sectionIds = [],
        ?int $clientId = null,
        ?int $ignoreBookingId = null,
    ): array {
        if (empty($ranges)) {
            return [];
        }

        $keys = array_column($ranges, 'key');

        // A stopped unit, or a scope it does not accept, closes every range at
        // once — answered before the diary is read, so no query goes out for a
        // question whose answer is already settled.
        if (! $unit->is_active
            || ($scope === 'whole' && ! $unit->allowsWholeBooking())
            || ($scope === 'sections' && (! $unit->allowsSectionBooking() || empty($sectionIds)))
        ) {
            return array_fill_keys($keys, false);
        }

        $from = $this->edge(array_column($ranges, 'starts_at'), earliest: true);
        $to = $this->edge(array_column($ranges, 'ends_at'), earliest: false);

        $existing = $this->blockingBookings($unit, $from, $to, $ignoreBookingId);
        $earliest = $this->earliestBookableDay($ignoreBookingId);

        $free = [];

        foreach ($ranges as $range) {
            ['key' => $key, 'starts_at' => $startsAt, 'ends_at' => $endsAt] = $range;

            if ($endsAt->lessThanOrEqualTo($startsAt) || $startsAt->lessThan($earliest)) {
                $free[$key] = false;

                continue;
            }

            // The same strict overlap scopeOverlapping() applies, run over the
            // rows already in memory rather than as another query per range.
            $clash = $existing->filter(
                fn (Booking $b) => $b->starts_at->lessThan($endsAt) && $b->ends_at->greaterThan($startsAt),
            );

            $free[$key] = $clash->isEmpty() || ($scope === 'whole'
                ? $this->checkWhole($clash)
                : $this->checkSections($unit, $clash, $sectionIds, $clientId))['ok'];
        }

        return $free;
    }

    /**
     * The earliest day that still accepts a booking — today, unless an
     * existing booking whose date has passed is the one being edited.
     *
     * Same rule as startsInThePast(), read once for a whole month: a booking
     * being edited must not find its own day struck out of the calendar it is
     * shown in.
     */
    private function earliestBookableDay(?int $ignoreBookingId): CarbonInterface
    {
        $today = now()->startOfDay();

        if ($ignoreBookingId === null) {
            return $today;
        }

        $own = Booking::whereKey($ignoreBookingId)->value('starts_at');

        return $own && $own->lessThan($today) ? $own->startOfDay() : $today;
    }

    /**
     * @param  list<CarbonInterface>  $dates
     */
    private function edge(array $dates, bool $earliest): CarbonInterface
    {
        return array_reduce(
            $dates,
            fn (?CarbonInterface $carry, CarbonInterface $date) => $carry === null
                || ($earliest ? $date->lessThan($carry) : $date->greaterThan($carry))
                    ? $date
                    : $carry,
            null,
        );
    }

    /**
     * القاعدة 2: أي حجز قائم — كاملًا كان أو قسمًا — يمنع حجز الوحدة كاملة.
     *
     * @param  Collection<int, Booking>  $existing
     * @return array{ok: bool, reason: string|null, conflicts: list<array<string, mixed>>}
     */
    private function checkWhole(Collection $existing): array
    {
        $whole = $existing->firstWhere('scope', 'whole');

        if ($whole) {
            return $this->fail(
                "الوحدة محجوزة كاملة في هذا الوقت ضمن الحجز {$whole->reference}.",
                $existing,
            );
        }

        $names = $existing
            ->flatMap(fn (Booking $b) => $b->sections->pluck('name'))
            ->unique()
            ->implode('، ');

        return $this->fail(
            "لا يمكن حجز الوحدة كاملة لوجود أقسام محجوزة في نفس الوقت ({$names}).",
            $existing,
        );
    }

    /**
     * القواعد 1 و3 و4 على حجز الأقسام.
     *
     * @param  Collection<int, Booking>  $existing
     * @param  list<int>  $sectionIds
     * @return array{ok: bool, reason: string|null, conflicts: list<array<string, mixed>>}
     */
    private function checkSections(Unit $unit, Collection $existing, array $sectionIds, ?int $clientId): array
    {
        // القاعدة 1: الوحدة محجوزة كاملة ⇒ كل أقسامها مقفلة.
        if ($whole = $existing->firstWhere('scope', 'whole')) {
            return $this->fail(
                "الوحدة محجوزة كاملة في هذا الوقت ضمن الحجز {$whole->reference}، فكل أقسامها مقفلة.",
                $existing,
            );
        }

        // القاعدة 3: القسم نفسه محجوز.
        foreach ($existing as $booking) {
            $clash = $booking->sections->whereIn('id', $sectionIds);

            if ($clash->isNotEmpty()) {
                $names = $clash->pluck('name')->implode('، ');

                return $this->fail(
                    "القسم محجوز في نفس الوقت ضمن الحجز {$booking->reference} ({$names}).",
                    $existing,
                );
            }
        }

        // القاعدة 4: الخصوصية — قسم محجوز يحجب بقية الأقسام عن عميل مختلف.
        if ($unit->isExclusive()) {
            $otherClient = $existing->first(
                fn (Booking $b) => $clientId === null || (int) $b->client_id !== (int) $clientId,
            );

            if ($otherClient) {
                $names = $otherClient->sections->pluck('name')->implode('، ');

                return $this->fail(
                    "خصوصية الوحدة مفعّلة: يوجد قسم محجوز لعميل آخر في نفس الوقت ({$names}) "
                    ."ضمن الحجز {$otherClient->reference}، فلا يجوز حجز قسم آخر معه.",
                    $existing,
                );
            }
        }

        return ['ok' => true, 'reason' => null, 'conflicts' => []];
    }

    /**
     * بداية الحجز في يوم مضى.
     *
     * تعديل حجز قائم لا يُمنع بتاريخه هو: الموظف يفتح حجز الأسبوع الماضي
     * ليصحّح عدد الضيوف أو ملاحظةً، ومنعُه يقفل السجل بلا فائدة. الممنوع أن
     * يُنشأ حجز في يوم مضى، أو أن يُنقل حجز قائم إلى يوم مضى — وكلاهما هنا
     * لا يجد لنفسه حجزًا مستثنًى بنفس التاريخ فيُرفض.
     */
    private function startsInThePast(CarbonInterface $startsAt, ?int $ignoreBookingId): bool
    {
        if (! $startsAt->isBefore(now()->startOfDay())) {
            return false;
        }

        if ($ignoreBookingId === null) {
            return true;
        }

        return ! Booking::whereKey($ignoreBookingId)
            ->whereDate('starts_at', $startsAt->toDateString())
            ->exists();
    }

    /**
     * الحجوزات الشاغلة للوحدة والمتقاطعة زمنيًا مع المدى المطلوب.
     *
     * @return Collection<int, Booking>
     */
    private function blockingBookings(Unit $unit, CarbonInterface $startsAt, CarbonInterface $endsAt, ?int $ignoreBookingId): Collection
    {
        return Booking::query()
            ->with('sections:id,name')
            ->where('unit_id', $unit->id)
            ->blocking()
            ->overlapping($startsAt->toDateTimeString(), $endsAt->toDateTimeString())
            ->when($ignoreBookingId, fn ($q) => $q->whereKeyNot($ignoreBookingId))
            ->get();
    }

    /**
     * @param  Collection<int, Booking>|null  $conflicts
     * @return array{ok: bool, reason: string, conflicts: list<array<string, mixed>>}
     */
    private function fail(string $reason, ?Collection $conflicts = null): array
    {
        return [
            'ok' => false,
            'reason' => $reason,
            'conflicts' => $conflicts
                ? $conflicts->map(fn (Booking $b) => [
                    'id' => $b->id,
                    'reference' => $b->reference,
                    'scope' => $b->scope,
                    'status' => $b->status,
                    'starts_at' => $b->starts_at->toDateTimeString(),
                    'ends_at' => $b->ends_at->toDateTimeString(),
                    'sections' => $b->sections->pluck('name')->all(),
                ])->values()->all()
                : [],
        ];
    }
}
