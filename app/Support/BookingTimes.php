<?php

namespace App\Support;

use App\Models\Setting;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * The hours a booking is built from, as the client has set them.
 *
 * Resolved once per request and memoised here rather than in a static, so
 * nothing survives between tests: this is bound as a container singleton and
 * the container is rebuilt per application instance. Setting::booted() drops
 * the instance on save, so an edit takes effect within the same request.
 *
 * Every value falls back — settings, then config, then the shipped constant —
 * so an install that has never opened the screen behaves as it always did.
 */
class BookingTimes
{
    /** @var array<string, array{label: string, start: string, end: string, overnight: bool}>|null */
    private ?array $periods = null;

    /** @var array{check_in: string, check_out: string, max_nights: int}|null */
    private ?array $stay = null;

    private ?Setting $settings = null;

    /**
     * Day periods with their hours applied.
     *
     * @return array<string, array{label: string, start: string, end: string, overnight: bool}>
     */
    public function periods(): array
    {
        if ($this->periods !== null) {
            return $this->periods;
        }

        $saved = $this->settings()?->booking_periods ?? [];
        $periods = [];

        foreach (BookingPeriod::DEFAULTS as $key => $meta) {
            $start = $this->time($saved[$key]['start'] ?? null, $meta['start']);
            $end = $this->time($saved[$key]['end'] ?? null, $meta['end']);

            $periods[$key] = [
                'label' => $meta['label'],
                'start' => $start,
                'end' => $end,
                // Derived, never stored: a period crosses midnight exactly when
                // it does not end later than it starts. Keeping a separate flag
                // would let it disagree with the hours beside it — an evening
                // shortened to 17:00–23:00 must stop closing the next night.
                'overnight' => $end <= $start,
            ];
        }

        return $this->periods = $periods;
    }

    /**
     * @return array{check_in: string, check_out: string, max_nights: int}
     */
    public function stay(): array
    {
        if ($this->stay !== null) {
            return $this->stay;
        }

        $settings = $this->settings();

        $nights = (int) ($settings?->chalet_max_nights
            ?: config('operations.booking.chalet.max_nights', 30));

        return $this->stay = [
            'check_in' => $this->time(
                $settings?->chalet_check_in_time,
                (string) config('operations.booking.chalet.check_in_time', '16:00'),
            ),
            'check_out' => $this->time(
                $settings?->chalet_check_out_time,
                (string) config('operations.booking.chalet.check_out_time', '12:00'),
            ),
            'max_nights' => max(1, $nights),
        ];
    }

    /**
     * A stored value only wins if it is a real HH:MM — a half-typed or blank
     * one falls back rather than producing a booking range nobody can parse.
     */
    private function time(mixed $value, string $fallback): string
    {
        return is_string($value) && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $value)
            ? $value
            : $fallback;
    }

    /**
     * Settings row, or null when it cannot be read — during migrations and on
     * a fresh install the table may not exist yet, and booting must not die
     * for want of an hour that has a default anyway.
     */
    private function settings(): ?Setting
    {
        if ($this->settings !== null) {
            return $this->settings;
        }

        try {
            if (! Schema::hasTable('settings')) {
                return null;
            }

            return $this->settings = Setting::current();
        } catch (Throwable) {
            return null;
        }
    }
}
