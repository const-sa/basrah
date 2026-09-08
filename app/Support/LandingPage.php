<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\Route as Router;

/**
 * The first admin screen a user may actually open — a pools role holds pos.*
 * and no dashboard, so /admin for everyone locks it out of its own system.
 */
class LandingPage
{
    /** Where the user belongs after login, or null when no screen is open to them. */
    public static function for(User $user): ?string
    {
        if ($user->hasPermission('dashboard.view')) {
            return route('dashboard');
        }

        // The registry orders systems and their screens as the sidebar lists them,
        // so the first one the user owns is the home they would have clicked.
        foreach (SystemRegistry::SYSTEMS as $system => $definition) {
            if (! $user->hasSystemAccess($system)) {
                continue;
            }

            foreach (array_keys($definition['modules']) as $module) {
                if ($user->hasPermission("{$module}.view") && $url = self::screenOf($module)) {
                    return $url;
                }
            }
        }

        return null;
    }

    /** The plain page guarded by this screen's view key — no record id, no download. */
    private static function screenOf(string $module): ?string
    {
        foreach (Router::getRoutes() as $route) {
            $matches = in_array('GET', $route->methods(), true)
                && str_starts_with($route->uri(), 'admin/')
                && ! str_contains($route->uri(), '{')
                && ! str_ends_with((string) $route->getName(), '.export')
                && in_array("perm:{$module}.view", $route->gatherMiddleware(), true);

            if ($matches) {
                return url($route->uri());
            }
        }

        return null;
    }
}
