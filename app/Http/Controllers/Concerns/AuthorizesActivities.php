<?php

namespace App\Http\Controllers\Concerns;

use App\Support\ActivityPermission;
use Illuminate\Http\Request;

/**
 * Clients, expenses and contracts are one screen per activity. A record route
 * cannot carry a key, so the activity is read off the record and checked here.
 */
trait AuthorizesActivities
{
    /** Stop unless the user may take this action on this activity's records. */
    protected function authorizeActivity(Request $request, string $screen, string $action, ?string $activity): void
    {
        abort_unless(
            ActivityPermission::allows($request->user(), $screen, $action, $activity),
            403,
            'ليس لديك صلاحية هذا الإجراء على سجلّ هذا النشاط.',
        );
    }

    /** For a catalogue every register shares — holding the action anywhere is enough. */
    protected function authorizeAnyActivity(Request $request, string $screen, string $action): void
    {
        abort_unless(
            ActivityPermission::allowsAnywhere($request->user(), $screen, $action),
            403,
            'ليس لديك صلاحية هذا الإجراء.',
        );
    }

    /**
     * What the screen may offer for this activity — handed to the front end so
     * the buttons follow the same answer the routes enforce.
     *
     * @param  list<string>  $actions
     * @return array<string, bool>
     */
    protected function activityAbilities(Request $request, string $screen, ?string $activity, array $actions): array
    {
        return ActivityPermission::abilities($request->user(), $screen, $activity, $actions);
    }
}
