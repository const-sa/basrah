<?php

namespace App\Support;

use App\Models\Booking;
use App\Models\BookingPayment;
use App\Models\Contract;
use App\Models\Sale;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Who hears a notice.
 *
 * The rule's permission keys answer "which desk" and the record's unit answers
 * "which branch". The actor is included like everyone else: with a staff this
 * size the inbox doubles as the day's record, and a register missing exactly
 * the entries you made yourself is not a record of the day.
 */
class NotificationAudience
{
    /**
     * @param  array<string, mixed>  $rule
     * @return Collection<int, User>
     */
    public static function for(array $rule, Model $record): Collection
    {
        $unitId = static::unitOf($record);
        $hears = $rule['hears'] ?? [];

        // The whole staff is a few dozen rows, and the pivot is loaded with
        // them so the unit check below costs no query per user.
        return User::query()
            ->where('is_active', true)
            ->with(['role', 'units:id'])
            ->get()
            // A role without the inbox would collect rows it can never open.
            ->filter(fn (User $user) => $user->hasPermission('notifications.view'))
            ->filter(fn (User $user) => $user->hasAnyPermission(...$hears))
            ->filter(fn (User $user) => $unitId === null || static::reaches($user, $unitId))
            ->values();
    }

    /**
     * The unit a record belongs to, or null when it belongs to none — a
     * supplier or a role is nobody's branch, and everyone entitled hears it.
     */
    public static function unitOf(Model $record): ?int
    {
        $unitId = match (true) {
            $record instanceof Unit => $record->getKey(),
            $record instanceof Booking, $record instanceof Sale => $record->unit_id,
            $record instanceof BookingPayment => $record->booking?->unit_id,
            $record instanceof Contract => $record->booking?->unit_id,
            default => null,
        };

        return $unitId === null ? null : (int) $unitId;
    }

    private static function reaches(User $user, int $unitId): bool
    {
        return $user->seesAllUnits() || $user->units->contains('id', $unitId);
    }
}
