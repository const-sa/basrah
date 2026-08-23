<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\Setting;
use App\Models\User;
use App\Support\BookingPeriod;
use App\Support\StayPeriod;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * أوقات الحجز تُدار من الإعدادات لا من الشيفرة.
 *
 * These hours build every booking's starts_at → ends_at, so the tests check
 * the resolved range, not only what was stored.
 */
class BookingTimesSettingsTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesSeeder::class);

        $this->owner = User::factory()->create([
            'role_id' => Role::where('slug', 'super-admin')->value('id'),
            'is_active' => true,
            'has_all_units' => true,
        ]);
    }

    public function test_untouched_settings_fall_back_to_the_shipped_hours(): void
    {
        $this->assertSame('09:00', BookingPeriod::periods()['morning']['start']);
        $this->assertSame('17:00', BookingPeriod::periods()['evening']['start']);
        $this->assertSame('16:00', StayPeriod::checkInTime());
        $this->assertSame('12:00', StayPeriod::checkOutTime());
        $this->assertSame(30, StayPeriod::maxNights());
    }

    public function test_the_screen_saves_hours_and_they_take_effect(): void
    {
        $this->actingAs($this->owner)
            ->post('/admin/settings/booking-times', [
                'periods' => [
                    ['key' => 'morning', 'start' => '08:00', 'end' => '14:00'],
                    ['key' => 'evening', 'start' => '15:00', 'end' => '23:00'],
                    ['key' => 'full_day', 'start' => '08:00', 'end' => '23:59'],
                ],
                'check_in_time' => '15:00',
                'check_out_time' => '11:00',
                'max_nights' => 14,
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame('08:00', BookingPeriod::periods()['morning']['start']);
        $this->assertSame('15:00', StayPeriod::checkInTime());
        $this->assertSame(14, StayPeriod::maxNights());

        // The hours must reach the range the conflict check compares.
        [$starts, $ends] = BookingPeriod::range('2026-10-01', 'morning');
        $this->assertSame('2026-10-01 08:00:00', $starts->toDateTimeString());
        $this->assertSame('2026-10-01 14:00:00', $ends->toDateTimeString());
    }

    /**
     * The overnight flag is derived from the hours rather than stored, so an
     * evening shortened to end before midnight must stop closing the next day.
     */
    public function test_shortening_the_evening_stops_it_crossing_midnight(): void
    {
        [, $before] = BookingPeriod::range('2026-10-01', 'evening');
        $this->assertSame('2026-10-02 01:00:00', $before->toDateTimeString());

        $this->actingAs($this->owner)->post('/admin/settings/booking-times', [
            'periods' => [
                ['key' => 'morning', 'start' => '09:00', 'end' => '17:00'],
                ['key' => 'evening', 'start' => '17:00', 'end' => '23:00'],
                ['key' => 'full_day', 'start' => '09:00', 'end' => '01:00'],
            ],
            'check_in_time' => '16:00',
            'check_out_time' => '12:00',
            'max_nights' => 30,
        ])->assertSessionHasNoErrors();

        $this->assertFalse(BookingPeriod::periods()['evening']['overnight']);

        [, $after] = BookingPeriod::range('2026-10-01', 'evening');
        $this->assertSame('2026-10-01 23:00:00', $after->toDateTimeString());

        // full_day still wraps, so the derivation is per period not global.
        $this->assertTrue(BookingPeriod::periods()['full_day']['overnight']);
    }

    public function test_a_malformed_time_is_rejected(): void
    {
        $this->actingAs($this->owner)
            ->post('/admin/settings/booking-times', [
                'periods' => [['key' => 'morning', 'start' => '25:00', 'end' => '17:00']],
                'check_in_time' => '16:00',
                'check_out_time' => '12:00',
                'max_nights' => 30,
            ])
            ->assertSessionHasErrors('periods.0.start');
    }

    public function test_reset_restores_the_shipped_hours(): void
    {
        Setting::current()->fill([
            'booking_periods' => ['morning' => ['start' => '06:00', 'end' => '10:00']],
            'chalet_check_in_time' => '18:00',
        ])->save();

        $this->assertSame('06:00', BookingPeriod::periods()['morning']['start']);

        $this->actingAs($this->owner)
            ->post('/admin/settings/booking-times/reset')
            ->assertRedirect();

        $this->assertSame('09:00', BookingPeriod::periods()['morning']['start']);
        $this->assertSame('16:00', StayPeriod::checkInTime());
    }

    /**
     * A stored value that is not a usable HH:MM must not reach a booking
     * range — it falls back instead of producing an unparseable time.
     */
    public function test_a_corrupt_stored_time_falls_back(): void
    {
        Setting::current()->fill([
            'booking_periods' => ['morning' => ['start' => 'صباحًا', 'end' => '']],
        ])->save();

        $this->assertSame('09:00', BookingPeriod::periods()['morning']['start']);
        $this->assertSame('17:00', BookingPeriod::periods()['morning']['end']);
    }

    public function test_the_screen_needs_permission(): void
    {
        $plain = User::factory()->create(['role_id' => null, 'is_active' => true]);

        $this->actingAs($plain)->get('/admin/settings/booking-times')->assertForbidden();
        $this->actingAs($this->owner)->get('/admin/settings/booking-times')->assertOk();
    }
}
