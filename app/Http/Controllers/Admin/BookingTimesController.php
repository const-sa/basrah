<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Support\BookingPeriod;
use App\Support\BookingTimes;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * أوقات الحجز — فترات اليوم وأوقات إقامة الشاليه.
 *
 * These hours are not labels: BookingPeriod::range() and StayPeriod::range()
 * build every booking's starts_at → ends_at from them, and that range is what
 * conflict detection compares. Editing them changes what counts as a clash.
 */
class BookingTimesController extends Controller
{
    public function edit(): Response
    {
        $stay = app(BookingTimes::class)->stay();

        return Inertia::render('admin/settings/BookingTimes', [
            'periods' => collect(BookingPeriod::periods())
                ->map(fn (array $meta, string $key) => [
                    'key' => $key,
                    'label' => $meta['label'],
                    'start' => $meta['start'],
                    'end' => $meta['end'],
                    'overnight' => $meta['overnight'],
                    'default_start' => BookingPeriod::DEFAULTS[$key]['start'],
                    'default_end' => BookingPeriod::DEFAULTS[$key]['end'],
                ])
                ->values()
                ->all(),
            'stay' => [
                'check_in_time' => $stay['check_in'],
                'check_out_time' => $stay['check_out'],
                'max_nights' => $stay['max_nights'],
            ],
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $time = ['required', 'date_format:H:i'];

        $data = $request->validate([
            'periods' => ['required', 'array'],
            'periods.*.key' => ['required', 'string', 'in:'.implode(',', array_keys(BookingPeriod::DEFAULTS))],
            'periods.*.start' => $time,
            'periods.*.end' => $time,
            'check_in_time' => $time,
            'check_out_time' => $time,
            // The ceiling guards against a typo booking a chalet for a year,
            // so it is a real bound rather than an open number.
            'max_nights' => ['required', 'integer', 'min:1', 'max:365'],
        ], [
            'date_format' => 'الوقت يُكتب بصيغة 24 ساعة مثل 09:00 أو 17:30.',
        ]);

        // Stored keyed by period so a period added later reads as "unset" and
        // takes its default, rather than shifting to a neighbour's hours.
        $periods = [];

        foreach ($data['periods'] as $row) {
            $periods[$row['key']] = ['start' => $row['start'], 'end' => $row['end']];
        }

        $settings = Setting::current();
        $settings->fill([
            'booking_periods' => $periods,
            'chalet_check_in_time' => $data['check_in_time'],
            'chalet_check_out_time' => $data['check_out_time'],
            'chalet_max_nights' => $data['max_nights'],
        ])->save();

        return back()->with('success', 'تم حفظ أوقات الحجز');
    }

    /**
     * Restore the shipped hours by clearing what was saved — null means
     * "use the default", so this is a clear rather than a rewrite.
     */
    public function reset(): RedirectResponse
    {
        Setting::current()->fill([
            'booking_periods' => null,
            'chalet_check_in_time' => null,
            'chalet_check_out_time' => null,
            'chalet_max_nights' => null,
        ])->save();

        return back()->with('success', 'أُعيدت أوقات الحجز إلى الافتراضي');
    }
}
