<?php

namespace App\Services\Concerns;

/**
 * Resolve a stored image to a path mpdf can read off disk — handing it an
 * HTTP URL of this same server hangs generation on a single worker.
 */
trait ResolvesPublicFiles
{
    /**
     * Local path to the image on disk, if it exists — otherwise null.
     */
    protected function localPath(?string $path): ?string
    {
        if (blank($path)) {
            return null;
        }

        $relative = ltrim(str_replace('\\', '/', $path), '/');

        // Paths are stored as «storage/logos/x.png» or «logos/x.png» depending
        // on where the upload came from, so both are tried.
        foreach ([public_path($relative), storage_path('app/public/'.$relative)] as $candidate) {
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        $stripped = preg_replace('#^storage/#', '', $relative) ?? $relative;
        $candidate = storage_path('app/public/'.$stripped);

        return is_file($candidate) ? $candidate : null;
    }
}
