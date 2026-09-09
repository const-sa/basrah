<?php

use App\Support\ActivitySegment;
use App\Support\SystemRegistry;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Clients, expenses and contracts become one key per activity. Each role keeps
 * what it held, scoped to the activities it actually works in.
 */
return new class extends Migration
{
    /** The screens being split, and the prefix each activity's key carries. */
    private const SPLIT = ['clients', 'expenses', 'contracts'];

    private const PREFIX = [
        ActivitySegment::HALLS => 'hall',
        ActivitySegment::CHALETS => 'chalet',
        ActivitySegment::POOLS => 'pool',
    ];

    /**
     * A role keeps the all-activities register if it oversees the business
     * rather than working one activity in it. A floor role gets its own only.
     */
    private const OVERSIGHT = ['dashboard.view', 'reports.view', 'fin_reports.view'];

    public function up(): void
    {
        DB::table('roles')->orderBy('id')->each(function ($role) {
            $permissions = json_decode($role->permissions ?? '[]', true);

            if (! is_array($permissions)) {
                return;
            }

            if ($role->slug === 'super-admin') {
                DB::table('roles')->where('id', $role->id)->update([
                    'permissions' => json_encode(SystemRegistry::permissionKeys(), JSON_UNESCAPED_UNICODE),
                ]);

                return;
            }

            $granted = $permissions;
            $oversees = (bool) array_intersect(self::OVERSIGHT, $permissions);

            foreach (self::SPLIT as $screen) {
                $held = $this->actionsHeld($permissions, $screen);

                if ($held === []) {
                    continue;
                }

                foreach ($this->activitiesOf($permissions) as $activity) {
                    foreach ($held as $action) {
                        $granted[] = self::PREFIX[$activity]."_{$screen}.{$action}";
                    }
                }

                // The global key is oversight, not day-to-day work: a role that
                // works an activity and oversees nothing loses it here.
                if (! $oversees && $this->activitiesOf($permissions) !== []) {
                    $granted = array_filter($granted, fn ($key) => ! str_starts_with((string) $key, "{$screen}."));
                }
            }

            DB::table('roles')->where('id', $role->id)->update([
                'permissions' => json_encode(
                    array_values(array_unique(array_filter($granted, fn ($k) => in_array($k, SystemRegistry::permissionKeys(), true)))),
                    JSON_UNESCAPED_UNICODE,
                ),
            ]);
        });
    }

    /**
     * The actions the role holds on a screen's global key today.
     *
     * @param  list<string>  $permissions
     * @return list<string>
     */
    private function actionsHeld(array $permissions, string $screen): array
    {
        $held = [];

        foreach ($permissions as $key) {
            [$module, $action] = array_pad(explode('.', (string) $key, 2), 2, null);

            if ($module === $screen && $action !== null) {
                $held[] = $action;
            }
        }

        return $held;
    }

    /**
     * The activities a role works in — read from the keys those sections own,
     * not from the shared ones being split here.
     *
     * @param  list<string>  $permissions
     * @return list<string>
     */
    private function activitiesOf(array $permissions): array
    {
        $out = [];

        foreach (array_keys(self::PREFIX) as $activity) {
            $owned = array_filter(
                SystemRegistry::systemPermissionKeys($activity),
                fn (string $key) => ! in_array(explode('.', $key)[0], $this->splitKeys(), true),
            );

            if (array_intersect($owned, $permissions)) {
                $out[] = $activity;
            }
        }

        return $out;
    }

    /**
     * The new scoped module names, excluded when reading activity access so a
     * re-run cannot bootstrap an activity off the keys it granted last time.
     *
     * @return list<string>
     */
    private function splitKeys(): array
    {
        $names = [];

        foreach (self::PREFIX as $prefix) {
            foreach (self::SPLIT as $screen) {
                $names[] = "{$prefix}_{$screen}";
            }
        }

        return $names;
    }

    /** Strips the scoped keys and hands the global ones back. */
    public function down(): void
    {
        DB::table('roles')->orderBy('id')->each(function ($role) {
            $permissions = json_decode($role->permissions ?? '[]', true);

            if (! is_array($permissions)) {
                return;
            }

            $kept = [];

            foreach ($permissions as $key) {
                $module = explode('.', (string) $key)[0];
                $action = explode('.', (string) $key)[1] ?? null;

                if (! in_array($module, $this->splitKeys(), true)) {
                    $kept[] = $key;

                    continue;
                }

                // pool_clients.edit → clients.edit
                foreach (self::SPLIT as $screen) {
                    if (str_ends_with($module, "_{$screen}") && $action !== null) {
                        $kept[] = "{$screen}.{$action}";
                    }
                }
            }

            DB::table('roles')->where('id', $role->id)->update([
                'permissions' => json_encode(array_values(array_unique($kept)), JSON_UNESCAPED_UNICODE),
            ]);
        });
    }
};
