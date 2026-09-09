<?php

namespace App\Support;

use App\Models\Client;
use App\Models\Contract;
use App\Models\Expense;
use App\Models\User;

/**
 * Clients, expenses and contracts are one screen per activity, so they are one
 * key per activity too — with the section's global key answering for them all.
 */
class ActivityPermission
{
    /** Activity → the prefix its keys carry (hall_clients, chalet_expenses…). */
    private const PREFIX = [
        ActivitySegment::HALLS => 'hall',
        ActivitySegment::CHALETS => 'chalet',
        ActivitySegment::POOLS => 'pool',
    ];

    /**
     * The scoped key for one activity, e.g. ('clients', 'edit', 'pools') → pool_clients.edit.
     */
    public static function key(string $screen, string $action, string $activity): ?string
    {
        $prefix = self::PREFIX[$activity] ?? null;

        return $prefix === null ? null : "{$prefix}_{$screen}.{$action}";
    }

    /**
     * May this user take the action on a record of this activity?
     * The global key covers every activity; the scoped key covers only its own.
     */
    public static function allows(?User $user, string $screen, string $action, ?string $activity): bool
    {
        if ($user === null) {
            return false;
        }

        if ($user->hasPermission("{$screen}.{$action}")) {
            return true;
        }

        $scoped = $activity === null ? null : self::key($screen, $action, $activity);

        return $scoped !== null && $user->hasPermission($scoped);
    }

    /**
     * The screen's answer for each action, ready to hand to the front end.
     *
     * @param  list<string>  $actions
     * @return array<string, bool>
     */
    public static function abilities(?User $user, string $screen, ?string $activity, array $actions): array
    {
        $out = [];

        foreach ($actions as $action) {
            $out[$action] = self::allows($user, $screen, $action, $activity);
        }

        return $out;
    }

    /**
     * For a catalogue shared by every register — an expense type, say. Holding
     * the action anywhere is enough, since the list itself belongs to no activity.
     */
    public static function allowsAnywhere(?User $user, string $screen, string $action): bool
    {
        if (self::allows($user, $screen, $action, null)) {
            return true;
        }

        foreach (ActivitySegment::activities() as $activity) {
            if (self::allows($user, $screen, $action, $activity)) {
                return true;
            }
        }

        return false;
    }

    /** Every activity whose register of this screen the user may open. */
    public static function readableActivities(?User $user, string $screen): array
    {
        return array_values(array_filter(
            ActivitySegment::activities(),
            fn (string $activity) => self::allows($user, $screen, 'view', $activity),
        ));
    }

    /** A client belongs to the activity its type names. */
    public static function ofClient(?Client $client): ?string
    {
        return self::fromClientType($client?->type);
    }

    /** ClientType keys are unit types; the segments are their plural names. */
    public static function fromClientType(?string $type): ?string
    {
        return match ($type) {
            ClientType::HALL => ActivitySegment::HALLS,
            ClientType::CHALET => ActivitySegment::CHALETS,
            ClientType::POOL => ActivitySegment::POOLS,
            default => null,
        };
    }

    /** An expense belongs to the activity of the cost centre it was booked to. */
    public static function ofExpense(?Expense $expense): ?string
    {
        return self::ofCostCenter($expense?->cost_center_id);
    }

    /** A centre outside the three activities answers to the global key alone. */
    public static function ofCostCenter(int|string|null $costCenterId): ?string
    {
        if ($costCenterId === null || $costCenterId === '') {
            return null;
        }

        $segment = app(ActivitySegment::class)->of((int) $costCenterId);

        return ActivitySegment::isActivity($segment) ? $segment : null;
    }

    /**
     * A contract follows its booking's unit; with no booking it follows the form
     * it was drawn on. Kept in step with ContractsController::register().
     */
    public static function ofContract(?Contract $contract): ?string
    {
        if ($contract === null) {
            return null;
        }

        $contract->loadMissing(['booking.unit:id,type']);

        if ($contract->booking?->unit?->type === 'hall') {
            return ActivitySegment::HALLS;
        }

        if ($contract->booking?->unit?->type === 'chalet') {
            return ActivitySegment::CHALETS;
        }

        return self::ofForm($contract->data['form'] ?? null);
    }

    /** Anything not drawn on a halls or chalets form is the pools'. */
    public static function ofForm(?string $form): string
    {
        return match (true) {
            $form === ChaletContractTemplate::FORM => ActivitySegment::CHALETS,
            in_array($form, [HallRentalContractTemplate::FORM, HallServicesContractTemplate::FORM], true) => ActivitySegment::HALLS,
            default => ActivitySegment::POOLS,
        };
    }

    /** The register scope a screen was opened under, as an activity. */
    public static function ofScope(string $scope): ?string
    {
        return match ($scope) {
            'hall' => ActivitySegment::HALLS,
            'chalet' => ActivitySegment::CHALETS,
            'quotation' => ActivitySegment::POOLS,
            default => null,
        };
    }
}
